<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0.5's physical truth at cutover — the odometer history and the yard tank's opening
 * litres.
 *
 * Two more datasets, and neither one touches the ledger's money in the way the do. The odometer
 * file is a *measurement* history: what each truck's face read on cutover day, in the order it was
 * read. The tank file is the one opening balance that is genuinely both — litres in a tank are an
 * asset (`1180`) and a quantity the next dip will disagree with — so it posts `Dr 1180 / Cr 3010` and
 * moves the book, or the first variance after cutover would be measured against an empty tank.
 *
 * Three CHECKs widen:
 *
 * - `cutover_batches_dataset_allowed` learns the two files, because a dataset the engine cannot
 *   validate must not be storable (the rule since Phase 1).
 * - `odometer_readings_source_allowed` gains `cutover`. A reading needs to say where it came from,
 *   because the plausibility rules and any later argument about "who moved the odometer" begin
 *   with the source column — and a cutover reading is neither a trip's departure nor a workshop's
 *   manual entry.
 * - `bulk_tank_movements_kind_allowed` gains `opening`. The tank's book has four kinds of movement
 *   today; an opening fill is none of them. It is not a `receipt`, because a receipt belongs to a
 *   purchase that names a supplier, a price and an invoice — and this diesel was bought by the old
 *   system, in the old year, and only its litres are coming forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance', 'opening_receivables', 'opening_payables', 'opening_odometer_readings', 'opening_tank_stock', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts'))");

        DB::statement('alter table `odometer_readings` drop check `odometer_readings_source_allowed`');
        DB::statement("alter table `odometer_readings` add constraint `odometer_readings_source_allowed` check (source in ('trip_departure', 'trip_arrival', 'fuel_issue', 'manual', 'kiosk', 'cutover'))");

        DB::statement('alter table `bulk_tank_movements` drop check `bulk_tank_movements_kind_allowed`');
        DB::statement("alter table `bulk_tank_movements` add constraint `bulk_tank_movements_kind_allowed` check (kind in ('receipt', 'issue', 'dip', 'reversal', 'opening'))");
    }

    public function down(): void
    {
        DB::statement('alter table `bulk_tank_movements` drop check `bulk_tank_movements_kind_allowed`');
        DB::statement("alter table `bulk_tank_movements` add constraint `bulk_tank_movements_kind_allowed` check (kind in ('receipt', 'issue', 'dip', 'reversal'))");

        DB::statement('alter table `odometer_readings` drop check `odometer_readings_source_allowed`');
        DB::statement("alter table `odometer_readings` add constraint `odometer_readings_source_allowed` check (source in ('trip_departure', 'trip_arrival', 'fuel_issue', 'manual', 'kiosk'))");

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance', 'opening_receivables', 'opening_payables', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts'))");
    }
};
