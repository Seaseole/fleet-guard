<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * / 'S delivery: the invoice PDF, emailed to the customer.
 *
 * Everything the mail says and everything it attaches is **captured before the job is queued**. A worker
 * runs with no company context (the defect: a queued notification re-reading a company-scoped model
 * threw `ModelNotFoundException`), so a mailable holding the `Invoice` model would either fail in the queue
 * or, worse, render a document from whichever tenant the worker happened to be pinned to. The bytes are the
 * document the clerk saw; a payment allocated after the mail queued cannot silently change what the customer
 * receives.
 *
 * The PDF is rendered at construction because `attachments()` runs on the worker, where `CurrentCompany` is
 * gone — the same boundary, one level down.
 */
final class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $documentNumber,
        public readonly string $customerName,
        public readonly string $companyName,
        public readonly string $totalFormatted,
        public readonly string $dueOn,
        public readonly string $kindLabel,
        public readonly string $pdf,
        public readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':kind :number from :company', [
                'kind' => $this->kindLabel,
                'number' => $this->documentNumber,
                'company' => $this->companyName,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoicing.invoice',
            with: [
                'documentNumber' => $this->documentNumber,
                'customerName' => $this->customerName,
                'companyName' => $this->companyName,
                'totalFormatted' => $this->totalFormatted,
                'dueOn' => $this->dueOn,
                'kindLabel' => $this->kindLabel,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
