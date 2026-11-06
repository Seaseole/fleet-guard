<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Actions;

use App\Domain\Fleet\Actions\ChangeTruckStatus;
use App\Domain\Fleet\Actions\RecordOdometerReading;
use App\Domain\Fleet\Data\OdometerReadingDto;
use App\Domain\Fleet\FleetStatus;
use App\Domain\Fleet\Models\Truck;
use App\Domain\Fleet\OdometerSource;
use App\Domain\Kernel\Alerts\Actions\TransitionAlert;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use App\Domain\Kernel\Tenancy\CurrentCompany;
use App\Domain\Maintenance\Models\ServiceSchedule;
use App\Domain\Maintenance\Models\WorkOrder;
use App\Domain\Maintenance\ServiceScheduleKind;
use App\Domain\Maintenance\WorkOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The `in_progress → completed`: the vehicle is released, and the odometer at fitment is recorded.
 *
 * The reading is recorded through Fleet's own door ({@see RecordOdometerReading}) rather than written
 * onto the truck here, so the measurement keeps its provenance row and the truck's projection moves by
 * the same monotonic rule every other reading follows. The source list has no `workshop` value, so
 * the reading is `manual`: it is a person reading a gauge and typing it, not the kiosk or a trip.
 *
 * A completion below the truck's recorded odometer is refused instead of stored as `suspect`: a
 * service's due point is computed from this figure, and advancing a schedule from a number the truck
 * has already passed would put the next reminder in the past the moment it was set. A genuinely lower
 * reading is a correction, and corrections go through the reading's own screen first.
 *
 * The linked schedule moves with the completion — last done at this reading, next due one interval
 * later — and the `service_due` alerts it raised are resolved in the same transaction, because the job
 * they were asking for is the job that just finished.
 *
 * The edge is "vehicle released", so the truck leaves `in_workshop` here through Fleet's own door.
 * `StartWorkOrder` took it off the road; if completion did not put it back, a truck that visited the
 * workshop once would stay unpickable to dispatch forever, and the register would be lying about which
 * vehicles can be booked.
 */
final class CompleteWorkOrder
{
    public function __construct(
        private readonly RecordOdometerReading $recordReading,
        private readonly TransitionAlert $transitionAlert,
        private readonly ChangeTruckStatus $changeStatus,
    ) {}

    public function __invoke(
        WorkOrder $workOrder,
        int $odometerKm,
        ?float $engineHours = null,
        ?int $actorId = null,
    ): WorkOrder {
        if (! Gate::allows('work_orders.create')) {
            throw new InvalidArgumentException('Completing a job is a workshop act.');
        }

        if ($odometerKm <= 0) {
            throw new InvalidArgumentException('Completing a work order needs the odometer reading at fitment.');
        }

        if ($engineHours !== null && $engineHours < 0) {
            throw new InvalidArgumentException('Engine hours cannot be negative.');
        }

        return DB::transaction(function () use ($workOrder, $odometerKm, $engineHours, $actorId): WorkOrder {
            /** @var WorkOrder $locked */
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WorkOrderStatus::InProgress) {
                throw new InvalidArgumentException(sprintf(
                    '%s is %s. A vehicle is released from work under way.',
                    $locked->wo_no,
                    strtolower($locked->status->label()),
                ));
            }

            /** @var Truck $truck */
            $truck = Truck::query()->findOrFail($locked->truck_id);

            if ($odometerKm < $truck->odometer_km) {
                throw new InvalidArgumentException(sprintf(
                    '%s reads %s km, lower than the truck’s recorded %s km. A service cannot be logged at a point the vehicle has already passed — correct the reading first.',
                    $truck->label(),
                    number_format($odometerKm),
                    number_format($truck->odometer_km),
                ));
            }

            $schedule = $locked->service_schedule_id === null
                ? null
                : ServiceSchedule::query()->find($locked->service_schedule_id);

            if ($schedule?->kind === ServiceScheduleKind::Hours && $engineHours === null) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is measured in engine hours; completion needs the hour meter reading as well as the odometer.',
                    $schedule->name,
                ));
            }

            $today = Carbon::now(CurrentCompany::timezone());

            ($this->recordReading)(new OdometerReadingDto(
                truckId: $truck->id,
                odometerKm: $odometerKm,
                readOn: $today->toDateString(),
                source: OdometerSource::Manual,
                engineHours: $engineHours,
            ));

            // Dirty attribute, so `moveTo` writes the reading and the state in one UPDATE — the CHECK
            // refuses `completed` without it.
            $locked->completion_odometer_km = $odometerKm;

            $locked->moveTo(
                WorkOrderStatus::Completed,
                null,
                ['odometer_km' => $odometerKm],
                ActorKind::User,
                $actorId,
            );

            if ($schedule !== null) {
                $schedule->recordService(
                    $odometerKm,
                    $engineHours === null ? null : (int) round($engineHours * 100),
                    $today,
                );

                $this->resolveScheduleAlerts($schedule, $locked, $actorId);
            }

            if ($truck->status === FleetStatus::InWorkshop && $truck->canTransitionTo(FleetStatus::Available)) {
                ($this->changeStatus)($truck, FleetStatus::Available);
            }

            return $locked->refresh();
        });
    }

    private function resolveScheduleAlerts(ServiceSchedule $schedule, WorkOrder $workOrder, ?int $actorId): void
    {
        /** @var iterable<Alert> $alerts */
        $alerts = Alert::query()
            ->live()
            ->where('type', AlertType::ServiceDue->value)
            ->where('subject_type', 'service_schedule')
            ->where('subject_id', (string) $schedule->id)
            ->get();

        foreach ($alerts as $alert) {
            $this->transitionAlert->handle(
                $alert,
                AlertState::Resolved,
                sprintf('Serviced under %s.', $workOrder->wo_no),
                null,
                $actorId,
                ActorKind::User,
            );
        }
    }
}
