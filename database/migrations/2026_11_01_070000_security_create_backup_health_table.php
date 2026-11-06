<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * `backup_health` — one row per company per backup job.
 *
 * The table exists because "the job is scheduled" and "the job ran" are different claims, and the
 * second is the one the design makes the Owner's dashboard answer. A row is written by every run, success
 * or failure, and its absence is a finding in its own right: a scheduler that silently stops is the
 * failure mode a backup promises never to have.
 *
 * `last_success_at` is kept separate from `last_run_at` on purpose. A failing run must not make the
 * last good export look fresh — that is how a month of failed backups reads as healthy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_health', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('job', 24);
            $table->boolean('ok')->default(false);
            $table->timestamp('last_run_at');
            $table->timestamp('last_success_at')->nullable();
            $table->string('object_key', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->unsignedSmallInteger('tables')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'job'], 'backup_health_company_job_unique');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("alter table `backup_health` add constraint `backup_health_job_known` check (`job` in ('offsite_export', 'offsite_verify'))");
            DB::statement('alter table `backup_health` add constraint `backup_health_size_nonnegative` check (`bytes` is null or `bytes` >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_health');
    }
};
