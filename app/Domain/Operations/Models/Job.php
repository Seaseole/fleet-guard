<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Invoicing\Models\Customer;
use App\Domain\Kernel\State\CanTransition;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use App\Domain\Operations\JobStatus;
use App\Domain\Operations\Policies\JobPolicy;
use App\Domain\Operations\RouteRateType;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * §4.1 JOB — the commercial agreement: one customer, one corridor, one quoted price, one promise.
 *
 * The quote is three columns and no more (§5.2 lists the eight-column FX block for the documents that
 * *post*; a job only offers). `base_amount` is the same figure in company currency as of
 * `quoted_on`, which is what lets the jobs board sort by money without a live FX read — and the
 * invoice that Phase 4 raises carries its own rate block, because the rate the customer is billed at
 * is the invoice's, not the quote's.
 *
 * The externally-referenced key is a ULID (§2), minted here so the number and the id cannot be
 * authored separately, and `RouteRate`'s corridor+customer pair is where `QuoteJob` proposes the
 * amount from.
 *
 * @property string $id
 * @property int $company_id
 * @property string $job_no
 * @property int $customer_id
 * @property int $route_id
 * @property JobStatus $status
 * @property string|null $previous_state
 * @property Carbon|null $state_changed_at
 * @property string|null $commodity
 * @property string|float|null $tonnes_10_2
 * @property RouteRateType|null $rate_type
 * @property int $quoted_amount
 * @property string|null $currency_code
 * @property int $base_amount
 * @property Carbon|null $quoted_on
 * @property Carbon|null $required_load_on
 * @property Carbon|null $required_delivery_on
 * @property array<string, mixed>|null $terms
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UsePolicy(JobPolicy::class)]
final class Job extends Model implements AuditableContract
{
    use Auditable, BelongsToCompany, CanTransition;

    /**
     * `freight_jobs`, not `jobs`: the database queue driver owns `jobs` (§4.1's note in the
     * migration). Named here rather than left to Eloquent's pluralisation so the collision is visible
     * at the model, which is where the next person reading `Job::query()` will look.
     */
    protected $table = 'freight_jobs';

    /** @var list<string> */
    protected $fillable = [
        'job_no',
        'customer_id',
        'route_id',
        'commodity',
        'tonnes_10_2',
        'rate_type',
        'quoted_amount',
        'currency_code',
        'base_amount',
        'quoted_on',
        'required_load_on',
        'required_delivery_on',
        'terms',
        'notes',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'customer_id' => 'integer',
            'route_id' => 'integer',
            'status' => JobStatus::class,
            'state_changed_at' => 'datetime',
            'tonnes_10_2' => 'float',
            // The same cast `RouteRate` applies: a job proposes its price from a rate card, and two
            // models reading one column as two types is how a screen starts calling `->value` on a string.
            'rate_type' => RouteRateType::class,
            'quoted_amount' => 'integer',
            'base_amount' => 'integer',
            'quoted_on' => 'date',
            'required_load_on' => 'date',
            'required_delivery_on' => 'date',
            'terms' => 'array',
        ];
    }

    /**
     * §10.3's machine, storable states only.
     *
     * `quoted → enquiry` is deliberately absent: a changed quote is a new quote on the same job, not
     * a return to the pending pile, and a board that shows re-quoted work as "new" hides how long the
     * customer has actually had it.
     *
     * @return array<string, list<string>>
     */
    protected function transitions(): array
    {
        return [
            JobStatus::Enquiry->value => [JobStatus::Quoted->value, JobStatus::Declined->value, JobStatus::Cancelled->value],
            JobStatus::Quoted->value => [JobStatus::Accepted->value, JobStatus::Declined->value, JobStatus::Cancelled->value],
            JobStatus::Accepted->value => [JobStatus::InExecution->value, JobStatus::Suspended->value, JobStatus::Closed->value],
            JobStatus::InExecution->value => [JobStatus::Suspended->value, JobStatus::Closed->value],
            JobStatus::Suspended->value => [JobStatus::Accepted->value, JobStatus::InExecution->value, JobStatus::Closed->value],
            JobStatus::Declined->value => [],
            JobStatus::Cancelled->value => [],
            JobStatus::Closed->value => [],
        ];
    }

    /** §10.1: refusing, cancelling and closing all say something about the world that outlives the click. */
    protected function transitionsRequiringReason(): array
    {
        return [JobStatus::Declined->value, JobStatus::Cancelled->value, JobStatus::Closed->value];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return HasMany<Trip, $this> */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /** The figure the jobs board prints: foreign amount when the customer was quoted in it. */
    public function quoteLabel(): string
    {
        return $this->currency_code === null
            ? 'not quoted'
            : number_format($this->quoted_amount / 100, 2).' '.$this->currency_code;
    }

    protected static function booted(): void
    {
        self::creating(function (self $job): void {
            $job->id ??= (string) Str::ulid();

            // The machine's first state is part of being created, not a column default to be read back:
            // `moveTo()` asks the model what it currently is, and a fresh in-memory object whose status
            // came only from the schema default answers '' — which the transition table refuses, with a
            // message about `'' → declined` that looks like a broken state machine rather than a missing
            // initialiser. (Same reason `Document` defaults `lifecycle` in its own `booted()`.)
            $job->status ??= JobStatus::Enquiry;
        });
    }
}
