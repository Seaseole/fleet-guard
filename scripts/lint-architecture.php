<?php

declare(strict_types=1);

/**
 * Architecture lint for the dependency rules in DESIGN.md.
 *
 * Deliberately dumb: it greps import statements and a handful of write-path patterns.
 * There is no committed test suite, so the module boundaries have to be
 * machine-checkable by something that is as cheap to run as the formatter.
 *
 * Exit code 1 on any violation. Run via `composer lint:architecture`.
 */
final class ArchitectureLint
{
    /**
     * Modules allowed to exist under app/Domain. A directory outside this list is a
     * typo'd module that would silently escape the cross-module rules below.
     */
    private const MODULES = [
        'Kernel', 'Fleet', 'Operations', 'Fuel', 'Maintenance', 'Invoicing',
        'Accounting', 'People', 'CrossBorder', 'Telematics', 'Reporting', 'Security',
    ];

    /**
     * D5 allowlist: qualified edges that are permitted to import another module's
     * models. Keyed "FromModule" => ["ToModule", ...]. Keep each entry short and
     * justified; a growing list here means the module map is dissolving (D5).
     */
    private const CROSS_MODULE_MODEL_EDGES = [
        // A route rate is "what this customer pays on this corridor", so Operations names the
        // Invoicing module's Customer on `route_rates` and in its rate Actions. The map already
        // draws OPS --> INV; this entry is that edge made explicit rather than hidden in an import.
        //
        // The `completed → closed` witness: closing a trip that advanced driver cash asks
        // People's settlement sheet for a signature — one exists query, in the one direction the
        // map draws (OPS --> PEOPLE); People reads the trip only through ids the screen hands it.
        'Operations' => ['Invoicing', 'People'],
        // The design gives a part an optional "specific part" truck, and draws MAINT --> FLEET. The
        // edge is one nullable FK and the label the catalogue prints, nothing more.
        //
        // The work order widens it, each edge one nullable FK: a breakdown names the `trip_id` it
        // stopped (Operations), an outside repair names the `supplier_id` that bills it (Accounting),
        // and the mechanic is an `employees` row (People). None of them writes into the other module —
        // they are the five columns the sketch already lists, made explicit here rather than hidden
        // in imports a reader would have to find.
        'Maintenance' => ['Fleet', 'Operations', 'Accounting', 'People'],
        // Draws FUEL --> OPS: an issue is fuel onto a trip, and the cost it posts is the trip's.
        // The FUEL_TANK/FUEL_ISSUE both carry `truck_id`, so Fleet is a second edge the map does
        // not draw but the schema does. A station or a card names the supplier that bills it
        // (`fuel_stations.supplier_id`), which is Accounting's model — three edges, each one FK and
        // a label, no writes.
        'Fuel' => ['Fleet', 'Operations', 'Accounting'],
        // The module table gives Reporting "report library, export queue, scheduled report
        // setup", and the decision 4 requires profitability reports to read the *operational*
        // tables with the ledger kept as the statement of record — a report that could not reach
        // the other modules' rows could not be built at all. Every one of these edges is a query:
        // Reporting never writes another module's model (D1 keeps writes in Actions) and never
        // bypasses a gate (the catalogue asks the field doors per column).
        // The tie-out, not the import list, is what keeps the numbers honest.
        'Reporting' => ['Fleet', 'Fuel', 'Maintenance', 'Operations', 'Invoicing'],
        // Phase 5's 13-week forecast is an Accounting screen, and its *money-in* schedule
        // is the receivables book — which Invoicing owns (draws the ACC↔INV pair through the
        // ledger: invoices post revenue, receipts settle it). The edge is one read of `invoices`
        // rows' due_on/balance_base projections — the forecast never writes another module's row,
        // and the tie-out remains the referee between its prediction and the ledger's fact.
        //
        // The design adds the second edge, and already drew it in the schema: `loans.truck_id` is
        // "the truck the money bought", a names-only reference with no FK across module tables. Only
        // the cutover importer reads `trucks`, and only to turn the accountant's registration number
        // into that id — one query, no writes, and the write still goes through Accounting's own door.
        'Accounting' => ['Invoicing', 'Fleet'],
    ];

