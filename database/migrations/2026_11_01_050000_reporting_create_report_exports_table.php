<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * `report_exports` — the queue's ticket (Phase 8: exports are queued for large ranges;
 * The design keeps PDFs/exports off the request).
 *
 * A row is created only after the seat's export ability passed, and the job that fills it is a
 * pure re-read: the bytes land on the private disk and the row says where. Nothing here
 * stores money — the figures live in the source modules and the report rebuilds them from its
 * window, so an export can never become a stale second copy of a ledger fact (the rule read
 * from the storage side).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('report', 40);
            $table->string('format', 6);
            $table->date('window_from');
            $table->date('window_to');
            $table->string('status', 8)->default('queued');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('storage_key', 320)->nullable();
            $table->string('filename', 160)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'actor_id', 'status'], 'report_exports_seat_index');
        });

        // Blueprint has no CHECK support (measured on 13.38), so the closed vocabularies are raw
        // DDL on MySQL, the same split the ledger's own tables use.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("alter table `report_exports` add constraint `report_exports_format_known` check (`format` in ('csv', 'xlsx', 'pdf'))");
            DB::statement("alter table `report_exports` add constraint `report_exports_status_known` check (`status` in ('queued', 'ready', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
