<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Invoicing\Data\CustomerStatement;
use App\Domain\Invoicing\Data\StatementLine;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * §10.6's customer statement: the invoices issued in a window, the receipts banked in it, and everything
 * still outstanding at the end.
 *
 * Three figures are deliberately kept apart, because conflating them is how statements start arguments:
 *
 * - **invoiced** is what this window's documents asked for (gross, including the void ones' gross of zero
 *   because a void is not an ask);
 * - **received** is the money that arrived in the window, whether or not it was applied to an invoice;
 * - **outstanding** is every open invoice's balance, regardless of when it was issued. A statement that
 *   windowed the balance would invite a customer to believe an old debt had expired.
 *
 * Credit notes have already moved the balances they corrected, so they need no separate line — the
 * `settled` figure on each invoice row is gross minus balance and therefore includes them. The PDF prints
 * that number, so a customer can tie the statement to the credits they hold.
 */
final class Statements
{
    public function forCustomer(int $customerId, DateTimeInterface $from, DateTimeInterface $to): CustomerStatement
    {
        $windowStart = Carbon::instance($from)->startOfDay();
        $windowEnd = Carbon::instance($to)->endOfDay();

        if ($windowEnd->lessThan($windowStart)) {
            throw new InvalidArgumentException('A statement ends after it starts; the window was given backwards.');
        }

        $customer = Customer::query()->find($customerId)
            ?? throw new InvalidArgumentException('That customer is not in this company\'s register, so there is nobody to address a statement to.');

        $invoices = Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', InvoiceLifecycle::Draft->value)
            ->whereBetween('issue_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        /** @var list<StatementLine> $lines */
        $lines = [];
        $invoiced = 0;

        foreach ($invoices as $invoice) {
            $gross = $invoice->amount + $invoice->tax_amount;

            if ($invoice->status !== InvoiceLifecycle::Void) {
                $invoiced += $gross;
            }

            $lines[] = new StatementLine(
                invoiceId: (string) $invoice->id,
                number: $invoice->reference(),
                issuedOn: $invoice->issue_date->toDateTimeImmutable(),
                dueOn: $invoice->due_on->toDateTimeImmutable(),
                grossMinor: $gross,
                balanceMinor: (int) $invoice->balance_amount,
                status: $invoice->status,
                settlement: $invoice->settlementState(),
                ageing: $invoice->ageingState(),
            );
        }

        $payments = Payment::query()
            ->where('customer_id', $customerId)
            ->whereBetween('received_on', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->orderBy('received_on')
            ->orderBy('number')
            ->get();

        /** @var list<array{number: string, receivedOn: \DateTimeImmutable, method: string, amountMinor: int, unappliedMinor: int}> $receipts */
        $receipts = [];
        $received = 0;

        foreach ($payments as $payment) {
            $received += (int) $payment->amount;

            $receipts[] = [
                'number' => (string) $payment->number,
                'receivedOn' => $payment->received_on->toDateTimeImmutable(),
                'method' => $payment->method->label(),
                'amountMinor' => (int) $payment->amount,
                'unappliedMinor' => (int) $payment->unapplied_amount,
            ];
        }

        // The outstanding and on-account figures look past the window on purpose: they are the balance the
        // customer has to settle today, not the balance this month happened to create.
        $outstanding = (int) Invoice::query()
            ->where('customer_id', $customerId)
            ->where('status', InvoiceLifecycle::Issued->value)
            ->where('balance_amount', '>', 0)
            ->sum('balance_amount');

        $unapplied = (int) Payment::query()
            ->where('customer_id', $customerId)
            ->where('status', 'recorded')
            ->sum('unapplied_amount');

        return new CustomerStatement(
            customerId: (int) $customer->id,
            customerName: $customer->name,
            customerCode: $customer->code,
            customerVatNumber: $customer->vat_number,
            from: $windowStart->toDateTimeImmutable(),
            to: $windowEnd->toDateTimeImmutable(),
            lines: $lines,
            receipts: $receipts,
            invoicedMinor: $invoiced,
            receivedMinor: $received,
            outstandingMinor: $outstanding,
            unappliedMinor: $unapplied,
            currencyCode: CurrentCompany::model()->base_currency_code,
        );
    }
}
