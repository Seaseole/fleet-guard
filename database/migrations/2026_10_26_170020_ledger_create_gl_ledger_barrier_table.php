<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §6.3 layer 3 — `gl_ledger_barrier`, the documented emergency hatch.
     *
     * The table is normally EMPTY, and that is the state the immutability triggers test for: no row
     * means no UPDATE or DELETE on `gl_journal_*`. A maintenance migration lifts the guard by
     * inserting the single row, performs its fix, and deletes it again — and the insert/delete is
     * itself an audited write to a ledger-adjacent table.
     *
     * It is a row rather than a config flag on purpose: config is per-environment and revocable
     * from a deployment, whereas this needs someone to change production data and leave a trace.
     */
    public function up(): void
    {
        Schema::create('gl_ledger_barrier', function (Blueprint $table): void {
            $table->tinyInteger('id')->primary();
            $table->string('reason', 255);
            $table->foreignId('lifted_by')->nullable()->constrained('users');
            $table->timestamp('lifted_at')->nullable();
        });

        // Single-row table, enforced below the application: `where id = 1` in the triggers is only
        // a barrier if nothing else can ever be in here.
        DB::statement('alter table `gl_ledger_barrier` add constraint `gl_ledger_barrier_single_row` check (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_ledger_barrier');
    }
};
