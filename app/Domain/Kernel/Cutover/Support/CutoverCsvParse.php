<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover\Support;

/**
 * A parsed table, or the file-level reasons there is none.
 *
 * A small result rather than a nullable-plus-out-parameter so a validator reads as one branch:
 * "if there is no table, these are the batch errors".
 */
final readonly class CutoverCsvParse
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public ?CsvTable $table,
        public array $errors = [],
    ) {}
}
