<?php

declare(strict_types=1);

use App\Domain\Invoicing\Data\CustomerDto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 CUSTOMER — the party an invoice is addressed to.
     *
     * `name` is the reference a dispatcher and an accountant both recognise, so it is unique per
     * company; `code` is the stable key a legacy export or a statement import can carry, because a
     * name is editable business text and an invoice history keyed on it breaks the first time a
     * customer rebrands. It is nullable — a company that has never imported anything is not asked
     * to invent one.
     *
     * `payment_terms` is deliberately a string, not an ENUM or a CHECK list: §4.1 sketches it as
     * `net_30`, and an owner negotiating `net_45` must not need a migration. The vocabulary
     * (`cash` or `net_<days>`) is validated once, in {@see CustomerDto},
     * which is also the path the Phase 1 importer uses — so the rule holds for typed entry and for
     * a CSV alike.
     *
     * No credit limit column yet. Credit control is a Phase 4 invoicing question and a limit with
     * nobody enforcing it is a number the owner will trust by mistake.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 24)->nullable();
            $table->string('name', 120);

            $table->string('contact_name', 80)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('vat_number', 32)->nullable();

            $table->string('payment_terms', 24)->default('net_30');

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'customers_company_name_unique');
            $table->unique(['company_id', 'code'], 'customers_company_code_unique');
            $table->index(['company_id', 'active'], 'customers_company_active_index');
        });

        // A customer with no way to reach them is a row that will be chased by phone anyway; the
        // email is optional because a cash customer at the yard may never give one.
        DB::statement('alter table `customers` add constraint `customers_payment_terms_not_blank` check (char_length(payment_terms) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
