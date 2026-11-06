<?php

declare(strict_types=1);

namespace Database\Seeders\Production;

use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Security\Actions\CreateOwner;
use Illuminate\Database\Seeder;

use function Laravel\Prompts\password;

/**
 * Creates the Owner account. The only production seeder that writes a `users` row, and it
 * writes one.
 *
 * It will not invent an address: `owner@example.test` in a seeder is how a demo mailbox ends up on a
 * real invoice, so the email must come from `FLEET_OWNER_EMAIL` and the password from
 * `FLEET_OWNER_PASSWORD` (unattended) or an interactive prompt. The password is read from the process
 * environment rather than a config key because `config:cache` would write it to disk.
 *
 * The work itself is in {@see CreateOwner} — the seeder and `fleet:setup-owner` must not be two
 * implementations of "who is allowed to become an Owner".
 */
final class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('fleet.identity.owner_email');

        if ($email === '') {
            throw new \RuntimeException(
                'Set FLEET_OWNER_EMAIL before seeding an Owner — this seeder will not create a placeholder account.'
            );
        }

        $company = Company::query()->orderBy('id')->first();

        if ($company === null) {
            throw new \RuntimeException('CompanySeeder must run before OwnerSeeder (owner-first).');
        }

        $owner = (new CreateOwner)(
            email: $email,
            name: (string) config('fleet.identity.owner_name'),
            password: $this->password(),
            companyId: $company->id,
        );

        $this->command?->info("Owner account ready: {$owner->email} (company {$company->id})");
    }

    /**
     * Read from the process environment rather than `config('fleet.…')`, and deliberately not via
     * the `env()` helper Larastan asks for: a value that goes through config becomes writable to
     * `bootstrap/cache/config.php` by `config:cache`, which is exactly what the design forbids for a
     * secret. Prompting belongs to `fleet:setup-owner`, which owns the interactive half.
     */
    private function password(): string
    {
        $fromEnvironment = getenv('FLEET_OWNER_PASSWORD');

        if (! is_string($fromEnvironment) || $fromEnvironment === '') {
            throw new \RuntimeException(
                'OwnerSeeder runs unattended, so it needs FLEET_OWNER_PASSWORD set. To type the password instead, run php artisan fleet:setup-owner.'
            );
        }

        return $fromEnvironment;
    }
}
