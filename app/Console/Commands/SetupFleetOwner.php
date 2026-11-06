<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Kernel\Identity\PermissionCatalogue;
use App\Domain\Kernel\Identity\RoleKey;
use App\Domain\Kernel\Identity\RoleProvisioner;
use App\Domain\Kernel\Tenancy\Company;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Security\Actions\CreateOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First-run setup (owner-first): the company row, its role set, the Owner account, and the
 * document sequence rows — in that order, because each step depends on the one before it.
 *
 * One command rather than a documented checklist, because a checklist is how a deploy ends up with a
 * company and no roles, and every screen then renders empty (the fail-closed scope in the design does exactly
 * what it should and shows nothing). The steps are already ordered in code, so the command is the
 * honest interface.
 */
final class SetupFleetOwner extends Command
{
    protected $signature = 'fleet:setup-owner
        {--company=1 : Company id to set up}
        {--email= : Owner email (falls back to FLEET_OWNER_EMAIL)}
        {--name= : Owner name (falls back to FLEET_OWNER_NAME)}
        {--password= : Owner password (falls back to FLEET_OWNER_PASSWORD, then a prompt)}';

    protected $description = 'Create the company, its role set and the Owner account (Phase 0 first run)';

    public function handle(CreateOwner $createOwner): int
    {
        $companyId = (int) ($this->option('company') ?: 1);
        $email = $this->ownerEmail();
        $name = $this->ownerName();
        $secret = $this->ownerPassword();
        $progressBar = $this->input->isInteractive() ? $this->output->createProgressBar(4) : null;

        $progressBar?->start();

        // The company row itself is seeded, not typed here: its fiscal year, currency and numbering
        // settings are the Production\CompanySeeder's shape, and two definitions of "what a company
        // row contains" is how the single source of truth becomes two.
        if (Company::query()->find($companyId) === null) {
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\Production\\CompanySeeder', '--force' => true]);
            $this->components->info('Company row created.');
        }

        $progressBar?->advance();
        CurrentCompany::force($companyId);

        try {
            $summary = (new RoleProvisioner)->provision($companyId);
            $progressBar?->advance();
            $this->components->info(sprintf('%d permissions, %d roles synchronised.', $summary['permissions'], $summary['roles']));

            $owner = $createOwner($email, $name, $secret, $companyId);
            $progressBar?->advance();
            $this->components->info("Owner ready: {$owner->email} as {$owner->roles->pluck('name')->join(', ')}");

            // Sequence rows are created here rather than left to the schedule, so the first invoice
            // on a new box cannot fail in the allocator (allocation never creates a row).
            Artisan::call('numbering:ensure', ['--company' => $companyId]);
            $progressBar?->finish();
            $this->newLine(2);
            $this->components->info('Document sequences prepared.');

            $this->report($companyId, $secret);
        } finally {
            // The command is one process, but leaving a pinned tenant in a static makes any code run
            // later in this same process — a chained command, a test helper — inherit it.
            CurrentCompany::forget();
        }

        return self::SUCCESS;
    }

    private function report(int $companyId, string $secret): void
    {
        $mfa = array_values(array_filter(
            RoleKey::cases(),
            fn (RoleKey $role): bool => $role->requiresMfa(),
        ));

        $this->components->twoColumnDetail('Company', (string) $companyId);
        $this->components->twoColumnDetail('Permissions in the catalogue', (string) count(PermissionCatalogue::names()));
        $this->components->twoColumnDetail(
            'Roles requiring MFA',
            implode(', ', array_map(fn (RoleKey $r): string => $r->label(), $mfa))
        );

        if ($this->input->isInteractive()) {
            $this->components->twoColumnDetail('Owner password (shown once)', OutputFormatter::escape($secret));
        }

        $this->components->warn('Next: sign in, enrol TOTP, and invite staff from the Users & roles screen. There is no sign-up form.');
    }

    private function ownerEmail(): string
    {
        $email = (string) ($this->option('email') ?: config('fleet.identity.owner_email'));

        if ($email === '') {
            $email = text('Owner email', required: true, validate: ['email']);
        }

        return $email;
    }

    private function ownerName(): string
    {
        $name = (string) ($this->option('name') ?: config('fleet.identity.owner_name'));

        return $name !== '' ? $name : text('Owner name', required: true, default: 'Owner');
    }

    /**
     * Never echoed and never written to a file. An option is accepted for unattended runs, the
     * environment second, and a hidden prompt last (secrets are not repository content).
     *
     * `getenv()` rather than the `env()` helper Larastan asks for, because `env()` is meant to be
     * read through config — and config is the one place a secret must not be written, since
     * `config:cache` puts it on disk.
     */
    private function ownerPassword(): string
    {
        $fromOption = $this->option('password');
        $fromEnvironment = getenv('FLEET_OWNER_PASSWORD');

        if (is_string($fromOption) && $fromOption !== '') {
            return $fromOption;
        }

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        if (! $this->input->isInteractive()) {
            throw new \RuntimeException('A non-interactive setup needs --password= or FLEET_OWNER_PASSWORD.');
        }

        return password('Owner password');
    }
}
