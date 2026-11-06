<?php

declare(strict_types=1);

use App\Domain\Kernel\Money\Currency;
use App\Domain\Kernel\Money\ExchangeRate;
use App\Domain\Kernel\Money\Fx;
use App\Domain\Kernel\Money\FxRateSource;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Money\RateResolver;
use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Numbering\NumberFormat;
use App\Domain\Kernel\Numbering\NumberingError;
use App\Domain\Kernel\Numbering\NumberPeriod;
use App\Domain\Kernel\Numbering\NumberSequenceRegistry;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 Kernel verification.
 *
 * This is not the test suite — tests are local-only by owner decision. It is the
 * substitute for the parts of the contract that a database constraint or a unit test would
 * otherwise prove: money cannot become a float, a rolled-back allocation must not burn a
 * number, and a scoped read with no tenant must throw rather than widen.
 *
 * Run: php scripts/verify-kernel.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

CurrentCompany::force(1);
$allocator = new NumberAllocator;

// Deterministic counters: this script asserts specific numbers, so it starts from a clean
// sequence table rather than from wherever the previous run (or the concurrency test) left it.
DB::table('number_sequences')->delete();

// Sequence rows are created ahead of allocation (NumberSequenceRegistry), never inside it —
// that ordering is the deadlock fix recorded in DESIGN.md.
(app(NumberSequenceRegistry::class))->ensureAll();

$pass = 0;
$fail = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $checks;

    $checks++;

    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }

    $line = '  '.($ok ? 'ok  ' : 'FAIL ').' '.$label;

    if ($detail !== '') {
        $line .= ' - '.$detail;
    }

    echo $line.PHP_EOL;
}

// ─── money ────────────────────────────────────────────────────────────────────

echo PHP_EOL.'Money'.PHP_EOL;

check('minor units round-trip', Money::ofMinor(125000, 'BWP')->format('BWP') === 'P 1 250,00');

try {
    Money::ofMinor(1250.5, 'BWP');
    check('float amount refused', false, 'no exception');
} catch (Throwable $e) {
    check('float amount refused', true, 'brick/math would have truncated it to 1250');
}

try {
    Money::ofMinor('1250.00', 'BWP');
    check('decimal string refused', false, 'no exception');
} catch (Throwable $e) {
    check('decimal string refused', true);
}

check('ZAR 1000.00 @ 0.54321 = BWP 543.21',
    Fx::foreignToBase(100000, 'ZAR', '0.54321000', 'BWP', FxRateSource::RbgDaily, Carbon::parse('2026-10-26'))
        ->baseAmount()->minorUnits === 54321);

check('HALF_UP at the half-minor boundary',
    Money::ofMinor(100, 'USD')->toBase('1.00500000', 'BWP')->minorUnits === 101,
    '1.00 x 1.005 -> 1.01, not banker-rounded to 1.00');

check('same-currency rate != 1 refused', (function (): bool {
    try {
        Money::ofMinor(50000, 'BWP')->toBase('0.54321', 'BWP');

        return false;
    } catch (Throwable) {
        return true;
    }
})());

// The app's date class is CarbonImmutable; a Carbon-typed parameter would reject now().
$override = Fx::withBaseAmount(100000, 'ZAR', 54322, 'BWP', now());
check('back-solve derives the rate at 8 dp', $override->rate === '0.54322000', $override->rate);
check('base-amount edit sets manual_override', $override->source === FxRateSource::ManualOverride);
check('derived rate reproduces the base amount', $override->foreignAmount()->toBase($override->rate, 'BWP')->minorUnits === 54322);
check('now() is accepted by the FX block', $override->rateDate instanceof Carbon);

check('money serialises as strings', json_encode(Money::ofMinor(125000, 'BWP')) === '{"amount":"125000","currency":"BWP"}');

check('cross-currency add refused', (function (): bool {
    try {
        Money::ofMinor(1, 'BWP')->add(Money::ofMinor(1, 'ZAR'));

        return false;
    } catch (Throwable) {
        return true;
    }
})());

check('unknown currency refused, not defaulted', (function (): bool {
    try {
        Currency::of('BWW');

        return false;
    } catch (Throwable) {
        return true;
    }
})());

ExchangeRate::query()->where('source', FxRateSource::Manual->value)->get()->each->delete();

$stored = ExchangeRate::query()->create([
    'currency_code' => 'ZAR',
    'rate_date' => Carbon::parse('2026-10-20'),
    'rate_to_base' => '0.54100000',
    'source' => FxRateSource::Manual,
    'ref' => 'desk entry',
    'entered_at' => now(),
]);

check('exchange_rate stamped with company', $stored->company_id === 1);

$resolver = app(RateResolver::class);
check('resolver finds the newest rate on or before the date', $resolver->resolve('ZAR', Carbon::parse('2026-10-26'))?->rate() === '0.54100000');
check('resolver flags a 6-day-old rate', $resolver->isStale('ZAR', Carbon::parse('2026-10-26')));
check('resolver accepts a 1-day-old rate', ! $resolver->isStale('ZAR', Carbon::parse('2026-10-21')));

