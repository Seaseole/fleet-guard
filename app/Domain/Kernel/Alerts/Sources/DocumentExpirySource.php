<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Sources;

use App\Domain\Kernel\Alerts\AlertSeverity;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Contracts\ReminderSource;
use App\Domain\Kernel\Alerts\Data\ReminderCandidate;
use App\Domain\Kernel\Alerts\Data\Threshold;
use App\Domain\Kernel\Alerts\Data\ThresholdSet;
use App\Domain\Kernel\Documents\DocumentType;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Validity;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

/**
 * §14.1's `doc_expiry` source — the nightly derivation §10.8 describes, as an emitter rather than an
 * editor of truth.
 *
 * §10.8 is explicit that `expired` is *not* a transition, and {@see Validity} already derives the answer
 * from `expires_on` in one place. So this class asks that enum the question and turns the answer into a
 * candidate. It writes nothing to `documents`: a job that flipped a column to say "expired" would put a
 * second definition of expiry in the app, which is the exact failure §10.8's derivation sentence exists
 * to prevent, and it would make the register disagree with the screen above it.
 *
 * **One alert per paper per expiry date, escalated as the bands tighten.** §14.1's cooldown key is
 * written as `type + subject + bucket`, and the literal reading puts the threshold band in the bucket —
 * which gives a licence that lapses without anyone acting on it four open rows (`60d`, `30d`, `14d`,
 * `7d`) plus a fifth once it is actually expired. The register would then be a list of the same problem
 * at four ages, and "how many things need me" — the first question §DESIGN-SYSTEM says the screen
 * answers — would be wrong by construction. The bucket here is therefore the *condition instance*
 * (`2026-12-01`), and the band is carried on the row: one paper, one row, and every threshold crossing
 * still goes out as a fresh delivery. Renew the licence and the date changes, which is a new instance and
 * a new alert — which is what makes the ladder repeatable rather than once-per-history.
 *
 * Only `current` documents are scanned. A `draft` is paper nobody has decided to rely on yet, and a
 * `superseded` one is evidence of what was on file at the time — its successor is the live paper and is
 * scanned in its own right, so alerting on both would report one expired licence twice.
 */
final class DocumentExpirySource implements ReminderSource
{
    /**
     * The bands at or inside which an approaching expiry is still a warning rather than a breach.
     *
     * Kept as a rule of this class rather than read from the threshold row because the threshold answers
     * "when do we tell people", and this answers "how bad is it when we do".
     */
    private const ImminentTighteningDays = 14;

    public function key(): string
    {
        return 'documents';
    }

    /**
     * §14.1's 60/30/14/7 ladder, one row per expiring document kind, addressed to the role that moves
     * the paper.
     *
     * @return list<Threshold>
     */
    public function defaultThresholds(): array
    {
        $defaults = [];

        foreach (DocumentType::all() as $type) {
            if (! $type->expires()) {
                continue;
            }

            /** @var list<int> $buckets */
            $buckets = (array) config('fleet.reminders.default_buckets', [60, 30, 14, 7]);

            foreach ($buckets as $days) {
                $defaults[] = new Threshold($type->value, $days, $this->defaultRecipientsFor($type));
            }
        }

        return $defaults;
    }

