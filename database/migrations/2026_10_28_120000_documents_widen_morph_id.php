<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A document's subject is now real: phase 1 attaches licences and permits to vehicles and
     * drivers, whose keys are `bigint`, while document rows themselves keep a ULID.
     *
     * `char(26)` was sized for the ULID and nothing else. A `bigint` key does fit inside it, but a
     * fixed-width column pads every shorter subject with spaces and invites a length assumption
     * every later store (`uuid`, a prefixed `truck:12`) would have to be checked against. A
     * variable-width 32 is the shape the pair actually has: heterogeneous, opaque, only ever
     * followed back to a row.
     *
     * The index is (morph_type, morph_id); MySQL rebuilds it as part of the MODIFY, so no index
     * work is needed here. The table held no rows when this ran, so no backfill or truncation
     * guard is warranted — `down()` restores the narrower char and would fail loudly on a
     * subject that does not fit rather than silently truncate.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('morph_id', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->char('morph_id', 26)->nullable()->change();
        });
    }
};
