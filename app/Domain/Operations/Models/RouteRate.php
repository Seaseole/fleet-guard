<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\Money\Money;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\Policies\RouteRatePolicy;
use App\Domain\Operations\RouteRateState;
use App\Domain\Operations\RouteRateType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.1 ROUTE_RATE — one customer's price for one corridor on one basis, over a validity window.
 *
 * The class depends on the Invoicing module's `Customer` for exactly one reason: a rate without a
 * customer is not a price, it is a wish. §3's module map already draws the edge Operations →
 * Invoicing, and `scripts/lint-architecture.php` declares it explicitly rather than letting the
 * import hide.
 *
 * A new price never edits an old one — it closes the old window and opens a new one. That is why
 * there is no `amount history` table: the history *is* the rows, and a trip billed last quarter can
 * still point at the card that priced it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $route_id
 * @property int $customer_id
 * @property RouteRateType $rate_type
 * @property int $rate_minor_units
 * @property string $currency_code
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(RouteRatePolicy::class)]
final class RouteRate extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'route_id',
        'customer_id',
        'rate_type',
        'rate_minor_units',
        'currency_code',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'route_id' => 'integer',
            'customer_id' => 'integer',
            'rate_type' => RouteRateType::class,
            'rate_minor_units' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function stateOn(CarbonInterface $on): RouteRateState
    {
        return RouteRateState::of($this->valid_from, $this->valid_to, $on);
    }

    /**
     * The stored amount as money, in the card's own currency. The screen formats it; the model
     * does not own a decimal format (§5.1), and a rate quoted in ZAR must not be rendered with the
     * company's Pula symbol as if it were a base amount.
     */
    public function amount(): Money
    {
        return Money::ofMinor($this->rate_minor_units, $this->currency_code);
    }
}
