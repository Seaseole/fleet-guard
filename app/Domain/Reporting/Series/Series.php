<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Series;

use JsonSerializable;

/**
 * One chart and its own table alternative, built in the same loop.
 *
 * The screen shows a picture; a screen reader, a printed page and a seat that simply prefers numbers get the
 * table (docs/UX-UI-PLAN.md "every chart ships with a table alternative — a chart alone is not screen-reader
 * readable"). Carrying both in one object is what stops them drifting: the same door, the same window, the same
 * formatted strings, so the figure under the bar is the figure in the row. `ReportData` already makes this promise
 * for reports — a chart is the same obligation, drawn.
 *
 * Money and litres leave as the column's own stored unit (integer minor units, hundredths of a litre) with the
 * axis carrying the scale, so no float ever enters the money path and the JSON holds no decimal money literal
 * (docs/DESIGN.md D6). The display strings come from {@see Axis} and the app's two formatting doors, never
 * from the browser.
 */
final readonly class Series implements JsonSerializable
{
    /**
     * @param  list<string>  $labels
     * @param  list<array{label: string, data: list<int>, formatted: list<string>, colour: string|list<string>, pattern?: string|list<string>, type?: string, axis?: string}>  $datasets
     * @param  list<array{id: string, position: string, scale: int, grouping: string, decimal: string, decimals: int, prefix?: string, suffix?: string, label?: string, drawGrid?: bool}>  $axes
     * @param  array{caption: string, columns: list<array{label: string, numeric: bool}>, rows: list<list<string>>, totals: list<string>}  $table
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $ariaLabel,
        public string $windowLabel,
        public string $windowKey,
        public array $labels,
        public array $datasets,
        public array $axes,
        public array $table,
        public ?string $note = null,
        public string $indexAxis = 'x',
    ) {}

    /**
     * Exactly what the canvas needs — and nothing else. The title, the table and the window are already in the
     * HTML the server rendered, so shipping them again would only enlarge the payload and invite a second,
     * divergent copy of the same words.
     *
     * @return array{type: string, indexAxis: string, labels: list<string>, datasets: list<array{label: string, data: list<int>, formatted: list<string>, colour: string|list<string>, pattern?: string|list<string>, type?: string, axis?: string}>, axes: list<array{id: string, position: string, scale: int, grouping: string, decimal: string, decimals: int, prefix?: string, suffix?: string, label?: string, drawGrid?: bool}>}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'indexAxis' => $this->indexAxis,
            'labels' => $this->labels,
            'datasets' => $this->datasets,
            'axes' => $this->axes,
        ];
    }

    /**
     * A chart with no rows is still a chart the seat is entitled to see, so the doors say what they found rather
     * than returning nothing and letting the template guess.
     */
    public function isEmpty(): bool
    {
        return $this->table['rows'] === [];
    }
}
