<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Policies\BankAccountPolicy;
use App\Domain\Kernel\Identity\FieldGate;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Security\DataGovernance\HasMaskedSecret;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A bank, cash or wallet account (§4.2 BANK_ACCOUNT) — the account the ledger will move money
 * through once the ledger slice lands, and today the subject that proves the §9.4 field-gate rule
 * and the §9.5 encrypted-column pattern actually hold together.
 *
 * Nothing here decrypts implicitly. The attributes a screen binds to are `number_masked` and
 * {@see maskedNumber()}; plaintext is reachable only through {@see revealedNumber()}, which asks
 * `seeBankDetail` before it calls the cipher, so "the list page leaked account numbers" is not a
 * shape this model can take.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $institution
 * @property string|null $branch
 * @property string|null $number_masked
 * @property string|null $key_version
 * @property string $currency_code
 * @property int|null $gl_account_id
 * @property string $type
 * @property Carbon|null $opened_on
 * @property Carbon|null $closed_on
 * @property bool $active
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(BankAccountPolicy::class)]
final class BankAccount extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, HasMaskedSecret;

    /**
     * §9.5's promise, applied to the audit trail: the ciphertext never belongs in a second table.
     *
     * `audits.new_values` is a text column any permission-holder with `audit.view` can read, and
     * the mask is already recorded through the ordinary `number_masked` attribute — so logging the
     * encrypted column would copy the secret into a place with different access rules, different
     * retention, and no field gate in front of it.
     */
    /**
     * @var list<string> attributes the audit trail must never copy
     */
    protected array $auditExclude = [
        'number_encrypted',
        'remember_token',
    ];

    /** The §9.5 column prefix for this model's secret: `number_encrypted` + `number_masked`. */
    public const NUMBER = 'number';

    protected $fillable = [
        'name',
        'institution',
        'branch',
        'currency_code',
        'gl_account_id',
        'type',
        'opened_on',
        'closed_on',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'gl_account_id' => 'integer',
            'opened_on' => 'date',
            'closed_on' => 'date',
            'active' => 'boolean',
        ];
    }

    /** Safe to render anywhere: the stored display form, never derived from ciphertext. */
    public function maskedNumber(): string
    {
        return $this->maskedSecret(self::NUMBER);
    }

    /**
     * The gated read (§9.4). Returns null rather than throwing when the gate is closed so a caller
     * that forgot to ask still cannot print a number it was not entitled to — the gate's answer is
     * the value, not an exception to catch.
     */
    public function revealedNumber(): ?string
    {
        return $this->revealMaskedSecret(self::NUMBER, FieldGate::SeeBankDetail);
    }

    /**
     * The only write path for the secret. Called from the Actions, which is what keeps §9.5's
     * "adding encryption later needs a re-encrypt migration" honest: the ciphertext, its mask and
     * its key version are always written together or not at all.
     */
    public function changeNumber(?string $number): void
    {
        $this->writeMaskedSecret(self::NUMBER, $number);
    }

    public function isCash(): bool
    {
        return $this->type === 'cash';
    }
}
