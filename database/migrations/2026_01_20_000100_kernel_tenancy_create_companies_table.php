<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T1-T4 in DESIGN.md §8 / ADR-0003: one company today, but the company is a row, not
     * an assumption. Settings that would otherwise be hard-coded (currency, timezone,
     * fiscal year) live here so a second company is data, not a deploy.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->string('vat_number')->nullable();

            // T4: the base currency is read from here, never from config('app.currency').
            $table->char('base_currency_code', 3)->default('BWP');
            $table->string('timezone', 64)->default('Africa/Gaborone');
            $table->char('country_code', 2)->default('BW');

            // §17 Q3 is unresolved, so the fiscal year is a value we can change, not a
            // migration. Calendar-year default until the accountant answers.
            $table->date('fiscal_year_starts_on')->default('2000-01-01');

            // Invoice footer, numbering formats, approval thresholds. Shape validated by
            // CompanySettings, not by the database.
            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
