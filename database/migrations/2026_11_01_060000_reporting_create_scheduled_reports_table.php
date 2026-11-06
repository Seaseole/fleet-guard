<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * `scheduled_reports` — the Owner's nomination slip (Phase 8: scheduled email for the
 * reports nominated). One row per company per report says how often the mail goes out, and that
 * is the whole design: recipients are not stored, because who may receive a scheduled report is
 * exactly who holds that report's export ability — the sentence ("an export is an authorised
 * read") applied to the calendar, with no second list to forget to update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('report', 40);
            $table->string('frequency', 8);
            $table->tinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('set_by')->nullable()->constrained('users');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'report'], 'scheduled_reports_company_report_unique');
        });

        // 1..28, not 1..31: Asked for a schedule that answers monthly, and day 29 of
        // February is the sort of promise that quietly stops being kept. The ALTERs sit outside
        // the create closure because Blueprint defers the CREATE TABLE — a DB::statement spoken
        // inside the closure arrives before the table exists (measured the hard way, 2026-11-01).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("alter table `scheduled_reports` add constraint `scheduled_reports_frequency_known` check (`frequency` in ('daily', 'weekly', 'monthly'))");
            DB::statement('alter table `scheduled_reports` add constraint `scheduled_reports_weekday_range` check (`day_of_week` is null or (`day_of_week` >= 0 and `day_of_week` <= 6))');
            DB::statement('alter table `scheduled_reports` add constraint `scheduled_reports_monthday_range` check (`day_of_month` is null or (`day_of_month` between 1 and 28))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
