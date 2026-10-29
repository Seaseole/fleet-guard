<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Models;

use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * §4.4 NOTIFICATION_PREFERENCE — one person's answer about one type on one channel.
 *
 * **The absence of a row means deliver.** That is the only shape that survives a new alert type: an
 * opt-in table would silently mute every type its author forgot to insert, and §14.1's whole sentence
 * is about the alert reaching somebody. So a row is written only when someone opts *out*, and the
 * `enabled` column exists so the screen can offer both directions without deleting the record of a
 * choice.
 *
 * A critical alert ignores this table entirely — see {@see AlertType::respectsOptOut()} — because the
 * promise "you will hear about an expired roadworthiness certificate" is worth more than a checkbox.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property AlertType $type
 * @property string $channel
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationPreference extends Model
{
    use BelongsToCompany;

    protected $table = 'notification_preferences';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'type', 'channel', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'enabled' => 'boolean',
        ];
    }

    public function isMail(): bool
    {
        return $this->channel === 'mail';
    }
}
