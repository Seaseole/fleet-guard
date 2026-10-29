<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's `DatabaseNotification` schema, verbatim (§14.1's delivery records).
     *
     * This table is why the bell can exist at all. `App\Models\User` has used the `Notifiable` trait
     * since slice 5, and the framework's `database` channel writes straight into `notifications` — a
     * table the skeleton never created and nothing noticed, because no code had sent a database
     * notification yet. Adding it here is not new design: it is the half of §14.1's "notifications are
     * a delivery of an alert" that had no destination.
     *
     * The bell reads *this* table rather than `alerts` because "unread" is a property of a person and
     * an alert row is a property of a company. One `doc_expiry` alert is delivered to the Operations
     * Manager and the Dispatcher; the first one opening it must not clear the second one's dot, and
     * one `read_at` column on the alert could only ever belong to one of them.
     *
     * The `morphs('notifiable')` index is widened to lead with the columns the bell actually filters
     * on: an unread count runs on every page render (§DESIGN-SYSTEM's topbar), so it must be an index
     * seek rather than a scan of every delivery the company has ever made.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
