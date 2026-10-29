<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0004 #8: a currency is a table, not an enum, because how many minor units a
     * currency has is a fact about the world rather than a decision of ours. Not company
     * scoped — the ISO 4217 list is shared reference data.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->unsignedTinyInteger('minor_units');
            $table->string('rounding_mode', 20)->default('half_up');
            $table->string('symbol', 8)->default('');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
