<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §4.2 / §6.1 GL_JOURNAL_ENTRY — the header row of the ledger (§6.1 decision 3).
     *
     * The FX block lives HERE and not on the lines: a line in a different currency from its own
     * entry is a re-design, not a column. `posted_at` is the UTC instant and `posted_on` is the
     * Africa/Gaborone business date that resolves the period (ADR-0001 #6) — two columns because
     * one timestamp cannot be both an audit instant and an accounting date.
     *
     * `total_debit`/`total_credit`/`line_count` are computed by the poster and verified by
     * `ledger:verify`: a CHECK cannot aggregate across rows (§6.3 layer 2).
     */
    public function up(): void
    {
        Schema::create('gl_journal_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->default(1)->constrained('companies');

            $table->string('entry_no', 40);
            $table->timestamp('posted_at');
            $table->date('posted_on');
            $table->foreignId('period_id')->constrained('gl_periods');
            $table->string('status', 8)->default('draft');
            $table->string('description', 255);

            // §5.2 block, named as Fx::toColumns() emits it. The entry has no single `amount`
            // column: its foreign/base pair is (sum of lines in currency_code, total_*_base).
            $table->char('currency_code', 3);
            $table->decimal('fx_rate', 19, 8);
            $table->string('fx_rate_source', 40);
            $table->date('fx_rate_date');
            $table->string('fx_rate_ref', 80)->nullable();
            $table->char('base_currency_code', 3);

            $table->unsignedBigInteger('total_debit')->default(0);
            $table->unsignedBigInteger('total_credit')->default(0);
            $table->unsignedSmallInteger('line_count')->default(0);

            // ADR-0001 #3: the source stays on the entry (one document → one entry) and the
            // triple is the replay guard. NOT NULL with '' rather than nullable on purpose:
            // MySQL treats NULLs as distinct inside a UNIQUE index, so a nullable
            // `source_event_id` would let a duplicate posting through the very constraint
            // that exists to stop it.
            $table->string('source_type', 40);
            $table->char('source_id', 26)->default('');
            $table->char('source_event_id', 26)->default('');

            $table->char('reverses_entry_id', 26)->nullable();
            $table->char('netting_group', 26)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'entry_no'], 'gl_journal_entries_company_entry_no_unique');

            // Idempotency: a redelivered domain event cannot post the same entry twice.
            $table->unique(
                ['company_id', 'source_type', 'source_id', 'source_event_id'],
                'gl_journal_entries_source_idempotency_unique',
            );

            $table->index('posted_on', 'gl_journal_entries_posted_on_index');
            $table->index('period_id', 'gl_journal_entries_period_index');
            $table->index(['source_type', 'source_id'], 'gl_journal_entries_source_index');
            $table->index(['company_id', 'status', 'posted_on'], 'gl_journal_entries_company_status_posted_index');
            $table->index('netting_group', 'gl_journal_entries_netting_group_index');
            $table->index(['company_id', 'reverses_entry_id'], 'gl_journal_entries_reverses_index');

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('base_currency_code')->references('code')->on('currencies');
            $table->foreign('reverses_entry_id')->references('id')->on('gl_journal_entries');
        });

        // ── §6.3 layer 2: CHECK constraints (raw DDL — Blueprint::check() does not exist) ──
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_status_allowed` check (status in (\'draft\', \'posted\', \'void\'))');
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_posted_balanced` check (status <> \'posted\' or (total_debit = total_credit and total_debit > 0))');
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_draft_is_empty` check (status <> \'draft\' or (total_debit = 0 and total_credit = 0))');
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_posted_has_lines` check (status <> \'posted\' or line_count >= 2)');

        // §5.1 rule 2 stated as data: 1 foreign unit = X base, always, so a same-currency row
        // whose rate is not 1 is an inverted feed. This is the constraint that protects the
        // convention even when the poster is a panic-edited SQL client rather than the Action.
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_same_currency_rate_is_one` check (currency_code <> base_currency_code or fx_rate = 1)');
        DB::statement('alter table `gl_journal_entries` add constraint `gl_journal_entries_rate_positive` check (fx_rate > 0)');

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // ── §6.3 layer 4: refuse a posting into a locked period ──
            // A CHECK cannot read another table, so this one has to be a trigger. The period row is
            // resolved by the poster and stored on the entry, which keeps this a single-row lookup.
            DB::statement(<<<'SQL'
            create trigger `gl_journal_entries_bi` before insert on `gl_journal_entries`
            for each row
            begin
              declare v_status varchar(8);

              select status into v_status from `gl_periods` where id = new.period_id;

              if v_status is null then
                signal sqlstate '45000'
                  set message_text = 'Fleet Guard: journal entry names a period row that does not exist';
              end if;

              if v_status = 'locked' then
                signal sqlstate '45000'
                  set message_text = 'Fleet Guard: this accounting period is locked';
              end if;
            end
            SQL);

            // ── §6.3 layer 3: immutability, unless the barrier row is present ──
            // The one permitted update is the transition of an *unpublished* entry — draft → posted
            // (which is where the poster writes the totals) and draft → void. A posted entry is never
            // updated: §6.4 corrects it with a reversing entry instead.
            DB::statement(<<<'SQL'
            create trigger `gl_journal_entries_bu` before update on `gl_journal_entries`
            for each row
            begin
              declare v_barrier int;

              select count(*) into v_barrier from `gl_ledger_barrier` where id = 1;

              if v_barrier = 0 and not (old.status = 'draft' and new.status in ('posted', 'void')) then
                signal sqlstate '45000'
                  set message_text = 'Fleet Guard: ledger rows are immutable';
              end if;
            end
            SQL);

            DB::statement(<<<'SQL'
            create trigger `gl_journal_entries_bd` before delete on `gl_journal_entries`
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
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::unprepared('drop trigger if exists `gl_journal_entries_bd`');
            DB::unprepared('drop trigger if exists `gl_journal_entries_bu`');
            DB::unprepared('drop trigger if exists `gl_journal_entries_bi`');
        }

        Schema::dropIfExists('gl_journal_entries');
    }
};
