<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EMPLOYEE — the payroll-boundary person record: who is on the books, in what
     * grade, at what gross cost, without pretending to be statutory payroll.
     *
     * The national ID follows the trio (`national_id_masked` / `national_id_encrypted` /
     * `key_version`) rather than a plain column. Unlike a bank number there is no field gate that
     * reveals it — the design names five gates and national identity is not one of them — so the mask is
     * the display form and nothing in phase 1 decrypts. The ciphertext exists so the day a gate is
     * justified the plaintext is already stored safely, not in a spreadsheet.
     *
     * `gross_salary_base` is minor units in the company base currency (D6). The base currency is a
     * company fact, not an employee one; an employee paid in another currency is a Phase 5 payroll
     * question and gets its own column then, with its own currency code.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('employee_no', 24);
            $table->string('name', 120);

            $table->string('national_id_masked', 32)->nullable();
            $table->text('national_id_encrypted')->nullable();
            $table->string('key_version', 32)->nullable();

            $table->string('role_or_grade', 80)->nullable();
            $table->date('hired_on');
            $table->date('left_on')->nullable();

            $table->unsignedBigInteger('gross_salary_base')->default(0);
            $table->string('pay_frequency', 12)->default('monthly');
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'employee_no'], 'employees_company_no_unique');
            $table->index(['company_id', 'active'], 'employees_company_active_index');
        });

        DB::statement('alter table `employees` add constraint `employees_pay_frequency_allowed` check (pay_frequency in (\'monthly\', \'fortnightly\'))');

        // Three-valued logic again: a nullable column in a CHECK needs its `is null` arm stated, or
        // an UNKNOWN expression passes. Here the two arms together cover every row.
        DB::statement('alter table `employees` add constraint `employees_national_id_is_a_pair` check ((national_id_masked is null and national_id_encrypted is null) or (national_id_masked is not null and national_id_encrypted is not null))');

        DB::statement('alter table `employees` add constraint `employees_left_after_hire` check (left_on is null or left_on >= hired_on)');
        DB::statement('alter table `employees` add constraint `employees_salary_not_negative` check (gross_salary_base >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
