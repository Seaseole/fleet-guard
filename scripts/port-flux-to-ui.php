<?php

/**
 * One-off migration: rewrites the starter kit's settings pages from flux:*
 * components onto our own x-ui.* primitives (owner decision: our own components).
 * Files with real behavioural differences (theme radios, modals) are finished by hand.
 *
 * Usage: php scripts/port-flux-to-ui.php [path ...]
 */
$targets = array_slice($argv, 1);

if ($targets === []) {
    $targets = glob(__DIR__.'/../resources/views/pages/settings/**/*.blade.php')
        ?: glob(__DIR__.'/../resources/views/pages/settings/*.blade.php');
}

$componentMap = [
    'input' => 'input',
    'button' => 'button',
    'text' => 'text',
    'heading' => 'heading',
    'subheading' => 'subheading',
    'link' => 'link',
    'separator' => 'separator',
    'badge' => 'badge',
    'checkbox' => 'checkbox',
    'otp' => 'otp',
    'navlist.item' => 'navlist.item',
    'navlist' => 'navlist',
    'radio.group' => 'radio.group',
    'radio' => 'radio.item',
    'modal' => 'dialog',
    'profile' => 'avatar',
];

foreach ($targets as $file) {
    if (! is_file($file)) {
        continue;
    }

    $original = $contents = file_get_contents($file);

    // <flux:icon.name … /> → <x-ui.icon name="name" … />
    $contents = preg_replace_callback(
        '#<flux:icon\.([a-z0-9-]+)([^>]*?)/?>#',
        fn (array $m) => sprintf('<x-ui.icon name="%s"%s />', $m[1], $m[2]),
        $contents
    );

    foreach ($componentMap as $flux => $ours) {
        $contents = str_replace(["<flux:{$flux}", "</flux:{$flux}>"], ["<x-ui.{$ours}", "</x-ui.{$ours}>"], $contents);
    }

    // Flux::toast(variant: 'success', text: X) → Livewire event consumed by app.js.
    $contents = preg_replace(
        "#Flux::toast\(\s*variant:\s*'([a-z]+)',\s*text:\s*(.+?)\);#s",
        "\$this->dispatch('toast', message: $2, kind: '$1');",
        $contents
    );

    $contents = preg_replace('/^\s*use Flux\\\\Flux;\n/m', '', $contents);

    // Prop names our primitives do not implement: drop rather than render an unknown attribute.
    $contents = preg_replace('/\s+size="lg"/', '', $contents);

    if ($contents !== $original) {
        file_put_contents($file, $contents);
        echo 'rewritten: '.basename($file).PHP_EOL;
    }
}
