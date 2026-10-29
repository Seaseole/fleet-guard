<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.4 BORDER_POST — the master list of gates a trip can cross at.
     *
     * Held per company because the post's country and opening hours are operational facts a
     * dispatcher edits, not a global gazetteer. `open_from`/`open_to` are nullable: a post that does
     * not publish hours is a real post, and a made-up 00:00–23:59 would read as one that never shuts.
     */
    public function up(): void
    {
        Schema::create('border_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('name', 120);
            $table->char('country_code', 2);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->time('open_from')->nullable();
            $table->time('open_to')->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'name', 'country_code'], 'border_posts_name_unique');
        });

        DB::statement('alter table `border_posts` add constraint `border_posts_country_upper` check (country_code = upper(country_code))');
    }

    public function down(): void
    {
        Schema::dropIfExists('border_posts');
    }
};