// ─── tenancy ──────────────────────────────────────────────────────────────────

echo PHP_EOL.'Tenancy fail-closed (T1-T5)'.PHP_EOL;

CurrentCompany::forget();

check('scoped read with no context throws', (function (): bool {
    try {
        ExchangeRate::query()->count();

        return false;
    } catch (Throwable) {
        return true;
    }
})());

CurrentCompany::force(1);

// ─── numbering ────────────────────────────────────────────────────────────────

echo PHP_EOL.'Numbering'.PHP_EOL;

check('allocation outside a transaction refused', (function () use ($allocator): bool {
    try {
        $allocator->allocate('trip');

        return false;
    } catch (NumberingError) {
        return true;
    }
})());

DB::transaction(function () use ($allocator): void {
    $a = $allocator->allocate('trip');
    $b = $allocator->allocate('trip');
    check('sequential numbers increment', $a === 'TRP-00001' && $b === 'TRP-00002', $a.', '.$b);
});

check('financial-year period key', NumberPeriod::keyFor('financial_year', Carbon::parse('2026-10-26')) === 'FY26/27');
check('calendar-year period key', NumberPeriod::keyFor('calendar_year', Carbon::parse('2026-10-26')) === '2026');
check('continuous period key', NumberPeriod::keyFor('continuous', Carbon::parse('2026-10-26')) === '');

DB::transaction(function () use ($allocator): void {
    check('fiscal number carries the FY period', $allocator->allocate('invoice') === 'INV-FY26/27-0000001');
});

// The claim ADR-0002 makes against MariaDB's non-transactional SEQUENCE, tested on the row.
// Commit one allocation first so the sequence row exists: otherwise the rollback correctly
// removes the row it created in the same transaction, which proves the same thing but reads
// like a failure.
DB::transaction(function () use ($allocator): void {
    $allocator->allocate('job');
});

$before = (int) DB::table('number_sequences')->where('scope', 'job')->value('next_value');

try {
    DB::transaction(function () use ($allocator): void {
        $allocator->allocate('job');
        $allocator->allocate('job');
        throw new RuntimeException('simulated failure after two allocations');
    });
} catch (RuntimeException) {
    // expected
}

$after = (int) DB::table('number_sequences')->where('scope', 'job')->value('next_value');
check('rollback un-burns two numbers', $after === $before, "counter was {$before}, is {$after}");

$nextAfterRollback = null;
DB::transaction(function () use ($allocator, &$nextAfterRollback): void {
    $nextAfterRollback = $allocator->allocate('job');
});
check('the number after a rollback continues the sequence', $nextAfterRollback === 'JOB-00002', (string) $nextAfterRollback);

$survivors = [];
DB::transaction(function () use ($allocator, &$survivors): void {
    $survivors[] = $allocator->allocate('work_order');
});
try {
    DB::transaction(function () use ($allocator): void {
        $allocator->allocate('work_order');
        throw new RuntimeException('nope');
    });
} catch (RuntimeException) {
    // expected
}
DB::transaction(function () use ($allocator, &$survivors): void {
    $survivors[] = $allocator->allocate('work_order');
});
check('no gap after a rolled-back allocation', $survivors === ['WO-00001', 'WO-00002'], implode(', ', $survivors));

check('unknown scope refused', (function () use ($allocator): bool {
    try {
        $allocator->allocate('vat_receipt');

        return false;
    } catch (NumberingError) {
        return true;
    }
})());

check('unknown format token refused', (function (): bool {
    try {
        NumberFormat::render('INV-{WAT}-{SEQ:3}', 'FY26/27', 1);

        return false;
    } catch (NumberingError) {
        return true;
    }
})());

check('format without a counter refused', (function (): bool {
    try {
        NumberFormat::render('INV-{PERIOD}', 'FY26/27', 1);

        return false;
    } catch (NumberingError) {
        return true;
    }
})(), 'a format with no {SEQ} cannot be gap-free');

check('padding never truncates', NumberFormat::render('X-{SEQ:3}', '', 10000) === 'X-10000');

// The deadlock fix: a missing row is an error, never an opportunistic INSERT under lock.
// `invoice` is a financial-year scope, so a 2031 period has no row until numbering:ensure runs.
check('missing sequence row fails loudly', (function () use ($allocator): bool {
    try {
        DB::transaction(fn () => $allocator->allocate('invoice', Carbon::parse('2031-06-01')));

        return false;
    } catch (NumberingError $e) {
        return str_contains($e->getMessage(), 'numbering:ensure');
    }
})(), 'a 2031 fiscal period has no row yet, and allocation must not create one under lock');

// CHECK (next_value > 0) is the database's own guard, so it is tested at the database.
check('DB CHECK refuses a rewound counter', (function (): bool {
    try {
        DB::table('number_sequences')->where('scope', 'trip')->update(['next_value' => 0]);

        return false;
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), '3819') || str_contains($e->getMessage(), 'Check constraint');
    }
})());

echo PHP_EOL.sprintf('%d checks, %d passed, %d failed', $checks, $pass, $fail).PHP_EOL;

exit($fail === 0 ? 0 : 1);
