<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTIFICATION_PREFERENCE — per-user opt-out, per type, per channel.
     *
     * **A row's absence means "deliver".** That is the only shape that survives a new alert type: an
     * opt-in table would silently mute every type its author forgot to insert, and the whole
     * sentence is about the alert reaching somebody. The exception — a critical financial or safety
     * alert cannot be opted out of — is enforced where the delivery decision is made
     * (`AlertType::cannotBeMutedBy`), not here, because it depends on the alert's severity at the
     * moment it fired, not on the type's general character.
     *
     * `whatsapp_later` appears in the channel list and is deliberately absent from the CHECK: a row
     * naming a channel the application cannot send on is a preference that looks saved and does
     * nothing. When the channel ships, the CHECK widens in the same migration that adds the driver.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'type', 'channel'], 'notification_preferences_user_type_channel_unique');
        });

        DB::statement("alter table `notification_preferences` add constraint `notification_preferences_type_allowed` check (type in ('doc_expiry', 'service_due', 'overdue_invoice', 'fuel_variance', 'geofence', 'route_deviation', 'loan_due', 'cash_shortage', 'permit_expiry', 'posting_failure', 'self_check_failure'))");
        DB::statement("alter table `notification_preferences` add constraint `notification_preferences_channel_allowed` check (channel in ('mail', 'database'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
