<?php

declare(strict_types=1);

namespace App\Domain\Kernel\Numbering;

use RuntimeException;

final class NumberingError extends RuntimeException
{
    public static function outsideTransaction(string $scope): self
    {
        return new self(
            "Allocating a {$scope} number must happen inside the business transaction. Outside one, the "
            .'increment commits before the document does, so a failed insert leaves a hole in the '
            .'sequence — which is the exact defect ADR-0002 exists to prevent.'
        );
    }

    public static function unknownScope(string $scope): self
    {
        return new self(sprintf(
            'Unknown numbering scope "%s". Declare it in config/fleet.php under numbering.scopes with a '
            .'format and a restart policy; Q2/Q3 are unresolved precisely so that adding a fiscal '
            .'sequence is a config change, not a migration.',
            $scope,
        ));
    }

    public static function unknownRestartPolicy(string $scope, string $restart): self
    {
        return new self(sprintf(
            'Scope "%s" has restart policy "%s", which is not one of continuous, calendar_year or '
            .'financial_year. A misspelt policy here would silently change when a fiscal '
            .'sequence restarts, so it is refused rather than defaulted.',
            $scope,
            $restart,
        ));
    }

    public static function malformedFormat(string $format): self
    {
        return new self(
            "Number format \"{$format}\" is not renderable. Allowed tokens are {PERIOD}, {SEQ:n} and literals."
        );
    }

    public static function rowMissing(string $scope, string $periodKey): self
    {
        return new self(sprintf(
            'No number_sequences row for scope "%s" period "%s". Sequence rows are created ahead of time by '
            .'numbering:ensure (registry: NumberSequenceRegistry), never inside the allocation itself — '
            .'an INSERT IGNORE there takes a shared lock and deadlocks the SELECT ... FOR UPDATE that follows.',
            $scope,
            $periodKey === '' ? '(continuous)' : $periodKey,
        ));
    }
}
