<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the company the current request or job belongs to (DESIGN.md).
 *
 * Resolution order is deliberate: an explicit force (CLI `--company=1`, a queued job carrying
 * its own company_id), then the authenticated user. Never the URL, a header or a session value
 * — a client-supplied tenant id is a horizontal privilege escalation waiting to happen.
 */
final class CurrentCompany
{
    private static ?int $forcedId = null;

    private static ?int $memo = null;

    private static bool $memoised = false;

    private static ?Company $model = null;

    /** The company id, or throw. Every scoped read goes through here. */
    public static function id(): int
    {
        return self::tryId() ?? throw MissingCompanyContext::forRead();
    }

    /** Non-throwing variant, used by the scope and by callers that legitimately allow guests. */
    public static function tryId(): ?int
    {
        if (self::$forcedId !== null) {
            return self::$forcedId;
        }

        if (! self::$memoised) {
            /** @var User|null $user */
            $user = Auth::guard()->user();

            self::$memo = $user?->company_id;
            self::$memoised = true;
        }

        return self::$memo;
    }

    /**
     * T4's single read path — `CurrentCompany::model()->base_currency_code` instead of
     * scattered `config()` calls. Memoised: it is read on almost every screen.
     */
    public static function model(): Company
    {
        $id = self::id();

        if (self::$model === null || self::$model->id !== $id) {
            // Company is the tenant root, so it is the one model that is never scoped by it.
            self::$model = Company::query()->findOrFail($id);
        }

        return self::$model;
    }

    /**
     * The company's business day, as a timezone name (UTC stores, the company zone displays).
     *
     * Anything that compares a *date* to today has to use this and not `now()`: an expiry derived
     * against the storage zone would report a licence as expired two hours early in Gaborone, and
     * "your truck is not roadworthy today" is not a rounding error.
     */
    public static function timezone(): string
    {
        return self::model()->timezone;
    }

    /**
     * Pin the context. Used by the seeder, by CLI commands that require `--company`, and by
     * queued jobs, which have no authenticated user at all (D4 fan-out).
     *
     * It clears the memo but not the pin — `force()` *is* the pin, so calling forget() here
     * would immediately undo it.
     */
    public static function force(?int $companyId): void
    {
        self::$forcedId = $companyId;
        self::clearMemo();
    }

    /**
     * The explicit pin, and only the pin — never the user-derived memo.
     *
     * A command or sweep that pins a company for its own loop has to put back what it found, not
     * unconditionally `forget()`. `Artisan::call('documents:verify-integrity')` from inside a verifier
     * that had already pinned company 1 used to leave the *verifier* without a context afterwards: the
     * nested command's cleanup was correct for the scheduler and destructive for the caller. Reading the
     * pin is what lets the callee restore rather than erase.
     */
    public static function pin(): ?int
    {
        return self::$forcedId;
    }

    /**
     * Drop the entire context, pin included. Called between requests under Octane and after
     * every queued job: a worker is one long-lived process, so a job that pinned company 1 and
     * left it set would run the next job — possibly another company's — against the wrong
     * tenant. That leak is precisely what T3 exists to make impossible.
     */
    public static function forget(): void
    {
        self::$forcedId = null;
        self::clearMemo();
    }

    private static function clearMemo(): void
    {
        self::$memo = null;
        self::$memoised = false;
        self::$model = null;
    }

    public static function isForced(): bool
    {
        return self::$forcedId !== null;
    }
}
