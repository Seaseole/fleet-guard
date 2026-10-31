<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Invoicing\AgeingBucket;
use App\Domain\Invoicing\Data\AgeingRow;
use App\Domain\Invoicing\InvoiceLifecycle;
use App\Domain\Invoicing\Models\Customer;
use App\Domain\Invoicing\Models\Invoice;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * §10.4's third axis, asked of every open invoice at once: the receivables ageing report.
 *
 * The bucket is computed from `due_on` against the company's today — never read from a column, because a
 * stored bucket is wrong every midnight (§10.4). Only `issued` invoices with a balance are in the report:
 * a void document is not owed, and a settled one is history. Credit notes need no special case because
 * they have already moved the balance they corrected.
 *
 * Rows are ordered by money owed descending. That is not cosmetic: a report sorted by customer name makes
 * the largest exposure a page turn away, and the first question a credit controller asks is "who owes us
 * the most, and for how long".
 */
final class AgeingReport
{
    /**
     * @return list<AgeingRow>
     */
    public function __invoke(?DateTimeInterface $today = null): array
    {
        $asOf = $today === null ? Carbon::today() : Carbon::instance($today)->startOfDay();

        $invoices = Invoice::query()
            ->where('status', InvoiceLifecycle::Issued->value)
            ->where('balance_amount', '>', 0)
            ->orderBy('due_on')
            ->get(['id', 'customer_id', 'number', 'due_on', 'balance_amount']);

        if ($invoices->isEmpty()) {
            return [];
        }

        $customers = Customer::query()
            ->whereIn('id', $invoices->pluck('customer_id')->unique()->all())
            ->get()
            ->keyBy('id');

        /** @var array<int, array{name: string, code: ?string, buckets: array<string, int>, count: int, oldest: ?DateTimeImmutable}> $byCustomer */
        $byCustomer = [];

        foreach ($invoices as $invoice) {
            $customerId = (int) $invoice->customer_id;
            $customer = $customers->get($customerId);

            $byCustomer[$customerId] ??= [
                'name' => $customer?->name ?? 'Customer #'.$customerId,
                'code' => $customer?->code,
                'buckets' => array_fill_keys(array_map(static fn (AgeingBucket $b): string => $b->value, AgeingBucket::cases()), 0),
                'count' => 0,
                'oldest' => null,
            ];

            $bucket = AgeingBucket::for($invoice->due_on, $asOf);

            $byCustomer[$customerId]['buckets'][$bucket->value] += (int) $invoice->balance_amount;
            $byCustomer[$customerId]['count']++;
            $byCustomer[$customerId]['oldest'] ??= $invoice->due_on->toDateTimeImmutable();
        }

        $rows = [];

        foreach ($byCustomer as $customerId => $row) {
            $rows[] = new AgeingRow(
                customerId: $customerId,
                customerName: $row['name'],
                customerCode: $row['code'],
                currentMinor: $row['buckets'][AgeingBucket::Current->value],
                days1to30Minor: $row['buckets'][AgeingBucket::Days1to30->value],
                days31to60Minor: $row['buckets'][AgeingBucket::Days31to60->value],
                days61to90Minor: $row['buckets'][AgeingBucket::Days61to90->value],
                over90Minor: $row['buckets'][AgeingBucket::Over90->value],
                invoiceCount: $row['count'],
                oldestDueOn: $row['oldest'],
            );
        }

        usort($rows, static fn (AgeingRow $a, AgeingRow $b): int => $b->totalMinor() <=> $a->totalMinor());

        return $rows;
    }

    /**
     * The five column totals, for the report's footer and the dashboard's AR card.
     *
     * @param  list<AgeingRow>  $rows
     * @return array{current: int, d1_30: int, d31_60: int, d61_90: int, d90_plus: int, total: int, overdue: int, invoices: int, customers: int}
     */
    public static function totals(array $rows): array
    {
        $totals = [
            'current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0,
            'total' => 0, 'overdue' => 0, 'invoices' => 0, 'customers' => count($rows),
        ];

        foreach ($rows as $row) {
            $totals['current'] += $row->currentMinor;
            $totals['d1_30'] += $row->days1to30Minor;
            $totals['d31_60'] += $row->days31to60Minor;
            $totals['d61_90'] += $row->days61to90Minor;
            $totals['d90_plus'] += $row->over90Minor;
            $totals['total'] += $row->totalMinor();
            $totals['overdue'] += $row->overdueMinor();
            $totals['invoices'] += $row->invoiceCount;
        }

        return $totals;
    }
}
