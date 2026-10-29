<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Cutover;

use App\Domain\Kernel\Cutover\Exceptions\CutoverRejected;
use Illuminate\Contracts\Container\Container;

/**
 * Dataset → handler, bound once in the provider that is allowed to name modules.
 *
 * The map is keyed by the enum's value rather than the case so a config-like array is readable at a
 * glance. An unmapped dataset is refused before a batch row exists, which is the same rule the old
 * hard-coded check enforced — just no longer written inside the engine.
 */
final class CutoverHandlers
{
    /**
     * @param  array<string, class-string<CutoverDatasetHandler>>  $map
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $map,
    ) {}

    public function for(CutoverDataset $dataset): CutoverDatasetHandler
    {
        $class = $this->map[$dataset->value] ?? null;

        if ($class === null || ! is_a($class, CutoverDatasetHandler::class, true)) {
            throw CutoverRejected::datasetUnsupported($dataset->value);
        }

        return $this->container->make($class);
    }

    public function supports(CutoverDataset $dataset): bool
    {
        $class = $this->map[$dataset->value] ?? null;

        return $class !== null && is_a($class, CutoverDatasetHandler::class, true);
    }
}
