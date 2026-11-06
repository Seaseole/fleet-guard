<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

use App\Domain\Kernel\Alerts\Models\Alert;

/**
 * What a delivery says, written at the moment it goes out.
 *
 * This class exists because of a failure measured in `scripts/verify-alerts.php`, not imagined: the first
 * version of `AlertRaised` took the `Alert` model itself, and the queued notification job died with
 * `ModelNotFoundException: No query results for model [Alert]`. A queued notification is rehydrated by the
 * worker, and `Alert` is company-scoped — a worker has no authenticated user, so `CompanyScope` resolves
 * no tenant and the row it needs cannot be found. Every alert delivery would have failed the same way in
 * production, and only on the queue, which is the hardest place for that kind of bug to be noticed.
 *
 * So the delivery carries words and ids, exactly like {@see ReminderCandidate}. It is also the better
 * answer for the reader: a mail that re-reads the alert on delivery would say whatever the register says
 * *now*, and a copy that says "this said the licence was 14 days out when we sent it" is the honest
 * artefact — the notifications are deliveries of an alert, not live views of one.
 */
final readonly class AlertCopy
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $alertId,
        public string $type,
        public string $typeLabel,
        public string $severity,
        public string $severityLabel,
        public string $tone,
        public string $title,
        public string $occurredOn,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $payload,
    ) {}

    public static function fromAlert(Alert $alert): self
    {
        return new self(
            alertId: (int) $alert->id,
            type: $alert->type->value,
            typeLabel: $alert->type->label(),
            severity: $alert->severity->value,
            severityLabel: $alert->severity->label(),
            tone: $alert->severity->tone(),
            title: $alert->title(),
            occurredOn: $alert->occurred_on->toDateString(),
            subjectType: $alert->subject_type,
            subjectId: $alert->subject_id,
            payload: $alert->payload ?? [],
        );
    }

    /**
     * The bell's row, as stored in `notifications.data`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alert_id' => $this->alertId,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'severity' => $this->severity,
            'tone' => $this->tone,
            'title' => $this->title,
            'occurred_on' => $this->occurredOn,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'url' => route('alerts.index'),
        ];
    }

    /**
     * Two lines of mail body, built from what the emitter knew.
     *
     * @return array{0: string, 1: string}
     */
    public function mailLines(): array
    {
        $expiry = $this->payload['expires_on'] ?? null;

        return [
            $this->typeLabel.': '.$this->title,
            is_string($expiry) && $expiry !== ''
                ? 'Reported by the run on '.$this->occurredOn.'; the paper itself expires '.$expiry.'.'
                : 'Reported by the run on '.$this->occurredOn.'.',
        ];
    }
}
