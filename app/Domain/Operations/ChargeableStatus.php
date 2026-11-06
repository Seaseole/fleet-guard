<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * CHARGEABLE's machine: `pending → ready → billed`, with `written_off` as the terminal exception.
 *
 * `ready` is where this phase stops: invoicing is Phase 4, and a `billed` chargeable is a fact an
 * invoice row owns, not a state this table may adopt in advance. The `billing_state` on the job is
 * derived from these rows, never stored.
 */
enum ChargeableStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Billed = 'billed';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'not ready',
            self::Ready => 'ready to bill',
            self::Billed => 'billed',
            self::WrittenOff => 'written off',
        };
    }

    /** @return 'neutral'|'warning'|'success'|'danger' */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Ready => 'success',
            self::Billed => 'neutral',
            self::WrittenOff => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
