<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Data;

use App\Domain\Fleet\FleetStatus;
use InvalidArgumentException;

/**
 * The validated input behind the equipment Actions (D1).
 */
final readonly class EquipmentDto
{
    public const KINDS = ['loader', 'generator', 'toolbox'];

    public function __construct(
        public string $code,
        public string $kind = 'loader',
        public int $purchaseAmountBase = 0,
        public FleetStatus $initialStatus = FleetStatus::Available,
    ) {
        if (trim($code) === '') {
            throw new InvalidArgumentException('Equipment needs a code.');
        }

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Equipment kind '{$kind}' is not one of ".implode(', ', self::KINDS).'.');
        }

        if ($purchaseAmountBase < 0) {
            throw new InvalidArgumentException('Purchase amount cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            code: trim((string) $input['code']),
            kind: (string) ($input['kind'] ?? 'loader'),
            purchaseAmountBase: (int) ($input['purchase_amount_base'] ?? 0),
            initialStatus: FleetStatus::tryFrom((string) ($input['status'] ?? FleetStatus::Available->value)) ?? FleetStatus::Available,
        );
    }
}
