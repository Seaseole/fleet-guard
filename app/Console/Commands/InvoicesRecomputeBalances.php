<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * §10.4's tie-out: rebuild every projected balance from the rows that decided it.
 *
 * `invoices.balance_amount` and `payments.unapplied_amount` are **projections**, not truth. The truth is
 * the allocation rows (and, for an invoice, the credit notes applied against it). The Actions maintain the
 * projection in the same transaction as the rows, so the two should never disagree — but "should never" is
 * what every stored total in every ledger system says, and the day one does disagree the ageing report,
 * the AR total and the statement all print the same wrong number with no way to see it.
 *
 * `--check` verifies and exits 1 on drift without writing. Without it the command repairs the projections,
 * which is the only safe automated change: it moves a cached figure toward the rows, never the reverse. The
 * ledger is untouched — this command proves the module's own sums, it does not post anything (§6.9's
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
            $allocations = DB::table('payment_allocations')
                ->where('company_id', $companyId)
                ->where('reversed', false)
                ->groupBy('invoice_id')
                ->selectRaw('invoice_id, sum(amount) as allocated')
                ->pluck('allocated', 'invoice_id');

            $credits = DB::table('credit_notes')
                ->where('company_id', $companyId)
                ->whereIn('status', ['issued', 'applied', 'settled'])
                ->groupBy('invoice_id')
                ->selectRaw('invoice_id, sum(amount + tax_amount) as credited')
                ->pluck('credited', 'invoice_id');

            $invoices = DB::table('invoices')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'draft')
                ->get(['id', 'number', 'amount', 'tax_amount', 'balance_amount']);

            foreach ($invoices as $invoice) {
                $checkedInvoices++;

                $expected = (int) $invoice->amount + (int) $invoice->tax_amount
                    - (int) ($allocations[$invoice->id] ?? 0)
                    - (int) ($credits[$invoice->id] ?? 0);

                if ((int) $invoice->balance_amount === $expected) {
                    continue;
                }

                $invoiceDrift++;

                $this->line(sprintf(
                    ' %s  invoice %s — stored %d, rows say %d',
                    $checkOnly ? 'DRIFT' : 'FIXED',
                    (string) $invoice->number,
                    (int) $invoice->balance_amount,
                    $expected,
                ));

                if (! $checkOnly) {
                    DB::table('invoices')->where('id', $invoice->id)->update([
                        'balance_amount' => $expected,
                        'balance_base' => $expected,
                        'updated_at' => now(),
                    ]);
                }
            }

            $receipts = DB::table('payments')
                ->where('company_id', $companyId)
                ->get(['id', 'number', 'amount', 'unapplied_amount']);

            foreach ($receipts as $payment) {
                $checkedReceipts++;

                $applied = (int) DB::table('payment_allocations')
                    ->where('company_id', $companyId)
                    ->where('payment_id', $payment->id)
                    ->where('reversed', false)
                    ->sum('amount');

                $expected = (int) $payment->amount - $applied;

                if ((int) $payment->unapplied_amount === $expected) {
                    continue;
                }

                $receiptDrift++;

                $this->line(sprintf(
                    ' %s  receipt %s — stored %d unapplied, rows say %d',
                    $checkOnly ? 'DRIFT' : 'FIXED',
                    (string) $payment->number,
                    (int) $payment->unapplied_amount,
                    $expected,
                ));

                if (! $checkOnly) {
                    DB::table('payments')->where('id', $payment->id)->update([
                        'unapplied_amount' => $expected,
                        'unapplied_base' => $expected,
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
