<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GL_PERIOD. A period is a row rather than a date calculation so that "this month is
     * locked" is data an auditor can read, and so the lock trigger is a single-row lookup instead
     * of a date-range scan (`posted_on → period_id` is resolved and stored at write time).
     *
     * `period` is 'YYYY-MM' and the fiscal calendar comes from `companies.fiscal_year_starts_on`,
     * never from this table — Q3 is unresolved and must not become a migration.
     */
    public function up(): void
    {
        Schema::create('gl_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('period', 7);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 8)->default('open');

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'period'], 'gl_periods_company_period_unique');
            $table->index(['company_id', 'status'], 'gl_periods_company_status_index');
        });

        DB::statement('alter table `gl_periods` add constraint `gl_periods_status_allowed` check (status in (\'open\', \'closed\', \'locked\'))');
        DB::statement('alter table `gl_periods` add constraint `gl_periods_range_ordered` check (ends_on >= starts_on)');

        // Closing is a state change, not a timestamp someone remembers to set: a period cannot
        // claim to be closed or locked without recording when and who.
        DB::statement('alter table `gl_periods` add constraint `gl_periods_closure_recorded` check (status = \'open\' or closed_at is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_periods');
    }
};
