# RepuVio — Engineering Rules

> Created by **Stefan Iftimie** — Founder of [Ahoi Digital SRL](https://www.ahoi.ro/).
> These rules are binding for every contributor (human or AI). A change that breaks a rule is a bug.

## 1. Living documentation

1. Every change to code or database MUST update `README.md`, `CLAUDE.md`, `RULES.md` and `schema.sql` in the same change, so they stay 100% in sync with the codebase.
2. `schema.sql` is always rewritten as a whole: idempotent (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`), InnoDB, `utf8mb4_unicode_ci`, with foreign keys, indexes and seed data.
3. Schema changes for existing installations go into `includes/migrations.php`:
   - the migration is idempotent and checks `information_schema` first;
   - bump `REPUVIO_SCHEMA_VERSION`;
   - never require a manual SQL step.
4. Files are written completely. No placeholders such as `// ... rest of code ...` or `/* TODO */`.
5. Every PHP file starts with a docblock naming the file's purpose, `@author Stefan Iftimie` and `@package RepuVio`.

## 2. Platform constraints

- Plain PHP 8.1+ and MySQL/MariaDB (InnoDB). **No frameworks, no Composer dependencies.**
- Must run on shared hosting (cPanel, Apache or LiteSpeed) with `mod_rewrite`.
- Required PHP extensions: `pdo_mysql`, `openssl`, `fileinfo`, `gd` (with WebP), `mbstring`, `curl` (only for Google OAuth).
- Front-end libraries come from `cdn.jsdelivr.net`, pinned versions only:
  - Bootstrap 5.3.3
  - Bootstrap Icons 1.11.3
  - Chart.js 4.4.1
  - qrcode-generator 1.4.4

## 3. WordPress Coding Standards (WPCS)

- Indent with **real tabs** in PHP, HTML, CSS and JS.
- `snake_case` for functions, variables, array keys and DB columns. Never `camelCase` in PHP. (JS follows the WordPress JS standard: `camelCase` is allowed there.)
- Spaces inside parentheses: `if ( $x ) {`, `foreach ( $items as $item ) {`, `my_func( $a, $b )`.
- **Yoda conditions** for every comparison against a literal or constant: `if ( 'prod' === $app_mode )`, `if ( 3 <= $stars )`.
- Strict comparisons (`===`, `!==`, `in_array( $x, $list, true )`).
- Full `<?php` tags only. Never `<?=` and never `?>` at the end of pure-PHP files.
- Braces on the same line; `elseif`, not `else if`.
- Array syntax: short `[]` is used consistently.

## 4. Output escaping (late escaping)

Every variable echoed into HTML is escaped at the point of output, using helpers from `includes/formatting.php`:

| Context | Helper |
|---|---|
| Text between tags | `esc_html( $text )` |
| HTML attribute | `esc_attr( $value )` |
| `href` / `src` | `esc_url( $url )` |
| Inline JS string | `esc_js( $value )` |
| Multiline text | `esc_textarea( $value )` / `nl2br( esc_html( $value ) )` |
| JSON for scripts | `safe_json( $data )` (uses `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`) |

Raw `echo $var` of user-controlled data is forbidden. Pre-built HTML (email signatures, badge snippets) may only be echoed raw when every dynamic part was escaped where it was built (`includes/promote.php`), with a `phpcs:ignore` comment explaining why.

## 5. Security rules (zero-trust)

1. **SQL:** PDO only (`includes/db.php`), real prepared statements (`ATTR_EMULATE_PREPARES = false`), `ERRMODE_EXCEPTION`. No variable interpolation in SQL. Dynamic identifiers come from hard-coded allow-lists. Each named placeholder is used once per statement.
2. **CSRF:** every `POST` request is verified globally in `includes/bootstrap.php` via `csrf_verify_request()` (`hash_equals`). Every form includes `csrf_field()`. State-changing actions are never done over `GET` (the Google OAuth callback validates a single-use `state` token instead).
3. **Authorization / tenancy (3-tier RBAC):**
   - `super_admin` reaches `admin/*` through `require_super_admin()`.
   - `user` (business owner) pages use `require_owner()`.
   - Pages open to operators (dashboard, feedback list and ticket, company switcher) use `require_panel_access()`. Everything else returns 403 to operators.
   - Every query touching a company, review, note, tag or printable is scoped by owner ID (`get_account_owner_id()`) through `get_owned_company()`, `get_owned_review()`, `build_review_filters()` and `analytics_scope()`. For operators these also apply `company_restriction_sql()` (assigned companies only).
   - Never trust an ID from the request without an ownership check.
   - Operators can only be created, edited and deleted by their own owner (`get_owner_operator()`). Company assignments are intersected with the owner's companies.
   - An operator session dies when the owner is suspended or deleted (`current_user()`, `attempt_login()`). Deleting an owner cascades to operators.
4. **Passwords:** `password_hash()` with `PASSWORD_ARGON2ID`, fallback `PASSWORD_BCRYPT` cost 12 (`hash_user_password()`). Rehash on login when needed. Minimum length 10. Operator passwords set by an owner force a change on first login.
5. **Rate limits** (`rate_limit_*()` in `includes/security.php`, table `rate_limits`):

   | Action | Limit |
   |---|---|
   | `login` | 5 failures → locked 15 minutes |
   | `register`, `password_reset`, `plan_request` | 5 / hour |
   | `print_order` | 5 / hour |
   | `test_email` | 10 / 10 minutes |
   | `roi_manual` | 20 / 10 minutes |
   | `review_submit` (funnel, PROD only) | 10 / 10 minutes per IP |
   | `review_rate` (funnel, PROD only) | 30 / 10 minutes per IP |
   | `funnel_view` (funnel, PROD only) | 120 / 10 minutes per IP; views beyond are not counted |

   Public feedback also uses a honeypot field and an HMAC-signed render timestamp (`ft`, 2 s – 2 h); spam is accepted silently and discarded. This applies in both modes.
6. **DEV vs. PROD (`app_environment`):** only the Super Admin can switch it, and the switch is audit logged.
   - **PROD** (default, mandatory in production): at most one counted review per company per device cookie (`rm_device`, random, HttpOnly, SameSite=Lax) or per IP-hash + User-Agent pair, within `review_cooldown_hours` (1–720, default 24). Duplicates are not counted; positive duplicates still reach Google.
   - **DEV**: funnel rate limits, session de-duplication and the cooldown are disabled. The panel header shows a DEV badge and the public page shows a DEV flag.
7. **No user enumeration:** login and password-recovery responses are identical for existing and non-existing accounts. A dummy hash is verified when the user does not exist, to equalise timing.
8. **Sessions:**
   - Configured only in `includes/session.php`: HttpOnly, Secure on HTTPS, SameSite=Lax, strict mode, cookies only.
   - The session ID is regenerated on login, logout, privilege change, impersonation and every 15 minutes.
   - Fingerprint = SHA-256(User-Agent + IP subnet /24 or /64). The idle timeout comes from `config.php`.
   - `users.session_version` invalidates all sessions after a password change or suspension.
9. **Uploads:** only through `process_image_upload()`.
   - Checks: `finfo` MIME + `getimagesize()`, max 5 MB and 6000×6000 px.
   - Re-encoded with GD (strips EXIF), random `bin2hex( random_bytes( 16 ) )` filename, `.webp` output.
   - **SVG is never accepted by the server** (it can carry scripts and GD cannot read it). Logo / cover inputs carry `data-svg-raster`, so the browser renders an SVG to a transparent PNG (logo 1200 px, cover 2400 px longest side) before upload (`assets/js/app.js`).
   - Image fields show the recommended size and a live check (`data-image-hint`):
     - logo: square, min. 500 × 500 px;
     - cover: wide, about 8:3, min. 1600 × 600 px.
   - Round logo frames (print, public page, cards, form preview) use `object-fit: contain` with inner padding (about 14% of the diameter), so square logos are never clipped.
   - `/uploads/.htaccess` disables script execution.
10. **Secrets at rest** (keys derived from `APP_KEY` with the brand-independent labels `app-secret-enc` / `app-secret-mac`, so renaming the product never invalidates secrets; `migrate_to_v6()` re-encrypted pre-rebrand secrets):
    - The SMTP password, Google client secret, tokens and app password are encrypted with AES-256-CBC + HMAC-SHA256 (`encrypt_secret()` / `decrypt_secret()`) using `APP_KEY`.
    - Secret fields are never echoed back into forms.
11. **Redirects:** only to internal paths (`safe_redirect()`), or to validated Google review URLs (`is_valid_google_review_url()`). A form POST is never answered with a redirect to an external host: the CSP `form-action` directive blocks it. Use PRG to an internal page that navigates with `<meta refresh>` / JS (see the funnel "immediate" mode).
12. **Private folders:**
    - `config/`, `includes/`, `storage/`, `templates/` and `tools/` are blocked by the root `.htaccess` and by their own deny-all `.htaccess`.
    - `tools/dev-router.php` refuses to run outside `php -S`.
    - `schema.sql` and `*.md` are blocked on the web.
13. **No inline JS handlers:** behaviour is attached from `assets/js/*.js` through `data-*` attributes. `assets/js/badge.js` runs on third-party sites: it uses no cookies, no network calls and no `innerHTML` with data (only `textContent` and CSSOM styles), and accepts only `http(s)` link URLs.
14. **Headers:** `send_security_headers()` emits:
    - CSP with a per-request nonce;
    - `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`.

    Inline `<script>` tags must carry `nonce="<?php echo esc_attr( csp_nonce() ); ?>"`.
15. **Errors:** never display stack traces in production (`APP_DEBUG = false`). Errors are logged to `storage/logs/php-error.log`.
16. **CSV export:** cells beginning with `= + - @ \t \r` are prefixed with `'` (formula injection). Export is owner-only.
17. **Public funnel never errors on business state:** expired subscriptions and exceeded quotas are soft-locked, never shown to the end customer.
18. **Prices come from the database:** plan requests and print orders never trust prices, totals or titles from the request. Order lines snapshot the title and unit price.
19. **WordPress plugin:**
    - The template lives in `includes/wp-plugin/`, which is blocked on the web.
    - The ZIP is served only to the owner of the company (`dashboard/wp-plugin.php`, `require_owner()` + `get_owned_company()`). Operators get 403.
    - Company data is written into the generated `defaults.php` only through `var_export()`.
    - Inside the plugin, the WordPress rules apply:
      - `defined( 'ABSPATH' ) || exit`;
      - Settings API with a sanitize callback (URL `http(s)` only, allow-listed position / theme / display values, integer page IDs);
      - `manage_options` capability;
      - late escaping with WP `esc_*`;
      - bundled script only, no external requests;
      - `uninstall.php` cleanup.
20. **GDPR & cookies:**
    - Every form that collects personal data has an explicit, unchecked-by-default consent checkbox that links to the privacy policy, validated server-side, with a stored proof:
      - registration → `users.terms_accepted_at`;
      - private feedback → `reviews.privacy_consent`;
      - contact → `contact_messages.gdpr_consent`.
    - Only strictly necessary / functional cookies are allowed:
      - the session cookie;
      - the `rm_device` anti-abuse cookie on `/r/{slug}`;
      - `repuvio_cookie_consent`.

      Adding an analytics or marketing cookie requires a real opt-in consent mechanism first, and the cookie table in `cookies.php` must list every cookie.
    - The cookie banner is shown on the landing and legal pages, never on `/r/{slug}`.
    - The contact form uses CSRF, a honeypot, a signed timer (3 s – 2 h) and the `contact_form` rate limit (5 / hour / IP). Spam is accepted silently and not stored. Messages are stored before the email is sent (`email_sent` flag).
    - The Super Admin can permanently delete contact messages (right to erasure).
21. **Email Center:**
    - `admin/emails.php` and `admin/email-preview.php` are super-admin only.
    - Previews and logged emails are shown only inside `<iframe sandbox>` (no scripts, separate origin) under the global CSP.
    - Template bodies are trusted admin HTML. Customer data reaches an email only through escaped placeholders.
    - The operator invitation contains the initial password; the operator must change it at first login (`must_change_password = 1`).
22. **Demo accounts for presentations (showcase):**
    - Only users with `users.is_showcase = 1` are touched by `reset_showcase_demo()`.
      - A configured email that belongs to a real account is refused, both in Admin → Setări → Cont demo and in the reset itself.
      - Showcase accounts are never super admins.
    - **Emails never reach a showcase address:**
      - `send_mail()` passes every recipient list through `showcase_filter_recipients()`;
      - showcase addresses are replaced by `showcase_notify_email`, with the subject prefixed "[Demo]";
      - when no address is set, the email is dropped.

      Never bypass `send_mail()`.
    - The shared password is set by the Super Admin and stored only as a hash (`showcase_password_hash`). It is never written in code, docs or logs.
    - Showcase sessions cannot change their own name, email or password (`dashboard/profile.php`) and never receive a reset token (`auth/forgot-password.php`).
    - Plan requests (`billing.php`) and print orders (`print-order.php`) from showcase accounts are not stored or emailed.
    - The reset is idempotent, runs in one transaction, and deletes uploads only after the commit.
    - Showcase users keep their ID (sessions survive) and are suspended when the feature is switched off.
    - Generated names, companies, phone numbers and emails are fictitious (`@example.com`).

23. **Backups (Google Drive):**
    - Only the Super Admin reaches `admin/backups.php` / `admin/gdrive-oauth.php`. Every action is a POST with CSRF, and the OAuth callback validates a single-use `state`.
    - OAuth scope is only `drive.file`. Tokens and the client secret are secret settings (encrypted at rest).
    - Archives are written only to `storage/backups/`, which is never web-accessible (own deny-all `.htaccess` + root rule). Local archives are deleted after upload unless "keep local" is on.
    - The files archive never includes `storage/backups/`, `.git/`, `node_modules/` or `.zip` / `.gz` / `.log` files (`backup_path_excluded()`), so backups never nest.
    - No `exec()` / `mysqldump`. The SQL dump streams in 500-row batches with `PDO::quote()`, and uploads stream in 2 MB chunks: memory use must stay flat.
    - A scheduled backup failure always produces a `failed` `backup_logs` row and the `admin_backup_failed` email.
    - Retention deletes only archives whose names match the backup pattern (`backup_type_from_name()`); other files in the folder are never touched.
## 6. Business rules

- **System emails (Email Center):**
  - Every system email goes through `send_templated_email()` with a slug from `email_templates`. Never build a system email with `render_email()` + `send_mail()`. Those are only for the SMTP test email.
  - A new email means a new entry in `get_default_email_templates_raw()` (`includes/email-templates.php`) with its placeholders and demo data. Then run `php tools/email-templates-sql.php` and replace the block in `schema.sql`. `ensure_email_templates()` adds it to existing installs.
  - Placeholder values are escaped by the engine. Only keys ending in `_html` may carry HTML, and that HTML must be built from escaped values. URLs go in keys ending in `_url`.
  - A disabled template is never sent automatically. It is logged as `skipped_disabled`. Admin test sends and the manual ROI / digest sends use `force`.
  - Every email (sent, failed, queued, skipped) is logged per recipient in `email_logs`, with the final HTML. Re-sending updates the same row.
  - Cron emails are queued (`queue` option). `process_email_queue()` sends at most `smtp_batch_limit_per_run` per run, `smtp_delay_ms` apart. Request-time emails are sent immediately.
- **Review notification delivery modes:**
  - The company `email_delivery_mode` wins over the global `review_email_delivery_mode`. `global` = follow the platform.
  - `notify_new_review()` decides what goes out instantly:
    - private feedback → `instant_negative_feedback`, except in `daily_digest_only`;
    - Google redirects → `instant_positive_review`, only in `instant_all` and only when that template is ON;
    - locked feedback → `quota_exceeded_softlock`, always instant.
  - The Daily Digest covers the previous calendar day and goes to companies in `smart_hybrid` or `daily_digest_only`.
    - It is queued once per day by the first cron run from `daily_digest_hour`; `last_daily_digest_run_date` is set before queuing.
    - It is never sent for a day without evaluations (zero-spam rule), except for a manual admin send.
    - Showcase companies are excluded.
  - Recipients are the company alert emails, capped by the plan (`get_company_notification_recipients()`).
- **Expiry reminders:**
  - Trials: once (`trial_reminder_sent`).
  - Paid subscriptions: once per expiry date (`subscription_reminder_for`), 3 days before.
- **Cron frequency:** hourly. Every task is idempotent per day / period.
- **Backup schedule:**
  - `daily` = every day; `twice_weekly` = Monday + Thursday; `weekly` = Sunday; `monthly` = the 1st; always at `gdrive_backup_hour`.
  - `gdrive_next_backup_at` is moved forward before the backup starts and is recomputed whenever the schedule is saved.
  - Retention counts database and files archives separately; 0 = unlimited.
  - Manual runs ignore the database / files switches: "complet" = both, "doar baza de date" = database.

- **Plans:**
  - Starter: 100 RON/month, 500 reviews, 1 location, 1 alert email, 12 months history.
  - Pro: 250 RON/month, 1.000 reviews, 3 locations, 3 alert emails, 24 months history, employee QR tags.
  - Enterprise: from 590 RON/month, 5.000+ reviews, 5+ locations, 10 alert emails, unlimited history, employee QR tags, onboarding.
  - The yearly price is 10 × the monthly price (2 months free), stored as `plans.price_yearly_ron`.
  - The billing cycle lives on the user (`users.billing_cycle`).
  - MRR counts monthly users at `price_ron` and yearly users at `price_yearly_ron / 12`.
- **Per-user overrides (Enterprise):** `users.custom_max_reviews` (NULL = plan, 0 = unlimited) and `users.custom_max_companies` (NULL = plan).
- **Free trial:**
  - New users get `subscription_status = 'trialing'`, `trial_ends_at = NOW() + trial_days` (default 30).
  - Trial limits come from `trial_max_reviews`, `trial_max_companies` and `trial_max_alert_emails`.
  - All features are unlocked, including employee QR tags.
- **Universal features (all plans + trial):** white-label toggle, CSV export, monthly ROI email, branded printables, widget, email signatures, operators.
- **A counted review** = any completed star interaction on `/r/{slug}`: a Google redirect or a private feedback (`counted_in_quota = 1`). Page loads are views only.
- **Over quota or inactive subscription:**
  - High ratings still redirect to Google and still increment the counter.
  - Low ratings are stored with `is_locked = 1`, masked in the dashboard, and the owner is emailed.
- **Quota alerts** at 80% and 100% are emailed once per cycle (`quota_alert_80_sent`, `quota_alert_100_sent`).
- **Quota cycle** = 1 month from `quota_cycle_start`, rolled lazily on access and by `cron.php`.
- **Locked feedback** is unlocked automatically, oldest first, consuming quota, as soon as the subscription is active and quota is available (`unlock_pending_reviews()`).
- **Default star threshold** is 3: rating ≥ threshold → Google, rating below it → private feedback. Only owners can change it.
- **Fiscal code (CUI / CIF) is mandatory for invoicing:**
  - `companies.tax_id` is required when creating or editing a company.
  - Normalized: spaces, dots and dashes removed; the optional `RO` prefix (VAT payer) kept upper case; leading zeros removed.
  - Validated with the Romanian control-digit algorithm (key `753217532`).
  - Plan requests are refused while the owner has no company or any company without a fiscal code.
  - Print orders are refused for a company without one.
  - Admin emails always include the company + CUI billing data.
- **Alert email recipients** are capped by the plan's `max_alert_emails`; empty means the owner's email.
- **Feedback history** in the CRM / export is limited to the plan's `history_months` (0 = unlimited). Data is kept, only hidden.
- **Employee / department tags (`?ref=`):**
  - Available on Pro, Enterprise and trial (`allow_ref_tags`).
  - Up to 100 tags per company. Slugs are unique per company.
  - Unknown or inactive tags are ignored by the funnel.
  - Deleting a tag keeps the stored `ref_tag` on old reviews.
- **Team report ("Rapoarte echipă"):**
  - Owner-only (`require_owner()`); needs `allow_ref_tags` (Pro, Enterprise, trial), otherwise it shows an upgrade card.
  - One company at a time, period 30 / 90 / 180 days.
  - Rows: every QR tag of the company, plus the general codes (`ref_tag = ''`). Inactive tags without data are hidden.
  - Thresholds are constants in `includes/reports.php`, and the page explanation reads the same constants. Never hard-code a threshold in the text:
    - `TEAM_REPORT_MIN_LEADER` = 10;
    - `TEAM_REPORT_MIN_REVIEWS` = 5;
    - `TEAM_REPORT_MIN_DAYS` = 14;
    - `TEAM_REPORT_FEW_RATIO` = 0.5;
    - `TEAM_REPORT_LOW_GAP` = 0.4.
  - Conclusions (`get_team_report()`):
    - Codes are compared by pace: evaluations per day of existence inside the period.
    - Codes younger than `MIN_DAYS` are not compared ("Cod nou").
    - No comparison happens until the leader has `MIN_LEADER` evaluations.
    - "Puține evaluări" = pace at most `FEW_RATIO` × the leader's pace.
    - "Note slabe" = at least `MIN_REVIEWS` evaluations and an average at least `LOW_GAP` below the company average. The rating colours (page and chart) use the same rule, and are grey under `MIN_REVIEWS`.
    - General codes are never judged.
  - Advice depends on `ref_tag_kind()`: person, place or team. Extend its keyword lists instead of adding wording that assumes a person.
  - Every conclusion is phrased for non-technical owners. Romanian counts from 20 up use "de" (`ro_count()`).
- **Demo accounts for presentations:**
  - The owner is on the Pro plan, active, with the subscription ending in 18 days (rolled forward daily) and two logged payments.
  - The owner has one company without logo / cover, threshold 4★, helper redirect mode.
  - There are 90 days of scans and reviews and two employee QR codes ("Ospătar Andreea" offers the code, "Ospătar Mihai" avoids it and gets weaker ratings).
  - The operator is assigned to the company.
  - The data is rebuilt on the first `cron.php` run of each day (`showcase_last_reset`) and on demand from the admin tab.
  - `last_roi_report_period` is set to the previous month, so the automatic ROI email is not resent every day. Use Admin → Rapoarte ROI to show it.
- **Monthly ROI report:**
  - `cron.php` queues the previous month for every active owner with `roi_report_enabled = 1` and at least one company, once per period (`last_roi_report_period` flag, flipped before sending).
  - It is controlled globally by the `monthly_roi_report` template switch (Email Center). The old `roi_reports_enabled` setting was removed in schema v9.
  - Manual sends from Admin → Rapoarte ROI do not touch the flag.
- **Physical print upsell:**
  - Hidden everywhere (sidebar, Studio banner, order page → 404) while `enable_physical_print_upsell = 0`.
  - Orders are saved in `print_orders` / `print_order_items` and emailed to `support_email` + active super admins.
- **Plan requests are never email-only:**
  - Every request is inserted into `subscription_requests` (plan name and price snapshotted from the DB) before the notification email is sent, and `email_sent` records the result.
  - The Super Admin works the queue from **Abonamente clienți**: Nouă → Proformă trimisă → Activată / Respinsă, with a note. The processor and time are recorded and audit logged.
  - Logging a payment with activation completes the client's open requests automatically.
- **Plan change requests:**
  - Lunar / Anual on the billing page only switches the displayed prices.
  - "Comandă abonamentul" opens a confirmation modal, and only the confirmation posts the request (emailed to `support_email`).
  - Activation happens when the Super Admin logs the payment.

- **Google review link input** accepts:
  - `https://g.page/r/...` (recommended, from "Solicită recenzii");
  - `https://search.google.com/local/writereview?placeid=...`;
  - another URL on the Google host allow-list;
  - a raw Place ID (stored in `google_place_id`).

  A missing `https://` is added, other domains are rejected (`parse_google_review_input()` + `is_valid_google_review_url()`), and a link is never required to create a company.
- **Activation checklist** (owners):
  - 4 milestones: Google link; logo or cover; public page tested (≥ 1 view or review); Print Studio / Widget visited or marked done.
  - It is hidden when complete or when dismissed (`users.onboarding_dismissed`).
  - The owner's own `?preview=1` page loads are never counted as views.

## 7. UI rules

- Bootstrap 5.3 monochrome SaaS style defined in `assets/css/app.css` with CSS variables (`--rm-bg: #F8FAFC`, `--rm-border: #E2E8F0`, `--rm-ink: #0F172A`, `--rm-muted: #64748B`).
- No Bootstrap info / primary status badges (`text-bg-info`, `text-bg-primary`, cyan or blue fills). Use the `rm-pill-*` classes:
  - `rm-pill-trial` — white with an amber dot (trial, new);
  - `rm-pill-positive`, `rm-pill-negative`, `rm-pill-neutral`, `rm-pill-muted`.
- `rm-alert-info` is neutral slate.
- Font: Inter (Google Fonts). Cards: white, 1px `#E2E8F0`, radius 14px, ultra-soft shadow.
- Sidebar:
  - 260px, `offcanvas-lg` (drawer below 992px).
  - At 992px and wider it is `position: fixed` and `.rm-main` gets `margin-left: 260px`, so the sidebar never overlaps content (Print Studio included).
- Company cards:
  - 135px cover (`object-fit: cover`, rounded top), with a slate geometric gradient and an upload button when empty.
  - 64px logo overlapping the cover (`margin-top: -32px`, 4px white border, soft shadow).
  - Glass badges for threshold and white-label, and KPI boxes on `#F8FAFC`.
- **Brand:** the product is **RepuVio** (repuvio.ro / repuvio.com), formerly RepuMan.
  - Code identifiers use `repuvio` / `REPUVIO_*`.
  - The old name may appear only in `LEGACY_BRAND` (`includes/migrations.php`, needed to upgrade old installs) and in local environment names that cannot change (folder, Laragon vhost `repuman.test`, local DB).
  - Historical rows (e.g. `email_logs` subjects) are not rewritten.
- **Public landing page** (`index.php` for guests):
  - Same monochrome palette as the panel (`#0F172A`, `#F8FAFC`, `#E2E8F0`) with `#2563EB` / `#16A34A` accents.
  - Pricing always comes from the `plans` table (never hard-coded).
  - No inline scripts (`landing.js`).
  - Responsive without horizontal scroll.
  - The public footer carries the attribution "Făurit cu ♥ în România de {legal_company_name}", plus the provider CUI, phone and email.
  - Phone CTAs use `phone_tel_url()` (`tel:+40…`).
  - Marketing claims about reviews must not use invented statistics. Cite verifiable sources or stay qualitative.
- Footers show no author attribution:
  - panel: "© year app name · version" only, with no links (legal pages stay reachable from the public site and the auth pages);
  - auth pages: "© year app name" plus the legal / Contact / ANPC links.

  Credits stay in file docblocks, README and the email footer.
- Sidebar labels are short: the "Widget & Semnătură Email" page is linked as "Widget & Email". The print order page and menu link are "Comandă printuri".
- **Legal pages:**
  - Provider details are editable in Admin → Setări → Date juridice (`legal_*`) and are never hard-coded.
  - Plan prices, quotas and trial length in the Terms are read from the database.
  - The legal texts are templates, not legal advice: the platform owner must have them reviewed by a lawyer and must replace the seed placeholders before launch (the admin tab warns while placeholders remain).
- **Product name is a setting:** all user-visible occurrences of the product name use `app_name()`:
  - panel, auth and public pages;
  - emails and the `X-Mailer` header;
  - help center and modals;
  - the generated WordPress plugin (header, `readme.txt`, settings page).

  The literal "RepuVio" is allowed only in comments, package tags, technical identifiers and as the fallback default.
- **No real brands in demo data or examples:** use invented business names (e.g. "Bistro Teiul Verde", "Bistro Livada Demo").
- **Showcase accounts** show a "DEMO" pill (`.rm-demo-badge`) in the top bar.
- **Reports for non-technical users** (Rapoarte echipă):
  - Every chart has a one-line plain explanation under its title.
  - Conclusions come first, as coloured insight cards (`.rm-insight-good / -warning / -danger / -info`).
  - Colours carry meaning: green = good, amber = attention, red = problem, grey = general codes.
  - Person colours are applied from `data-color` by JS (no inline style attributes).
- **Onboarding:**
  - Business owners with no company are routed to the 3-step wizard (`dashboard/onboarding.php`).
  - The advanced form stays reachable through "Sari peste ghid" (`company-edit.php?advanced=1`).
  - The wizard pre-fills the welcome text and CTA, so step 1 only asks for name, logo and cover.
- **Help center** ("Ajutor & Ghid", `/help`) is available to every role, with role-specific content (operator ticket guide, Super Admin system documentation).
- Product naming: the QR materials module is called **Print Studio** (files stay `printables.php` / `studio.js`), and the company card button is "Print".
- Empty states (`.rm-empty`): only the direct decorative icon is enlarged (`.rm-empty > i`); icons inside buttons keep the button size.
- Destructive actions inside compact card footers are icon-only buttons with `title` and `aria-label`.
- User-facing text is Romanian (with diacritics). Code, comments and docs are English.
