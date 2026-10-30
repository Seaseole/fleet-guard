<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Data;

use App\Domain\Maintenance\Actions\RecordInspection;
use App\Domain\Maintenance\InspectionKind;
use App\Domain\Maintenance\InspectionResult;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A safety check as the "record inspection" dialog hands it to {@see RecordInspection} (D1).
 *
 * The DTO carries the whole verdict: every item of the kind's checklist answered, the defect lines
 * written down, and the three rules that make `result` mean something —
 *
 * - a **pass** has every item answered yes and no defects,
 * - a **fail** has at least one "no" and at least one defect line,
 * - an **advisory** carries at least one defect line (the truck may go, with a note).
 *
 * Refusing here keeps the register from holding a "pass" nobody actually checked, which is the whole
 * value of keeping a register.
 */
final readonly class InspectionDto
{
    /**
     * @param  array<string, bool>  $answers
     * @param  list<string>  $defects
     * @param  list<string>  $photoDocumentIds
     */
    public function __construct(
        public InspectionKind $kind,
        public int $truckId,
        public CarbonInterface $doneAt,
        public InspectionResult $result,
        public array $answers,
        public array $defects = [],
        public ?string $tripId = null,
        public ?string $workOrderId = null,
        public ?int $driverId = null,
        public array $photoDocumentIds = [],
    ) {
        $expected = $this->kind->checklistKeys();
        $given = array_keys($answers);

        foreach ($expected as $key) {
            if (! array_key_exists($key, $answers)) {
                throw new InvalidArgumentException(sprintf(
                    'A %s must answer every item; "%s" was left blank.',
                    strtolower($this->kind->label()),
                    $this->kind->checklist()[$key],
                ));
            }
        }

        foreach ($given as $key) {
            if (! in_array($key, $expected, true)) {
                throw new InvalidArgumentException(sprintf('"%s" is not an item on a %s.', $key, strtolower($this->kind->label())));
            }

            if (! is_bool($answers[$key])) {
                throw new InvalidArgumentException('Every checklist item is answered yes or no; anything else is not an answer.');
            }
        }

        $this->assertVerdictMatches($answers);
        $this->assertDefectsAreReadable();
    }

    /** @param  array<string, bool>  $answers */
    private function assertVerdictMatches(array $answers): void
    {
        $failed = array_keys(array_filter($answers, static fn (bool $answer): bool => $answer === false));
        $defects = $this->cleanDefects();

        if ($this->result === InspectionResult::Pass) {
            if ($failed !== []) {
                throw new InvalidArgumentException(sprintf(
                    'A pass cannot carry a "no": "%s" was answered no, so the result is a fail or an advisory.',
                    $this->kind->checklist()[$failed[0]],
                ));
            }

            if ($defects !== []) {
                throw new InvalidArgumentException('A pass has no defects; write the note as an advisory, or mark the item and fail the check.');
            }

            return;
        }

        if ($defects === []) {
            throw new InvalidArgumentException(sprintf(
                'A %s inspection must say what was seen; add at least one defect line.',
                $this->result === InspectionResult::Fail ? 'failed' : 'advisory',
            ));
        }

        if ($this->result->requiresFailedItem() && $failed === []) {
            throw new InvalidArgumentException('A fail needs the item that failed answered no; a walk-around with every item yes is a pass with notes.');
        }
    }

    private function assertDefectsAreReadable(): void
    {
        $defects = $this->cleanDefects();

        if (count($defects) > 20) {
            throw new InvalidArgumentException('Keep an inspection to 20 defect lines; anything longer belongs in the work order.');
        }

        foreach ($defects as $line) {
            if (mb_strlen($line) > 200) {
                throw new InvalidArgumentException('Keep each defect line to 200 characters.');
            }
        }

        if (count($this->photoDocumentIds) > 10) {
            throw new InvalidArgumentException('Keep an inspection to ten photographs; the rest belong to the work order.');
        }
    }

    /** @return list<string> */
    public function cleanDefects(): array
    {
        $lines = [];

        foreach ($this->defects as $line) {
            $line = is_string($line) ? trim($line) : '';

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @return array<string, bool> */
    public function checklist(): array
    {
        return $this->answers;
    }
}
