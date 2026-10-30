<?php

declare(strict_types=1);

namespace App\Domain\Fuel\Actions;

use App\Domain\Fuel\FuelVarianceState;
use App\Domain\Fuel\Models\FuelVariance;
use App\Domain\Kernel\Alerts\Actions\TransitionAlert;
use App\Domain\Kernel\Alerts\AlertState;
use App\Domain\Kernel\Alerts\AlertType;
use App\Domain\Kernel\Alerts\Models\Alert;
use App\Domain\Kernel\Audit\ActorKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * §10.9's variance decision: a person reads the figures and says what they think of them.
 *
 * Every decision carries its sentence — the migration's CHECK and the state trait both refuse a
 * decided row without one, because "we looked at it" that records no reason is how a variance
 * register becomes a place numbers go to be forgotten. The seat is `fuel.waive_variance` (§9.3):
 * deciding that a truck's diesel consumption is acceptable is the Operations Manager's or the
 * Owner's judgement, not the clerk's who recorded the litres.
 *
 * Resolving the variance closes the alert it raised, through §14.1's own transition door — the
 * register must not keep saying "fuel variance" after the person who owns it has answered.
 */
final class DecideFuelVariance
{
    public function __construct(private readonly TransitionAlert $transition) {}

    public function __invoke(
        FuelVariance $variance,
        FuelVarianceState $to,
        string $note,
        ?int $actorId = null,
    ): FuelVariance {
        if (! Gate::allows('fuel.waive_variance')) {
            throw new InvalidArgumentException('Deciding a fuel variance is an operations decision (§9.3).');
        }

        if ($to === FuelVarianceState::Open) {
            throw new InvalidArgumentException('A variance does not go back to open; the machine has no such move (§10.9).');
        }

        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('A variance decision needs the sentence that explains it — the trail and the row both store it.');
        }

        if (mb_strlen($note) > 160) {
            throw new InvalidArgumentException('Keep the decision to 160 characters — it is the sentence, not the file.');
        }

        return DB::transaction(function () use ($variance, $to, $note, $actorId): FuelVariance {
            $variance->forceFill([
                'acknowledgement_note' => $note,
                'decided_by' => $actorId,
                'decided_at' => now(),
            ])->save();

            $variance->moveTo($to, $note, [], ActorKind::User, $actorId);

            if ($to === FuelVarianceState::Resolved) {
                $this->closeAlerts($variance, $note, $actorId);
            }

            return $variance->refresh();
        });
    }

    private function closeAlerts(FuelVariance $variance, string $note, ?int $actorId): void
    {
        /** @var iterable<Alert> $alerts */
        $alerts = Alert::query()
            ->live()
            ->where('type', AlertType::FuelVariance->value)
            ->where('subject_type', 'fuel_variance')
            ->where('subject_id', (string) $variance->id)
            ->get();

        foreach ($alerts as $alert) {
            $this->transition->handle($alert, AlertState::Resolved, $note, null, $actorId, ActorKind::User);
        }
    }
}
