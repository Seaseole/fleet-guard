<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Documents\Support;

use App\Domain\Kernel\Documents\DocumentStore;
use App\Domain\Kernel\Documents\Lifecycle;
use App\Domain\Kernel\Documents\Models\Document;
use App\Domain\Kernel\Documents\Models\DocumentVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The `sha256`, actually re-checked. A digest nobody compares with the file is a claim sitting in
 * a column, and the interesting failures — an object replaced, truncated in a bucket sync, deleted by
 * hand — are invisible until somebody reads the bytes again.
 *
 * Five findings, ordered by what they mean:
 *
 *  - **missing** — the row is right and the disk is not; a storage or backup problem, and the reason
 *    Replicates the private bucket;
 *  - **digest** — the object exists and is *different*; the most serious thing this can see, because
 *    it looks exactly like a healthy row;
 *  - **size** — a truncated write, usually the same incident as digest and worth naming separately
 *    because a partial PDF opens;
 *  - **no current copy** — a live document whose only versions are gone, which the register renders as
 *    an empty cell and an inspector reads as a missing document;
 *  - **orphan bytes** — an object with no row, invisible to every screen and unbounded in cost. The
 *    upload Action deletes its own on failure, so an orphan means something else happened.
 *
 * Reads are scope-free on purpose: this is the sanctioned cross-company path (T4) and a per-tenant
 * sweep would report a clean bill of health for the tenants it never looked at.
 */
final class DocumentIntegrityVerifier
{
    public function __construct(private readonly DocumentStore $store) {}

    /**
     * @return list<array{kind: string, company?: int, document_id: string, version_id: string|null, detail: string}>
     */
    public function findings(): array
    {
        $findings = [];

        /** @var array<string, true> $indexed disk:path pairs the index rows account for */
        $indexed = [];

        // A cursor, not `get()`: this sweep walks the whole archive (the no-unbounded-get rule
        // applies most strongly to the command that exists to read all of it).
        /** @var DocumentVersion $version */
        foreach (DocumentVersion::query()->withoutGlobalScopes()->orderBy('id')->cursor() as $version) {
            $indexed[$version->disk.':'.$version->path] = true;

            if (! $this->store->exists($version)) {
                $findings[] = [
                    'kind' => 'missing',
                    'company' => (int) $version->company_id,
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'detail' => "recorded on \"{$version->disk}\" at \"{$version->path}\", not present",
                ];

                continue;
            }

            $digest = $this->store->digest($version);

            if ($digest !== null && ! hash_equals($version->sha256, $digest)) {
                $findings[] = [
                    'kind' => 'digest',
                    'company' => (int) $version->company_id,
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'detail' => "recorded {$version->sha256}, on disk {$digest}",
                ];

                continue;
            }

            $size = Storage::disk($version->disk)->size($version->path);

            if ($size !== $version->size_bytes) {
                $findings[] = [
                    'kind' => 'size',
                    'company' => (int) $version->company_id,
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'detail' => "recorded {$version->size_bytes} bytes, on disk {$size}",
                ];
            }
        }

        // One grouped query answers both lifecycle findings — a live document with no current copy, and
        // (impossible by index, so worth re-checking) a document with two. Doing it here rather than
        // through `$document->currentVersion` also keeps the sweep free of a query per document, and of
        // the tenant scope: the relation would ask `CurrentCompany::id()` in a command that has no
        // request to answer it.
        /** @var array<string, int> $currents document_id => how many rows claim to be current */
        $currents = DocumentVersion::query()->withoutGlobalScopes()
            ->select('document_id')
            ->addSelect(DB::raw('count(*) as total'))
            ->where('is_current', true)
            ->groupBy('document_id')
            ->pluck('total', 'document_id')
            ->mapWithKeys(static fn ($total, $id): array => [(string) $id => (int) $total])
            ->all();

        Document::query()->withoutGlobalScopes()
            ->whereIn('lifecycle', [Lifecycle::Current->value, Lifecycle::Superseded->value])
            ->orderBy('id')
            ->chunk(200, function (Collection $documents) use (&$findings, $currents): void {
                /** @var Document $document */
                foreach ($documents as $document) {
                    if (($currents[$document->id] ?? 0) === 0) {
                        $findings[] = [
                            'kind' => 'no current copy',
                            'company' => (int) $document->company_id,
                            'document_id' => $document->id,
                            'version_id' => null,
                            'detail' => "lifecycle is {$document->lifecycle->value} with no version flagged current",
                        ];
                    }
                }
            });

        // The unique index makes two current versions impossible; counting them anyway is how a
        // future migration that dropped that index gets reported rather than discovered.
        foreach ($currents as $documentId => $total) {
            if ($total > 1) {
                // The company is looked up rather than carried in the grouped map, because this branch is
                // the one the unique index makes impossible: paying a query in the case that cannot
                // happen is cheaper than widening the sweep's aggregate for every document on disk.
                $findings[] = [
                    'kind' => 'two current versions',
                    'company' => (int) Document::query()->withoutGlobalScopes()->whereKey($documentId)->value('company_id'),
                    'document_id' => $documentId,
                    'version_id' => null,
                    'detail' => "{$total} rows flagged current — document_versions_one_current_unique is not holding",
                ];
            }
        }

        foreach (Storage::disk(DocumentStore::Disk)->allFiles() as $path) {
            if (! isset($indexed[DocumentStore::Disk.':'.$path])) {
                // The path is `company/{id}/…`, so an orphan usually still names the tenant whose
                // bytes it is. One that does not is a stray object at the disk root: the finding is real,
                // but there is no honest company to put it on, and the alert refuses to guess.
                $segments = explode('/', str_replace('\\', '/', $path));
                $at = array_search('company', $segments, true);

                $finding = [
                    'kind' => 'orphan bytes',
                    'document_id' => '—',
                    'version_id' => null,
                    'detail' => "\"{$path}\" is on the private disk with no version row behind it",
                ];

                if ($at !== false && isset($segments[$at + 1]) && ctype_digit($segments[$at + 1])) {
                    $finding = ['company' => (int) $segments[$at + 1]] + $finding;
                }

                $findings[] = $finding;
            }
        }

        return $findings;
    }

    public function versionCount(): int
    {
        return DocumentVersion::query()->withoutGlobalScopes()->count();
    }

    public function documentCount(): int
    {
        return Document::query()->withoutGlobalScopes()->count();
    }
}
