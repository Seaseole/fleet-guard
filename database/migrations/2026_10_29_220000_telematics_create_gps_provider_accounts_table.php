<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.4 GPS_PROVIDER_ACCOUNT — one provider feed per company, and the seam the simulator and
     * Phase 7's real adapters both plug into.
     *
     * `adapter` is a key into `fleet.telematics.adapters`, not a CHECK list: adding Teltonika is a
     * config line plus a class, and a CHECK would make it a migration (§16 R6). One adapter per
     * company is enough because a company buys one telematics product; the unique key says so.
     *
     * `api_key_encrypted` is nullable and unused by the simulator. `key_version` sits beside it for
     * the same reason it sits beside a bank number (§9.5): once a credential is stored, rotating
     * the column key has to be possible without a data-loss event. The Phase 2 simulator carries
     * no credential at all, which is why both columns may be empty.
     */
    public function up(): void
    {
        Schema::create('gps_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('adapter', 32);
            $table->string('endpoint', 255)->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->string('key_version', 32)->nullable();

            $table->boolean('active')->default(true);
            $table->datetime('last_sync_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'adapter'], 'gps_provider_accounts_company_adapter_unique');
        });

        DB::statement('alter table `gps_provider_accounts` add constraint `gps_provider_accounts_adapter_named` check (char_length(trim(adapter)) > 0)');
        // A ciphertext with no key version cannot be rotated, and a key version with no ciphertext
        // is a promise the row does not keep. Either both are written or neither is (§9.5).
        DB::statement('alter table `gps_provider_accounts` add constraint `gps_provider_accounts_key_version_paired` check ((api_key_encrypted is null and key_version is null) or (api_key_encrypted is not null and key_version is not null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_provider_accounts');
    }
};
