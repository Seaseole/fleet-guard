<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Documents;

use App\Domain\Invoicing\Data\CustomerStatement;
use App\Domain\Invoicing\InvoiceKind;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\CreditNote;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * The print door: the three documents a customer receives, rendered by the **DOMPDF** driver.
 *
 * DOMPDF is the default on purpose: it needs no Chrome, no Node and no container binary, which is
 * the only honest answer for a Windows dev box and for Laravel Cloud. `config/laravel-pdf.php` pins it as
 * the default rather than leaving Browsershot in place with a comment, because a deployment that never
 * installs Chrome would discover the difference at the first invoice.
 *
 * Rendering is synchronous here and called from the queue where the caller wants it so. The bytes are
 * returned rather than a response because the two callers differ: the browser wants a download, the
 * mailable wants an attachment. Neither needs a live model after the call — that is the point, since the
 * worker runs without a company context.
 */
final class InvoiceDocuments
{
    public function invoice(Invoice $invoice): string
    {
        return $this->render('pdf.invoice', $this->invoiceData($invoice));
    }

    public function creditNote(CreditNote $note): string
    {
        $note->loadMissing(['lines', 'customer', 'invoice']);

        return $this->render('pdf.credit-note', [
            'company' => CurrentCompany::model(),
            'note' => $note,
            'customer' => $note->customer,
            'invoice' => $note->invoice,
            'kind' => 'Credit note',
            'number' => (string) $note->number,
            'issuedOn' => $note->issue_date->format('d M Y'),
            'dueOn' => null,
        ]);
    }

    public function statement(CustomerStatement $statement): string
    {
        return $this->render('pdf.statement', [
            'company' => CurrentCompany::model(),
            'statement' => $statement,
            'kind' => 'Customer statement',
            'number' => $statement->from->format('d M Y').' – '.$statement->to->format('d M Y'),
            'issuedOn' => $statement->to->format('d M Y'),
            'dueOn' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceData(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'customer']);

        return [
            'company' => CurrentCompany::model(),
            'invoice' => $invoice,
            'customer' => $invoice->customer,
            'kind' => $invoice->kind === InvoiceKind::Consolidated
                ? 'Consolidated tax invoice'
                : 'Tax invoice',
            'number' => $invoice->reference(),
            'issuedOn' => $invoice->issue_date->format('d M Y'),
            'dueOn' => $invoice->due_on->format('d M Y'),
            'watermark' => $invoice->status === InvoiceLifecycle::Void ? 'VOID' : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        return $this->builder($view, $data)->generatePdfContent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function builder(string $view, array $data): PdfBuilder
    {
        return Pdf::driver('dompdf')
            ->view($view, $data)
            ->format('A4')
            ->margins(18, 16, 20, 16);
    }
}
