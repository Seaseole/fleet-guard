<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use App\Domain\Invoicing\AgeingBucket;
use App\Domain\Invoicing\Queries\AgeingReport;
use App\Domain\Kernel\Formatter;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Reporting\Support\Cells;

/**
 * Receivables by ageing bucket — the chart the Phase 4d door already answers with no new query at all.
 *
 * {@see AgeingReport::totals()} returns the five buckets summed across every open customer, which is
 * precisely a bar chart. Reading it rather than re-deriving it is the point: a dashboard that computed its own
 * buckets would be a second definition of "over 90 days", and the rule is that the dashboard and the report
 * must never hold two answers to one question.
 *
 * This is a **point-in-time** picture, not a trend: the buckets are derived from `due_on` against the window's
 * closing day and stored nowhere (a stored bucket is wrong every midnight). Moving the window's end
 * backward therefore ages the book as it stood then, which is why the card says so in its own caption.
 */
final class AgeingBuckets
{
    /**
     * The five dated buckets, soonest first. `disputed` is excluded deliberately: it is a flag a person raises,
     * not a position on a time ramp, and plotting it as if it were would imply a day count nobody asserted.
     *
     * @var list<AgeingBucket>
     */
    private const BUCKETS = [
        AgeingBucket::Current,
        AgeingBucket::Days1to30,
        AgeingBucket::Days31to60,
        AgeingBucket::Days61to90,
        AgeingBucket::Over90,
    ];

    /**
     * The divergent ramp (docs/DESIGN-SYSTEM.md `danger → neutral → success`) read as risk, not as decoration: money not yet due
     * is not bad news, and the oldest bucket is the one a credit controller chases first. Two slices share the
     * danger hue and differ by texture, because docs/DESIGN-SYSTEM.md the design forbids colour being the only difference.
     *
     * @var array<string, array{colour: string, pattern: string}>
     */
    private const RAMP = [
        'current' => ['colour' => '--color-fg-ink-muted', 'pattern' => 'solid'],
        'd1_30' => ['colour' => '--color-accent', 'pattern' => 'solid'],
        'd31_60' => ['colour' => '--color-warning', 'pattern' => 'solid'],
        'd61_90' => ['colour' => '--color-danger', 'pattern' => 'solid'],
        'd90_plus' => ['colour' => '--color-danger', 'pattern' => 'stripe'],
    ];

    public function __construct(private readonly AgeingReport $ageing) {}

    /**
     * The buckets this chart plots, in ramp order. Public because "is `disputed` on the axis?" is a question
     * worth asserting, and a private list can only be checked by copying it into the test.
     *
     * @return list<AgeingBucket>
     */
    public static function buckets(): array
    {
        return self::BUCKETS;
    }

    public function __invoke(ChartWindow $window): Series
    {
        $asAt = $window->asAt();
        $currency = CurrentCompany::model()->base_currency_code;
        $totals = AgeingReport::totals(($this->ageing)($asAt->toDateTimeImmutable()));

        $data = [];
        $formatted = [];
        $colours = [];
        $patterns = [];
        $rows = [];

        foreach (self::BUCKETS as $bucket) {
            $minor = $totals[$bucket->value];
            $share = $totals['total'] > 0 ? $minor / $totals['total'] * 100 : 0.0;

            $data[] = $minor;
            $formatted[] = Cells::minor($minor, $currency);
            $colours[] = self::RAMP[$bucket->value]['colour'];
            $patterns[] = self::RAMP[$bucket->value]['pattern'];

            // The same three numbers the bar is drawn from, in the row a screen reader reads out.
            $rows[] = [
                $bucket->label(),
                Cells::minor($minor, $currency),
                Formatter::number($share, 1).'%',
            ];
        }

        return new Series(
            id: 'receivables-ageing',
            type: 'bar',
            indexAxis: 'y',
            title: __('Receivables by ageing bucket'),
            ariaLabel: __('Horizontal bar chart of money owed split into five ageing buckets, :total in total.', [
                'total' => Cells::minor($totals['total'], $currency),
            ]),
            windowLabel: __('Aged as at :date', ['date' => $asAt->format('d M Y')]),
            windowKey: $window->key(),
            labels: array_map(static fn (AgeingBucket $bucket): string => $bucket->label(), self::BUCKETS),
            datasets: [[
                'label' => __('Owed'),
                'data' => $data,
                'formatted' => $formatted,
                'colour' => $colours,
                'pattern' => $patterns,
                'axis' => 'x',
            ]],
            axes: [Axis::money('x', $currency, position: 'bottom', drawGrid: false)],
            table: [
                'caption' => __('Receivables by ageing bucket as at :date', ['date' => $asAt->format('d M Y')]),
                'columns' => [
                    ['label' => __('Bucket'), 'numeric' => false],
                    ['label' => __('Owed'), 'numeric' => true],
                    ['label' => __('Share'), 'numeric' => true],
                ],
                'rows' => $rows,
                'totals' => [
                    __('Total'),
                    Cells::minor($totals['total'], $currency),
                    $totals['total'] > 0 ? '100%' : '0%',
                ],
            ],
            note: __(':n open invoices owed by :c customers. :overdue of it is past its due date.', [
                'n' => $totals['invoices'],
                'c' => $totals['customers'],
                'overdue' => Cells::minor($totals['overdue'], $currency),
            ]),
        );
    }
}
