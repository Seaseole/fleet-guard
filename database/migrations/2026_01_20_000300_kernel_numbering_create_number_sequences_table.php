<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-0002 /. The row is the counter and the lock target: allocation is a
     * SELECT ... FOR UPDATE plus a transactional increment, so a rollback releases both the lock
     * and the value. That is the whole reason MariaDB's CREATE SEQUENCE was rejected — its values
     * are non-transactional, so a rolled-back invoice burns a number and creates the gap the
     * requirement forbids.
     */
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('scope', 40);
            $table->string('period_key', 12)->default('');
            $table->unsignedBigInteger('next_value')->default(1);
            $table->string('format', 64);

            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('last_used_by')->nullable()->constrained('users');
            $table->timestamps();

            // The allocation key. Also what makes step 1 of idempotent: INSERT IGNORE on a
            // duplicate lands the lock on an existing row rather than on an insert-intention gap.
            $table->unique(['company_id', 'scope', 'period_key'], 'number_sequences_company_scope_period_unique');
        });

        // Laravel's Blueprint has no CHECK support (measured on 13.38: Blueprint::check() does not
        // exist), so layer 2 of the design is raw DDL. Both engines enforce CHECK from MySQL 8.0.16 and
        // MariaDB 10.6.4 upward, which is the subset this project writes to.
        DB::statement('alter table `number_sequences` add constraint `number_sequences_next_value_positive` check (next_value > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
