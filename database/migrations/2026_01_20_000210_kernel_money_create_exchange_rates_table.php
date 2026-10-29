<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0004 #7: a reference lookup only. It pre-fills a new document and powers the
     * "this rate is four days stale" warning — it never joins into a historical amount,
     * because a mutable rate behind an immutable ledger is how trial balances stop tying.
     *
     * `rate_to_base` carries the direction convention in its name: 1 foreign unit = X base,
     * always, which is why there is no rate_type column.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            // The foreign currency; the base side is the company's, resolved not stored.
            $table->char('currency_code', 3);
            $table->date('rate_date');
            $table->decimal('rate_to_base', 19, 8);

            $table->string('source', 40);
            $table->string('ref', 80)->nullable();
            $table->timestamp('entered_at');

            $table->timestamps();

            $table->unique(
                ['company_id', 'currency_code', 'rate_date', 'source'],
                'exchange_rates_company_currency_date_source_unique',
            );

            // The read that actually happens: "give me the newest rate for ZAR as of today".
            $table->index(
                ['company_id', 'currency_code', 'rate_date'],
                'exchange_rates_lookup_index',
            );

            $table->foreign('currency_code')->references('code')->on('currencies');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
