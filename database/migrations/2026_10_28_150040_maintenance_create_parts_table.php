<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 PART — the workshop's catalogue of what it fits, not yet its stock.
     *
     * Phase 1 ships the register only (§15 phase 1 "parts register"): sku, name, category, the
     * cost it is bought at, and the vehicle it is specific to. Stock movements and counts arrive
     * with the workshop in phase 3 — Q5 chose an issue register with optional counts, so the
     * denormalised `stock_quantity` column §4.3 marks "only if perpetual stores chosen" is
     * deliberately absent until that decision is exercised.
     *
     * `truck_id` is nullable because most parts fit any truck; a body panel or an ECU does not.
     * The FK is restrictive rather than nulling: trucks are retired, never deleted (§2 deletes),
     * so a dangling specific part would mean something has gone wrong upstream.
     *
     * `unit_cost_minor` + `currency_code` follow §5.1: money is integer minor units and carries
     * its currency. A part bought in ZAR and fitted in Botswana must keep the number the invoice
     * showed, exactly as a rate card does.
     */
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('sku', 32);
            $table->string('name', 120);
            $table->string('category', 24)->default('other');

            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->char('currency_code', 3);

            $table->unsignedSmallInteger('reorder_level')->nullable();

            $table->foreignId('truck_id')->nullable()->constrained('trucks');
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'sku'], 'parts_company_sku_unique');
            $table->index(['company_id', 'active'], 'parts_company_active_index');
            $table->index(['company_id', 'category'], 'parts_company_category_index');
            $table->index(['company_id', 'truck_id'], 'parts_company_truck_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        DB::statement("alter table `parts` add constraint `parts_category_allowed` check (category in ('filters', 'lubricants', 'tyres', 'brakes', 'suspension', 'electrical', 'body', 'consumables', 'other'))");
        DB::statement('alter table `parts` add constraint `parts_unit_cost_not_negative` check (unit_cost_minor >= 0)');
        // A blank sku would collide with itself once and then be unaddressable by the stores clerk.
        DB::statement('alter table `parts` add constraint `parts_sku_not_blank` check (char_length(sku) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
