<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\Models\FuelStation;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Registers a fuel station — master data, one dialog behind `fuel.record` (§3.2's workshop screens).
 *
 * No supplier is required: a cutover sheet will name pumps before anyone has raised the supplier
 * rows that bill them, and refusing until the accountant has finished Phase 5's supplier list would
 * stop the fuel register from starting. The unique name is the real guard — two "Engen Gaborone"
 * rows split a station's purchases across two ledgers nobody rejoins.
 */
final class CreateFuelStation
{
    public function __invoke(string $name, ?string $town = null, string $countryCode = 'BW', ?int $supplierId = null): FuelStation
    {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Registering a fuel station is workshop recording (§9.3).');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('A station needs a name of 120 characters or fewer.');
        }

        $countryCode = strtoupper(trim($countryCode));

        if (strlen($countryCode) !== 2) {
            throw new InvalidArgumentException('A station needs a two-letter country code.');
        }

        if (FuelStation::query()->where('name', $name)->exists()) {
            throw new InvalidArgumentException(sprintf('A station called "%s" already exists. Use it, or rename this one.', $name));
        }

        return FuelStation::create([
            'name' => $name,
            'town' => $town === null || trim($town) === '' ? null : trim($town),
            'country_code' => $countryCode,
            'supplier_id' => $supplierId,
            'active' => true,
        ]);
    }
}
