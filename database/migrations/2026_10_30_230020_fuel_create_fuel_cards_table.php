<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUEL_CARD — a pump card the fleet holds, as master data (the card itself is not in the
     * Sketch; purchases name `fuel_card_id`, so the row has to exist somewhere).
     *
     * The card number is the trio: `<base>_encrypted` holds the ciphertext, `<base>_masked`
     * holds the display form, `key_version` records which key made it. A fuel card number is a
     * payment instrument — the same class of secret as a bank number — so it never lands in a plain
     * column and reading it back is a named, audited call, not an Eloquent cast.
     *
     * Alias and active are what the issue form shows: a clerk picks "Truck 7 — Engen" not sixteen
     * digits they cannot see.
     */
    public function up(): void
    {
        Schema::create('fuel_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('issuer_supplier_id')->nullable()->constrained('suppliers');

            $table->string('alias', 60);
            $table->text('card_ref_encrypted');
            $table->string('card_ref_masked', 32);
            $table->string('key_version', 20);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'alias'], 'fuel_cards_company_alias_unique');
        });

        DB::statement('alter table `fuel_cards` add constraint `fuel_cards_masked_not_plain` check (card_ref_masked <> \'\' and length(card_ref_encrypted) > length(card_ref_masked))');
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_cards');
    }
};
