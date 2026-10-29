<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents;

use App\Domain\Kernel\Documents\Exceptions\UnconfiguredDocumentType;

/**
 * §4.4 DOCUMENT.type — the ten kinds of paper the fleet has to keep.
 *
 * The allowlist and the size cap are per type and come from `fleet.documents.types`, not from this
 * enum, because both are numbers an owner will want to move (a POD cap tuned to phone cameras, a
 * customs PDF bundle that legitimately runs to 15 MB) and neither should require a code change.
 * The enum is the shape; the config is the number.
 *
 * A type with no config entry is **refused** rather than defaulted: silently accepting a new kind
 * of document under some other kind's rules is how `application/x-msdownload` ends up filed as a
 * licence.
 */
enum DocumentType: string
{
    case Contract = 'contract';
    case Licence = 'licence';
    case Insurance = 'insurance';
    case Permit = 'permit';
    case DeliveryNote = 'delivery_note';
    case Pod = 'pod';
    case Customs = 'customs';
    case WorkOrder = 'work_order';
    case Maintenance = 'maintenance';
    case Weighbridge = 'weighbridge';

    /** @return list<string> the content-sniffed MIME types this kind of paper may have */
    public function allowedMimeTypes(): array
    {
        /** @var list<string> $mime */
        $mime = (array) ($this->settings()['mime'] ?? []);

        return $mime;
    }

    public function maxBytes(): int
    {
        return (int) ($this->settings()['max_bytes'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = config('fleet.documents.types.'.$this->value);

        if (! is_array($settings)) {
            throw UnconfiguredDocumentType::for($this);
        }

        return $settings;
    }

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::Licence => 'Licence',
            self::Insurance => 'Insurance',
            self::Permit => 'Permit',
            self::DeliveryNote => 'Delivery note',
            self::Pod => 'Proof of delivery',
            self::Customs => 'Customs paper',
            self::WorkOrder => 'Work order',
            self::Maintenance => 'Maintenance record',
            self::Weighbridge => 'Weighbridge ticket',
        };
    }

    /**
     * Whether this kind carries an expiry that the §10.8 validity axis should be computed for.
     *
     * Only some do: a POD, a delivery note and a work order record an event that already happened
     * and never expires, while a licence, permit, insurance policy and customs paper all have a
     * date after which they are worthless. The distinction exists because the nightly derivation
     * (§10.8) must not raise `DocumentExpiryImminent` for a weighbridge ticket.
     */
    public function expires(): bool
    {
        return match ($this) {
            self::Licence, self::Insurance, self::Permit, self::Customs, self::Contract => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
