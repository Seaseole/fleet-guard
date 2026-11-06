<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BANK_ACCOUNT. It lands here rather than with the ledger slice because it is the first
     * real subject for the `seeBankDetail` gate and the encrypted-column trio — the
     * Phase 0 definition of done needs a field somebody can be refused.
     *
     * `gl_account_id` is nullable and unconstrained until `gl_accounts` exists in the ledger slice
     * (#23); adding the FK then is a one-line migration, and inventing the ledger table early is not.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('name', 120);
            $table->string('institution', 120);
            $table->string('branch', 120)->nullable();

            // The trio. `number_masked` is in clear so lists, search and exports work; the
            // ciphertext never has to be read to render a row.
            $table->text('number_encrypted')->nullable();
            $table->string('number_masked', 32)->nullable();
            $table->string('key_version', 8)->nullable();

            $table->char('currency_code', 3);
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->string('type', 12)->default('bank');

            $table->date('opened_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'bank_accounts_company_name_unique');
            $table->index(['company_id', 'active'], 'bank_accounts_company_active_index');
            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        // Blueprint has no CHECK support. These two are the invariants a screen must not be
        // trusted to hold: a closed account cannot be active, and a type outside the enum is a
        // reporting bug that would otherwise only surface in a reconciliation a year later.
        DB::statement('alter table `bank_accounts` add constraint `bank_accounts_type_allowed` check (type in (\'bank\', \'cash\', \'wallet\'))');
        DB::statement('alter table `bank_accounts` add constraint `bank_accounts_closed_is_inactive` check (closed_on is null or active = 0)');

        //, Stated as data: closing the loop on "adding encryption later needs a re-encrypt
        // migration" — the row that stores the secret also records the key that made it, so a
        // missing version on a row that has ciphertext is a bug we can detect rather than assume.
        DB::statement('alter table `bank_accounts` add constraint `bank_accounts_key_version_required` check (number_encrypted is null or key_version is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
