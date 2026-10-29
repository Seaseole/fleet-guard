<?php

declare(strict_types=1);

/**
 * Citation lint for the § references scattered through the source.
 *
 * Code cites the design contract by section (DESIGN.md, docs/DESIGN-SYSTEM.md), and a citation is a
 * claim about a document that gets rewritten as decisions land — §10.8 was re-worded after slice 7
 * shipped. Without a checker a citation rots silently and the next reader follows it to a heading
 * that says something else. There is no committed test suite (§14.6), so this is the same regime as
 * the architecture lint: static, no framework bootstrap, as cheap to run as the formatter.
 *
 * Three failure shapes, each one found by the first pass over this repo:
 *   DANGLING    — no heading by that number in either document.
 *   AMBIGUOUS   — the number exists only in the non-default document, cited without naming it.
 *   LINE-NUMBER — a 3+ digit number, which is a source line pasted into section syntax (the alerts
 *                 reason rule at DESIGN.md §10.1 was cited that way for four files).
 *
 * Exit code 1 on any violation. Run via `composer lint:section-refs`.
 */
final class SectionRefLint
{
    /**
     * Documents a citation can point into, keyed by the name an author writes on the line.
     * The first is the default: a bare `§N.M` reads against it, so anything aimed at the UI
     * contract has to name it.
     *
     * @var array<string, string>
     */
    private const DOCS = [
        'DESIGN.md' => 'DESIGN.md',
        'DESIGN-SYSTEM.md' => 'docs/DESIGN-SYSTEM.md',
    ];

    private const DEFAULT_DOC = 'DESIGN.md';

    /** Directories walked for citations. */
    private const SOURCE_DIRS = ['app', 'database', 'resources', 'routes', 'config', 'scripts'];

    private const SOURCE_EXTENSIONS = ['php', 'md', 'js'];

    /** Compiled Blade, vendored code and built assets inherit citations from files they were built from. */
    private const SKIP_PATH_FRAGMENTS = ['/vendor/', '/node_modules/', '/storage/framework/', '/public/build/'];

    /** @var list<string> */
    private array $violations = [];

    /** @var array<string, array<string, string>> doc name => anchor => heading text */
    private array $anchors = [];

    private string $root;

    public function __construct(string $root)
    {
        // Windows: __DIR__ arrives with backslashes while walked paths are normalised to forward
        // slashes, so the display-prefix strip and the skip fragments need one form.
        $this->root = str_replace('\\', '/', rtrim($root, '/\\'));
    }

    /**
     * Physical lines, matching what an editor and `grep -n` number. `/\R/` is not usable here: this
     * documentation is UTF-8 and contains bytes that PCRE reads as NEL outside unicode mode, so
     * splitting on it reports line numbers that drift ahead of the file.
     *
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        return preg_split('/\r\n|\r|\n/', $contents);
    }

    public function run(): int
    {
        foreach (array_keys(self::DOCS) as $name) {
            $this->anchors[$name] = $this->readAnchors($name);
        }

        $files = array_merge($this->sourceFiles(), $this->docFiles());
        $refCount = 0;

        foreach ($files as $file) {
            foreach ($this->citations((string) file_get_contents($file)) as $citation) {
                $refCount++;
                $this->check($file, $citation);
            }
        }

        return $this->report(count($files), $refCount);
    }

    /**
     * Anchors a document defines: its `## N.` and `### N.M` headings, plus — for a section with no
     * `###` subsections — its numbered list items, which is how `DESIGN-SYSTEM.md §1.5` (principle 5)
     * is addressed. A section that has subsections is never cited by list position, so the fallback
     * cannot invent anchors in DESIGN.md §1, whose list is bulleted.
     *
     * @return array<string, string>
     */
    private function readAnchors(string $docName): array
    {
        $path = $this->root.'/'.self::DOCS[$docName];

        if (! is_file($path)) {
            $this->violations[] = "document '{$docName}' is missing at ".self::DOCS[$docName];

            return [];
        }

        $lines = $this->lines((string) file_get_contents($path));
        $hasSubsections = [];
        $anchors = [];

        foreach ($lines as $line) {
            if (preg_match('/^###\s+(\d+)\.\d+/', $line, $m)) {
                $hasSubsections[$m[1]] = true;
            }
        }

        $currentTop = null;

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(\d+)\.?\s+(.*)$/', $line, $m)) {
                $anchors[$m[1]] = trim($m[2]);
                $currentTop = $m[1];

                continue;
            }

            if (preg_match('/^###\s+(\d+\.\d+)\.?\s+(.*)$/', $line, $m)) {
                $anchors[$m[1]] = trim($m[2]);

                continue;
            }

