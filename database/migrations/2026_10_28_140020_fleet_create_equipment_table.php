<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.3 EQUIPMENT — the yard's loaders, generators and toolboxes.
     *
     * Separate from trucks because it is dispatched by nobody and has no papers, but it still costs
     * money and still goes to the workshop, so it gets the same state column and the same cost
     * field. Keeping it out of `trucks` is what makes "how many vehicles do we run" an honest count.
     */
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('code', 24);
            $table->string('kind', 20)->default('loader');

            $table->string('status', 20)->default('available');
            $table->string('previous_state', 20)->nullable();
            $table->timestamp('state_changed_at')->nullable();

            $table->unsignedBigInteger('purchase_amount_base')->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'code'], 'equipment_company_code_unique');
            $table->index(['company_id', 'status'], 'equipment_company_status_index');
        });

        DB::statement('alter table `equipment` add constraint `equipment_kind_allowed` check (kind in (\'loader\', \'generator\', \'toolbox\'))');
        DB::statement('alter table `equipment` add constraint `equipment_status_allowed` check (status in (\'available\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `equipment` add constraint `equipment_previous_state_allowed` check (previous_state is null or previous_state in (\'available\', \'in_workshop\', \'out_of_service\', \'sold\'))');
        DB::statement('alter table `equipment` add constraint `equipment_purchase_not_negative` check (purchase_amount_base >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
