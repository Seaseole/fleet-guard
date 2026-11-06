<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\Models\BulkTank;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Opens a bulk tank — the yard tank deliveries go into (BULK_TANK, phase 3).
 *
 * A tank starts empty and worth nothing; its stock only moves through {@see BulkTank::recordMovement()},
 * so there is no way to open one holding fuel nobody can account for. Capacity is a master-data
 * figure: a delivery that would take the tank past it is flagged rather than refused (R7), because
 * the figure is what somebody measured once, and the litres are what the pump delivered.
 */
final class CreateBulkTank
{
    public function __invoke(string $name, int $capacityLitres): BulkTank
    {
        if (! Gate::allows('fuel.record')) {
            throw new InvalidArgumentException('Opening a bulk tank is workshop recording.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Give the tank a name of up to 80 characters.');
        }

        if ($capacityLitres <= 0 || $capacityLitres > 65535) {
            throw new InvalidArgumentException('A bulk tank needs a capacity between 1 and 65,535 litres.');
        }

        if (BulkTank::query()->where('name', $name)->exists()) {
            throw new InvalidArgumentException(sprintf('This company already has a bulk tank called %s.', $name));
        }

        return BulkTank::create([
            'name' => $name,
            'capacity_litres' => $capacityLitres,
            'stock_litres_x100' => 0,
            'stock_value_base' => 0,
            'average_cost_per_litre_minor' => 0,
            'active' => true,
        ]);
    }
}
