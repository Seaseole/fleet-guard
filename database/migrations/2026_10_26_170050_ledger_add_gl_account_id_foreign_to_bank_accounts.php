<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The deferred half of the bank-account migration: `bank_accounts.gl_account_id` was created as
     * an unconstrained bigint in #22 because `gl_accounts` did not exist yet. Now it does, so the
     * linkage the ledger needs to move money through a bank or cash account becomes real.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('alter table `bank_accounts` add constraint `bank_accounts_gl_account_fk` foreign key (`gl_account_id`) references `gl_accounts` (`id`)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('alter table `bank_accounts` drop constraint `bank_accounts_gl_account_fk`');
        }
    }
};
