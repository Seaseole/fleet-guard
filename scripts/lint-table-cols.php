<?php

declare(strict_types=1);

/**
 * Every register hides the same columns in its header as in each of its data rows.
 *
 * `hideBelow` on a `th` without its `td` — or the other way round — puts a label over the wrong figures,
 * which is worse than a table that scrolls: the row still looks complete, and the reader is quietly mislead.
 * A responsive column is really two edits that must agree, a header written in one place and cells written
 * in another, and the registers carrying one today will be edited by someone who never read the other half.
 *
 * Rows whose cells carry `colspan` are summary or empty-state rows: they are laid out by span rather than by
 * index, and the contract in docs/DESIGN-SYSTEM.md says how to keep one honest instead of judging it here.
 *
 * Run: php scripts/lint-table-cols.php   (composer lint:cols)
 */
final class ColumnLint
{
    /** @var list<string> */
    private array $violations = [];

    private int $tables = 0;

    private int $rows = 0;

    public function __construct(private readonly string $root) {}

    public function run(): int
    {
        foreach ($this->pageFiles() as $file) {
            $this->checkFile($file);
        }

        return $this->report();
    }

    private function checkFile(string $file): void
    {
        $contents = (string) file_get_contents($file);

        foreach ($this->tableSpans($contents) as [$start, $end]) {
            $block = substr($contents, $start, $end - $start);
            if (! str_contains($block, 'hideBelow')) {
                continue;
            }

            $this->tables++;
            $line = substr_count(substr($contents, 0, $start), "\n") + 1;

            preg_match_all('/<x-ui\.table\.th\b((?:(?!>)[\s\S])*)>/', $block, $heads, PREG_SET_ORDER);
            $headHidden = [];
            foreach ($heads as $i => $head) {
                $headHidden[$i] = str_contains($head[1], 'hideBelow');
            }

            preg_match_all('/<tr\b(?:(?!<\/tr>)[\s\S])*<\/tr>/', $block, $rows);
            $rowNo = 0;

            foreach ($rows[0] as $row) {
                preg_match_all('/<x-ui\.table\.td\b((?:(?!>)[\s\S])*)>/', $row, $cells, PREG_SET_ORDER);

                if ($cells === [] || str_contains($row, 'colspan')) {
                    continue;
                }

                $rowNo++;
                $this->rows++;

                if (count($cells) !== count($heads)) {
                    $this->fail($file, $line, sprintf(
                        'row %d has %d cells but the header declares %d columns — a cell sits inside a conditional branch, so a dropped column cannot be read positionally here',
                        $rowNo,
                        count($cells),
                        count($heads)
                    ));

                    continue;
                }

                foreach ($cells as $i => $cell) {
                    if (str_contains($cell[1], 'hideBelow') !== $headHidden[$i]) {
                        $this->fail($file, $line, sprintf(
                            'row %d column %d is %s while its header is %s',
                            $rowNo,
                            $i + 1,
                            str_contains($cell[1], 'hideBelow') ? 'dropped' : 'kept',
                            $headHidden[$i] ? 'dropped' : 'kept'
                        ));
                    }
                }
            }
        }
    }

    /**
     * Component-table instances, found with a depth counter so a nested table is not read as the end.
     *
     * @return list<array{int, int}>
     */
    private function tableSpans(string $contents): array
    {
        preg_match_all('/<x-ui\.table\b(?!\.)|<\/x-ui\.table>/', $contents, $marks, PREG_OFFSET_CAPTURE);

        $depth = 0;
        $spans = [];
        $openAt = null;

        foreach ($marks[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '</')) {
                $depth--;
                if ($depth === 0 && $openAt !== null) {
                    $spans[] = [$openAt, $offset + strlen($tag)];
                    $openAt = null;
                }
            } else {
                if ($depth === 0) {
                    $openAt = $offset;
                }
                $depth++;
            }
        }

        return $spans;
    }

    /** @return list<string> */
    private function pageFiles(): array
    {
        $files = [];
        $pages = $this->root.'/resources/views/pages';

        if (is_dir($pages)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages)) as $entry) {
                if ($entry instanceof SplFileInfo && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = str_replace('\\', '/', $entry->getPathname());
                }
            }
        }

        sort($files);

        return $files;
    }

    private function fail(string $file, int $line, string $message): void
    {
        $this->violations[] = $this->rel($file).":{$line}: {$message}";
    }

    private function rel(string $path): string
    {
        return str_replace('\\', '/', str_replace($this->root.'/', '', $path));
    }

    private function report(): int
    {
        if ($this->violations === []) {
            echo "column lint: PASS ({$this->tables} table(s) drop a column, {$this->rows} data row(s) agree)\n";

            return 0;
        }

        foreach ($this->violations as $violation) {
            echo $violation."\n";
        }

        echo 'column lint: FAIL ('.count($this->violations)." violation(s))\n";

        return 1;
    }
}

exit((new ColumnLint(dirname(__DIR__)))->run());
