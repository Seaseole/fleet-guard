<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Actions;

use App\Domain\Kernel\Audit\AuditRecorder;
use App\Domain\Kernel\Audit\SecurityEventKind;
use App\Domain\Kernel\Documents\Exceptions\LifecycleTransitionRefused;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Taking a document out of use: `superseded`, `archived` or `voided` (§10.8, §4.4).
 *
 * One action for the three targets, because the rules are the same three rules asked in a different
 * order — is the move legal, does it need a reason, what timestamp does it date itself with. Three
 * near-identical classes would each have to remember the §4.4 destructive-reason rule, and a rule
 * remembered in three places is a rule that drifts in two.
 *
 * `draft → archived` is not offered: a document with no bytes and no decision taken on it is deleted
 * or left alone, not retired — and the transition table in {@see Lifecycle} refuses it, which is why
 * this class asks the table rather than a form.
 *
 * The reason is checked **before** the update, and the DB CHECK then refuses the event that follows.
 * That order is not belt-and-braces: without the first check the document would move and the trail
 * would fail, leaving the state changed with nothing saying who did it — the precise shape of the
 * hole §10.1 closes.
 */
final class TransitionDocument
{
    /** @var list<Lifecycle> */
    private const Targets = [Lifecycle::Superseded, Lifecycle::Archived, Lifecycle::Voided];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Document $document, Lifecycle $to, ?string $reason = null, ?int $byUserId = null): Document
    {
        if (! in_array($to, self::Targets, true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s is not a transition this action makes. A document becomes current by having a version published — '
                .'%s is {@see UploadDocumentVersion}, not a state to be set (§10.8).',
                $to->value,
                Lifecycle::Current->value,
            ));
        }

        $from = $document->lifecycle;

        if (! $from->canTransitionTo($to)) {
            throw LifecycleTransitionRefused::between($from, $to, $document->id);
        }

        $reason = $reason === null ? null : trim($reason);

        if ($to->isDestructive() && ($reason === null || $reason === '')) {
            throw LifecycleTransitionRefused::withoutReason($to, $document->id);
        }

        DB::transaction(function () use ($document, $from, $to, $reason, $byUserId): void {
            $document->forceFill(match ($to) {
                Lifecycle::Voided => [
                    'lifecycle' => $to,
                    'voided_at' => now(),
                    'void_reason' => $reason,
                ],
                Lifecycle::Archived => [
                    'lifecycle' => $to,
                    'archived_at' => now(),
                ],
                default => ['lifecycle' => $to],
            })->save();

            $this->audit->operational('documents', $document->id, $from->value, $to->value, $reason, [
                'title' => $document->title,
            ], actorId: $byUserId);

            if ($to === Lifecycle::Voided) {
                // Voiding is on the security trail as well as the state trail: §4.4 lists it as a
                // kind of its own because "who denied this document" is a question with a different
                // answer from "who closed the record".
                $this->audit->security(SecurityEventKind::DocumentVoided, [
                    'document_id' => $document->id,
                    'from' => $from->value,
                    'reason' => $reason,
                ], $byUserId);
            }
        });

        return $document->refresh();
    }
}
