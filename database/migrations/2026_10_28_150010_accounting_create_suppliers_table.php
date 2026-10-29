<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SUPPLIER — the party that bills the fleet (§4.2 `SUPPLIER ||--o{ BILL`).
     *
     * The sketch in §4.2 shows the relationship but not the columns, so the shape is taken from
     * what a bill actually needs: a name to print, a category that decides which expense account
     * the bill lands on (§4.2 `BILL.category`), and the contact a buyer calls when a delivery is
     * short. `category` is one of the six values §4.2 already defines for a bill — using the same
     * vocabulary here is what lets Phase 5 default the bill's category from the supplier instead
     * of asking every time.
     *
     * Bank details are deliberately **not** here. Paying a supplier is a Phase 5 concern with its
     * own §9.4 gate (`seeBankDetail`) and its own encryption trio (§9.5); a plaintext account
     * number sitting on a master-data row until then would be the wrong half of that design.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 24)->nullable();
            $table->string('name', 120);
            $table->string('category', 16)->default('other');

            $table->string('contact_name', 80)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('vat_number', 32)->nullable();

            $table->string('payment_terms', 24)->default('net_30');

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'suppliers_company_name_unique');
            $table->unique(['company_id', 'code'], 'suppliers_company_code_unique');
            $table->index(['company_id', 'active'], 'suppliers_company_active_index');
            $table->index(['company_id', 'category'], 'suppliers_company_category_index');
        });

        DB::statement('alter table `suppliers` add constraint `suppliers_category_allowed` check (category in (\'fuel\', \'parts\', \'labour\', \'insurance\', \'lease\', \'other\'))');
        DB::statement('alter table `suppliers` add constraint `suppliers_payment_terms_not_blank` check (char_length(payment_terms) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
