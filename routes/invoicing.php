<?php

declare(strict_types=1);

use App\Domain\Invoicing\Documents\InvoiceDocuments;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\Statements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Invoicing screens (§3.2): the customer register, the invoice register and detail, and the ageing report.
 *
 * The PDF downloads are plain routes rather than Livewire actions because a browser download is a
 * response, not a state change: the page links to it, and the route renders and returns the document.
 * Both bind the model through the company scope, so another tenant's id is a 404 rather than a document
 * with the wrong letterhead.
 *
 * Every screen asks for `invoices.view`; the pages ask for the narrower abilities on the controls they
 * render (`invoices.void`, `payments.allocate`, `credit_notes.manage`, `invoices.issue`) and every Action
 * asks again — a screen is not a permission check (§9.2).
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('customers', 'pages::invoicing.customers')
        ->middleware('can:customers.view')
        ->name('invoicing.customers');

    Route::livewire('invoices', 'pages::invoicing.invoices')
        ->middleware('can:invoices.view')
        ->name('invoicing.index');

    Route::livewire('invoices/{invoice}', 'pages::invoicing.invoice')
        ->middleware('can:invoices.view')
        ->name('invoicing.invoices.show');

    Route::livewire('ageing', 'pages::invoicing.ageing')
        ->middleware('can:invoices.view')
        ->name('invoicing.ageing');

    Route::get('invoices/{invoice}/pdf', function (Invoice $invoice, InvoiceDocuments $documents) {
        $pdf = $documents->invoice($invoice);
        $filename = str_replace(['/', '\\'], '-', $invoice->reference()).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            // The document is a snapshot of a frozen number and a balance at render time; a shared cache
            // could serve one customer's statement to another.
            'Cache-Control' => 'private, no-store',
        ]);
    })->middleware('can:invoices.view')->name('invoicing.invoices.pdf');

    Route::get('customers/{customer}/statement.pdf', function (Request $request, Customer $customer, Statements $statements, InvoiceDocuments $documents) {
        $window = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($window['from']) ? \Carbon\Carbon::parse($window['from']) : \Carbon\Carbon::now()->startOfMonth();
        $to = isset($window['to']) ? \Carbon\Carbon::parse($window['to']) : \Carbon\Carbon::now();

        $statement = $statements->forCustomer((int) $customer->id, $from, $to);
        $pdf = $documents->statement($statement);
        $filename = 'statement-'.($customer->code ?: $customer->id).'-'.$from->toDateString().'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    })->middleware('can:customers.view')->name('invoicing.statements.pdf');
});
