<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.1 PROOF_OF_DELIVERY — the evidence a delivery happened, one per leg that carried a load.
     *
     * A POD hangs off the **trip**, not the job: the customer's complaint is about the truck that did or
     * did not arrive, and §4.1's chain runs `trip → POD per leg → chargeables`. `load_id` is optional
     * because a trip may deliver against one manifest with a single signature for the lot.
     *
     * The photo and the signature are **documents**, not columns. The bytes, the MIME truth, the retention
     * class and the signed download URL are already the document module's job (§10.8), so a POD references
     * them by ULID and inherits all of it — including the rule that a signed POD is kept for the tax
     * horizon and not deleted because a dispatcher wanted the screen tidier.
     *
     * `client_uuid` is §9.6's offline answer and it is `UNIQUE`: a driver at a border post with one bar
     * of signal retries the submit, and the second attempt must land on the same row instead of becoming
     * a second delivery note. That is why it is a natural key rather than a `posted` flag — the retry is
     * the normal case, not the error case.
     *
     * `status` is the POD's own little machine (`pending → submitted → approved|rejected`), deliberately
     * separate from the trip's §10.2 state: §10.2 moves the trip `pod_pending → delivered` **only** on an
     * office approval, so a driver's submit is not the same fact as an accountant accepting it, and one
     * column that tried to carry both would let the kiosk complete a delivery by itself.
     */
    public function up(): void
    {
        Schema::create('proof_of_deliveries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('trip_id', 26);
            $table->foreignId('load_id')->nullable()->constrained('loads');

            $table->string('status', 16)->default('pending');
            $table->datetime('delivered_at')->nullable();
            $table->string('receiver_name', 120)->nullable();
            $table->decimal('quantity_received_tonnes_10_2', 8, 2)->nullable();

            $table->char('signature_document_id', 26)->nullable();
            $table->json('exceptions')->nullable();

            $table->char('client_uuid', 36)->nullable();
            $table->char('captured_by_token_id', 26)->nullable();
            $table->timestamps();

            $table->unique('client_uuid', 'proof_of_deliveries_client_uuid_unique');
            $table->unique(['id', 'company_id'], 'proof_of_deliveries_id_company_unique');
            $table->index(['company_id', 'trip_id'], 'proof_of_deliveries_company_trip_index');
            $table->index(['company_id', 'status'], 'proof_of_deliveries_company_status_index');
        });

        DB::statement('alter table `proof_of_deliveries` add constraint `pod_trip_company_fk` foreign key (`trip_id`, `company_id`) references `trips` (`id`, `company_id`) on delete cascade');
        // `restrict`, not `set null`: MySQL refuses a `SET NULL` on a column a CHECK reads, and the rule
        // is the one we want anyway — §10.8 keeps a signed POD for the tax horizon, so deleting the
        // signature image under an approved delivery is an error to raise, not a cascade to perform.
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_signature_document_fk` foreign key (`signature_document_id`) references `documents` (`id`) on delete restrict');
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_capture_token_fk` foreign key (`captured_by_token_id`) references `trip_access_tokens` (`id`) on delete set null');

        DB::statement('alter table `proof_of_deliveries` add constraint `pod_status_allowed` check (status in (\'pending\', \'submitted\', \'approved\', \'rejected\'))');

        // §10.2's gate as an engine rule: nothing that claims to have been delivered is undated, and
        // nothing that claims to be approved is unsigned. Both belong below the application because a
        // half-written POD is exactly what a retry after a dropped signal produces.
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_dated_once_submitted` check (status = \'pending\' or delivered_at is not null)');
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_approved_is_signed` check (status <> \'approved\' or signature_document_id is not null)');
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_quantity_positive` check (quantity_received_tonnes_10_2 is null or quantity_received_tonnes_10_2 > 0)');
        DB::statement('alter table `proof_of_deliveries` add constraint `pod_receiver_not_blank` check (receiver_name is null or char_length(trim(receiver_name)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
    }
};
