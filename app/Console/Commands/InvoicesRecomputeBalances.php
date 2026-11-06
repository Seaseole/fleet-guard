<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The tie-out: rebuild every projected balance from the rows that decided it.
 *
 * `invoices.balance_amount` and `payments.unapplied_amount` are **projections**, not truth. The truth is
 * the allocation rows (and, for an invoice, the credit notes applied against it). The Actions maintain the
 * projection in the same transaction as the rows, so the two should never disagree — but "should never" is
 * what every stored total in every ledger system says, and the day one does disagree the ageing report,
 * the AR total and the statement all print the same wrong number with no way to see it.
 *
 * `--check` verifies and exits 1 on drift without writing. Without it the command repairs the projections,
 * which is the only safe automated change: it moves a cached figure toward the rows, never the reverse. The
 * ledger is untouched — this command proves the module's own sums, it does not post anything (the
 * `reports:tie-to-ledger` is the operational-to-statutory tie-out).
 */
final class InvoicesRecomputeBalances extends Command
{
    protected $signature = 'invoices:recompute-balances
        {--company= : Only this company id}
        {--check : Report drift without repairing it}';

    protected $description = 'Recompute invoice and receipt balances from their allocations and credit notes';

    public function handle(): int
    {
        $companyIds = $this->option('company') !== null
            ? [(int) $this->option('company')]
            : DB::table('companies')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $checkOnly = (bool) $this->option('check');
        $invoiceDrift = 0;
        $receiptDrift = 0;
        $checkedInvoices = 0;
        $checkedReceipts = 0;

        foreach ($companyIds as $companyId) {
            /*
             * One pass over the allocation rows feeds both projections. An invoice's *document*
             * balance moves by the allocation's own currency and its *base* balance by the base the
             * invoice was booked at; a receipt's base balance moves by the cash that actually left
             * `2150`, which is the booked base plus a realised gain or minus a realised loss
             *. Rebuilding the receipt side from `base_amount` alone would undo every foreign
             * settlement's FX line, so the signed difference is part of the arithmetic here.
             */
            $allocationRows = DB::table('payment_allocations')
                ->where('company_id', $companyId)
                ->where('reversed', false)
                ->get(['invoice_id', 'payment_id', 'amount', 'base_amount', 'fx_difference_side', 'fx_difference_base']);

            /** @var array<string, array{doc: int, base: int}> $toInvoice */
            $toInvoice = [];

            /** @var array<string, array{doc: int, base: int}> $fromReceipt */
            $fromReceipt = [];

            foreach ($allocationRows as $row) {
                $cashBase = (int) $row->base_amount + match ((string) $row->fx_difference_side) {
                    'gain' => (int) $row->fx_difference_base,
                    'loss' => -(int) $row->fx_difference_base,
                    default => 0,
                };

                $invoiceKey = (string) $row->invoice_id;
                $paymentKey = (string) $row->payment_id;

                $toInvoice[$invoiceKey] ??= ['doc' => 0, 'base' => 0];
                $fromReceipt[$paymentKey] ??= ['doc' => 0, 'base' => 0];

                $toInvoice[$invoiceKey]['doc'] += (int) $row->amount;
                $toInvoice[$invoiceKey]['base'] += (int) $row->base_amount;
                $fromReceipt[$paymentKey]['doc'] += (int) $row->amount;
                $fromReceipt[$paymentKey]['base'] += $cashBase;
            }

            $credits = DB::table('credit_notes')
                ->where('company_id', $companyId)
                ->whereIn('status', ['issued', 'applied', 'settled'])
                ->groupBy('invoice_id')
                ->selectRaw('invoice_id, sum(amount + tax_amount) as credited, sum(base_amount + tax_base) as credited_base')
                ->get(['invoice_id', 'credited', 'credited_base'])
                ->keyBy('invoice_id');

            $invoices = DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'draft')
                ->get(['id', 'number', 'amount', 'tax_amount', 'base_amount', 'tax_base', 'balance_amount', 'balance_base']);

            foreach ($invoices as $invoice) {
                $checkedInvoices++;

                $note = $credits[(string) $invoice->id] ?? null;

                $expected = (int) $invoice->amount + (int) $invoice->tax_amount
                    - ($toInvoice[(string) $invoice->id]['doc'] ?? 0)
                    - (int) ($note->credited ?? 0);

                $expectedBase = (int) $invoice->base_amount + (int) $invoice->tax_base
                    - ($toInvoice[(string) $invoice->id]['base'] ?? 0)
                    - (int) ($note->credited_base ?? 0);

                if ((int) $invoice->balance_amount === $expected && (int) $invoice->balance_base === $expectedBase) {
                    continue;
                }

                $invoiceDrift++;

                $this->line(sprintf(
                    ' %s  invoice %s — stored %d/%d, rows say %d/%d',
                    $checkOnly ? 'DRIFT' : 'FIXED',
                    (string) $invoice->number,
                    (int) $invoice->balance_amount,
                    (int) $invoice->balance_base,
                    $expected,
                    $expectedBase,
                ));

                if (! $checkOnly) {
                    DB::table('invoices')->where('id', $invoice->id)->update([
                        'balance_amount' => $expected,
                        'balance_base' => $expectedBase,
                        'updated_at' => now(),
                    ]);
                }
            }

            $receipts = DB::table('payments')
                ->where('company_id', $companyId)
                ->get(['id', 'number', 'amount', 'base_amount', 'unapplied_amount', 'unapplied_base']);

            foreach ($receipts as $payment) {
                $checkedReceipts++;

                $spent = $fromReceipt[(string) $payment->id] ?? ['doc' => 0, 'base' => 0];

                $expected = (int) $payment->amount - $spent['doc'];
                $expectedBase = (int) $payment->base_amount - $spent['base'];

                if ((int) $payment->unapplied_amount === $expected && (int) $payment->unapplied_base === $expectedBase) {
                    continue;
                }

                $receiptDrift++;

                $this->line(sprintf(
                    ' %s  receipt %s — stored %d/%d unapplied, rows say %d/%d',
                    $checkOnly ? 'DRIFT' : 'FIXED',
                    (string) $payment->number,
                    (int) $payment->unapplied_amount,
                    (int) $payment->unapplied_base,
                    $expected,
                    $expectedBase,
                ));

                if (! $checkOnly) {
                    DB::table('payments')->where('id', $payment->id)->update([
                        'unapplied_amount' => $expected,
                        'unapplied_base' => $expectedBase,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $drift = $invoiceDrift + $receiptDrift;

        if ($drift === 0) {
            $this->info(sprintf(
                'Balances tie out: %d invoices and %d receipts agree with their allocations and credit notes.',
                $checkedInvoices,
                $checkedReceipts,
            ));

            return self::SUCCESS;
        }

        if ($checkOnly) {
            $this->error(sprintf('%d projection(s) disagree with the rows (%d invoices, %d receipts).', $drift, $invoiceDrift, $receiptDrift));
            $this->line('Run without --check to rebuild them; the rows are the truth and the columns are the cache.');

            return self::FAILURE;
        }

        $this->warn(sprintf('Repaired %d projection(s): %d invoices, %d receipts.', $drift, $invoiceDrift, $receiptDrift));

        return self::SUCCESS;
    }
}
