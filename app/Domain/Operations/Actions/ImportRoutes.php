<?php

declare(strict_types=1);

namespace App\Domain\Operations\Actions;

use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\CutoverDatasetHandler;
use App\Domain\Kernel\Cutover\Data\CutoverValidation;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;
use App\Domain\Kernel\Cutover\Support\CutoverCells;
use App\Domain\Kernel\Cutover\Support\CutoverRows;
use App\Domain\Kernel\Cutover\Support\ImportValues;
use App\Domain\Operations\Data\RouteDto;
use App\Domain\Operations\Models\Route;
use InvalidArgumentException;

/**
 * §15.2 Phase 1 — the corridor register.
 *
 * Matched by code. The distance stays the string the file wrote until {@see RouteDto} has read it,
 * because the DTO is where the "positive, under a million, kilometres not miles" rule lives; parsing
 * it into a float here and back would be a second opinion about the same cell.
 */
final class ImportRoutes implements CutoverDatasetHandler
{
    public function __construct(
        private readonly CreateRoute $create,
        private readonly UpdateRoute $update,
    ) {}

    public function validate(string $contents, string $companyCurrency): CutoverValidation
    {
        $seenCodes = [];

        return CutoverRows::read(
            CutoverDataset::Routes,
            $contents,
            function (CutoverCells $cells) use (&$seenCodes) {
                $errors = [];

                [$code, $error] = ImportValues::code($cells->get('code'), 24, 'Route code', true);
                ImportValues::push($errors, $error);

                [$origin, $error] = ImportValues::text($cells->get('origin'), 80, 'Origin', true);
                ImportValues::push($errors, $error);

                [$destination, $error] = ImportValues::text($cells->get('destination'), 80, 'Destination', true);
                ImportValues::push($errors, $error);

                [$originCountry, $error] = ImportValues::code($cells->get('origin_country_code'), 2, 'Origin country');
                ImportValues::push($errors, $error);

                [$destinationCountry, $error] = ImportValues::code($cells->get('destination_country_code'), 2, 'Destination country');
                ImportValues::push($errors, $error);

                [, $error] = ImportValues::decimal($cells->get('distance_km'), 'Distance (km)');
                ImportValues::push($errors, $error);

                $distanceKm = trim($cells->get('distance_km'));

                [$notes, $error] = ImportValues::text($cells->get('notes'), 255, 'Notes');
                ImportValues::push($errors, $error);

                [$active, $error] = ImportValues::flag($cells->get('active'), 'Active');
                ImportValues::push($errors, $error);

                if ($code !== null) {
                    if (isset($seenCodes[$code])) {
                        $errors[] = sprintf('Line %d already carries the route code "%s".', $seenCodes[$code], $code);
                    } else {
                        $seenCodes[$code] = $cells->line;
                    }
                }

                $values = [
                    'code' => $code,
                    'origin' => $origin,
                    'destination' => $destination,
                    'origin_country_code' => $originCountry,
                    'destination_country_code' => $destinationCountry,
                    'distance_km' => $distanceKm,
                    'notes' => $notes,
                    'active' => $active,
                ];

                if ($errors === []) {
                    try {
                        RouteDto::fromArray($values);
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }

                return $cells->result($values, $errors);
            },
        );
    }

    public function apply(CutoverBatch $batch, int $userId): int
    {
        $applied = 0;

        foreach ($batch->rows()->where('status', 'valid')->get() as $row) {
            $dto = RouteDto::fromArray($row->payload ?? []);
            $existing = Route::query()->where('code', $dto->code)->first();

            if ($existing === null) {
                ($this->create)($dto);
            } else {
                ($this->update)($existing, $dto);
            }

            $applied++;
        }

        return $applied;
    }
}
