<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * One file, as the domain receives it: a path that exists right now, and what the client said about
 * it.
 *
 * It exists so the upload Action has a single shape to accept from three very different sources — a
 * Livewire component's `TemporaryUploadedFile`, a kiosk POST (§9.6), and a verifier script writing
 * a fixture from disk — and so the client's declared MIME type travels as a *claim* rather than as
 * the type anything trusts. The trusted type is
 * {@see ContentSniffer::sniff()}'s answer, and the two are compared so a spoofed
 * `Content-Type: application/pdf` around an executable is a recorded fact instead of a stored file.
 */
final class DocumentInput
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $declaredMime,
        public readonly string $clientName,
    ) {}

    public static function fromUploadedFile(UploadedFile $file): self
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('The uploaded file vanished before it could be read.');
        }

        return new self($path, (string) $file->getClientMimeType(), (string) $file->getClientOriginalName());
    }

    public static function fromPath(string $absolutePath, string $declaredMime = '', string $clientName = ''): self
    {
        return new self($absolutePath, $declaredMime, $clientName === '' ? basename($absolutePath) : $clientName);
    }
}
