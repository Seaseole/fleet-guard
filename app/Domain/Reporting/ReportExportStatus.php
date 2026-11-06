<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * The export row's three states. `failed` keeps its reason (the silent-failure rule: an
 * export that died says why on the screen that offered it, rather than spinning forever).
 */
enum ReportExportStatus: string
{
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';
}
