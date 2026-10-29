<?php

declare(strict_types=1);

use App\Domain\Kernel\Numbering\NumberAllocator;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency worker for the numbering check: allocates N numbers, each in its own
 * transaction, and prints them. Run two or more of these at once and their output must be
 * disjoint — that is the SELECT ... FOR UPDATE row lock doing the only job it is asked to do.
 *
 * Usage: php scripts/allocate-numbers.php <count>
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

CurrentCompany::force(1);
$allocator = new NumberAllocator;
$count = (int) ($argv[1] ?? 10);
$numbers = [];

for ($i = 0; $i < $count; $i++) {
    $numbers[] = DB::transaction(fn (): string => $allocator->allocate('purchase_order'));
}

echo implode(PHP_EOL, $numbers).PHP_EOL;
