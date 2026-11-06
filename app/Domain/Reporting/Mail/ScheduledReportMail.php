<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The scheduled nomination mail, built exactly like the invoice mail (the rule for
 * anything that queues: scalars and bytes in, nothing that needs a tenant context to come back
 * out).
 *
 * The inline table is a bounded preview and the attached PDF is the whole sheet — both are the
 * same ReportData from the same door, so a preview and an attachment cannot be two different
 * answers (the one-definition rule, applied to a mail).
 */
final class ScheduledReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $tableLines
     */
    public function __construct(
        public readonly string $reportTitle,
        public readonly string $window,
        public readonly string $companyName,
        public readonly array $tableLines,
        public readonly ?string $note,
        public readonly string $pdf,
        public readonly string $filename,
        public readonly string $truncatedBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Report — {$this->reportTitle} ({$this->window})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.reporting.scheduled',
            with: [
                'reportTitle' => $this->reportTitle,
                'window' => $this->window,
                'companyName' => $this->companyName,
                'tableLines' => $this->tableLines,
                'note' => $this->note,
                'truncatedBy' => $this->truncatedBy,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
