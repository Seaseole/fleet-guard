<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exceptions;

use RuntimeException;

/**
 * A kiosk token was asked for a scope this build cannot honour, or for no scope at all.
 *
 * It is a `RuntimeException` rather than an `InvalidArgumentException` because the caller cannot fix it by
 * passing a different string: the scope belongs to a phase that has not been built, and the honest answer
 * is "not yet", not "wrong type".
 */
final class KioskScopeUnavailable extends RuntimeException {}