    /**
     * @return iterable<ReminderCandidate>
     */
    public function candidates(ThresholdSet $thresholds, CarbonInterface $businessToday): iterable
    {
        if ($thresholds->count() === 0) {
            return [];
        }

        $widest = $this->widestConfiguredBand($thresholds);

        // One bounded query, then the band matching happens in PHP: the horizon is a *company business
        // day* (§2), and pushing that comparison into SQL would compare a stored date against a
        // differently-zoned "today" — the same two-hours-early error §20.7 records for a licence read
        // as expired from 22:00 UTC.
        /** @var Document $document */
        foreach ($this->expiringDocuments($widest, $businessToday) as $document) {
            /** @var CarbonInterface $expiresOn */
            $expiresOn = $document->expires_on;

            // Tightest band first, and stop at the first match: a licence twelve days from lapsing is
            // inside the 60, 30, 14 and 7 windows at once, and the band that describes it is the narrow
            // one. `buckets()` answers furthest-horizon first, so this is a reversal, not a sort.
            $matchedDays = null;
            $matchedValidity = null;

            foreach (array_reverse($thresholds->buckets($document->type->value)) as $days) {
                $validity = Validity::derive($expiresOn, $businessToday, $days);

                if ($validity === Validity::Valid || $validity === Validity::Perpetual) {
                    continue;
                }

                $matchedDays = $days;
                $matchedValidity = $validity;

                break;
            }

            if ($matchedDays === null || $matchedValidity === null) {
                continue;
            }

            yield new ReminderCandidate(
                type: $document->type === DocumentType::Permit ? AlertType::PermitExpiry : AlertType::DocExpiry,
                severity: $this->severityFor($matchedValidity, $matchedDays),
                family: $document->type->value,
                daysBefore: $matchedDays,
                subjectType: 'document',
                subjectId: $document->id,
                occurredOn: $businessToday,
                bucket: $expiresOn->toDateString(),
                title: sprintf(
                    '%s — %s expires %s (%s)',
                    $document->title,
                    $document->type->label(),
                    $expiresOn->toDateString(),
                    $matchedValidity->label(),
                ),
                payload: [
                    'document_id' => $document->id,
                    'document_type' => $document->type->value,
                    'title' => $document->title,
                    'expires_on' => $expiresOn->toDateString(),
                    'validity' => $matchedValidity->value,
                    'band_days' => $matchedDays,
                    'days_until_expiry' => Validity::daysUntil($expiresOn, $businessToday),
                    // The paper's own subject, as words rather than as a relation: a later phase turns it
                    // into a link, and until then the register can still say "truck, licence" from what
                    // the emitter knew. Reading `$document->subject` here would build the morph relation
                    // this source has no business resolving.
                    'subject_type' => $document->morph_type,
                    'subject_ref' => $document->morph_id,
                ],
            );
        }
    }

    /**
     * @return LazyCollection<int, Document>
     */
    private function expiringDocuments(int $widest, CarbonInterface $businessToday): LazyCollection
    {
        $horizon = $businessToday->copy()->startOfDay()->addDays($widest);

        return Document::query()
            ->where('lifecycle', Lifecycle::Current->value)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $horizon)
            ->orderBy('expires_on')
            ->cursor();
    }

    private function widestConfiguredBand(ThresholdSet $thresholds): int
    {
        $widest = 0;

        foreach ($thresholds->all() as $threshold) {
            $widest = max($widest, $threshold->daysBefore);
        }

        return $widest;
    }

    private function severityFor(Validity $validity, int $days): AlertSeverity
    {
        // A paper that has already lapsed is a breach, not a reminder; anything inside the last fortnight
        // is worth a warning whatever the ladder says; the wide bands are informational.
        if ($validity === Validity::Expired) {
            return AlertSeverity::Critical;
        }

        return $days <= self::ImminentTighteningDays ? AlertSeverity::Warning : AlertSeverity::Info;
    }

    /**
     * @return list<string>
     */
    private function defaultRecipientsFor(DocumentType $type): array
    {
        // The workshop papers are the Workshop Manager's; a contract is the Owner's to chase. Everything
        // that travels with a truck belongs to whoever dispatches it. All editable by the Owner, because
        // these are rows (§14.1) and not constants.
        return match ($type) {
            DocumentType::WorkOrder, DocumentType::Maintenance => ['workshop_manager'],
            DocumentType::Contract => ['owner'],
            // A licence or permit expiry blocks a dispatch (§10.2), so the person who books the
            // truck hears about it alongside the Operations Manager — §14.1's own worked example.
            DocumentType::Licence, DocumentType::Permit => ['operations_manager', 'dispatcher'],
            default => ['operations_manager'],
        };
    }
}
