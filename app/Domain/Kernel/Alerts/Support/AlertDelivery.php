<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Support;

use App\Domain\Kernel\Alerts\Data\AlertCopy;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Alerts\Notifications\AlertRaised;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Telling people about an alert — the delivery half of, and the only place that decides channels.
 *
 * The decision is per *recipient*, not per alert, because the opt-out is theirs and the exception to it
 * depends on the severity this particular alert carried. A single "is mail on" flag on the alert would
 * have to be either everyone's or nobody's.
 *
 * **A delivery that reaches nobody is a finding, not a success.** `deliver()` returns the number of
 * people actually told so the runner can say so out loud: a threshold row naming a role that currently
 * has no holder creates the alert, satisfies every CHECK, and leaves the Owner believing the licence
 * reminder is working. The recipients sentence is only as good as somebody noticing when it stops
 * being true.
 */
final class AlertDelivery
{
    /**
     * The channels this application can actually send on.
     *
     * The list also names `whatsapp_later`; it is absent here and from the DB CHECK because a
     * preference row for a channel nothing sends on is a choice that looks saved and does nothing.
     *
     * @var list<string>
     */
    public const Channels = ['database', 'mail'];

    public function __construct(private readonly RecipientResolver $recipients) {}

    public function deliver(Alert $alert, Threshold $threshold): int
    {
        return $this->send($alert, $this->recipients->resolve($threshold));
    }

    /**
     * Delivery for a finding no threshold row authorised — the self-check alerts, and only they.
     *
     * @param  list<string>  $roleKeys
     */
    public function deliverToRoles(Alert $alert, array $roleKeys, string $for): int
    {
        return $this->send($alert, $this->recipients->resolveRoleKeys($roleKeys, $for));
    }

    /**
     * @param  Collection<int, User>  $people
     */
    private function send(Alert $alert, Collection $people): int
    {
        // The words of the delivery are written here, while the tenant context is pinned, and not in the
        // worker: see {@see AlertCopy} for the failure that taught us the difference.
        $copy = AlertCopy::fromAlert($alert);
        $told = 0;

        foreach ($people as $user) {
            $channels = array_values(array_filter(
                self::Channels,
                fn (string $channel): bool => $this->recipients->wants($user, $alert->type, $channel, $alert->severity),
            ));

            if ($channels === []) {
                continue;
            }

            // Named queue rather than the default, so an alerts backlog cannot stall the ledger postings
            // on the same worker, and so a repair (`queue:work --queue=alerts`) is specific.
            $user->notify((new AlertRaised($copy, $channels))->onQueue('alerts'));
            $told++;
        }

        if ($told > 0) {
            // One increment per raise that reached somebody, not per person told: the column answers
            // "how many times did this alert go out", which is the question the cooldown sentence
            // is asked about.
            $alert->forceFill(['notify_count' => $alert->notify_count + 1])->save();
        }

        return $told;
    }
}
