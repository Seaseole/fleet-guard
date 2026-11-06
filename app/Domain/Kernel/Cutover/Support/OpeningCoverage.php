<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

use App\Domain\Kernel\Cutover\CutoverBatchStatus;
use App\Domain\Kernel\Cutover\CutoverDataset;
use App\Domain\Kernel\Cutover\Models\CutoverBatch;

/**
 * Who owns an account on cutover day.
 *
 * Two files can put money on `1150`: the opening **trial balance**, which posts the control total the
 * accountant's schedule adds up to, and the **opening receivables** intake, which posts one line per
 * unpaid document so the ageing report, the customer statement and the payable tie-out have
 * something to read. Both are correct answers to the same question, and posting both is how a balance
 * sheet ends up owing the customers twice — with the ledger's own arithmetic agreeing with itself the
 * whole way down.
 *
 * So the order is decided in one place and asked of both doors:
 *
 * - the trial-balance validator refuses a line on an account an **applied** party-detail batch owns;
 * - a party-detail handler refuses to apply when a **posted** trial balance already carries its
 *   account, naming the file that got there first.
 *
 * The Kernel asks no module for anything: `cutover_batches` and `cutover_rows` are the engine's own
 * tables, and the account each detail dataset owns is a fact about the file, carried on
 * {@see CutoverDataset::coversAccount()} rather than repeated in two modules.
 */
final class OpeningCoverage
{
    /**
     * The control accounts already owned by an applied party-detail batch, keyed by account code.
     *
     * A reversed or rejected batch owns nothing — it wrote no documents and posted no entry, so its
     * account is still the trial balance's to carry.
     *
     * @return array<string, array{dataset: CutoverDataset, filename: string}>
     */
    public static function openedByDetail(): array
    {
        $covered = [];

        foreach (CutoverDataset::openingDetailDatasets() as $dataset) {
            $code = $dataset->coversAccount();

            if ($code === null) {
                continue;
            }

            $batch = CutoverBatch::query()
                ->where('dataset', $dataset->value)
                ->where('status', CutoverBatchStatus::Applied->value)
                ->latest('id')
                ->first(['id', 'original_filename']);

            if ($batch !== null) {
                $covered[$code] = [
                    'dataset' => $dataset,
                    'filename' => (string) ($batch->original_filename ?? 'an earlier upload'),
                ];
            }
        }

        return $covered;
    }

    /**
     * The sentence a party-detail batch must refuse by when a committed trial balance already carries
     * its account, or null when the field may be applied.
     */
    public static function blockedByTrialBalance(CutoverDataset $dataset): ?string
    {
        $code = $dataset->coversAccount();

        if ($code === null) {
            return null;
        }

        $batch = CutoverBatch::query()
            ->where('dataset', CutoverDataset::OpeningTrialBalance->value)
            ->where('status', CutoverBatchStatus::Posted->value)
            ->whereHas('rows', static fn ($rows) => $rows->where('account_code', $code))
            ->latest('id')
            ->first(['id', 'original_filename', 'posted_on']);

        if ($batch === null) {
            return null;
        }

        return sprintf(
            'Account %s is already on the ledger: the trial balance %s was committed on %s and carries a line for it. '
            .'Reverse that commit and post the file without the %s line first — one account has one opener.',
            $code,
            (string) ($batch->original_filename ?? 'file'),
            $batch->posted_on === null ? 'an earlier date' : $batch->posted_on->format('d M Y'),
            $code,
        );
    }
}
