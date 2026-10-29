<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Invoicing screens (§3.2). Phase 1 ships the customer register only — the module's documents
 * (invoices, payments, credit notes) arrive in Phase 4, but a job cannot be quoted and an invoice
 * cannot be addressed until the party paying for it exists.
 *
 * The route asks for `customers.view`, which every seat that names a customer on a job holds.
 * Editing the record is `customers.manage` and is asked by the page, because the same screen serves
 * both readers and editors.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('customers', 'pages::invoicing.customers')
        ->middleware('can:customers.view')
        ->name('invoicing.customers');
});
