# CLAUDE.md — AI assistant guide for RepuVio

> Project by **Stefan Iftimie** — Founder of [Ahoi Digital SRL](https://www.ahoi.ro/).

Read `RULES.md` before changing anything. It is binding.

## Mandatory workflow

1. After **every** code or DB change, update `README.md`, `CLAUDE.md`, `RULES.md` and rewrite `schema.sql` so all four match the codebase.
2. DB changes on existing installs: add an idempotent step in `includes/migrations.php` and bump `REPUVIO_SCHEMA_VERSION`. `schema.sql` stays the source of truth for fresh installs; the migration reads its `CREATE TABLE` statements.
3. Write complete files — never placeholders.
4. Follow WordPress Coding Standards: tabs, `snake_case`, spaces inside parentheses, Yoda conditions, no `<?=`.
5. Escape late with `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`, `safe_json()`.
6. Lint: `php -l` on every touched PHP file (see "Checks" below).

## Architecture in one page

Plain PHP pages (one file per screen) that all start with:

```php
require_once dirname( __DIR__ ) . '/includes/bootstrap.php';
$user = require_owner(); // or require_panel_access() / require_login() / require_super_admin()
```

`includes/bootstrap.php` does the following, in order:
1. Loads config and sets error handling + timezone.
2. Loads all libraries.
3. Runs `maybe_run_migrations()`.
4. Sends security headers.
5. Starts the hardened session.
6. Verifies CSRF for every POST.

The logged-in session is validated lazily by `current_user()`.

| File | Responsibility |
|---|---|
| `includes/db.php` | `db()` PDO singleton; `db_query()`, `db_row()`, `db_all()`, `db_value()`, `db_insert_id()`, `db_transaction()` |
| `includes/formatting.php` | `esc_*`, `safe_json()`, sanitizers (`sanitize_text()`, `sanitize_email_address()`, `sanitize_slug()`, `parse_email_list()`), date/number formatters |
| `includes/helpers.php` | `app_url()`, `asset_url()`, `upload_url()`, `redirect()`, `safe_redirect()`, flash, `input_post()`/`input_get()`, `client_ip()`, pagination, `render_template()` |
| `includes/security.php` | Security headers + CSP nonce, CSRF (`_csrf` field), rate limiting, password hashing, AES-256-CBC+HMAC secrets (`secret_keys( $label = 'app-secret' )` — brand-independent labels), `audit_log()` |
| `includes/session.php` | Session cookie params, fingerprint, idle timeout, rotation |
| `includes/auth.php` | `attempt_login()`, `login_user()`, `current_user()`; guards `require_login()`, `require_owner()` (owners only, operators → 403), `require_panel_access()` (owners + operators), `require_super_admin()`; impersonation |
| `includes/operators.php` | Roles: `get_role_label()`, `get_account_owner_id()`, `get_account_owner()`, `get_operator_company_ids()`, `company_restriction_sql()`, `get_accessible_companies()`, operator CRUD helpers |
| `includes/settings.php` | `get_setting()`, `update_setting()` (auto-encrypt secret keys), cached |
| `includes/mailer.php` | Socket SMTP client (STARTTLS/SSL, AUTH LOGIN, XOAUTH2), Google OAuth, `send_mail()` (showcase redirect, logs every recipient in `email_logs`; options `template_slug`, `user_id`, `log_id`, `reply_to`), `log_email()`. Template engine: `send_templated_email( $slug, $to, $placeholders, $user_id, $options )` (`queue`, `force`, `subject_prefix`, `reply_to`), `replace_email_placeholders()` (escape; `_html` raw, `_url` esc_url), `render_email_template()`, `render_email_layout()` (master wrapper from `email_*` settings), `get_email_layout_settings()`, `process_email_queue()`, `send_logged_email()` (retry, updates the same row), `get_email_log_statuses()` |
| `includes/email-templates.php` | `get_default_email_templates()` (factory texts, pretty-printed by `email_pretty_html()`), categories, global placeholders, `ensure_email_templates()` (seed / refresh defaults; unedited rows follow new factory text), `get_email_template()`, `get_email_templates()`, `is_email_template_enabled()`, `get_template_placeholders()`, `get_email_template_demo_data()` |
| `includes/emails.php` | Senders built on templates: `notify_new_review()` (delivery modes), `send_password_reset_email()`, `send_welcome_email()` (+ admin new-user alert), `send_operator_invite_email()`, `send_quota_alert_email()`, `send_trial_ending_email()` (trial or paid), `send_subscription_expired_email()`, `send_admin_notification()` (contact / print order / plan request); delivery modes (`get_review_delivery_modes()`, `get_global_delivery_mode()`, `get_company_delivery_mode()`); recipients (`get_company_notification_recipients()` capped by plan, `get_admin_notification_recipients()`); Daily Digest (`get_daily_digest_data()`, `build_feedback_digest_table()`, `send_daily_digest()`, `run_daily_digests()`, `maybe_run_daily_digests()`); `render_email()` only for the SMTP test email |
| `includes/subscription.php` | `get_user_limits()` (plan + per-user overrides, trial, operators inherit the owner), `get_plan_price()`, `get_billing_cycles()`, `user_can_use_ref_tags()`, quota (`get_quota_usage()`, `format_quota_usage()`, `consume_quota()`), `unlock_pending_reviews()`, `get_subscription_summary()` (`badge` = `rm-pill-*` class), MRR |
| `includes/subscription.php` (requests & revenue) | `get_request_statuses()`, `create_subscription_request()`, `complete_open_subscription_requests()`, `count_open_subscription_requests()`, `get_revenue_series()`, `get_mrr_by_plan()` |
| `includes/antiabuse.php` | `is_dev_environment()`, `funnel_device_id()` cookie, `is_duplicate_review()`, `funnel_rate_limit_*()`, `funnel_dedupe_window()` |
| `includes/companies.php` | Operator-aware `get_owned_company()`; slug, Google URL (`parse_google_review_input()` accepts `g.page/r/...`, `writereview?placeid=` or a raw Place ID); fiscal code (`parse_tax_id()`, `is_valid_romanian_cui()`, `get_companies_missing_tax_id()`, `render_billing_companies_html()`); `get_company_public_url( $company, $source, $ref )`; ref tag helpers (`sanitize_ref_tag()`, `get_company_ref_tags()`, `get_company_ref_tag()`, `create_ref_tag()`, `format_ref_tag()`); printable catalogue |
| `includes/reviews.php` | Operator-aware `get_owned_review()`; CRM filters (incl. `ref` and the plan history window `get_history_months()`); analytics; `get_ref_tag_breakdown()`; badges |
| `includes/reports.php` | ROI report: `get_report_period()`, `get_roi_report_data()`, `send_roi_report_email()`, `send_monthly_roi_reports()` (cron). Team report: `TEAM_REPORT_*` threshold constants, `get_team_report_periods()`, `ref_tag_kind()` (person / place / team from the label), `get_team_report( $owner_id, $company_id, $days )` (rows per QR tag + general codes with `pace`, `active_days`, `too_new`, `reliable`, `flags`; monthly buckets; `comparing`; plain-language `insights`), `format_stars()`, `ro_count()` |
| `includes/google-drive-backup.php` | Backup Google Drive: settings (`get_backup_defaults()`, `get_backup_setting()`, frequencies / scopes / retention / labels), OAuth (`gdrive_credentials()`, `gdrive_redirect_uri()`, `gdrive_authorize_url()`, `gdrive_exchange_code()`, `gdrive_access_token()`, `gdrive_disconnect()`, `gdrive_is_connected()`), Drive API (`gdrive_http()`, `gdrive_api()`, `gdrive_ensure_folder()`, `gdrive_upload_file()` resumable 2 MB chunks, `gdrive_list_backup_files()`, `gdrive_delete_file()`, `gdrive_apply_retention()`), local files (`backup_dir()`, `backup_file_name()`, `backup_type_from_name()`, `prune_local_backups()`, `format_bytes()`), generators (`dump_database_to_gzip()`, `collect_backup_files()`, `backup_path_excluded()`, `build_files_backup_zip()`, `stream_zip_files()` fallback), runner (`run_backup()`, `send_backup_notification()`, `compute_next_backup_at()`, `maybe_run_scheduled_backup()`, `stream_database_backup_download()`, `delete_backup_log()`, `backup_log_start()` / `backup_log_update()`) |
| `includes/showcase.php` | Demo accounts for presentations: `get_showcase_defaults()`, `get_showcase_setting()`, `is_showcase_enabled()`, `is_showcase_user()`, `get_showcase_emails()`, `showcase_filter_recipients()` (used by `send_mail()`), `maybe_reset_showcase_demo()` (cron, once per day), `reset_showcase_demo()`, `get_showcase_story()` (who scans how often, rating weights, feedback texts) |
| `includes/print-shop.php` | `is_print_upsell_enabled()`, products, `create_print_order()` (prices from the DB), admin notification |
| `includes/promote.php` | Trust badge snippets (`build_widget_snippet()`, `build_inline_badge_html()`), `build_email_signature()`, tutorials, pure-PHP `build_zip_archive()`, `build_wordpress_plugin_zip()` |
| `includes/legal.php` | `get_legal_setting_fields()` (company name, URL, CUI, Reg. Com., CAEN, address, email, phone, last updated), `get_legal_settings()`, `get_legal_placeholders()`, `phone_tel_url()` (`0762 82 33 33` → `tel:+40762823333`), `get_public_pages()`, `legal_url( 'terms'|'privacy'|'cookies'|'contact' )` (pretty route when `PRETTY_URLS`), `has_cookie_consent()` (`COOKIE_CONSENT_NAME`), contact form (`contact_form_timer()`, `handle_contact_submission()`, `send_contact_notification_email()`, `count_new_contact_messages()`) |
| `includes/onboarding.php` | `get_onboarding_defaults()`, `should_start_onboarding()`, `mark_onboarding_materials()`, `get_onboarding_checklist()` (4 milestones for the dashboard card) |
| `includes/wp-plugin/repuvio-trust-badge/` | WordPress plugin template (WP APIs and WP escaping, not RepuVio helpers). Zipped with `assets/js/badge.js` and a generated `defaults.php` (`var_export` of URL, company name and `brand` = app name). The `Plugin Name:` header line and `{{brand}}` in `readme.txt` are replaced at download time. Keep `readme.txt` and the version constant in sync |
| `includes/uploads.php` | `process_image_upload()` secure GD pipeline, `delete_upload()` |
| `includes/migrations.php` | `maybe_run_migrations()`, `migrate_to_v2()` (RBAC, tags, print upsell, plans), `migrate_to_v3()` (onboarding columns), `migrate_to_v4()` (`companies.tax_id`), `migrate_to_v5()` (`subscription_requests`), `migrate_to_v6()` (RepuVio rebrand: re-encrypt secrets from the legacy labels, rename old default settings; uses the documented `LEGACY_BRAND` constant — the only place the old name may appear), `migrate_to_v7()` (legal settings, `contact_messages`, consent columns, drop `terms_url`), `migrate_to_v8()` (`users.is_showcase`), `migrate_to_v9()` (Email Center: `email_templates`, `email_logs` ← old `email_log`, `companies.email_delivery_mode`, `users.subscription_reminder_for`, email settings, `roi_reports_enabled` → template switch), `migrate_to_v10()` (`backup_logs`, `gdrive_*` settings, backup email templates), information_schema helpers. Current `REPUVIO_SCHEMA_VERSION` = 10 |

### Roles & tenancy
- `users.role` ∈ `super_admin`, `user`, `operator`. Operators have `parent_id` = owner ID and companies in `operator_companies`.
- Tenant queries take the **owner ID**. Always pass `get_account_owner_id( $user )`, never `$user['id']`, on pages open to operators.
- `build_review_filters()`, `analytics_scope()` and `get_feedback_notifications()` append `company_restriction_sql()` automatically. It is empty for owners, admins and CLI.
- Owner-only pages use `require_owner()`: companies, company-edit, printables, print, promote, wp-plugin, operators, billing, export, print-order, team-report.
- Pages shared with operators use `require_panel_access()`: index, reviews, review-view, switch-company. Profile and help use `require_login()`.
- `onboarding.php` uses `require_owner()`.

### Pages
- `terms.php`, `privacy.php`, `cookies.php` — legal documents. Each defines `$doc_key`, `$doc_title`, `$doc_intro`, `$doc_sections` ([ id, title, html ] with every dynamic value escaped) and requires `templates/legal-document.php`. Routes: `/termeni-si-conditii`, `/confidentialitate`, `/politica-cookies` (`.htaccess` + `tools/dev-router.php`).
- `contact.php` — `/contact`: the form is posted to itself, uses PRG with the `$_SESSION['contact_sent']` flag, and is processed by `handle_contact_submission()`.
- `index.php` — guests: public sales landing page (plans from DB via `get_plans( true )`, `landing_plan_features()`, `assets/css/landing.css`, `assets/js/landing.js`); logged-in users: redirect to `get_home_url()`.
- `review.php` — public funnel (`/r/{slug}`). Footer "Protejat de {app_name()}" unless white-label.
  - POST `action=rate` (high rating → Google) and `action=feedback` (low rating).
  - Captures `?src=` and validated `?ref=` into the session on page load.
  - PRG states: `thanks_google`, `redirect_google` (immediate mode: meta refresh + `data-rf-autoredirect` in `review.js`), `thanks`, `thanks_feedback`, `already` (duplicate in PROD). Never `redirect()` a form POST to an external host: CSP `form-action` blocks it.
  - `?preview=1` from a logged-in owner (onboarding phone mock-up) is not counted as a view.
- `dashboard/*` — owner / operator panel. The selected company is `$_SESSION['current_company_id']` (`0` = all), validated with `get_owned_company()`.
  - `onboarding.php` — 3-step wizard (steps 1–2 are one multipart form turned into steps by `onboarding.js`; POST `action=create`; step 3 = `?step=3&company_id=`). It also handles the checklist POSTs `dismiss_checklist` and `mark_materials`.
    - Owners with 0 companies are redirected here from `index.php` (unless `$_SESSION['onboarding_skipped']`), from `register.php` and from `company-edit.php` (unless `?advanced=1`, which sets the skip flag).
  - `help.php` — "Ajutor & Ghid" (`/help` rewrite in `.htaccess` and `tools/dev-router.php`). The FAQ is a PHP array of authored HTML; `help.js` does the live search.
  - `operators.php` — team management.
  - `promote.php` — widget, WordPress plugin card and signatures. The page title is "Widget & Semnătură Email"; the sidebar link is "Widget & Email".
  - `wp-plugin.php?company_id=` — streams the pre-configured plugin ZIP.
  - `print-order.php` — "Comandă printuri" (title and sidebar link); 404 while the upsell is hidden.
  - `printables.php` and `promote.php` call `mark_onboarding_materials()` (checklist milestone 4).
  - `billing.php` — Lunar / Anual toggle (display only) and a confirmation modal `#rmPlanModal` that posts `action=request_plan`, `plan_id`, `cycle`.
  - `team-report.php` — "Rapoarte echipă" (sidebar link for owners, plus a button on the Panou tag card).
    - `?company_id=` and `?days=30|90|180`.
    - Content: KPIs, insight cards, ranking cards (`team_flag_pill()`), 4 Chart.js charts (`assets/js/team-report.js`, data in `RM_DATA.teamReport`), a detail table, and the bottom explanation "Cum citești acest raport", whose thresholds come from the `TEAM_REPORT_*` constants.
    - Plans without `allow_ref_tags` get an upgrade card.
- `admin/*` — super admin only.
  - `contact-messages.php` — contact form inbox (status, reply, delete).
  - `settings.php` tab `legal` — "Date Juridice & Contact Furnizor" (`legal_*`).
  - `subscriptions.php` — "Abonamente clienți": KPIs, revenue / MRR charts (`assets/js/subscriptions.js`), and request tabs (POST `action=update_request`), active, trials, expiring and expired.
  - `plans.php` / `plan-edit.php` — "Planuri tarifare".
  - `roi-reports.php` — manual ROI send.
  - `print-products.php` — catalogue + orders.
  - `emails.php` — "Email & Șabloane" (`?tab=rules|templates|layout|logs`).
    - POST actions: `save_rules`, `run_digest`, `run_queue`, `toggle_template`, `save_template`, `reset_template`, `test_template`, `save_layout`, `retry_log`, `purge_logs`.
    - `?edit=slug` opens the editor; `?preview=slug` shows a preview; logs use `?view=id`.
    - The sidebar badge counts failed emails in the last 7 days.
  - `backups.php` — "Backup Google Drive" (sidebar, Sistem group).
    - POST actions: `save_credentials`, `ensure_folder`, `save_schedule`, `run_full`, `run_db`, `download_sql` (streams a `.sql.gz` and exits), `delete_log`.
    - Connect / disconnect POST to `gdrive-oauth.php`, which is also the OAuth callback (state = `create_onetime_token( 'gdrive_oauth' )`).
  - `email-preview.php` — renders `?slug=`, `?log=` or a POST `action=draft` (editor "Previzualizează modificările" via `formaction` + `formtarget`) for the sandboxed iframes.
  - `settings.php` tabs: `general`, `legal`, `environment`, `features`, `showcase`, `email`, `test`.
    - `showcase` is "Cont demo". POST `action=showcase` saves; the clicked button sends `showcase_enabled`:
      - `1` = activate / save + regenerate;
      - `0` = save while disabled, or disable.

      There is no on/off switch, by design: a switch left off silently disabled the accounts.
    - `action=showcase_reset` regenerates.
  - Impersonation: `admin/impersonate.php` (owners only).
- `cron.php` — CLI or `?token=CRON_TOKEN`.

Templates (included with `require REPUVIO_ROOT . '/templates/...'` so page variables are in scope):
- `app-header.php` expects `$page_title`; optional `$page_breadcrumbs`, `$active_nav`, `$page_styles`. It shows the DEV badge, a company switcher of accessible companies, and notifications for owners and operators.
- `app-footer.php` — optional `$page_scripts`, `$page_inline_data` → `window.RM_DATA`. The footer shows only "© year app name · version": no author attribution and no links (the legal / Contact / ANPC links were removed on request). `auth-footer.php` shows "© year app name" plus the legal links (`.rm-auth-links`).
- `sidebar.php` — role-specific menus, rendered via `render_template()`. A nav item may have a 4th element: a counter badge (`.rm-nav-badge`), e.g. open subscription requests.
- `auth-header.php` / `auth-footer.php`, `error.php`, `feedback-form.php`.
- `public-head.php` (`$public_title`, `$public_description`, optional `$public_robots`, `$public_og`), `public-nav.php` (optional `$is_landing`), `public-footer.php` (legal links, ANPC / SAL, provider attribution, cookie banner, `landing.js`, closes the document) — shared by `index.php` and the legal pages. Never used on `/r/{slug}`.
- `legal-document.php` — legal page layout (tabs, last updated, print, sticky TOC, provider card).
- `google-link-modal.php` — "Cum găsesc linkul meu Google în 10 secunde?" (`#rmGoogleHelpModal`), included by `onboarding.php` and `company-edit.php` before the footer.

Assets:

| File | Used by |
|---|---|
| `assets/css/app.css` | Panel design system: fixed 260 px sidebar with `.rm-main { margin-left }` at ≥ 992 px, company cards, segmented toggle, pills (`rm-pill-trial/positive/negative/neutral/muted`), studio, widget, signature, billing styles |
| `assets/css/public.css` | Public funnel (incl. `.rf-dev-flag`) |
| `assets/css/print.css` | Printable templates (mm sizes, `.rm-ref-label`) |
| `assets/js/app.js` | `data-autosubmit`, `data-confirm` (on a form, or on a single submit button), `data-copy`, `data-svg-raster` (SVG → PNG in the browser before upload), `data-preview` (+ `data-image-hint`, `data-min-width`, `data-min-height`, `data-ratio`, `data-ratio-hint` size check), slug generator, `data-width`, `data-price-target`, `data-toggle-custom`, `data-order-form`, `data-cycle-toggle` / `data-cycle-show`, `#rmPlanModal` filling, mail driver sections |
| `assets/js/dashboard.js` | Owner charts (Chart.js 4.4.1) |
| `assets/js/admin.js` | Admin overview chart |
| `assets/js/review.js` | Funnel stars, low-rating modal |
| `assets/js/printables.js` | Vector QR (qrcode-generator 1.4.4 → SVG path) for every `[data-qr]`, preview scaling, print |
| `assets/js/studio.js` | Print Studio (template, `[data-ref-select]`, CTA, preview, SVG download) |
| `assets/js/promote.js` | Widget configurator preview, rich-HTML signature copy (`data-copy-html`) |
| `assets/js/badge.js` | Embeddable trust badge for client websites (`data-rm-badge`), also `window.RepuVioBadge.render()` for the preview |
| `assets/js/onboarding.js` | Wizard step navigation, live slug / initials preview, drag-and-drop highlight, live threshold ("scut") diagram |
| `assets/js/subscriptions.js` | Admin cash-in bar + payment count line chart and MRR-per-plan doughnut |
| `assets/css/landing.css` / `assets/js/landing.js` | Public landing + legal + contact pages: styles (incl. footer grid, cookie banner, legal layout, contact form, print rules); mobile menu, sticky nav, Smart Funnel demo, ROI estimator, pricing toggle, cookie banner accept, print button, TOC highlighting |
| `assets/js/help.js` | Help center instant search (diacritics-insensitive) over `[data-help-item]` |
| `assets/js/emails.js` | Email Center: `[data-insert-placeholder]` pills insert into the last focused `[data-placeholder-target]` (subject / body) at the caret |
| `assets/js/team-report.js` | Rapoarte echipă: `[data-color]` dots / bars, scans vs evaluations, average rating (green / amber / red), sentiment 100% stacked, monthly grouped bars; `[data-team-chart-height]` sizes the horizontal charts by row count |

There are no inline event handlers anywhere: the CSP has no `unsafe-inline` for scripts. Add behaviour through `data-*` attributes in `app.js` (or the page script).

## Key conventions

- URLs: always `app_url( 'dashboard/reviews.php' )`, never hard-coded paths. Public link: `get_company_public_url( $company, $source, $ref )`.
- Tenancy: fetch with `get_owned_company( $id )` / `get_owned_review( $id )`.
  - Owners list companies with `get_user_companies( $owner_id )`.
  - Panel pages list with `get_accessible_companies( $user )`.
- Plan features: read them from `get_user_limits( $user )` (`max_reviews`, `unlimited_reviews`, `max_companies`, `max_alert_emails`, `history_months`, `allow_ref_tags`, `includes_onboarding`). Never compare plan slugs.
- Flash: `flash( 'success', 'Mesaj' )`, rendered by the header.
- Settings secret keys list: `get_secret_setting_keys()`.
- Quota: call `consume_quota( $owner_id )` exactly once per counted review. Only star interactions count; views never do.
- Funnel anti-abuse: use `funnel_rate_limit_*()`, `funnel_dedupe_window()` and `is_duplicate_review()`. They are no-ops in DEV.
- Rate limit pattern (admin / auth):
  ```php
  if ( rate_limit_is_locked( 'login' ) ) { ... }
  rate_limit_hit( 'login', 5, 900, 900 ); // max, window seconds, lockout seconds
  rate_limit_clear( 'login' );
  ```
- Inline scripts need the CSP nonce: `<script nonce="<?php echo esc_attr( csp_nonce() ); ?>">`.
- No Bootstrap `text-bg-info` / `text-bg-primary` status badges: use the `rm-pill-*` classes. `rm-alert-info` is neutral slate.
- Product name: never hard-code "RepuVio" in user-visible text. Use `app_name()` (Settings → Nume aplicație), escaped late. "RepuVio" may appear only in code comments, `@package` tags, technical identifiers (`repuvio-trust-badge` slug, `RepuVioBadge`, `REPUVIO_*`) and as the fallback default.
- Demo / example names must be invented. Never use real registered brands (e.g. the demo restaurant is "Bistro Teiul Verde").
- Backups: never use `exec()` or `mysqldump`. Keep the dump / zip / upload streaming.
  - New folders that must not be archived go into `backup_path_excluded()`.
  - New secret `gdrive_*` keys go into `get_secret_setting_keys()`.
  - A new table is dumped automatically (every base table is included).
- Emails: every system email = `send_templated_email( 'slug', ... )`.
  - New email → add it to `get_default_email_templates_raw()` with placeholders and demo data, then refresh the `schema.sql` block with `php tools/email-templates-sql.php`.
  - Pass HTML only in `_html` placeholders built from escaped values.
  - In cron use `[ 'queue' => true ]`.
  - Review notifications go through `notify_new_review()`; never email from `review.php` directly.
- Demo accounts for presentations (`users.is_showcase = 1`):
  - Never send email around `send_mail()`: it redirects showcase recipients to `showcase_notify_email` ("[Demo]" prefix).
  - New features that send email, change login data or place orders must keep that guarantee. Check `is_showcase_user()` where needed.
  - When a table that holds an owner's data is added, also clean it in `reset_showcase_demo()`.
- Google links: always run user input through `parse_google_review_input()`.
- Legal / GDPR:
  - Provider details always come from `get_legal_settings()`, never literals.
  - Links to legal pages use `legal_url()`.
  - Any new form that collects personal data needs a consent checkbox validated server-side and a stored consent flag or timestamp.
  - Any new cookie must be added to the table in `cookies.php`.
- Fiscal codes: always run user input through `parse_tax_id()`. It is required on company create / edit (wizard and advanced form) and checked before plan requests (`billing.php`) and print orders (`print-order.php`). The demo seeder generates checksum-valid fictitious CUIs (`demo_cui()`).
- UI text Romanian; code/comments English.

## Local development

- On this machine port 80 is served by **Laragon**, which has a vhost `http://repuman.test` → `C:/xampp/htdocs/repuman` with real `.htaccess`. The folder, vhost and local DB name (`repuman`) keep the pre-rebrand name on purpose (renaming them would break the Laragon auto-vhost); everything inside the code is RepuVio. `config/config.php` uses `APP_URL = http://repuman.test`. `APP_URL` must match the browser host (cookies are host-bound).
- Alternative: `php -S 127.0.0.1:8791 -t . tools/dev-router.php` (configured in `.claude/launch.json` as `repuvio-dev`). With it, set `APP_URL` to `http://127.0.0.1:8791`.
- Local DB `repuman` (`DB_NAME` in `config/config.php`, kept from before the rebrand; a fresh install may use any name, e.g. `repuvio`) on MySQL 8.4 (root, no password). The XAMPP `mysql.exe` cannot authenticate (`caching_sha2_password`), so import via PDO:
  ```bash
  php -r "\$p=new PDO('mysql:host=127.0.0.1','root','');\$p->exec('CREATE DATABASE IF NOT EXISTS repuman CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');\$p->exec('USE repuman');\$p->exec(file_get_contents('schema.sql'));"
  ```
  Existing databases are migrated automatically on the next request.
- Mail driver `log` writes messages to `storage/logs/mail.log`. The production DB uses `smtp`. For local email tests, switch to `log` in a CLI script and restore it in a `finally` block.
- Every email is also visible in Admin → Email & Șabloane → Jurnal emailuri, with the full HTML.
- Demo data: `php tools/seed-demo.php` (re-runnable; `--remove` to delete). Demo users are on `@repuvio-demo.test`; accounts and the shared password are listed in README → Demo data. Keep the seeder in sync with the schema.
- Demo accounts for presentations (`proprietar@repuvio.ro` / `operator@repuvio.ro`) are separate from the seeder.
  - They are configured in Admin → Setări → Cont demo, where the Super Admin sets the password; the code holds no password.
  - Regenerate them with "Regenerează acum" or by running `cron.php`. Locally, call `reset_showcase_demo()` from a CLI script: running the full cron with the `smtp` driver sends real emails.
- DEV mode (Admin → Setări → Mediu & Anti-abuz) disables funnel limits for testing. Return to PROD before deploying.
- The session fingerprint includes the User-Agent: switching browser device emulation logs you out (expected).
- The login form CSRF field is named `_csrf` (useful for curl smoke tests).

## Checks

```bash
find . -name "*.php" -not -path "./uploads/*" -exec php -l {} \; | grep -v "No syntax errors"
```

Manual smoke test:
1. Register → you land in the onboarding wizard → complete the 3 steps (g.page link, 4★ threshold) → step 3 QR + phone preview → dashboard checklist shows progress → open `/help`.
   Then open `/r/{slug}`.
2. Rate 5★ (Google redirect) and 1★ (feedback); see both in the dashboard.
3. In PROD, rate again from the same browser: not counted. In DEV it is counted.
4. Create an operator and log in as them: only Panou + Feedback for the assigned companies; other pages return 403.
5. Print Studio with a `?ref=` tag; widget / signature copy; billing toggle + order modal.
   Then open Rapoarte echipă: the insight cards, the ranking and the 4 charts render.
6. Demo accounts for presentations:
   - Admin → Setări → Cont demo → Salvează și regenerează.
   - Log in as `proprietar@`: a DEMO pill, a company without logo, the team report story.
   - Log in as `operator@`: Panou + Feedback only.
   - Profile changes are refused.
   - With the `log` driver, an email to the demo owner lands at the notification address with "[Demo]".
7. Admin: ROI manual send, print catalogue toggle, impersonation, cron.
8. Email & Șabloane (with the `log` driver):
   - Toggle a template OFF: its email is logged as "Oprit".
   - Edit + "Previzualizează modificările", "Trimite test", "Resetează".
   - Change the accent colour.
   - Send a manual Daily Digest for yesterday; a company in "Doar rezumat zilnic" gets no instant feedback alert.
   - Re-send a failed log row.
9. Backup Google Drive:
   - "Descarcă backup SQL direct în browser" downloads a `.sql.gz` that restores into an empty database.
   - Connect Google Drive (needs a real OAuth client) → the `ahoi-backups` folder appears.
   - "Rulează backup complet acum" → two archives in Drive and two log rows.
   - Set retention 5 and run 6 times → the oldest are removed.
   - Disconnect and run → failed row + `admin_backup_failed` email (`log` driver).