    /** @var list<string> */
    private array $violations = [];

    private string $root;

    public function __construct(string $root)
    {
        // Windows: __DIR__ arrives with backslashes while walked paths are normalised to
        // forward slashes, so the display-prefix strip has to compare like with like.
        $this->root = str_replace('\\', '/', rtrim($root, '/\\'));
    }

    public function run(): int
    {
        $files = array_merge(
            $this->phpFiles('app'),
            $this->phpFiles('database/migrations'),
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->checkKernelIsLeaf($file, $contents);
            $this->checkCrossModuleModelImports($file, $contents);
            $this->checkLedgerAccessIsThroughTheService($file, $contents);
            $this->checkWritePathGoesThroughAnAction($file, $contents);
            $this->checkUnknownDomainModule($file);
            $this->checkMoneyIsNeverDecimal($file, $contents);
        }

        return $this->report(count($files));
    }

    /** D2 — the Kernel may not know any module exists. */
    private function checkKernelIsLeaf(string $file, string $contents): void
    {
        if (! $this->inDir($file, 'app/Domain/Kernel')) {
            return;
        }

        foreach ($this->imports($contents) as $import) {
            if (! preg_match('#^App\\\\Domain\\\\([A-Z][A-Za-z]*)#', $import, $m)) {
                continue;
            }

            if ($m[1] !== 'Kernel') {
                $this->fail($file, "D2: Kernel imports module {$m[1]} ({$import}) — the shared layer may not depend on a module");
            }
        }
    }

    /** D5 — a module may not reach into another module's models. */
    private function checkCrossModuleModelImports(string $file, string $contents): void
    {
        $from = $this->moduleOf($file);

        if ($from === null || $from === 'Kernel') {
            return;
        }

        $allowed = self::CROSS_MODULE_MODEL_EDGES[$from] ?? [];

        foreach ($this->imports($contents) as $import) {
            if (! preg_match('#^App\\\\Domain\\\\([A-Z][A-Za-z]*)\\\\Models\\\\#', $import, $m)) {
                continue;
            }

            $to = $m[1];

            if ($to !== $from && ! in_array($to, $allowed, true)) {
                $this->fail($file, "D5: module {$from} imports {$to}'s model ({$import}) — use that module's DTO/read model or an edge declared in CROSS_MODULE_MODEL_EDGES");
            }
        }
    }

    /** D3 — posting to the ledger happens in exactly one place. */
    private function checkLedgerAccessIsThroughTheService(string $file, string $contents): void
    {
        if ($this->inDir($file, 'app/Domain/Kernel/Ledger')) {
            return;
        }

        // `LineDto`, `Side` and `PostingDto` are the *input vocabulary* of the one door, not an
        // alternative to it: a module cannot build a posting without naming its lines. The ban is
        // on the models and the barrier, which reading or writing directly would bypass the service.
        // The `$` anchor says so: `Data\AccountType` is the vocabulary of the ChartOfAccounts
        // projection (a module comparing an account's statement axis must name its enum), while
        // `Models\Account` ends exactly at the ban — unanchored, the prefix spelled the enum a
        // crime it cannot commit.
        foreach ($this->imports($contents) as $import) {
            if (preg_match('#^App\\\\Domain\\\\Kernel\\\\Ledger\\\\(Models|Data)\\\\(JournalEntry|JournalLine|Account|Period|LedgerBarrier)$#', $import, $m)) {
                $this->fail($file, "D3: touches ledger internals ({$m[2]}) — modules post through LedgerService::post(PostingDto)");
            }
        }

        // Migrations are the schema's own definition, so naming gl_* tables is their job. The rule
        // is about application code reaching past the door, which is what the loop above and the
        // table scan below catch.
        if ($this->inDir($file, 'database/migrations')) {
            return;
        }

        // The real table names. The pattern this replaces guessed at `gl_entries`/`gl_lines`,
        // so every reference to `gl_journal_entries` outside the Kernel passed unnoticed.
        if (preg_match('/\bgl_(journal_entries|journal_lines|accounts|periods|ledger_barrier)\b/', $contents, $m)) {
            $this->fail($file, "D3: references the {$m[0]} table directly — post through LedgerService::post(PostingDto)");
        }
    }

