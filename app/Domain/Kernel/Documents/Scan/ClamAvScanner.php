<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Scan;

/**
 * The ClamAV sidecar adapter (§14.4's "queue-clamav against a ClamAV sidecar is the honest Linux
 * design").
 *
 * INSTREAM rather than a temp-path command: the file is already on disk, and asking clamd to read a
 * path means the daemon and the app must agree on a filesystem — true in a sidecar container that
 * shares the volume, false the moment the private disk is the Cloud bucket. Streaming the bytes
 * works either way and costs one socket.
 *
 * Every transport failure answers **pending** with the reason. That is the rule the interface
 * documents: an unreachable scanner must not be reported as a clean file, or the download gate
 * waves through everything during exactly the window when nothing is checking.
 */
final class ClamAvScanner implements VirusScanner
{
    private const ChunkSize = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 3310,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    public function scan(string $absolutePath, string $hint): ScanResult
    {
        if ($this->host === '') {
            return ScanResult::pending('clamav selected but fleet.documents.clamav_host is empty.');
        }

        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return ScanResult::pending("clamav could not read {$hint}: the file is not on disk.");
        }

        try {
            $socket = @stream_socket_client(
                sprintf('tcp://%s:%d', $this->host, $this->port),
                $errno,
                $errstr,
                $this->timeoutSeconds,
            );

            if ($socket === false) {
                return ScanResult::pending(sprintf('clamav unreachable at %s:%d (%s); the file is not scanned.', $this->host, $this->port, (string) $errstr));
            }

            stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));

            $verdict = $this->instream($socket, $handle, $hint);
            $meta = stream_get_meta_data($socket);
            fclose($socket);

            if ($meta['timed_out']) {
                return ScanResult::pending("clamav timed out after {$this->timeoutSeconds}s on {$hint}.");
            }

            return $verdict;
        } finally {
            fclose($handle);
        }
    }

    public function isAuthoritative(): bool
    {
        return true;
    }

    /**
     * @param  resource  $socket
     * @param  resource  $handle
     */
    private function instream($socket, $handle, string $hint): ScanResult
    {
        if (fwrite($socket, "zINSTREAM\0") === false) {
            return ScanResult::pending('clamav closed the socket before the INSTREAM command was accepted.');
        }

        while (! feof($handle)) {
            $chunk = fread($handle, self::ChunkSize);

            if ($chunk === false || $chunk === '') {
                break;
            }

            // The wire format is a 32-bit big-endian length per chunk, then a zero length that
            // means "that is the whole file". A truncated stream is what clamd reads as a short
            // file, so a failed write stops the scan rather than continuing with half a document.
            if (fwrite($socket, pack('N', strlen($chunk)).$chunk) === false) {
                return ScanResult::pending('clamav stopped accepting bytes mid-scan.');
            }
        }

        if (fwrite($socket, pack('N', 0)) === false) {
            return ScanResult::pending('clamav did not accept the end-of-stream marker.');
        }

        $response = $this->readLine($socket);

        if ($response === null) {
            return ScanResult::pending('clamav sent no response.');
        }

        if (str_contains($response, 'FOUND SOME VIRUS')) {
            return ScanResult::infected($this->signature($response, $hint));
        }

        if (str_contains($response, ': OK')) {
            return ScanResult::clean();
        }

        // Anything else — a size limit, an unknown command, a database not loaded — is a scanner
        // that did not judge these bytes, which is pending by definition and not clean by accident.
        return ScanResult::pending("clamav answered \"{$response}\".");
    }

    /**
     * @param  resource  $socket
     */
    private function readLine($socket): ?string
    {
        $line = fgets($socket);

        if ($line === false) {
            return null;
        }

        return trim(str_replace("\0", '', $line));
    }

    /**
     * `stream: FOUND SOME VIRUS. EICAR-Test-File(…)` — the name before the parenthesis is the one
     * an owner recognises; the rest is engine detail.
     */
    private function signature(string $response, string $hint): string
    {
        if (preg_match('/FOUND SOME VIRUS\.\s*(.+?)\(/', $response, $m) === 1) {
            return trim($m[1]);
        }

        return $hint.' — signature not reported by clamd';
    }
}
