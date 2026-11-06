<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TYRE — one physical casing, tracked from purchase to scrap (phase 3, slice 3f).
     *
     * A tyre is an asset with a serial number, not a line in the parts catalogue: it is bought once,
     * fitted to many positions over its life, retreaded, and eventually scrapped. Its identity is the
     * serial — two tyres of the same size are not interchangeable in the book, because the whole point
     * of tracking one is knowing what happened to it.
     *
     * The status set includes `rotating`. Nothing stores it: a rotation is a removal and a fit,
     * and a row that sat in `rotating` would be a tyre fitted to no wheel with a status that says it
     * is on its way somewhere. This slice keeps the four states a tyre can honestly be observed in —
     * on the spares rack, fitted, waiting for a retread, scrapped — and the two-position transition
     * is the rotation. The deviation is recorded in DESIGN.
     *
     * `purchase_cost_base` is what the casing cost, in the company's currency; the cost per kilometre
     * a fitment earns is derived at removal by the position row, not stored here.
     */
    public function up(): void
    {
        Schema::create('tyres', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('serial', 32);
            $table->string('brand', 40)->nullable();
            $table->string('size', 20);

            $table->unsignedBigInteger('purchase_cost_base')->default(0);
            // Millimetres ×100, so 8.5 mm is 850 and the tread is an integer (the scale).
            $table->unsignedSmallInteger('tread_depth_new_x100')->nullable();

            $table->string('status', 12)->default('on_spares');
            $table->timestamps();

            $table->unique(['company_id', 'serial'], 'tyres_company_serial_unique');
            $table->index(['company_id', 'status'], 'tyres_company_status_index');
        });

        DB::statement("alter table `tyres` add constraint `tyres_status_allowed` check (status in ('on_spares', 'in_service', 'retread_due', 'scrap'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tyres');
    }
};
