<?php

declare(strict_types=1);

namespace App\Domain\People\Queries;

use App\Domain\People\Data\DriverOption;
use App\Domain\People\Models\Driver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The People module's read door for other modules (D5).
 *
 * Fleet's assignment form needs the list of drivers and their paper dates; it may not import the
 * model. This class is the whole contract: ids, names, status and the two dates, in the tenant
 * scope the caller is already in.
 */
final class DriverDirectory
{
    /** @return list<DriverOption> */
    public function options(): array
    {
        /** @var Collection<int, Driver> $drivers */
        $drivers = Driver::query()
            ->with('employee')
            ->get()
            ->sortBy(static fn (Driver $driver): string => sprintf(
                '%s %06d',
                $driver->employee->name ?? '',
                $driver->id,
            ))
            ->values();

        return array_values($drivers
            ->map(static fn (Driver $driver): DriverOption => self::toOption($driver))
            ->all());
    }

    public function find(int $driverId): ?DriverOption
    {
        $driver = Driver::query()->with('employee')->find($driverId);

        return $driver === null ? null : self::toOption($driver);
    }

    /**
     * Several drivers in one read, keyed by id.
     *
     * The board asks this once per trip row; without the bulk door that is one query per row for
     * a screen that already knows every id it needs. Ids that do not exist are simply absent —
     * the caller reports "not in this company" from the missing key, exactly as `find()` does.
     *
     * @param  iterable<int>  $driverIds
     * @return array<int, DriverOption>
     */
    public function findMany(iterable $driverIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            is_array($driverIds) ? $driverIds : iterator_to_array($driverIds, false),
        )));

        if ($ids === []) {
            return [];
        }

        $options = [];

        /** @var Collection<int, Driver> $drivers */
        $drivers = Driver::query()->with('employee')->whereKey($ids)->get();

        foreach ($drivers as $driver) {
            $options[$driver->id] = self::toOption($driver);
        }

        return $options;
    }

    private static function toOption(Driver $driver): DriverOption
    {
        return new DriverOption(
            id: $driver->id,
            name: $driver->employee->name ?? 'Driver #'.$driver->id,
            employeeNo: $driver->employee->employee_no ?? '',
            status: $driver->status,
            licenceExpiresOn: $driver->licence_expires_on === null
                ? null
                : CarbonImmutable::instance($driver->licence_expires_on)->startOfDay(),
            permitExpiresOn: $driver->permit_expires_on === null
                ? null
                : CarbonImmutable::instance($driver->permit_expires_on)->startOfDay(),
            kioskEnabled: $driver->kiosk_enabled,
        );
    }
}
