<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `audits.auditable_id` is a ULID column, and the published migration made it a BIGINT.
     *
     * `2026_10_26_131410_create_audits_table` is the package's own stub, and `morphs('auditable')`
     * is what it ships: an `unsignedBigInteger`. The schema says `char26 auditable_id`. Half the
     * audited models — `freight_jobs`, `trips`, `trip_costs`, `fuel_purchases`, `fuel_issues`,
     * `chargeables`, `border_costs`, `border_crossings`, `proof_of_deliveries` and
     * `trip_access_tokens` — are keyed by ULID, so for those the audit insert was not a silent
     * truncation but a refusal: `SQLSTATE[01000] 1265 Data truncated for column 'auditable_id'`,
     * raised inside the creating transaction, which is why **the job itself failed to save**.
     *
     * It looked healthy because the key space is mixed: `customers`, `routes`, `border_posts`,
     * `trucks`, `drivers` and the rest are still `bigint`, their audit rows fit, and the only audit
     * row this database held was one of those. `char(26)` is the width every ULID column here uses,
     * and it stores an integer key as its digits — `where auditable_id = 68` still compares true
     * against `'68'` — so the integer-keyed half of the model set keeps auditing unchanged.
     *
     * The composite `(auditable_type, auditable_id)` index is rebuilt by the `alter` and keeps its
     * name, so nothing that reads the trail by key has to change.
     *
     * The `down()` is honest only on a database whose audits are all integer-keyed: a `char` column
     * holding a ULID cannot become a `BIGINT` again.
     */
    public function up(): void
    {
        DB::statement('alter table `audits` modify `auditable_id` char(26) not null');
    }

    public function down(): void
    {
        DB::statement('alter table `audits` modify `auditable_id` bigint unsigned not null');
    }
};
