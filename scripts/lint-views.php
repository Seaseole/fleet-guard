<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;

/**
 * Every Blade view in the app must compile to parseable PHP.
 *
 * This gate exists because a responsive pass deleted one closing `</x-ui.badge>` tag, and an inline
 * component with no close silently swallows the rest of the template: `/fleet` and `/invoices` answered 500
 * on every viewport. Nothing caught it. `composer lint:ui` reads tags as text and was green; `php artisan
 * view:cache` dies earlier on an unrelated vendor component (`monocle::facts`), so it never reaches this
 * file; and a render sweep only proves 200 for the pages its own author thought to name. A Blade file that
 * cannot compile is a page that cannot load, so the check belongs on every view, not on the views a feature
 * happened to touch.
 *
 * Compiling is not rendering: no request, no database, no seat. A view can pass this and still be wrong on
 * screen — that half stays with the render sweeps and the browser pass (the rule).
 *
 * Run: php scripts/lint-views.php   (composer lint:views)
 */
final class ViewLint
{
    /** @var list<string> */
    private array $violations = [];

    private int $files = 0;

    public function __construct(private readonly string $root) {}

    public function run(): int
    {
        $compiler = app('blade.compiler');
        $tmp = sys_get_temp_dir();

        foreach ($this->viewFiles() as $file) {
            $this->files++;
            $contents = (string) file_get_contents($file);

            $this->checkInlineComponentsClose($file, $contents);

            try {
                $compiled = $compiler->compileString($contents);
            } catch (Throwable $e) {
                $this->fail($this->rel($file), 'compile: '.$e->getMessage());

                continue;
            }

            $probe = $tmp.'/lint-views-'.md5($file).'.php';
            file_put_contents($probe, $compiled);

            $output = [];
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($probe).' 2>&1', $output, $status);
            unlink($probe);

            if ($status !== 0) {
                $this->fail($this->rel($file), 'compiled PHP will not parse: '.trim($output[0] ?? 'unknown'));
            }
        }

        return $this->report();
    }

    /**
     * An inline component that never closes.
     *
     * `<x-ui.badge :tone="...">{{ $x }}</x-ui.badge>` written on one line is the app's idiom for a status
     * chip, and dropping the closing tag is invisible to `php -l`: the rest of the file simply becomes the
     * badge's slot, and the page 500s. The parse error only shows up once the swallowed region contains a
     * directive, which is why the compile pass alone is not enough — it needs to be paired with the text.
     */
    private function checkInlineComponentsClose(string $file, string $contents): void
    {
        foreach (explode("\n", $contents) as $index => $line) {
            if (preg_match('#<(x-[a-z0-9.-]+)((?:(?!>)[\s\S])*[^\/\s])\s*>\s*\{\{.*\}\}\s*$#', $line, $m) === 1) {
                $this->fail($this->rel($file), sprintf(
                    'unclosed <%s> at line %d — it swallows every line below it',
                    $m[1],
                    $index + 1,
                ));
            }
        }
    }

    /**
     * All views, including `components/` and the `vendor/` overrides — a broken component is every page that
     * uses it, and the compile pass must see the file the page resolves, not just the page.
     *
     * @return list<string>
     */
    private function viewFiles(): array
    {
        $files = [];

        $depth = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/resources/views')),
            '/\.blade\.php$/',
        );

        foreach ($depth as $file) {
            $files[] = $file->getPathname();
        }

        $files = array_values(array_unique(array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            $files,
        )));
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
        if ($this->violations === []) {
            echo "view lint: PASS ({$this->files} Blade files compile)\n";

            return 0;
        }

        foreach ($this->violations as $violation) {
            echo $violation."\n";
        }

        echo 'view lint: FAIL ('.count($this->violations)." violation(s), {$this->files} file(s) scanned)\n";

        return 1;
    }
}

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

exit((new ViewLint($root))->run());