            if ($currentTop !== null && ! isset($hasSubsections[$currentTop])
                && preg_match('/^\s*(\d+)\.\s+\*\*(.+?)\*\*/', $line, $m)) {
                $anchors["{$currentTop}.{$m[1]}"] = trim($m[2]);
            }
        }

        return $anchors;
    }

    /**
     * @return list<array{line: int, anchor: string, doc: string}>
     */
    private function citations(string $contents): array
    {
        $found = [];

        foreach ($this->lines($contents) as $index => $line) {
            if (! str_contains($line, '§')) {
                continue;
            }

            preg_match_all('/§\s*(\d+(?:\.\d+)?)/', $line, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as $match) {
                $found[] = [
                    'line' => $index + 1,
                    'anchor' => $match[0],
                    'doc' => $this->attributedDoc(substr($line, 0, $match[1])),
                ];
            }
        }

        return $found;
    }

    /**
     * Which document a citation reads against: the name written closest in front of it, so a line
     * that mentions the UI contract and then cites `DESIGN.md §20.2` is still a DESIGN.md citation.
     */
    private function attributedDoc(string $before): string
    {
        $doc = self::DEFAULT_DOC;
        $position = -1;

        foreach (array_keys(self::DOCS) as $candidate) {
            $at = strrpos($before, $candidate);

            if ($at !== false && $at > $position) {
                $doc = $candidate;
                $position = $at;
            }
        }

        return $doc;
    }

    /** @param array{line: int, anchor: string, doc: string} $citation */
    private function check(string $file, array $citation): void
    {
        $anchor = $citation['anchor'];
        $doc = $citation['doc'];
        $other = $doc === self::DEFAULT_DOC ? 'DESIGN-SYSTEM.md' : self::DEFAULT_DOC;

        if (strlen(explode('.', $anchor)[0]) >= 3) {
            $this->fail($file, $citation, sprintf(
                'line-number citation: §%s is not a section of %s — that is a source line written in section '
                .'syntax. %s',
                $anchor,
                $doc,
                isset($this->anchors[$other][$anchor])
                    ? "It is §{$anchor} of {$other}, so name the document."
                    : 'Cite the heading the rule actually sits under.'
            ));

            return;
        }

        if (isset($this->anchors[$doc][$anchor])) {
            return;
        }

        if (isset($this->anchors[$other][$anchor])) {
            $this->fail($file, $citation, sprintf(
                'ambiguous citation: §%s is %s\'s "%s", but a bare § reads against %s — name the document on the line.',
                $anchor,
                $other,
                $this->anchors[$other][$anchor],
                $doc
            ));

            return;
        }

        $this->fail($file, $citation, sprintf(
            'dangling citation: §%s heads nothing in %s. %s',
            $anchor,
            implode(' or ', array_keys(self::DOCS)),
            $this->hint($anchor, $doc)
        ));
    }

    /** Suggest the closest real anchor, which is normally a renumbered section rather than an invention. */
    private function hint(string $anchor, string $doc): string
    {
        $top = explode('.', $anchor)[0];

        if (isset($this->anchors[$doc][$top])) {
            $siblings = array_values(array_filter(
                array_keys($this->anchors[$doc]),
                fn (string $candidate): bool => str_starts_with($candidate, $top.'.')
            ));

            sort($siblings, SORT_NATURAL);

            return $siblings === []
                ? "§{$top} ({$this->anchors[$doc][$top]}) has no subsections."
                : '§'.$top.' has: '.implode(', ', $siblings).'.';
        }

        return "There is no section {$top} in this document.";
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        foreach (self::SOURCE_DIRS as $dir) {
            $absolute = $this->root.'/'.$dir;

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::SOURCE_EXTENSIONS, true)) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (! $this->isSkipped($path)) {
                    $files[] = $path;
                }
            }
        }

        $files[] = $this->root.'/README.md';

        sort($files);

        return array_values(array_unique($files));
    }

    /** @return list<string> */
    private function docFiles(): array
    {
        return array_values(array_filter(
            array_map(fn (string $rel): string => $this->root.'/'.$rel, self::DOCS),
            'is_file'
        ));
    }

    private function isSkipped(string $path): bool
    {
        foreach (self::SKIP_PATH_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{line: int, anchor: string, doc: string} $citation */
    private function fail(string $file, array $citation, string $message): void
    {
        $this->violations[] = str_replace($this->root.'/', '', str_replace('\\', '/', $file))
            .":{$citation['line']}: {$message}";
    }

    private function report(int $fileCount, int $refCount): int
    {
        if ($this->violations === []) {
            echo "section-reference lint: PASS ({$refCount} citations across {$fileCount} files resolve to a heading)\n";

            return 0;
        }

        fwrite(STDERR, sprintf("section-reference lint: FAIL — %d unresolved citation(s)\n\n", count($this->violations)));

        foreach ($this->violations as $violation) {
            fwrite(STDERR, '  '.$violation."\n");
        }

        fwrite(STDERR, "\nCitations point at numbered headings in ".implode(' or ', array_keys(self::DOCS))
            .'; a bare § reads against '.self::DEFAULT_DOC.", which numbers its sections with headings only.\n");

        return 1;
    }
}

exit((new SectionRefLint(dirname(__DIR__)))->run());
