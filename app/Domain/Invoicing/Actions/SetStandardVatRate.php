<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Models\TaxRate;
use App\Domain\Invoicing\TaxRateKind;
use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Q4 / the company's standard VAT rate, changed where an Owner can reach it.
 *
 * The Application-settings field is a front door onto this Action, not onto the settings blob: the
 * register is the only place a VAT rate may live, because a rate is a **dated window** — an invoice
 * issued in December under 12% must print and post 12% in March, and a percentage read from a blob at
 * report time would let a later edit rewrite what a filed return was computed from.
 *
 * One transaction, three possible answers:
 *
 * 1. The figure already applies today → nothing is written. A save that changes nothing must not
 *    close and reopen a window, because the register would fill with one-day rows every time the
 *    form is pressed.
 * 2. A window opened **today** is amended in place. `tax_rates_company_code_window_unique` is
 *    (company, code, starts_on), so two new windows cannot start on the same day, and a same-day
 *    change of mind is not history being rewritten: the rate is already frozen on any line issued
 *    before this call.
 * 3. Otherwise the open window is closed at today (half-open: the day it ends is the first day it no
 *    longer applies) and a new window starts today, default, open.
 *
 * `is_active` stays true on a closed row. `TaxRate::effectiveOn()` filters on it *and* on the window,
 * so a closed row must remain reachable to answer the past — switching it off would make the rate a
 * document was charged under unfindable, which is the opposite of why the window exists.
 */
final class SetStandardVatRate
{
    /** The scale the design keeps percentages at: 12.00% is 120 000. */
    private const RATE_SCALE = 10_000;

    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(float $percent, ?int $actorId = null): TaxRate
    {
        if (! Gate::allows('settings.manage')) {
            throw new InvalidArgumentException('Changing the company\'s standard VAT rate needs the settings.manage ability.');
        }

        if ($percent <= 0 || $percent > 100) {
            throw new InvalidArgumentException(sprintf(
                'A standard rate must be more than zero and at most 100 per cent; "%s" is neither. A line that carries no VAT is a zero-rated kind, not a 0%% rate — the register\'s own CHECK says so (Q4).',
                rtrim(rtrim(number_format($percent, 4, '.', ''), '0'), '.'),
            ));
        }

        $scaled = $percent * self::RATE_SCALE;

        // 12.005% cannot be stored: the column is a percentage at four decimal places (12.0000% is
        // 120 000), and quietly rounding a figure an Owner typed would be a VAT line nobody agreed.
        if (abs($scaled - round($scaled)) > 0.001) {
            throw new InvalidArgumentException(sprintf(
                'A VAT rate is stored to four decimal places of a per cent, and %s per cent has more. Round it before saving.',
                rtrim(rtrim(number_format($percent, 6, '.', ''), '0'), '.'),
            ));
        }

        $rateX10000 = (int) round($scaled);
        $today = Carbon::now(CurrentCompany::timezone())->startOfDay();

        return DB::transaction(function () use ($rateX10000, $today, $actorId): TaxRate {
            /** @var Collection<int, TaxRate> $open */
            $open = TaxRate::query()
                ->where('company_id', CurrentCompany::id())
                ->where('kind', TaxRateKind::Standard->value)
                ->whereNull('ends_on')
                ->orderBy('starts_on')
                ->lockForUpdate()
                ->get();

            // `Collection::last()` is typed non-nullable, so an empty register is asked as emptiness
            // rather than as null — the shape the rest of this method reads.
            $current = $open->isEmpty() ? null : $open->last();

            if ($current !== null && $current->rate_x10000 === $rateX10000) {
                return $current;
            }

            $before = $current === null ? null : $current->rate_x10000;

            if ($current !== null && $current->starts_on->isSameDay($today)) {
                // Answer 2: the window that opened today is amended where it stands.
                $current->forceFill(['rate_x10000' => $rateX10000])->save();

                $this->audit($before, $rateX10000, $current->id, 'amended today', $actorId);

                return $current->refresh();
            }

            // Answer 3: close what is open — but only windows that actually started before today, so
            // `ends_on > starts_on` (the half-open rule) cannot be violated.
            foreach ($open as $row) {
                if ($row->starts_on->lessThan($today)) {
                    $row->forceFill(['ends_on' => $today->toDateString(), 'is_default' => false])->save();
                }
            }

            // A company whose register has no standard window yet gets the seeder's own code and name,
            // so the row this Action writes is indistinguishable from the seeded one.
            $code = $current === null ? 'VAT' : $current->code;
            $name = $current === null ? 'VAT standard' : $current->name;

            // Force-filled, not mass-assigned: `company_id` is deliberately outside the model's
            // fillable list (a screen must not choose which company a fiscal row belongs to), and the
            // key is set outside it too — the defect was a ULID primary key lost exactly that way.
            $rate = new TaxRate;
            $rate->id = (string) Str::ulid();
            $rate->forceFill([
                'company_id' => CurrentCompany::id(),
                'code' => strtoupper($code),
                'name' => $name,
                'kind' => TaxRateKind::Standard->value,
                'rate_x10000' => $rateX10000,
                'starts_on' => $today->toDateString(),
                'ends_on' => null,
                'is_active' => true,
                'is_default' => true,
            ])->save();

            // The row just closed keeps `is_default` only if it is the one still answering today —
            // which it is not: the new row starts today and the closed window ends today, half-open.
            $this->audit($before, $rateX10000, $rate->id, 'new window from today', $actorId);

            return $rate;
        }, 3);
    }

    private function audit(?int $before, int $after, string $rateId, string $what, ?int $actorId): void
    {
        $this->audit->security(SecurityEventKind::SettingsChanged, [
            'changed' => [
                'tax.standard_rate_pct' => [
                    'from' => $before === null ? null : $before / self::RATE_SCALE,
                    'to' => $after / self::RATE_SCALE,
                    'rate_id' => $rateId,
                    'window' => $what,
                ],
            ],
        ], $actorId);
    }
}
