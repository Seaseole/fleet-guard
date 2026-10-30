<?php

declare(strict_types=1);

/**
 * UI lint for the promises in docs/DESIGN-SYSTEM.md that a human can only keep by machine.
 *
 * Same shape as lint-architecture.php: dumb, textual, no dependencies, exit 1 on any violation. There is
 * no committed test suite (§14.6), so the design contract needs the same treatment as the module
 * boundaries — a rule nobody can check is a rule that quietly stops being true. This file exists because
 * `x-ui.button` defaulted to `primary` and nothing measured how many times that put two filled buttons in
 * one container (docs/UX-UI-PLAN.md P0-1).
 *
 * Rules:
 *   U1  one primary per view. A view is the page outside any `<x-ui.dialog>`, or one dialog — a native
 *       `<dialog>` makes the page behind it inert, so a dialog may carry its own affirmative action. Inline
 *       forms do *not* get their own budget: their submits are on screen together with each other.
 *   U2  no raw `<select>` in a page component — the design system's controls only (§13, `x-ui.select`).
 *   U3  every `<x-ui.table>` carries `:caption` — the accessible name of the table (WCAG, §10).
 *   U4  a `role="tab"` may not appear without its `tabpanel` and `aria-controls` — half a WAI-ARIA
 *       pattern is a promise a screen reader cannot keep.
 *   U5  one numeric path in a table: a component cell asks for `numeric`/`identifier`/`nowrap`, it does not
 *       re-type the classes; and no cell hand-copies `text-right font-mono`, which is how the same Amount
 *       column came to read differently on two screens (docs/UX-UI-PLAN.md P3-1).
 *
 * Run: php scripts/lint-ui.php   (composer lint:ui)
 */
final class UiLint
{
    private const PAGES_GLOB = 'resources/views/pages/*/*.blade.php';

    private const BUTTON_COMPONENT = 'resources/views/components/ui/button.blade.php';

    /**
     * Rules that are live. A rule is only enabled once the codebase satisfies it, because a gate that fails
     * on 75 known future edits gets switched off entirely — so anything still open sits in PENDING below and
     * says so in the report rather than pretending the check does not exist.
     *
     * @var list<string>
     */
    private const ENABLED = ['U1', 'U2', 'U3', 'U4', 'U5'];

    /** @var array<string, string> */
    private const PENDING = [];

    /** @var list<string> */
    private array $violations = [];

    private int $containers = 0;

    private int $files = 0;

    public function __construct(private readonly string $root) {}

    public function run(): int
    {
        if (! $this->buttonFollowsTheSubmitRule()) {
            $this->fail(
                $this->rel(self::BUTTON_COMPONENT),
                'U1: the component no longer resolves an unstated variant as "primary if it submits a form" — update this lint to match, or the gate is reporting a codebase it is not reading',
            );

            return $this->report();
        }

        foreach ($this->pageFiles() as $file) {
            $this->files++;
            $this->checkContainers($file);
            $this->checkRolePatternsAreComplete($file);
            $this->checkSelectsAreComponents($file);
            $this->checkTablesHaveCaptions($file);
            $this->checkNumericTreatmentIsOnePath($file);
        }

        return $this->report();
    }

    /** @param list<array{key: string, tag: string}> $stack */
    private function openFrame(array &$stack, array &$scopes, string $key): void
    {
        $stack[] = ['key' => $key, 'tag' => str_starts_with($key, 'dialog:') ? 'dialog' : 'form'];
        $scopes[$key] = ['primary' => 0, 'buttons' => 0, 'lines' => []];
    }

