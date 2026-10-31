<?php

declare(strict_types=1);
use App\Domain\Telematics\Adapters\SimulatorProvider;

/*
 * Module-level knobs that must be data rather than code, so that an open question in DESIGN.md
 * §17 becomes a config edit instead of a migration. Company-held values (base currency, fiscal
 * year, thresholds) live on the companies row, not here — this file holds the shape, that row
 * holds the numbers (T4).
 */

return [

    'queue_health' => [
        'company_id' => (int) env('FLEET_QUEUE_OPERATOR_COMPANY_ID', 1),
        'queues' => ['default', 'alerts'],
        'max_wait_seconds' => 300,
    ],

    /*
     * §20.37 — the two things a Owner now sets at runtime rather than in a deploy.
     *
     * `appearance.theme` is what every device in the company opens in (light | dark | system); a seat
     * may still override it for its own browser, which is stored client-side because it has to apply
     * before first paint. The deployment default matters for the login screen, which has no company.
     *
     * `tax.standard_rate_pct` is only the **starting** figure: the standard VAT rate's truth is the
     * `tax_rates` register, dated window and all (§17 Q4), and the Application-settings field reads and
     * writes that register through `SetStandardVatRate`. The default lives here so the field has a
     * number to show before the register is seeded, not so the blob can hold a second rate.
     */
    'appearance' => [
        'theme' => env('FLEET_DEFAULT_THEME', 'system'),
    ],

    'tax' => [
        'standard_rate_pct' => 12.0,
    ],

    'numbering' => [

        /*
         * Every gap-free scope, its default format and its restart policy.
         *
         * `restart` is the unresolved §17 Q2/Q3, kept per scope so a per-calendar-year fiscal
         * sequence and a continuous operational reference can coexist:
         *   - continuous      : period_key '' — one unbroken run forever
         *   - calendar_year   : period_key '2026'
         *   - financial_year  : period_key from company.fiscal_year_starts_on, e.g. 'FY26/27'
         *
         * Formats understand {PERIOD}, {SEQ:n} and any literal. The rendered value is written to
         * the document once, at issue (§7.4).
         */
        'scopes' => [
            'invoice' => ['format' => 'INV-{PERIOD}-{SEQ:7}', 'restart' => 'financial_year', 'fiscal' => true],
            'credit_note' => ['format' => 'CN-{PERIOD}-{SEQ:7}', 'restart' => 'financial_year', 'fiscal' => true],
            'debit_note' => ['format' => 'DN-{PERIOD}-{SEQ:6}', 'restart' => 'financial_year', 'fiscal' => true],
            'receipt' => ['format' => 'RCP-{PERIOD}-{SEQ:6}', 'restart' => 'financial_year', 'fiscal' => true],
            'pro_forma' => ['format' => 'PF-{PERIOD}-{SEQ:6}', 'restart' => 'financial_year', 'fiscal' => false],
            'journal_entry' => ['format' => 'JV-{PERIOD}-{SEQ:6}', 'restart' => 'financial_year', 'fiscal' => true],
            // One return per period is the intent, but the counter is still what makes a second
            // one in the same period impossible — a format without {SEQ} cannot be gap-free and
            // the validator refuses it, so the period alone is not the numbering.
            'vat_return' => ['format' => 'VAT-{PERIOD}-{SEQ:2}', 'restart' => 'financial_year', 'fiscal' => true],

            // Operational references: never reused matters more here than no gaps.
            'trip' => ['format' => 'TRP-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
            'job' => ['format' => 'JOB-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
            'work_order' => ['format' => 'WO-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
            'purchase_order' => ['format' => 'PO-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
            // §20.35 (Phase 5 slice 5a). A bill is the *supplier's* fiscal document, not ours — our own
            // reference needs never-reused, not gap-free-at-issue — so it takes the operational shape
            // and is numbered at creation like `WO-`.
            'bill' => ['format' => 'BILL-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
            // §20.36 (Phase 5 slice 5b). Our own bank fact, not a fiscal document: same operational
            // shape as the bill it settles.
            'supplier_payment' => ['format' => 'PAY-{SEQ:5}', 'restart' => 'continuous', 'fiscal' => false],
        ],

        /*
         * Where each scope's allocated numbers physically live, so `documents:number-integrity`
         * can prove the sequence and the documents agree. A scope absent from this map is
         * reported as "unchecked" rather than silently passing — the command must never look
         * green because it checked nothing. Each module registers its store when it ships.
         *
         *   table         the documents table
         *   number_column the document's own reference
         *   status_column + draft_status: the value that means "not yet issued", so §7.4's rule
         *                 (number at issue, never at creation) can be verified as data
         */
        'stores' => [
            // Registered by §20.32. The `status_column` is what lets `documents:number-integrity` prove §7.4's
            // rule as data: a draft invoice must carry no number, and every issued one must carry the number
            // its sequence gave it. `credit_note` and `receipt` join when their issue paths exist.
            'invoice' => ['table' => 'invoices', 'number_column' => 'number', 'status_column' => 'status', 'draft_status' => 'draft'],
            // §20.34. A credit note is issued and applied in one transaction today, but the draft state the
            // column carries is the one §7.4's rule must be able to prove the day a draft editor exists.
            'credit_note' => ['table' => 'credit_notes', 'number_column' => 'number', 'status_column' => 'status', 'draft_status' => 'draft'],

            // Operational references are numbered **at creation**, not at issue (§7: "never reused
            // matters more here than no gaps"), so none declares a `status_column`. A draft trip
            // already carries its `TRP-` number, which is what lets a dispatcher quote it to the
            // yard over the phone before anyone has chosen a truck; a work order is quoted the same
            // way when the fitter rings the supplier, so `WO-` is allocated when it is raised.
            'trip' => ['table' => 'trips', 'number_column' => 'trip_no'],
            'job' => ['table' => 'freight_jobs', 'number_column' => 'job_no'],
            'work_order' => ['table' => 'work_orders', 'number_column' => 'wo_no'],
            // §20.35. Numbered at creation, so no `status_column`: even a draft bill carries its
            // BILL- reference, exactly as a draft trip carries its TRP- number.
            'bill' => ['table' => 'bills', 'number_column' => 'number'],
            // §20.36. The same shape: a payment is created already recorded.
            'supplier_payment' => ['table' => 'supplier_payments', 'number_column' => 'number'],
        ],

        'integrity' => [
            // A gap of this size or more is an alarm rather than a footnote. Kept at 1 because the
            // promise is gap-free: a single missing number is a missing number.
            'alarm_gap' => 1,
        ],
    ],

    /*
     * Commercial master data (§4.1). The corridor set a route may name as its endpoints.
     *
     * Data rather than an enum or a DB CHECK because it is a business fact: the day the company
     * takes a lane to Lusaka the fix is a line here, not a migration. It is deliberately **not** a
     * full ISO table — only the countries this fleet drives into are listed, so a mistyped code in
     * a route form is refused instead of being accepted as a plausible-looking ISO code that no
     * cross-border summary will ever group correctly.
     */
    'commercial' => [
        'countries' => [
            'BW' => 'Botswana',
            'ZA' => 'South Africa',
            'NA' => 'Namibia',
            'ZW' => 'Zimbabwe',
            'ZM' => 'Zambia',
            'MZ' => 'Mozambique',
            'AO' => 'Angola',
            'CD' => 'DR Congo',
            'TZ' => 'Tanzania',
            'MW' => 'Malawi',
        ],
    ],

    /*
     * §9.6 — the driver's field-capture door.
     *
     * The horizon is measured from the trip's own end rather than from the issue moment because a
     * cross-border truck is gone for a week: a token that expired at midnight would strand the driver at
     * a border post and teach the yard to keep a paper POD instead. `pin_attempts` is §9.6's rate limit on
     * redemption — a 6-digit PIN is 10^6 possibilities, which is nothing without a counter.
     */
    'kiosk' => [
        'ttl_hours_after_trip_end' => (int) env('FLEET_KIOSK_TTL_HOURS', 24),
        'pin_attempts_per_minute' => (int) env('FLEET_KIOSK_PIN_ATTEMPTS', 5),
    ],

    /*
     * §4.4 / §14.3 / §16 R6 — the GPS seam.
     *
     * `adapters` maps a `gps_provider_accounts.adapter` value to the class that parses that
     * provider's payload. Phase 2 ships the simulator only; Phase 7 adds `teltonika`, `gaia` and
     * `flespi` here and nothing else about ingest changes (§16 Q7's pull fallback lands as a second
     * entry point into the same Action).
     *
     * `places` is the simulator's corridor reference, not business master data: a route stores place
     * *names*, and the simulator needs two points to walk between. Coordinates are the published
     * centres of the corridor cities the demo fleet runs; a route whose endpoint is not listed is
     * refused with a message asking for `--from`/`--to` rather than guessed at. Depots and customer
     * sites get their real coordinates in Phase 7's geofence setup (§16 Q7).
     */
    'telematics' => [
        'adapters' => [
            'simulator' => SimulatorProvider::class,
        ],

        // §12.2 defers Reverb: a 60 s poll is the whole live-map transport at this fleet size, and
        // the page reads the projection table, never the history table (§14.3).
        'poll_seconds' => (int) env('FLEET_GPS_POLL_SECONDS', 60),

        // A fix older than this is shown as stale rather than as the truck's current place. The
        // figure is generous for a corridor: a truck out of signal at Kazungula is still trackable,
        // a truck silent for an hour is a question for dispatch.
        'stale_after_minutes' => (int) env('FLEET_GPS_STALE_MINUTES', 15),

        'simulator' => [
            'speed_kph' => (int) env('FLEET_GPS_SIMULATOR_SPEED', 80),
            'places' => [
                'Gaborone' => [-24.6282, 25.9231],
                'Francistown' => [-21.1670, 27.5120],
                'Kazungula' => [-17.7920, 25.2320],
                'Kasane' => [-17.8167, 25.1500],
                'Maun' => [-19.9833, 23.4167],
                'Windhoek' => [-22.5609, 17.0658],
                'Walvis Bay' => [-22.9576, 14.5053],
                'Johannesburg' => [-26.2041, 28.0473],
                'Durban' => [-29.8587, 31.0218],
                'Beitbridge' => [-22.2167, 30.0000],
                'Lusaka' => [-15.3875, 28.3228],
                'Ndola' => [-12.9587, 28.6366],
                'Harare' => [-17.8252, 31.0335],
                'Bulawayo' => [-20.1535, 28.5738],
                'Beira' => [-19.8436, 34.8389],
                'Maputo' => [-25.9692, 32.5732],
                'Dar es Salaam' => [-6.7924, 39.2083],
            ],
        ],
    ],

    'modules' => [
        // Phase flags let a screen ship disabled rather than half-built. Telematics is on since
        // Phase 2 slice 2g: the simulator, the ingest door and the trip map are real. The flag now
        // only tells a deployment whether provider accounts are expected (§16 R6).
        'telematics' => env('FLEET_MODULE_TELEMATICS', true),
        'payroll' => env('FLEET_MODULE_PAYROLL', false),
    ],

    /*
     * §14.4 — documents and storage.
     *
     * The allowlist and the cap are keyed by *type* rather than global because the honest risk
     * differs per paper: a POD is a phone photo, a customs declaration is a scanned multi-page PDF,
     * and a licence is a single page. One global "10 MB, any MIME" rule would either reject a
     * legitimate weighbridge bundle or accept an executable as a licence.
     *
     * `mime` is checked against the sniffed content type, never against the client's declared one
     * (§14.4's "type validation by MIME sniff of the content, not the extension"). A type not
     * listed here is refused at upload rather than defaulted to something permissive — adding a
     * document type is a deliberate act.
     */
    'documents' => [
        // §14.4: short TTL, no token store. Sixty seconds is enough to start a download from a
        // click and too short for a link to be forwarded and still work.
        'signed_url_ttl_seconds' => (int) env('FLEET_DOCUMENT_URL_TTL', 60),

        // none | clamav. `none` is not "we trust the file", it is "this environment has no scanner
        // and the download gate therefore refuses anything unscanned in production" (§17 Q8).
        'scanner' => env('FLEET_DOCUMENT_SCANNER', 'none'),
        'clamav_host' => env('FLEET_CLAMAV_HOST', ''),
        'clamav_port' => (int) env('FLEET_CLAMAV_PORT', 3310),

        'types' => [
            'contract' => ['mime' => ['application/pdf'], 'max_bytes' => 20971520],
            'licence' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], 'max_bytes' => 8388608],
            'insurance' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 15728640],
            'permit' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 8388608],
            'delivery_note' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 8388608],
            // Kiosk-captured (§9.6): the phone produces a JPEG, and a 5 MB cap is the difference
            // between "the driver photographed the load" and "the upload failed at the border".
            'pod' => ['mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], 'max_bytes' => 8388608],
            'customs' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 20971520],
            'work_order' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 10485760],
            'maintenance' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png'], 'max_bytes' => 10485760],
            // A weighbridge slip arrives as a PDF, a photo of the printed ticket, or the bridge
            // operator's CSV. Both `text/csv` and `text/plain` are listed because the sniffed answer
            // depends on the file's shape and on the magic database in the PHP build: measured on
            // PHP 8.4.27 here, a comma-separated header row reports `text/csv` and a single column
            // reports `text/plain`. An allowlist that names only the one this machine produces today
            // is a rule that breaks on next month's ticket — and the sniffed value, never the
            // extension, is what the gate compares.
            'weighbridge' => ['mime' => ['application/pdf', 'image/jpeg', 'image/png', 'text/csv', 'text/plain'], 'max_bytes' => 5242880],
        ],

        // §10.8's derived `expiring_soon` window, in days. Sixty because the re-registration queue
        // for a licence, permit or insurance policy is measured in weeks, and a reminder that fires
        // at 14 days arrives after the broker has stopped answering.
        'expiry_warning_days' => (int) env('FLEET_DOCUMENT_EXPIRY_WARNING_DAYS', 60),
    ],

    /*
     * Identity (§9.1): how long an invitation lives, how often one may be sent, and the values the
     * first-run setup reads. Product rules with an owner-visible answer, so they are config —
     * unlike `crypto` below, which is key material.
     */
    'identity' => [
        // An invitation is single-use and expires. 72 h covers "the Owner sent it on Friday, the
        // clerk opens it Monday" without leaving a live link in an inbox over a week.
        'invite_ttl_hours' => (int) env('FLEET_INVITE_TTL_HOURS', 72),

        // Per (email + IP), matching the login limiter's shape in §9.1.
        'invite_throttle' => ['max' => 5, 'decay_seconds' => 3600],

        // Owner-first setup (§9.1). Values, not code: the first-run command reads them and refuses
        // to invent a placeholder address (§14.6). The owner's password is deliberately **not** a
        // config key — `config:cache` writes values to disk, so the secret is read from the process
        // environment by the seeder and the setup command instead.
        'owner_email' => env('FLEET_OWNER_EMAIL', ''),
        'owner_name' => env('FLEET_OWNER_NAME', 'Owner'),

        // §9.1's re-auth window, in seconds, for the ⚑ abilities (post a manual journal, change
        // bank details, close a period). Fifteen minutes is long enough to finish the one
        // transaction the confirmation was for and short enough that a left session does not
        // stay authorised to move money.
        'reauth_window_seconds' => (int) env('FLEET_REAUTH_WINDOW_SECONDS', 900),

        // §9.2's absolute ceiling on a signed-in device, measured from the sign-in itself so activity
        // cannot extend it: `config/session.lifetime` is an *idle* timeout, and an hour of clicking is
        // how a hijacked session survives a 2-hour setting forever. Twelve hours is one working day in
        // Gaborone — long enough that a dispatcher mid-shift is not cut off, short enough that a
        // forgotten laptop on a depot desk is not a standing credential the next morning.
        'session_absolute_seconds' => (int) env('FLEET_SESSION_ABSOLUTE_SECONDS', 43200),
    ],

    /*
     * §14.1 — the reminder engine.
     *
     * Both keys here are *starting values*, not the rule. The thresholds that decide when a reminder
     * fires and who is told are `reminder_thresholds` rows, per company and per family, because §14.1 is
     * emphatic that 60/30/14/7 are "rows, not constants" and an Owner who wants 45 days must be able to
     * say so without a deploy. `default_buckets` is only what `reminders:ensure-thresholds` writes the
     * first time a company has none, and a family with no rows afterwards means the Owner deleted it.
     */
    'reminders' => [
        'default_buckets' => [60, 30, 14, 7],

        /*
         * Who hears about a failed scheduled self-check (§14.6). Role keys, the same vocabulary as the
         * threshold rows' `recipients` and the same rule: an unknown key throws rather than quietly
         * notifying nobody.
         *
         * Config rather than a threshold row because that table's shape is "N days before a date" and an
         * integrity drift has no such date. Defaulting to the Owner is the honest answer: these are the
         * findings that mean a number or a stored file cannot be trusted.
         */
        'self_check_recipients' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('FLEET_SELF_CHECK_RECIPIENTS', 'owner'))),
            static fn (string $role): bool => $role !== '',
        )),
    ],

    /*
     * §9.5 — the dedicated column-encryption key.
     *
     * Deliberately a separate entry from APP_KEY: `APP_KEY` rotation is a deploy event that
     * re-encrypts the whole app, while column re-encryption is a key-management job
     * (`keys:reencrypt`) that must be schedulable separately. Versions are recorded per row so an
     * old key can still read old rows while new writes use the current one — which is what turns
     * "rotate the key" into a migration rather than a data-loss event.
     *
     * Missing key is a hard failure, never a silent fallback to APP_KEY: a column the design
     * promises is encrypted must not quietly become "encrypted with the app key" because an
     * environment variable was left out of a deploy.
     */
    'crypto' => [
        'current_version' => env('DB_CRYPT_KEY_VERSION', 'v1'),

        // AES-256-CBC with the HMAC Laravel's Encrypter already computes: the payload is
        // authenticated either way, and switching to GCM would need a versioned payload header
        // before any v1 row could be read again (§9.5).
        'cipher' => 'AES-256-CBC',

        /** @see docs/operations/hardening.md — keys are escrowed off-box, never committed. */
        'keys' => [
            'v1' => env('DB_CRYPT_KEY'),
        ],
    ],

    /*
     * §10.9 — when a trip's diesel consumption becomes a question somebody must answer.
     *
     * The comparison is the trip's litres per 100 km against the truck's own
     * `expected_consumption_per_100`, so the threshold is a percentage of that figure rather than an
     * absolute litre count: 10% over on a 45 L/100 km hauler and 10% over on a 12 L/100 km bakkie are
     * both worth asking about. Inside the threshold writes no row — noise is not a finding.
     *
     * The critical band is what makes the alert financial-or-safety rather than a nudge: §14.1 says a
     * critical alert cannot be opted out of, and a reading 25% over expectation is the shape
     * siphoning takes.
     */
    'fuel' => [
        /*
         * The pump's reference price per litre, in the base currency's minor units. These prefill the
         * office slip and the driver link; the slip's own figure is what the register records, so a
         * changed reference never re-prices a litre that was already bought.
         */
        'price_diesel' => (int) env('FLEET_FUEL_PRICE_DIESEL', 1_975),
        'price_petrol' => (int) env('FLEET_FUEL_PRICE_PETROL', 2_150),
        'price_paraffin' => (int) env('FLEET_FUEL_PRICE_PARAFFIN', 1_450),

        'variance_threshold_pct' => (float) env('FLEET_FUEL_VARIANCE_THRESHOLD_PCT', 10),
        'variance_critical_pct' => (float) env('FLEET_FUEL_VARIANCE_CRITICAL_PCT', 25),

        /** Role keys, same spelling as §9.3's seats; an unknown key throws rather than notifying nobody. */
        'variance_recipients' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('FLEET_FUEL_VARIANCE_RECIPIENTS', 'owner,operations_manager'))),
            static fn (string $role): bool => $role !== '',
        )),
    ],

    /*
     * §10.6 and §15.2 phase 3 — the workshop's two numbers.
     *
     * `work_order_approval_threshold_base` is the single control the design calls out: an order whose
     * estimate is above it cannot be worked until somebody holding `work_orders.approve` says yes.
     * BWP 5,000.00 is the default because it sits above a routine service and below a gearbox — the
     * owner can change it without a deploy, and the value in force is copied onto each row so changing
     * it never re-writes history.
     *
     * The three `due_soon_*` windows are how far before the due point the register says "due soon".
     * They are not the alert's day bands (those are §14.1 threshold rows, editable by the Owner); they
     * are the register's own colour, and they differ per kind because 1,000 km is two days on a
     * corridor truck and a month on a yard bakkie.
     */
    'maintenance' => [
        'work_order_approval_threshold_base' => (int) env('FLEET_WORK_ORDER_APPROVAL_THRESHOLD', 500_000),
        'due_soon_km' => (int) env('FLEET_SERVICE_DUE_SOON_KM', 1_000),
        'due_soon_hours' => (int) env('FLEET_SERVICE_DUE_SOON_HOURS', 50),
        'due_soon_days' => (int) env('FLEET_SERVICE_DUE_SOON_DAYS', 14),
    ],
];
