<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0.5's party-detail intake — opening AR and AP as documents.
 *
 * The cutover engine has taken ten datasets since and nine master files and the
 * opening trial balance. The trial balance carries **control totals**, which is why its own validator
 * refuses `1150` and `2110`: those accounts demand a customer and a supplier, and an aggregate journal
 * line cannot name one. That refusal is the hole this migration opens — two datasets whose rows become
 * real documents, each carrying its party, its due date and its own balanced entry, so the ageing
 * report, the customer statement and `reports:tie-to-ledger`'s per-bill check find the opening balance
 * exactly where they find a live one.
 *
 * Three CHECKs widen, and each is the schema repeating a rule the application already keeps:
 *
 * - `cutover_batches_dataset_allowed` gains the two datasets, because a dataset the engine cannot
 *   validate must not be storable (the rule for every dataset since Phase 1).
 * - `invoices_kind_allowed` gains `opening`. An opening receivable is neither a trip nor a consolidated
 *   statement nor a hand-raised charge — it is last year's invoice, still owed, arriving in this
 *   system's register. A kind exists precisely so the screen can say which door a document came from.
 * - `bill_lines_category_allowed` gains `opening`, whose account is `3010` Owner capital: an opening
 *   payable's debit side is equity, not an expense, because the cost was expensed in the old system
 *   and re-expensing it here would report the same diesel twice in two different years.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance', 'opening_receivables', 'opening_payables', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts'))");

        DB::statement('alter table `invoices` drop check `invoices_kind_allowed`');
        DB::statement("alter table `invoices` add constraint `invoices_kind_allowed` check (kind in ('trip', 'consolidated', 'manual', 'opening'))");

        DB::statement('alter table `bill_lines` drop check `bill_lines_category_allowed`');
        DB::statement("alter table `bill_lines` add constraint `bill_lines_category_allowed` check (category in ('fuel', 'parts', 'labour', 'insurance', 'lease', 'other', 'opening'))");
    }

    public function down(): void
    {
        DB::statement('alter table `bill_lines` drop check `bill_lines_category_allowed`');
        DB::statement("alter table `bill_lines` add constraint `bill_lines_category_allowed` check (category in ('fuel', 'parts', 'labour', 'insurance', 'lease', 'other'))");

        DB::statement('alter table `invoices` drop check `invoices_kind_allowed`');
        DB::statement("alter table `invoices` add constraint `invoices_kind_allowed` check (kind in ('trip', 'consolidated', 'manual'))");

        DB::statement('alter table `cutover_batches` drop check `cutover_batches_dataset_allowed`');
        DB::statement("alter table `cutover_batches` add constraint `cutover_batches_dataset_allowed` check (dataset in ('opening_trial_balance', 'customers', 'suppliers', 'trucks', 'trailers', 'employees', 'drivers', 'routes', 'rates', 'parts'))");
    }
};
