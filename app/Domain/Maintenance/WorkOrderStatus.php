<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/**
 * The work-order machine, as vocabulary.
 *
 * The transitions live on {@see Models\WorkOrder} so they cannot drift from the migration's CHECK;
 * this enum is the label and the tone the register prints.
 *
 * `rejected` and `cancelled` are different words on purpose: a rejected order was refused *before*
 * anyone approved it (the Owner said no to the spend), a cancelled one was approved or drafted and
 * then withdrawn. Both are terminal and both require a reason.
 */
enum WorkOrderStatus: string
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Inspected = 'inspected';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
            self::Inspected => 'Inspected',
            self::Closed => 'Closed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Rejected, self::Cancelled => 'neutral',
            self::AwaitingApproval, self::OnHold => 'warning',
            self::Approved, self::Planned => 'accent',
            self::InProgress => 'accent',
            self::Completed, self::Inspected => 'success',
            self::Closed => 'success',
        };
    }

    /** Whether the order still occupies a workshop slot — what the register's "open" filter asks. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Draft,
            self::AwaitingApproval,
            self::Approved,
            self::Planned,
            self::InProgress,
            self::OnHold,
            self::Completed,
            self::Inspected => true,
            self::Closed, self::Rejected, self::Cancelled => false,
        };
    }

    /**
     * Where the cost lines may still be edited: before the vehicle is in the workshop.
     *
     * From `in_progress` onward the costs are what the work actually took, and phase 3e's stock issues
     * and phase 5's bills are what will write them. An estimate that changes while a mechanic is
     * under the truck is a hold (`awaiting_owner_approval`), not a quiet edit to a number the
     * accountant may already have seen.
     */
    public function acceptsLines(): bool
    {
        return match ($this) {
            self::Draft, self::AwaitingApproval, self::Approved, self::Planned => true,
            default => false,
        };
    }
}
