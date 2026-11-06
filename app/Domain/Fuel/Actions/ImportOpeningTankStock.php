<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\Models\BulkTank;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverRowResult;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Kernel\Cutover\Support\OpeningCoverage;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Phase 0.5 — the opening fuel readings file.
 *
 * One row is one yard tank: the litres on the dip gauge and what the accountant says they are worth.
 * The row becomes an `opening` movement plus its `Dr 1180 / Cr 3010` entry, through
 * {@see OpenBulkTankStock} — never a typed value into the tank row, because a book that was moved by an
 * assignment has no movement behind it and the next dip would measure a variance against nothing.
 *
 * Litres are parsed as text and scaled in integers (`21 500.75 L` → 2 150 075 hundredths), which is
 * The rule for a quantity: a float that has been through binary is a rounding error looking for a
 * tank to hide in.
 *
 * The file cannot be applied after a trial balance that already carries `1180` — one account, one
 * opener, in both directions.
 */
final class ImportOpeningTankStock implements CutoverDatasetHandler
{
    public function __construct(
        private readonly OpenBulkTankStock $open,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $tanks = [];
        $capacityByName = [];

        foreach (BulkTank::query()->get(['id', 'name', 'capacity_litres']) as $tank) {
            $tanks[mb_strtolower(trim((string) $tank->name))] = $tank->id;
            $capacityByName[mb_strtolower(trim((string) $tank->name))] = (int) $tank->capacity_litres;
        }

        $seen = [];

        $validation = CutoverRows::read(
            CutoverDataset::OpeningTankStock,
            $contents,
            function (CutoverCells $cells) use ($tanks, $capacityByName, $companyCurrency, &$seen): CutoverRowResult {
                $errors = [];

                $name = trim($cells->get('tank_name'));

                if ($name === '') {
                    $errors[] = 'Tank name is required — the file must say which tank was dipped.';
                } elseif (! isset($tanks[mb_strtolower($name)])) {
                    $errors[] = sprintf(
                        'Tank "%s" is not on the register. Open it on the fuel screens first: an opening fill belongs to a tank '
                        .'with a capacity, and this one has no row to check it against.',
                        $name,
                    );
                }

                $litresRaw = trim($cells->get('litres'));
                $litresX100 = null;

                if ($litresRaw === '') {
                    $errors[] = 'Litres is required — that is the reading the file exists to carry.';
                } elseif (preg_match('/^\d{1,9}(\.\d{1,2})?$/', $litresRaw) !== 1) {
                    $errors[] = 'Litres must be a positive number with at most 2 decimals (the tank is read to the half litre).';
                } else {
                    [$whole, $cents] = $this->split($litresRaw);
                    $litresX100 = $whole * 100 + $cents;

                    if ($litresX100 <= 0) {
                        $errors[] = 'An empty tank has nothing to open; leave it out of the file.';
                    }
                }

                [$valueMinor, $error] = ImportValues::money($cells->get('value'), $companyCurrency, 'Value');
                ImportValues::push($errors, $error);

                if ($valueMinor === null && $errors === []) {
                    $errors[] = 'Value is required — litres with no value would price every issue at zero until the next delivery.';
                }

                if ($valueMinor !== null && $valueMinor <= 0) {
                    $errors[] = 'Value must be greater than zero for the same reason.';
                }

                [$readOn, $error] = ImportValues::date($cells->get('read_on'), 'Counted on');
                ImportValues::push($errors, $error);

                [$description, $error] = ImportValues::text($cells->get('description'), 160, 'Note');
                ImportValues::push($errors, $error);

                if ($litresX100 !== null && $name !== '' && isset($capacityByName[mb_strtolower($name)])) {
                    $capacity = $capacityByName[mb_strtolower($name)];

                    if ($litresX100 > $capacity * 100) {
                        $errors[] = sprintf(
                            '%s litres will not fit in %s, which holds %s litres — fix the count on the dip sheet rather than open '
                            .'a book the yard cannot read.',
                            number_format($litresX100 / 100, 2, '.', ''),
                            $name,
                            number_format($capacity, 0, '.', ''),
                        );
                    }
                }

                if ($name !== '') {
                    $key = mb_strtolower($name);

                    if (isset($seen[$key])) {
                        $errors[] = sprintf('Line %d already opens that tank.', $seen[$key]);
                    } else {
                        $seen[$key] = $cells->line;
                    }
                }

                $values = array_filter([
                    'tank_name' => $name === '' ? null : $name,
                    'litres_x100' => $litresX100,
                    'value_minor' => $valueMinor,
                    'read_on' => $readOn,
                    'description' => $description,
                ], static fn (mixed $value): bool => $value !== null);

                return $cells->result($values, $errors);
            },
        );

        $blocker = OpeningCoverage::blockedByTrialBalance(CutoverDataset::OpeningTankStock);

        return $blocker === null
            ? $validation
            : new CutoverValidation($validation->rows, [...$validation->batchErrors, $blocker]);
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $payload = $row->payload ?? [];
            $name = (string) ($payload['tank_name'] ?? '');

            $tank = BulkTank::query()->where('name', $name)->first();

            if ($tank === null) {
                throw new InvalidArgumentException(sprintf(
                    'Row %d names tank "%s", which is no longer on the register.',
                    $row->line_number,
                    $name,
                ));
            }

            $this->open->execute(
                tank: $tank,
                litresX100: (int) ($payload['litres_x100'] ?? 0),
                valueBaseMinor: (int) ($payload['value_minor'] ?? 0),
                on: Carbon::parse((string) ($payload['read_on'] ?? $batch->created_at?->toDateString())),
                note: isset($payload['description']) ? (string) $payload['description'] : null,
                actorId: $userId,
            );

            $applied++;
        }

        return $applied;
    }

    /**
     * The integer half of a litres figure: whole litres and hundredths, never a float.
     *
     * @return array{0: int, 1: int}
     */
    private function split(string $raw): array
    {
        $dot = strpos($raw, '.');

        if ($dot === false) {
            return [(int) $raw, 0];
        }

        $cents = str_pad(substr($raw, $dot + 1), 2, '0');

        return [(int) substr($raw, 0, $dot), (int) $cents];
    }
}
