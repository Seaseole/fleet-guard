<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Notifications;

use App\Domain\Kernel\Alerts\Data\AlertCopy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The delivery of one alert — never the alert itself.
 *
 * The distinction is the whole design: one `doc_expiry` alert is delivered to the Operations Manager and
 * the Dispatcher, and both of them acting on it must not double-count the finding, and neither of them
 * reading it must clear the other's unread dot. `alerts` stays the register of truth; this row in
 * `notifications` is one person's copy of it, which is why the bell reads that table and the register
 * reads this one.
 *
 * Queued: a nightly sweep over twenty-one trucks' papers must not spend its run on SMTP
 * round-trips, and a mail server that is down at 01:40 should delay a reminder rather than fail the scan
 * that produced it.
 *
 * It carries an {@see AlertCopy} rather than the `Alert` model, and that is a measured correction rather
 * than a style choice. A queued notification is rehydrated by a worker that has no company context, and
 * `Alert` is company-scoped — so the model the first version carried could not be found again, and every
 * delivery died with `ModelNotFoundException` on the queue while the same code passed inline. The
 * "put anything that can fail slowly on the queue" is only safe when the job carries what it needs to say.
 *
 * The channel list is decided before the job is queued, by {@see
 * \App\Domain\Kernel\Alerts\Support\AlertDelivery}, because that is where the recipient's preferences and
 * the alert's severity are both in hand; a `via()` that re-queried preferences per recipient would put the
 * same decision in two places and let them disagree.
 */
class AlertRaised extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly AlertCopy $copy,
        public readonly array $channels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->copy->toArray();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = $this->copy->mailLines();

        // `name` lives on User; the notification contract hands this method an untyped notifiable, so the
        // narrowing is asserted where it is used rather than in the signature the parent class fixes.
        $greeting = $notifiable instanceof User
            ? sprintf('Hello %s', $notifiable->name)
            : 'Hello';

        return (new MailMessage)
            ->subject(sprintf('[%s] %s', $this->copy->severityLabel, $this->copy->title))
            // No "Good morning": this mail is queued by a 02:30 sweep and by a failing command, and a
            // greeting that is wrong at 07:00 is worse than no greeting.
            ->greeting($greeting)
            ->line($lines[0])
            ->line($lines[1])
            ->action('Open the alert register', route('alerts.index'))
            ->line('This alert is recorded once, in the register. Everything you do to it there — '
                .'acknowledging, muting with an end, resolving with a reason — is what this email is a copy of.');
    }
}
