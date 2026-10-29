<?php

declare(strict_types=1);

use App\Domain\Accounting\Ledger\LedgerService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 / §6.2 GL_JOURNAL_LINE — append-only, typed dimensions (ADR-0001 #2).
     *
     * `amount_base` is the authoritative number and the line is `direction` + one amount rather
     * than a debit and a credit column, so "a line carrying both" is unrepresentable instead of
     * checked. `posted_on` is denormalised from the entry for exactly the reason §6.7 gives:
     * date-range + dimension reports become single-table index range scans.
     *
     * HAZARD, stated rather than hidden: the dimension columns have **no foreign keys yet**. None
     * of their tables exist before Phase 1, and an FK to a table that does not exist is not a
     * deferral — it is a migration that cannot run. Referential integrity for dimensions therefore
     * rests on {@see LedgerService} asserting the mask, on the
     * account's own `allowed_dimension_mask`, and on `ledger:verify` in the meantime. Each Phase
     * 1+ migration adds the FK for the table it creates.
     */
    public function up(): void
    {
        Schema::create('gl_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies');
            $table->char('entry_id', 26);
            $table->foreignId('account_id')->constrained('gl_accounts');

            $table->string('direction', 6);
            $table->unsignedBigInteger('amount_foreign')->nullable();
            $table->unsignedBigInteger('amount_base');
            $table->date('posted_on');
            $table->char('currency_code', 3);
            $table->string('line_note', 191)->nullable();

            // §6.2 typed dimensions. A dimension earns a column when a report needs it twice —
            // route_id, tyre_id and permit_id deliberately stay in dimension_extra.
            $table->unsignedBigInteger('truck_id')->nullable();
            $table->unsignedBigInteger('trailer_id')->nullable();
            $table->char('trip_id', 26)->nullable();
            $table->char('job_id', 26)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->char('work_order_id', 26)->nullable();
            $table->char('invoice_id', 26)->nullable();
            $table->char('bill_id', 26)->nullable();
            $table->char('fuel_issue_id', 26)->nullable();
            $table->char('border_crossing_id', 26)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->json('dimension_extra')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('entry_id', 'gl_journal_lines_entry_index');
            $table->index(['account_id', 'posted_on'], 'gl_journal_lines_account_posted_index');
            $table->index(['posted_on', 'account_id'], 'gl_journal_lines_posted_account_index');
            $table->index(['trip_id', 'account_id'], 'gl_journal_lines_trip_account_index');
            $table->index(['truck_id', 'account_id', 'posted_on'], 'gl_journal_lines_truck_account_posted_index');
            $table->index(['customer_id', 'account_id', 'posted_on'], 'gl_journal_lines_customer_account_posted_index');
            $table->index('work_order_id', 'gl_journal_lines_work_order_index');
            $table->index(['company_id', 'posted_on'], 'gl_journal_lines_company_posted_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('entry_id')->references('id')->on('gl_journal_entries');
        });

        // ── §6.3 layer 2 ──
        DB::statement('alter table `gl_journal_lines` add constraint `gl_journal_lines_direction_allowed` check (direction in (\'debit\', \'credit\'))');

        // A zero-value line is not a line: it silently inflates line_count, and a posted entry
        // whose line_count does not match its real rows is the drift `ledger:verify` alarms on.
        DB::statement('alter table `gl_journal_lines` add constraint `gl_journal_lines_amount_positive` check (amount_base > 0)');
        DB::statement('alter table `gl_journal_lines` add constraint `gl_journal_lines_foreign_amount_positive` check (amount_foreign is null or amount_foreign > 0)');

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // ── §6.3 layer 3 ──
            DB::statement(<<<'SQL'
            create trigger `gl_journal_lines_bu` before update on `gl_journal_lines`
            for each row
            begin
              declare v_barrier int;

              select count(*) into v_barrier from `gl_ledger_barrier` where id = 1;

              if v_barrier = 0 then
                signal sqlstate '45000'
                  set message_text = 'Fleet Guard: ledger rows are immutable';
              end if;
            end
            SQL);

            DB::statement(<<<'SQL'
            create trigger `gl_journal_lines_bd` before delete on `gl_journal_lines`
            for each row
            begin
              declare v_barrier int;

              select count(*) into v_barrier from `gl_ledger_barrier` where id = 1;

              if v_barrier = 0 then
                signal sqlstate '45000'
                  set message_text = 'Fleet Guard: ledger rows are immutable';
              end if;
            end
            SQL);

            // Added beyond the §6.3 list, because it closes a hole the other four leave open: a line
            // appended to an entry that is already posted passes every CHECK (the entry's own totals do
            // not move), so the entry would claim to balance while its lines do not. Only an
            // unpublished entry accepts new lines, and the barrier row is the audited exception.
            DB::statement(<<<'SQL'
            create trigger `gl_journal_lines_bi` before insert on `gl_journal_lines`
            for each row
            begin
              declare v_status varchar(8);
              declare v_barrier int;

              select count(*) into v_barrier from `gl_ledger_barrier` where id = 1;

              if v_barrier = 0 then
                select status into v_status from `gl_journal_entries` where id = new.entry_id;

                if v_status is null or v_status <> 'draft' then
                  signal sqlstate '45000'
                    set message_text = 'Fleet Guard: lines may only be added to an unpublished journal entry';
                end if;
              end if;
            end
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::unprepared('drop trigger if exists `gl_journal_lines_bi`');
            DB::unprepared('drop trigger if exists `gl_journal_lines_bd`');
            DB::unprepared('drop trigger if exists `gl_journal_lines_bu`');
        }

        Schema::dropIfExists('gl_journal_lines');
    }
};
