<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Alerts\Data;

use App\Domain\Kernel\Alerts\Models\Alert;

/**
 * What raising an alert actually did, because the caller has to know whether to tell anybody.
 *
 * The engine only delivers when `isNew` is true. That single flag is the cooldown sentence — "a
 * nightly job cannot email the same expiring licence fourteen times" — turned into a return value
 * instead of a counter someone has to remember to check, and it is the reason the reminder path can run
 * every night without the Owner's inbox becoming the company's log.
 *
 * `refreshed` is the other honest answer: the same condition seen again while its alert is still live.
 * No row was created and nobody was told, but the row's `last_seen_at` moved — which is what lets the
 * register say "still failing, seen 6 times since Monday" rather than "failing, raised Monday".
 */
final readonly class RaiseOutcome
{
    public function __construct(
        public Alert $alert,
        public bool $isNew,
        public bool $refreshed = false,
        public bool $escalated = false,
    ) {}

    /**
     * Whether this run should go out again.
     *
     * A brand new finding always does. A refresh does only when the condition got *worse* — an
     * approaching expiry crossing another threshold band, or a warning becoming a breach — because the
     * register keeps one row per condition instance and the ladder still has to be heard. Everything
     * else stays quiet, which is the cooldown sentence in one method.
     */
    public function shouldDeliver(): bool
    {
        return $this->isNew || $this->escalated;
    }
}