    /**
     * Closes the nearest frame of this tag and returns the scope the walk continues in.
     *
     * Matching by tag rather than popping blindly, because Blade's `@if` blocks make it easy for a form to
     * open in one branch and close in another; the walk then has to keep going with the right view, not
     * whatever happens to be on top.
     *
     * @param  list<array{key: string, tag: string}>  $stack
     */
    private function closeFrame(array &$stack, string $tag, string $currentKey): string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i]['tag'] === $tag) {
                array_splice($stack, $i, 1);

                break;
            }
        }

        return $stack === [] ? 'page' : $stack[count($stack) - 1]['key'];
    }

    /**
     * U1. Two scopes, because that is what "one primary per view" means when a modal is involved:
     *
     * - **page** — everything not inside an `<x-ui.dialog>`. Inline `<form>`s count here, because their
     *   submits are on screen at the same time as each other; giving each form its own budget would let a
     *   page show four filled buttons and still "pass".
     * - **each dialog** — a native `<dialog>` makes the page behind it inert, so a dialog is its own view
     *   and may carry one affirmative action of its own.
     *
     * Textual, and it says where that limits it: an `@if` branch that can never render beside its sibling
     * still counts both buttons, because the lint cannot evaluate Livewire's PHP. On the trip page
     * `canDepart()` and `canComplete()` are mutually exclusive by state, so the two submits never share a
     * screen — and the lint cannot know that. The truthful per-view count therefore lives in the render
     * sweeps, which render one real state and can count what is actually on screen; this rule is the
     * coarse net that keeps the accident from coming back.
     */
    private function checkContainers(string $file): void
    {
        $contents = (string) file_get_contents($file);

        // Optional leading slash so closing tags are seen: without them a walk cannot know a dialog ended,
        // and every button after the first dialog would be counted inside it.
        $pattern = '#</?(x-ui\.dialog|form|x-ui\.button)\b((?:(?!>)[\s\S])*)>#';

        /** @var list<string> $stack */
        $stack = [];
        /** @var array<string, array{primary: int, buttons: int, lines: list<int>}> $scopes */
        $scopes = ['page' => ['primary' => 0, 'buttons' => 0, 'lines' => []]];
        $scopeKey = 'page';

        // A page may declare exclusive branches with `{{-- ui-scope: name (reason) --}}` immediately before a
        // `<form>`, and that form's buttons then count as their own view. It binds to the next form and
        // nothing else — a marker that re-scoped the rest of the file would quietly absorb a later
        // page-level button and hide the very conflict the rule exists to catch. The reason is mandatory in
        // the pattern because an unexplained claim is how a marker turns into a mute button.
        //
        // This is not a suppression. `canDepart()` and `canComplete()` (§10.2) are mutually exclusive states
        // the textual pass cannot evaluate, and the truthful per-view count lives in the render sweeps,
        // which render one real state and can count what is actually on screen.
        $markers = [];
        preg_match_all(
            '/\{\{--\s*ui-scope:\s*([a-z0-9-]+)\s*\(([^)]{20,})\)\s*--\}\}/',
            $contents,
            $markers,
            PREG_OFFSET_CAPTURE,
        );
        $markerIndex = 0;
        $pendingBranch = null;

        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            $offset = $match[0][1];

            while ($markerIndex < count($markers[0]) && $markers[0][$markerIndex][1] < $offset) {
                $pendingBranch = 'branch:'.$markers[1][$markerIndex][0];
                $markerIndex++;
            }

            $raw = ltrim($match[0][0]);
            $tag = $match[1][0];
            $attrs = preg_replace('/\s+/', ' ', trim($match[2][0])) ?? '';
            $line = substr_count(substr($contents, 0, $match[0][1]), "\n") + 1;
            $closing = str_starts_with($raw, '</');

            if ($tag === 'form') {
                if ($closing) {
                    $scopeKey = $this->closeFrame($stack, 'form', $scopeKey);

                    continue;
                }

                // Only a marked form becomes its own view; an unmarked one keeps contributing to the page,
                // which is what makes "two inline forms, two filled submits" a failure rather than a pass.
                if ($pendingBranch !== null) {
                    $this->openFrame($stack, $scopes, $pendingBranch);
                    $scopeKey = $pendingBranch;
                    $pendingBranch = null;
                }

                continue;
            }

            if ($tag === 'x-ui.dialog') {
                if ($closing) {
                    $scopeKey = $this->closeFrame($stack, 'dialog', $scopeKey);

                    continue;
                }

                $name = $this->capture($attrs, '/name\s*=\s*["\']([a-zA-Z_-]+)["\']/', 'dialog') ?? 'dialog';
                $key = $name.'@'.$line;
                $this->openFrame($stack, $scopes, $key);
                $scopeKey = $key;

                continue;
            }

            if ($closing || str_ends_with(trim($raw), '/>')) {
                continue;
            }

            $scopes[$scopeKey]['buttons']++;

            if ($this->isPrimary($attrs)) {
                $scopes[$scopeKey]['primary']++;
                $scopes[$scopeKey]['lines'][] = $line;
            }
        }

        foreach ($scopes as $key => $scope) {
            if ($scope['buttons'] === 0) {
                continue;
            }

            $this->containers++;

            if ($scope['primary'] > 1) {
                $this->fail(
                    $this->rel($file),
                    sprintf(
                        'U1: %d buttons resolve to primary in %s (lines %s) — §5 allows one affirmative action per view, and a dialog counts as its own',
                        $scope['primary'],
                        $key,
                        implode(', ', $scope['lines']),
                    ),
                );
            }
        }
    }

    /**
     * Does this button render filled?
     *
     * The same resolution as the component: an explicit literal variant wins, an explicit *expression*
     * (`variant="{{ ... ? 'outline' : 'ghost' }}"`) is a decision someone made and is never primary, and an
     * unstated one is primary only when it submits a form. `x-ui.button`'s `$variant ??= ...` line is the
     * other half of this rule, and {@see buttonFollowsTheSubmitRule()} fails the build if the two drift.
     */
    private function isPrimary(string $attrs): bool
    {
        if (preg_match("/variant\s*=\s*[\"']([a-z-]+)[\"']/", $attrs, $m) === 1) {
            return $m[1] === 'primary';
        }

        if (str_contains($attrs, 'variant=')) {
            return false;
        }

        return str_contains($attrs, 'type="submit"') && ! str_contains($attrs, 'href=');
    }

    /**
     * The component must still resolve an unstated variant the way this file assumes.
     *
     * Checked rather than mirrored silently: a default that changes in the Blade and not here would make the
     * lint report a codebase it is not looking at, which is worse than no check at all.
     */
    private function buttonFollowsTheSubmitRule(): bool
    {
        $path = $this->root.'/'.self::BUTTON_COMPONENT;

        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        return str_contains($contents, "'variant' => null")
            && str_contains($contents, '$variant ??= ($type === \'submit\' && $href === null) ? \'primary\' : \'outline\';');
    }

    /** @param array<string, mixed> $match */
    private function capture(string $attrs, string $pattern, string $prefix): ?string
    {
        return preg_match($pattern, $attrs, $m) === 1 ? $prefix.':'.$m[1] : null;
    }

    /**
     * U4 — a WAI-ARIA pattern is a promise to a screen reader, so a half of it is worse than none.
     *
     * Two pages declared `role="tab"` without a `tabpanel`, an `aria-controls`, or the arrow-key handling the
     * role means, which left an assistive tech announcing a widget whose keyboard contract the page then
     * breaks. Those two were turned into links (UX-UI-PLAN P0-2); this rule keeps the next one from landing.
     */
    private function checkRolePatternsAreComplete(string $file): void
    {
        if (! in_array('U4', self::ENABLED, true)) {
            return;
        }

        $contents = (string) file_get_contents($file);

        if (preg_match('/role=["\']tab["\']/', $contents) === 1) {
            $missing = [];

            foreach (['role=["\']tabpanel["\']' => 'a role=tabpanel', 'aria-controls=' => 'aria-controls'] as $pattern => $what) {
                if (preg_match('/'.$pattern.'/', $contents) !== 1) {
                    $missing[] = $what;
                }
            }

            if ($missing !== []) {
                $this->fail($this->rel($file), 'U4: claims role="tab" without '.implode(' or ', $missing).' — finish the pattern or use links');
            }
        }
    }

    /** U2 — a page must use the component, not a hand-copied class string, so the control themes with the rest. */
    private function checkSelectsAreComponents(string $file): void
    {
        if (! in_array('U2', self::ENABLED, true)) {
            return;
        }

        $contents = (string) file_get_contents($file);

        preg_match_all('/<select\b/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $line = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
            $this->fail($this->rel($file), "U2: raw <select> at line {$line} — use x-ui.select (§13: one component system)");
        }
    }

    /** U3 — the table's accessible name. */
    private function checkTablesHaveCaptions(string $file): void
    {
        if (! in_array('U3', self::ENABLED, true)) {
            return;
        }

        $contents = (string) file_get_contents($file);

        preg_match_all('/<x-ui\.table\b(?!\.)(?:(?!>)[\s\S])*?>/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $tag = $match[0];

            if (str_contains($tag, ':caption')) {
                continue;
            }

            $line = substr_count(substr($contents, 0, $match[1]), "\n") + 1;
            $this->fail($this->rel($file), "U3: <x-ui.table> at line {$line} has no :caption — the table is unnamed for a screen reader");
        }
    }

    /**
     * U5 — the numeric treatment has exactly one route: a component cell names its job with a prop, and a
     * hand-written cell (only legal in the two raw tables) uses the `.fg-numeric` / `.fg-identifier` token.
     * `text-right` and `font-mono` typed into a class is the second mechanism this task removed, and the one
     * that let a money column align on the wrong side of the decimal point.
     */
    private function checkNumericTreatmentIsOnePath(string $file): void
    {
        if (! in_array('U5', self::ENABLED, true)) {
            return;
        }

        $contents = (string) file_get_contents($file);

        $component = '/<x-ui\.table\.(td|th)\b((?:(?!>)[\s\S])*)>/';

        if (preg_match_all($component, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $match) {
                if (! preg_match('/class="([^"]*)"/', $matches[2][$index][0], $class) || ! preg_match('/\b(text-right|font-mono|fg-numeric|fg-identifier|whitespace-nowrap)\b/', $class[1], $token)) {
                    continue;
                }

                $this->fail($this->rel($file), sprintf(
                    'U5: a %s cell at line %d carries "%s" in a class — use the numeric/identifier/nowrap prop instead',
                    $matches[1][$index][0],
                    substr_count(substr($contents, 0, $match[1]), "\n") + 1,
                    $token[1],
                ));
            }
        }

        // A raw <td> is legal for an empty-state row and inside the journal's nested lines table; it is not
        // legal as a second way to ask for monospace figures.
        if (preg_match_all('/<td\b((?:(?!>)[\s\S])*)>/', $contents, $raw, PREG_OFFSET_CAPTURE)) {
            foreach ($raw[1] as $index => $attrs) {
                if (! preg_match('/class="([^"]*)"/', $attrs[0], $class) || ! preg_match('/\b(text-right|font-mono)\b/', $class[1], $token)) {
                    continue;
                }

                $this->fail($this->rel($file), sprintf(
                    'U5: a raw <td> at line %d hand-copies "%s" — use .fg-numeric / .fg-identifier, or the x-ui.table.td component',
                    substr_count(substr($contents, 0, $raw[0][$index][1]), "\n") + 1,
                    $token[1],
                ));
            }
        }
    }

    /** @return list<string> */
    private function pageFiles(): array
    {
        $files = glob($this->root.'/'.self::PAGES_GLOB) ?: [];

        sort($files);

        return $files;
    }

    private function fail(string $file, string $message): void
    {
        $this->violations[] = "{$file}: {$message}";
    }

    private function rel(string $path): string
    {
        return str_replace('\\', '/', str_replace($this->root.'/', '', $path));
    }

    private function report(): int
    {
        $rules = implode(',', self::ENABLED);
        $pending = array_values(self::PENDING);

        if ($this->violations === []) {
            echo "ui lint: PASS ({$this->files} page files, {$this->containers} containers, rules {$rules})\n";

            foreach ($pending as $note) {
                echo '  pending: '.$note."\n";
            }

            return 0;
        }

        foreach ($this->violations as $violation) {
            echo $violation."\n";
        }

        echo 'ui lint: FAIL ('.count($this->violations)." violation(s), rules {$rules})\n";

        return 1;
    }
}

$root = dirname(__DIR__);

exit((new UiLint($root))->run());