    /** D1 — HTTP/queue/Livewire entry points validate, then call one Action. */
    private function checkWritePathGoesThroughAnAction(string $file, string $contents): void
    {
        $guarded = $this->inDir($file, 'app/Http/Controllers')
            || $this->inDir($file, 'app/Livewire')
            || $this->inDir($file, 'app/Console/Commands');

        if (! $guarded) {
            return;
        }

        // Reads are fine; these are the writes that belong in an Action.
        $patterns = [
            '/->save\(\s*\)/' => '->save()',
            '/->forceDelete\(\s*\)/' => '->forceDelete()',
            '/::(?:force)?create\(/' => '::create()',
            '/\bDB::table\([^)]*\)->(?:insert|update|delete|increment|decrement)\b/' => 'DB::table(...) write',
        ];

        foreach ($patterns as $regex => $label) {
            if (preg_match($regex, $contents)) {
                $this->fail($file, "D1: entry point performs a write via {$label} — build a DTO and call the Action");
            }
        }
    }

    private function checkUnknownDomainModule(string $file): void
    {
        if (! preg_match('#app/Domain/([A-Z][A-Za-z]*)/#', str_replace('\\', '/', $file), $m)) {
            return;
        }

        if (! in_array($m[1], self::MODULES, true)) {
            $this->fail($file, "unknown app/Domain module '{$m[1]}' — the design lists: ".implode(', ', self::MODULES));
        }
    }

    /**
     * D6 — money is integer minor units. `fx_rate decimal(19,8)` is the sanctioned
     * exception: a rate is not an amount.
     */
    private function checkMoneyIsNeverDecimal(string $file, string $contents): void
    {
        if (! $this->inDir($file, 'database/migrations')) {
            return;
        }

        // $table->decimal('total_amount', ...) / ->double('x') / ->float('x')
        if (preg_match('/\$(?:table|column)->(decimal|double|float|real)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)) {
            $name = $m[2];

            if (preg_match('/(amount|total|cost|price|balance|paid|due)_?/i', $name) && ! str_contains($name, 'fx_rate')) {
                $this->fail($file, "D6: money column '{$name}' is {$m[1]} — use integer minor units");
            }
        }

        // Any *_amount column in a migration must have a currency code beside it.
        if (preg_match('/->(integer|unsignedBigInteger|bigInteger|smallInteger)\s*\(\s*[\'"]([a-z_]*amount)[\'"]/i', $contents, $m)
            && ! preg_match('/currency_code/', $contents)) {
            $this->fail($file, "D6: money column '{$m[2]}' has no currency_code column alongside it");
        }
    }

    /** @return list<string> */
    private function imports(string $contents): array
    {
        preg_match_all('/^use\s+(App\\\\[A-Za-z0-9_\\\\]+)/m', $contents, $m);

        return $m[1] ?? [];
    }

    private function moduleOf(string $file): ?string
    {
        $path = str_replace('\\', '/', $file);

        return preg_match('#app/Domain/([A-Z][A-Za-z]*)/#', $path, $m) ? $m[1] : null;
    }

    private function inDir(string $file, string $relativeDir): bool
    {
        return str_contains(str_replace('\\', '/', $file), $relativeDir);
    }

    /** @return list<string> */
    private function phpFiles(string $relativeDir): array
    {
        $dir = $this->root.'/'.$relativeDir;

        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function fail(string $file, string $message): void
    {
        $this->violations[] = str_replace($this->root.'/', '', $file).': '.$message;
    }

    private function report(int $fileCount): int
    {
        if ($this->violations === []) {
            echo "architecture lint: PASS ({$fileCount} files, rules D1-D6)\n";

            return 0;
        }

        fwrite(STDERR, sprintf("architecture lint: FAIL — %d violation(s)\n\n", count($this->violations)));

        foreach ($this->violations as $violation) {
            fwrite(STDERR, '  '.$violation."\n");
        }

        fwrite(STDERR, "\nRules: DESIGN.md (D1 entry points delegate to Actions, D2 Kernel is a leaf, D3 one ledger door, D5 no cross-module model reach, D6 money is never decimal).\n");

        return 1;
    }
}

exit((new ArchitectureLint(dirname(__DIR__)))->run());
