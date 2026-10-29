<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Models;

use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * §4.4 REMINDER_THRESHOLD — one bucket for one family, and the roles it notifies.
 *
 * The column is `family`, not §4.4's `type`, for the reason written on the migration: `alerts.type`
 * answers *what kind of alert* (`doc_expiry`) and this row answers *what kind of paper* (`licence`).
 * One name for both is how a reviewer joins `doc_expiry` to a threshold row named `doc_expiry` that
 * nothing wrote, and reads the empty result as "reminders are configured".
 *
 * `recipients` is guarded rather than fillable-plain because the DB CHECK already insists the value is
 * a JSON array with at least one element, and the only way to break that from here is to assign a bare
 * string and let the insert fail in a nightly job.
 *
 * @property int $id
 * @property int $company_id
 * @property string $family
 * @property int $days_before
 * @property array<int, mixed> $recipients
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ReminderThreshold extends Model
{
    use BelongsToCompany;

    protected $table = 'reminder_thresholds';

    /**
     * @var list<string>
     */
    protected $fillable = ['family', 'days_before', 'recipients', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'days_before' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * The role keys this row notifies, as strings.
     *
     * The cast is `array`, and a JSON array written by hand can contain a number or a null. Those are
     * not roles, and passing them on would produce a "no such role" error from the resolver naming a
     * row the Owner cannot find. So the shape is asserted here, in the one place that reads it.
     *
     * @return list<string>
     */
    public function recipientRoleKeys(): array
    {
        $keys = [];

        foreach ($this->recipients ?? [] as $recipient) {
            if (is_string($recipient) && trim($recipient) !== '') {
                $keys[] = trim($recipient);
            }
        }

        return $keys;
    }
}
