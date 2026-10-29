<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 / §6.1 GL_ACCOUNT — the chart of accounts as a tree (§6.1 decision 1).
     *
     * `parent_id` gives the trial-balance rollups without a report-definition table;
     * `is_postable` marks the header rows that group but never take a line; `is_system` marks the
     * seeded accounts that cannot be deleted or re-coded; `external_ref` holds the accountant's own
     * code so an export lines up without a mapping spreadsheet.
     *
     * `default_tax_rate_id` and the dimension-mask check land with their tables (§17 Q4 leaves the
     * VAT rates unresolved), which is why it is an unconstrained bigint here rather than an FK that
     * would have to be dropped later.
     */
    public function up(): void
    {
        Schema::create('gl_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 16);
            $table->string('normal_balance', 6);
            $table->unsignedBigInteger('parent_id')->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);

            // §6.1 bit flags: truck|trip|customer|driver|cost_centre. A mask rather than five
            // booleans because the poster asserts one integer and the report reads it the same way.
            $table->unsignedTinyInteger('allowed_dimension_mask')->default(0);

            $table->unsignedBigInteger('default_tax_rate_id')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('external_ref', 40)->nullable();

            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'gl_accounts_company_code_unique');
            $table->index(['company_id', 'type', 'is_active'], 'gl_accounts_company_type_active_index');
            $table->index(['company_id', 'parent_id'], 'gl_accounts_company_parent_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        // Blueprint has no CHECK support (§20.4). §6.1 decision 2: `type` is a string + enum +
        // CHECK rather than a MySQL ENUM, because adding an ENUM value is an ALTER TABLE with the
        // whole value list and both engines enforce a CHECK list as one statement.
        DB::statement('alter table `gl_accounts` add constraint `gl_accounts_type_allowed` check (type in (\'asset\', \'liability\', \'equity\', \'revenue\', \'expense\'))');
        DB::statement('alter table `gl_accounts` add constraint `gl_accounts_normal_balance_allowed` check (normal_balance in (\'debit\', \'credit\'))');

        // Deliberately NOT checked: the type → normal_balance pairing. An accumulated-depreciation
        // account is `asset` with a credit normal balance, and a sales-discount account is `revenue`
        // with a debit one. A CHECK here would refuse the contra rows every real COA contains.
        $charLength = DB::connection()->getDriverName() === 'sqlite' ? 'length' : 'char_length';
        DB::statement("alter table `gl_accounts` add constraint `gl_accounts_code_not_blank` check ({$charLength}(trim(code)) > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_accounts');
    }
};
