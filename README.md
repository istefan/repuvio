# RepuVio — Reputation Management SaaS

> Created by **Stefan Iftimie** — Founder of [Ahoi Digital SRL](https://www.ahoi.ro/).

RepuVio is a multi-tenant SaaS that helps local businesses collect more positive Google reviews and intercept negative experiences privately. Customers scan a QR code and pick 1–5 stars. Happy customers are routed to the business's Google "Write a review" page. Unhappy customers leave private feedback that lands in the owner's CRM, where the owner or a support operator resolves it.

Built with **plain PHP 8.1+ and MySQL (InnoDB)** — no frameworks, no Composer — so it runs on any shared hosting (cPanel, Apache or LiteSpeed).

Product site: **repuvio.ro** / **repuvio.com**. The product was formerly called RepuMan; schema v6 migrates existing installations (see "Upgrading").

**Branding:** "RepuVio" is only the default product name. Every name shown to users comes from **Admin → Setări → Nume aplicație** (`app_name()`), including:
- the panel, the emails and the public pages;
- the help center and the Google help modal;
- the `X-Mailer` header;
- the WordPress plugin (name in its header, `readme.txt` and settings page).

---

## Roles (3-tier RBAC)

| Role | Who | Can |
|---|---|---|
| `super_admin` | Platform administrator (Stefan Iftimie) | Everything: users, plans, payments, settings (DEV/PROD, SMTP / Google OAuth), physical print catalogue and toggle, manual ROI reports, impersonation |
| `user` | Business owner (Proprietar de firmă) | Companies within plan limits, thresholds, printables, widget & email signature, analytics, billing, and **operators** (Operatori) |
| `operator` | Customer support (Operator reclamații) | Only **Panou** and **Feedback & Recenzii** for the companies assigned by the owner: change ticket status (Nou / În lucru / Rezolvat) and add internal notes |

Operators are rows in `users` with `role = 'operator'` and `parent_id` pointing to the owner. Assignments are stored in `operator_companies`. Operators cannot:
- add or delete companies or change star thresholds;
- see billing or subscriptions, or export CSV;
- manage other operators.

An operator is logged out automatically when the owner account is suspended or deleted.

## Plans

| Plan | Price | Reviews / month | Locations | Alert emails | Feedback history | Employee QR tags |
|---|---|---|---|---|---|---|
| **Starter** | 100 RON / month or 1.000 RON / year | 500 | 1 | 1 | 12 months | — |
| **Pro** | 250 RON / month or 2.500 RON / year | 1.000 | 3 | 3 | 24 months | ✔ |
| **Enterprise** | from 590 RON / month or 5.900 RON / year (negotiable) | 5.000+ | 5+ | 10 | unlimited | ✔ + assisted onboarding |
| Free trial | 30 days (setting) | 50 (setting) | 1 (setting) | 1 (setting) | unlimited | ✔ |

- Yearly price = 10 months (2 months free).
- The billing cycle is stored per user (`users.billing_cycle`).
- Enterprise deals use the per-user overrides `custom_max_reviews` and `custom_max_companies`. `custom_max_reviews = 0` means unlimited.

**Included in every plan and in the trial:**
- white-label toggle (hide the "Protejat de RepuVio" footer);
- CSV export;
- monthly ROI email report;
- branded printables;
- website widget and email signatures;
- operators.

**What counts as a review:** every completed star interaction on `/r/{slug}` counts as 1 review toward the monthly quota, whether it is a Google redirect or a private feedback. Page loads without a star click are only **views** (`funnel_views`).

## Features

### Public sales landing page — `/` (`index.php`)
Guests see a fast landing page built for Romanian business owners. Logged-in users are redirected to their panel.
- Built with `assets/css/landing.css` and `assets/js/landing.js`; no Bootstrap CSS.
- Sections, in order:
  1. Sticky navbar with smooth scroll and a mobile menu.
  2. Hero with an interactive Smart Funnel mock-up (click the stars → Google / private branch) and a phone CTA ("Preferi să vorbim?", `tel:` link from `legal_phone`).
  3. **"De ce contează recenziile"** (`#importanta`):
     - the customer decision journey: search → compare stars → read recent reviews → choose or leave;
     - three cards: Google local visibility (as stated in the Google Business Profile documentation), the rating filters before the first click, recency.

     No invented statistics.
  4. "De ce pierd afacerile locale clienți pe Google?" (`#avantaje`) — the 1★ = 12 × 5★ math and the 6 core advantages.
  5. 3-step "Cum funcționează".
  6. **"Pentru clienții tăi"** (`#clienti`) — benefits for end customers: a public 1★ review vs. a private RepuVio ticket, plus three cards (heard by the decision maker, 30 seconds without an account, private data).
  5. ROI estimator (sliders).
  6. Pricing read live from the `plans` table, with the Plată Lunară / Plată Anuală (2 luni gratuite, -17%) toggle.
  7. 6-question FAQ (`<details>`).
  8. Final dark CTA.
  9. Shared public footer (`templates/public-footer.php`):
     - columns Produs / Legal / Protecția consumatorilor (ANPC, SAL);
     - "© year RepuVio · Făurit cu ♥ în România de {legal company} · CUI {legal_cui}";
     - phone (`tel:`) and email of the provider;
     - the cookie information banner.
  The Enterprise plan and the final dark CTA also show the phone number.
- The page is indexable (no `noindex`) and has meta description and Open Graph tags.

### Legal, GDPR & contact pages
Public pages share the landing navbar and footer (`templates/public-*.php`), use the `legal_*` settings, and are printable.

| Page | Route | File | Content |
|---|---|---|---|
| Termeni și Condiții | `/termeni-si-conditii` | `terms.php` | Provider & definitions (Beneficiar, Operator, Client Final); service; trial, plans and prices **read from the DB**; review counting and Soft-Lock; acceptable use; independence from Google LLC & liability; IP, force majeure, Romanian law |
| Politica de Confidențialitate | `/confidentialitate` | `privacy.php` | Provider as data **controller** (accounts) and as **processor** with an incorporated DPA (end-customer feedback); security measures; recipients; data subject rights; ANSPDCP |
| Politica de Cookies | `/politica-cookies` | `cookies.php` | The 3 cookies actually set (names read from the code), what is not used, how to delete / block cookies per browser |
| Contact | `/contact` | `contact.php` | Provider card + quick links; form (name, company, email, phone, subject, message), CSRF, honeypot, signed timer, rate limit, GDPR checkbox → `contact_messages` + email |

- Legal documents get a sticky table of contents with section highlighting, tabs between documents, and a "Printează / PDF" button (print stylesheet).
- **Cookie banner:** a floating monochrome bar on the landing and legal pages, never on `/r/{slug}`. "Am înțeles" sets `repuvio_cookie_consent=1` for 365 days.
- **Consent records:**
  - Registration requires the checkbox "Sunt de acord cu Termenii și Condițiile și am citit Politica de Confidențialitate GDPR" (client and server side) and stores `users.terms_accepted_at`.
  - The private feedback form on `/r/{slug}` requires the consent "Datele tale sunt transmise în siguranță exclusiv conducerii {companie} …" (client and server side) and stores `reviews.privacy_consent = 1`.
  - The contact form stores `contact_messages.gdpr_consent`.
- **Footer links** to the legal pages, Contact and ANPC are on the public pages and the auth pages. The panel footer has no links, only "© year app name · version".

### Public review funnel — `/r/{slug}`
- Mobile-first page with cover, logo, name and welcome message.
- Animated 5-star selector:
  - Rating ≥ company threshold (default 3) → Google review link, immediately or via a helper step. "Immediate" returns to the page (PRG, state `redirect_google`), which opens Google with `<meta refresh>` + JS — a direct POST → Google redirect would be blocked by the CSP `form-action` directive.
  - Rating below the threshold → empathetic private feedback modal (name, contact, message, honeypot, signed timer, CSRF).
- Tracking parameters:
  - `?src=` — channel: `qr_a4`, `qr_a5`, `qr_card`, `qr_sticker`, `qr`, `widget`, `email_sig`.
  - `?ref=` — employee / department / table tag (Pro, Enterprise, trial). Unknown tags are ignored.
- Never errors because of the owner's subscription: when the owner is over quota or expired, feedback is saved **locked** and the owner is emailed.
- Footer "Protejat de RepuVio" (shield icon, app name from Settings); hidden by White-label (`companies.white_label_enabled = 1`).

### DEV vs. PROD anti-abuse (`app_environment`)
Set from **Admin → Setări → Mediu & Anti-abuz**. A "DEV" badge appears in the header while DEV is on.

- **PROD** (default):
  - At most 1 counted review per company per device cookie (`rm_device`), or per IP + browser (User-Agent) pair, within `review_cooldown_hours` (default 24).
  - IP alone is not enough to block, so customers sharing a venue's Wi-Fi are not blocked.
  - Duplicates are still sent to Google, just not counted. Duplicate feedback shows "Am primit deja părerea ta".
  - Per-IP rate limits, session de-duplication, honeypot, signed timer and CSRF.
- **DEV**:
  - Rate limits, session de-duplication and the cooldown are disabled, so unlimited reviews can be simulated from one browser.
  - Honeypot, timer and CSRF stay on.
  - An orange "Mod DEV" flag is shown on the public page.

### Logos & covers
- Formats: JPG, PNG, WEBP and **SVG**. An SVG is converted to a transparent PNG in the browser before upload; the server never stores SVG.
- Every upload is re-encoded to WebP:
  - logo: longest side 600 px, transparency kept;
  - cover: longest side 1920 px.
- Recommended sizes are shown under the fields, with a live check after choosing a file:
  - logo: square, min. 500 × 500 px (ideal 1000 × 1000), transparent PNG or SVG;
  - cover: about 8:3, min. 1600 × 600 px, important content centred.
- Logos are shown in a circle with inner padding (printables, public page, company cards), so they are never clipped.

### Onboarding — `/dashboard/onboarding.php`
New business owners land in a **3-step wizard**:
- after registration;
- when they open the dashboard with no company;
- when they click "Adaugă companie" for the first time.

The wizard has a progress bar and a "Sari peste ghid / Mergi la formularul avansat" link (`company-edit.php?advanced=1`, which skips the wizard for the session).

1. **Identitatea afacerii tale:**
   - Name with a live `/r/{slug}` preview (editable in a collapsible field).
   - **Cod fiscal (CUI / CIF) \***, required for invoicing (see below).
   - Optional logo (drag & drop; without one, the initials icon is shown).
   - Optional cover with a live preview of the default monochrome gradient.
   - The welcome text and CTA are pre-filled in the background.
2. **Google & scutul de reputație:**
   - One field that accepts `https://g.page/r/...` (recommended), `writereview?placeid=...` or a raw Place ID, plus the helper modal **"Cum găsesc linkul meu Google în 10 secunde?"**.
   - Visual threshold cards: 3★+ (default), 4★+ "Recomandat pentru creșterea mediei", or 5★ only.
   - A live diagram of private feedback vs. Google redirection.
   - Saving creates the company.
3. **Celebration:**
   - The banner "Felicitări! Scutul de reputație pentru … este activ!".
   - The live QR code and a phone mock-up of `/r/{slug}?preview=1`. The owner's own preview is never counted as a view.
   - Buttons: A4 poster in Print Studio, test the public page, go to the dashboard.

### Business owner panel — `/dashboard/`
- **Cod fiscal (CUI / CIF)**, required on every company:
  - Asked in the wizard and in the company form.
  - Validated with the Romanian control digit; the optional `RO` prefix (VAT payer) is kept.
  - Companies without it show "Cod fiscal lipsă" on their card, and the Abonament page shows a warning.
  - Plan requests and print orders are blocked until every company (respectively the ordering company) has one.
  - Admin request and order emails include the billing table (company + CUI).
- **Checklist de Activare Rapidă** (Panou, owners): a progress bar "X din 4 pași completați" with four milestones:
  1. A company with a Google link.
  2. A logo or cover uploaded.
  3. The public page tested (≥ 1 view or review).
  4. Print Studio or Widget & Email visited, or "Marchează ca finalizat".

  "Ascunde ghidul" hides it (`users.onboarding_dismissed`).
- **Panou:**
  - KPIs with 30-day trends and the quota bar.
  - Chart.js activity chart and star distribution.
  - **Performanță pe angajat / sursă** table (tag breakdown), with a shortcut to the team report.
- **Rapoarte echipă** (`dashboard/team-report.php`, owners, Pro / Enterprise / trial; other plans see an upgrade card):
  - Compares the employee / department QR codes (`?ref=`) of one company over 30 days, 3 months (default) or 6 months.
  - KPIs: scans, evaluations, the company average (the benchmark) and the number of employee codes.
  - **"Pe scurt — ce înseamnă cifrele"**: plain-language conclusions built from each client's own data by `get_team_report()` (no demo-specific text):
    - who brings the most evaluations, or the most per day when codes have different ages;
    - who brings at most half as many per day: "aduce de N ori mai puține evaluări", with scans per week;
    - whose guests rate at least 0,4★ below the company average (minimum 5 evaluations), with a link to that code's private feedback;
    - who has the happiest guests;
    - which codes are new (under 14 days, not compared yet).
  - Safeguards against misleading conclusions:
    - **minimum data**: no comparison until the busiest code has 10 evaluations in the period ("Prea devreme pentru concluzii"); no rating judgement under 5 evaluations ("Puține date încă", grey);
    - **fair pace**: codes are compared by evaluations per day of existence, so a code created mid-period is not "weaker" by default; codes younger than 14 days show "Cod nou";
    - **advice by code type** (`ref_tag_kind()`): a person ("Ospătar Mihai", "Dr. Popescu"), a place ("Masa 4", "Terasa": check that the code is visible and undamaged) or a team / department ("Recepție").
  - Team ranking cards with flags ("Puține evaluări", "Note slabe", "Cod nou", "Puține date încă", "Prea devreme pentru concluzii", "Oferă codul, clienți mulțumiți") and the share of all evaluations.
  - **"Cum citești acest raport și cum ajungem la concluzii"** at the bottom: definitions, every rule with its threshold (read from the same constants as the calculation), and a reminder to interpret in context (shifts, holidays, part-time).
  - Four simple charts (`assets/js/team-report.js`):
    - scans vs evaluations per code;
    - average rating, coloured green / amber / red;
    - happy / neutral / unhappy split per 100 evaluations;
    - month-by-month evolution, with partial months labelled.
  - A detail table with links to the filtered reviews.
- **Feedback & Recenzii:**
  - Filters: company, stars, type, status, employee/source tag, dates and search.
  - Tickets, internal notes (status changes are logged as notes), CSV export with the tag column.
  - History is limited to the plan's `history_months`.
- **Companii:**
  - Rich cards: 135 px cover banner (gradient fallback with an upload button), overlapping 64 px logo, glass badges (threshold, white-label).
  - Card details: operators count, copy-link bar, QR preview modal, KPI boxes.
  - Actions: Editează / Print / Widget & Email / Feedback, plus an icon-only delete button (trash).
- **Print Studio:**
  - Templates: A4 poster, A5 table tent, 85 × 55 mm card, 100 × 100 mm sticker.
  - Vector QR, SVG download, CTA editor.
  - **QR codes per employee / department** — create tags such as "Chelner Mihai" (`?ref=chelner-mihai`), encoded into the QR and printed under it.
  - Physical print upsell banner (when enabled).
- **Widget & Semnătură Email** (sidebar link: "Widget & Email"):
  - Live configurator for the "Insignă de încredere" trust badge:
    - Position: floating bottom-right, floating bottom-left, or inline.
    - Theme: Light Monochrome or Dark Slate.
    - Copy-paste snippet: `assets/js/badge.js` for floating, plain HTML for inline. No cookies, links to `?src=widget`.
  - **WordPress plugin "RepuVio Trust Badge"** — "Descarcă plugin (.zip)" (`dashboard/wp-plugin.php`):
    - Generated per company and pre-configured with the review link (`?src=widget`) and the company name.
    - Built in pure PHP, so `ext-zip` is not needed.
    - Install in WordPress via Module → Adaugă nou → Încarcă modul.
    - Settings page **Setări → RepuVio Badge**:
      - enable / disable, link, display name;
      - position: floating right / left, or shortcode only;
      - theme;
      - pages: whole site, front page only, or selected page IDs, with excluded IDs (e.g. cart / checkout);
      - show on phones.
    - Shortcode `[repuvio_badge theme="light|dark"]` for blocks, Elementor and widgets.
    - Bundles its own copy of `badge.js`: no external scripts, no cookies. `uninstall.php` removes its option.
  - Three HTML email signatures (minimal link, stars box with logo, compact banner) linking to `?src=email_sig`:
    - **Copiază Semnătura Formatată (Vizual)** — rich copy for direct paste.
    - **Copiază Codul HTML**.
  - Tabbed tutorials for Outlook, Gmail, Apple Mail and Thunderbird.
- **Operatori:** create, edit, suspend and delete operator accounts; assign companies; reset passwords (forced change on first login).
- **Comandă printuri** (page title and menu link; only while the Super Admin enables the physical print upsell): order stands, flyers, cards, stickers and bundles. The Super Admin is emailed.
- **Ajutor & Ghid** (`/help` → `dashboard/help.php`, every role):
  - Instant search: ignores diacritics and searches guides and FAQ.
  - Three visual guides: Smart Funnel, how to get the Google link, QR placement best practices per industry.
  - FAQ accordions in four tabs: company & Google; Print Studio, `?ref=` & widget; complaints & operators; plans, quota & soft-lock.
  - Role-specific content: an operator ticket guide, and the Super Admin system documentation (DEV/PROD, cron, ROI, print upsell).
  - A support contact card with "Relansează Ghidul de Configurare Pas cu Pas" (owners).
- **Abonament:**
  - Current plan, limits and quota.
  - A Lunar / Anual toggle that only switches the displayed prices; a "Comandă abonamentul" button opens a confirmation modal before the request is sent.
  - The request is saved first (`subscription_requests`), then emailed. The owner sees "Solicitările tale de abonament" with their status.
  - Card of universal features, bank details and payment history.
- **Profil:** account data, password, and opt in / out of the monthly ROI email.
- **Demo accounts:** a "DEMO" badge in the top bar. Name, email and password cannot be changed, password reset emails are never issued, and plan / print orders are not sent.

### Super Admin panel — `/admin/`
- **Overview:** MRR (monthly price, or yearly price ÷ 12), users, trials, subscriptions, companies, reviews.
- **Utilizatori:**
  - Owners, operators (with their owner) and admins.
  - Plan, billing cycle, custom Enterprise limits, dates, quota reset, payments (plan × cycle), impersonation.
- **Abonamente clienți** (`admin/subscriptions.php`, sidebar badge = open requests):
  - KPIs: MRR, ARR, cash-in this month and over 12 months, active subscriptions, trials, open requests.
  - Charts: monthly cash-in from logged payments over 12 months (with payment count) and MRR per plan.
  - Tabs:
    - **Solicitări**: every plan request from the Abonament page, stored in `subscription_requests` even when the notification email fails. Shows email sent / not sent, the status Nouă / Proformă trimisă / Activată / Respinsă, and an admin note.
    - **Active**, **În probă**, **Expiră în 14 zile**, **Expirate & restante**.
  - Logging a payment with "Activează abonamentul" marks the client's open requests "Activată".
  - The overview page shows a banner while requests are open.
- **Planuri tarifare** (`admin/plans.php`, formerly "Abonamente"): plan CRUD — monthly + yearly price, "from" price, quota, locations, alert emails, history months, employee QR tags, onboarding, target description.
- **Rapoarte ROI:** "Trimite Raport Lunar ROI (Test / Manual)".
  - Recipient: one owner or all owners.
  - Period: previous month (default), current month or a custom range.
  - Click **Trimite Email ROI Acum** to send; the page shows a per-recipient result table.
- **Printuri fizice:** product / bundle catalogue (title, description, price in RON, order, visibility) and order statuses.
- **Mesaje contact** (`admin/contact-messages.php`, sidebar badge = new messages): filters (new / unarchived / archived / all); full message, reply by email, status, GDPR delete.
- **Setări:**
  - General settings and trial limits.
  - **Date juridice** — "Date Juridice & Contact Furnizor", the `legal_*` settings used by the legal pages and the public footer. Includes the CAEN code (`legal_caen`). A warning lists fields that still contain placeholder patterns (`....` or `xx`).
  - **Mediu & Anti-abuz** (DEV/PROD, cooldown).
  - **Printuri & Rapoarte** (`enable_physical_print_upsell` Activat / Ascuns). The automatic ROI report is switched on / off from the Email Center (template `monthly_roi_report`).
  - **Cont demo:** the sales showcase accounts (see "Demo accounts for presentations").
  - Email delivery: SMTP, Google OAuth2, Google App Password, PHP mail, or local log.
  - Test email (the last 10 entries of the email log are listed under it).
- **Email & Șabloane** (`admin/emails.php`, sidebar badge = failed emails in the last 7 days): see "Email Center" below.
- **Backup Google Drive** (`admin/backups.php`, sidebar badge when the last backup failed): see "Google Drive Backup Manager" below.

### Email Center ("Email & Șabloane")
Every system email is a row in `email_templates` and is sent by `send_templated_email( $slug, $to, $placeholders, $user_id, $options )` (`includes/mailer.php`):
1. Checks the ON/OFF switch. A switched-off template is not sent: one `skipped_disabled` row per recipient is logged instead.
2. Replaces the `{{placeholders}}` in the subject and the HTML body:
   - values are HTML-escaped;
   - keys ending in `_html` are pre-built escaped tables;
   - keys ending in `_url` go through `esc_url()`.
3. Wraps the body in the master layout.
4. Sends through the configured driver, or queues the email when called from cron.
5. Logs every recipient in `email_logs`, including the final HTML.

Sales demo (showcase) recipients are still redirected by `send_mail()`.

**Templates** (factory text in Romanian, defined in `includes/email-templates.php`):

| Slug | Email | Category | Default |
|---|---|---|---|
| `welcome_trial` | Welcome + free trial started | user_auth | ON |
| `password_reset` | Password reset link (with requesting IP) | user_auth | ON |
| `operator_invite` | Operator invitation with the initial password (forced change at first login) | user_auth | ON |
| `instant_negative_feedback` | Instant alert: private feedback below the threshold | reviews_digest | ON |
| `instant_positive_review` | Instant alert: customer sent to Google (only in `instant_all` mode) | reviews_digest | OFF |
| `daily_digest` | Summary of the previous day | reviews_digest | ON |
| `monthly_roi_report` | Monthly ROI / value generated | reviews_digest | ON |
| `quota_warning_80` | 80% of the monthly quota used | billing_quota | ON |
| `quota_reached_100` | Monthly quota reached | billing_quota | ON |
| `quota_exceeded_softlock` | Private feedback locked (quota / inactive subscription) | billing_quota | ON |
| `trial_expiring_soon` | Trial or paid subscription ends in 3 days | billing_quota | ON |
| `subscription_expired` | Trial / subscription expired | billing_quota | ON |
| `admin_new_user_alert` | [Admin] New user registered | admin_system | ON |
| `admin_contact_or_order` | [Admin] Contact message / print order / plan request | admin_system | ON |
| `admin_backup_failed` | [Admin] Google Drive backup failed (with the error) | admin_system | ON |
| `admin_backup_success` | [Admin] Google Drive backup succeeded (archives + size) | admin_system | OFF |

Global placeholders in every template: `{{app_name}}`, `{{app_url}}`, `{{support_email}}`, `{{year}}`, `{{accent_color}}`. The editor lists each template's own placeholders with a description.

**Review notification delivery modes** (`review_email_delivery_mode`, overridable per company in Companii → Editează → "Când primești emailurile despre recenzii"):

| Mode | Private feedback (below the threshold) | Google redirects | Daily Digest |
|---|---|---|---|
| `instant_all` | instant | instant, if `instant_positive_review` is ON | — |
| `smart_hybrid` (default, recommended) | instant | in the digest | every morning |
| `daily_digest_only` | in the digest | in the digest | every morning |

- Locked feedback (`quota_exceeded_softlock`) and account / billing emails are always instant.
- **Daily Digest** = activity of the previous calendar day (00:00–23:59) per company:
  - scans, Google redirects, private feedback count, average rating;
  - the table of every private message (locked messages masked).
- **Zero-spam rule:** a day without any evaluation sends nothing. A manual admin send goes out anyway.
- Sales showcase companies are excluded from the automatic digest.

**Tabs:**
1. **Reguli de expediere & Rezumat zilnic:**
   - the delivery mode;
   - the digest hour (`daily_digest_hour`, default 08:00; `last_daily_digest_run_date` guards one run per day);
   - SMTP throttling: `smtp_batch_limit_per_run` (default 30 emails per cron run) and `smtp_delay_ms` (default 250 ms);
   - "Trimite rezumatul zilnic acum" (company + day, to me with "[Test]" or to the company recipients);
   - "Trimite acum coada".
2. **Șabloane de email:** for every template, grouped by category:
   - an ON/OFF switch;
   - **Editează**: subject, HTML body and placeholder pills inserted at the cursor (`assets/js/emails.js`);
   - **Previzualizează modificările** (unsaved draft, in a sandboxed iframe rendered by `admin/email-preview.php`);
   - **Trimite test** (demo data, "[Test]" prefix, to the admin, even when the template is OFF);
   - **Resetează la textul implicit**.
3. **Aspect global:** header title or logo URL (https), accent colour, footer text (`{{year}}`, `{{app_name}}`, `{{provider_name}}`) and a default Reply-To. A live preview is shown.
4. **Jurnal emailuri:**
   - filters: status Trimis / Eroare / În coadă / Oprit, template, search;
   - "Vezi emailul trimis" (the stored HTML) and "Retrimite acum" (updates the same row);
   - "Curăță jurnalul mai vechi de 30 de zile". Cron also purges rows older than 90 days.

### Google Drive Backup Manager ("Backup Google Drive")
The backup manager is pure PHP (`includes/google-drive-backup.php`): no Composer, no SDK, no `exec()`. It needs `curl`, `zlib` and, optionally, `zip`.

**Connection (card 1):**
- OAuth 2.0 with the scope `https://www.googleapis.com/auth/drive.file`: the app can only see the folder and files it created.
- Credentials:
  - dedicated `gdrive_client_id` / `gdrive_client_secret`;
  - or reuse the Gmail SMTP OAuth client (`gdrive_use_mail_credentials`).
- The authorized redirect URI is `/admin/gdrive-oauth.php` (copy button on the page).
- The modal "Cum obțin credențialele din Google Cloud Console în 2 minute?" walks through the setup, including "Publish app". In Testing mode Google expires refresh tokens after 7 days.
- "Conectează contul Google Drive" redirects to Google with `access_type=offline` and `prompt=consent` and a single-use `state`.
- The callback:
  - stores the refresh / access tokens encrypted (AES-256-CBC + HMAC, like the SMTP password);
  - stores the account email (`/about`);
  - finds or creates the folder (`gdrive_folder_name`, default **`ahoi-backups`**) and stores `gdrive_folder_id`.
- "Verifică / recreează folderul" searches by name again. "Deconectează" revokes the token at Google.

**Content & schedule (card 2):**
- Master switch `gdrive_backup_enabled`.
- Database switch (`gdrive_backup_db`, `.sql.gz`) and files switch (`gdrive_backup_files`, `.zip`).
- Files scope `gdrive_files_scope`:
  - `uploads_and_config` (default): `/uploads/` + `/config/`;
  - `full_app`: the whole application.
- Frequency `gdrive_frequency`: `daily`, `twice_weekly` (Monday + Thursday), `weekly` (Sunday), `monthly` (the 1st). The hour is `gdrive_backup_hour` (default 03:00).
- The page shows the next and the last backup.
- Retention `gdrive_retention_count`: 5 / 10 / 14 (default) / 30 / 0 = unlimited. It keeps the newest N database archives and the newest N files archives in the folder.
- Keep a local copy of the latest archive of each type in `storage/backups` (`gdrive_keep_local`).

**Manual (card 3):**
- "Rulează backup complet acum (DB + fișiere în Google Drive)".
- "Doar backup bază de date acum".
- "Descarcă backup SQL direct în browser": streams a fresh `.sql.gz` and works without Google Drive.

**History (card 4):** `backup_logs`, one row per archive:
- date, type, trigger (cron / manual), file name, size, status (Reușit / În curs / Eșuat + error);
- "Descărcat în browser" / "Șters din Drive (retenție)" notes;
- a Google Drive link and delete (also deletes the Drive file and the local copy).

**How a backup runs** (`run_backup( 'full'|'db'|'files', 'cron'|'manual' )`, guarded by the MySQL lock `repuvio_backup`):
1. **Database:** `dump_database_to_gzip()` streams every base table into `storage/backups/{site}-db-{date}-{rand}.sql.gz` (gzip level 6):
   - header with date, app version and schema;
   - `SET FOREIGN_KEY_CHECKS=0`, `DROP TABLE IF EXISTS`, `SHOW CREATE TABLE`;
   - rows in 500-row batches ordered by primary key, as multi-row `INSERT`s escaped with `PDO::quote()`;
   - `SET FOREIGN_KEY_CHECKS=1` at the end.

   The file is a standard SQL dump: restore it with phpMyAdmin or `gunzip < file.sql.gz | mysql`.
2. **Files:** `build_files_backup_zip()` uses `ZipArchive`, or a streaming pure-PHP ZIP writer when the `zip` extension is missing (files over 16 MB are stored uncompressed; 4 GB limit). Always excluded: `storage/backups/`, `.git/`, `node_modules/`, `.claude/`, IDE folders and `.zip` / `.gz` / `.log` / `.tmp` / `.bak` files.
3. **Upload:** resumable Drive upload (`uploadType=resumable`) in 2 MB chunks read with `fread()`. Memory stays flat, the token is refreshed between chunks, and 308 responses resume from the confirmed byte.
4. **Retention:** after a successful upload, older archives beyond the limit are deleted from the folder, per type (`gdrive_deleted_at` is set in the log).
5. **Local cleanup:** uploaded archives are removed from `storage/backups/`. With "keep local", only the newest archive of each type stays. Failed uploads are kept for 7 days.
6. **Logging and alerts:**
   - Every archive is logged in `backup_logs`; `gdrive_last_backup_at` / `gdrive_last_backup_status` are updated.
   - Admins are emailed through `admin_backup_failed` (ON) or `admin_backup_success` (OFF by default).
   - Both templates are editable in Email & Șabloane.

**Schedule:**
- `maybe_run_scheduled_backup()` runs from the hourly `cron.php` when `gdrive_next_backup_at` has passed. The next run is computed before starting, so overlapping crons never double-run.
- Saving the schedule recomputes `gdrive_next_backup_at`.
- If Drive is not connected, the scheduled run fails, is logged and sends the alert.

**Security:**
- `storage/backups/` gets its own deny-all `.htaccess` (the root `.htaccess` also blocks `storage/`).
- Archives contain personal data, encrypted settings and, with the files backup, `config/config.php` (DB password, `APP_KEY`). Keep the Drive account private and protected with 2-step verification.

### Monthly ROI / "Valoare generată" email
The email is HTML and contains:
- QR scans / views;
- customers sent to Google;
- negatives intercepted privately (and how many were resolved);
- average internal rating and conversion;
- a per-location table;
- **top employee / source tags** (Pro / Enterprise / trial).

`cron.php` queues the previous month's report once per owner (`users.last_roi_report_period`) while the `monthly_roi_report` template is ON. The manual send from Admin → Rapoarte ROI also works while it is OFF.

### Demo accounts for presentations (showcase)
Two shared accounts for presenting the product to prospects. They are rebuilt automatically every day (`includes/showcase.php`).

| Account | Default email | What it shows |
|---|---|---|
| Business owner | `proprietar@repuvio.ro` | Pro plan (active, paid), one company "Bistro Livada Demo" **without logo or cover** (upload them live), 3 months of scans and reviews, 2 employee QR codes, Rapoarte echipă, payment history, the activation checklist |
| Operator | `operator@repuvio.ro` | Handles the private feedback of the same company: new tickets from the last week, tickets in progress, resolved tickets with notes |

The data tells a clear story:
- "Ospătar Andreea" offers the QR code to almost every guest: about 3–4 scans per day and an average rating around 4,6★.
- "Ospătar Mihai" avoids it: about 5–7 times fewer evaluations and an average around 3★.
- The general codes (posters, table stickers, link) sit around 4,3★.

Settings live in **Admin → Setări → Cont demo**:
- both emails and display names;
- the company name (invented);
- an optional Google link for live tests;
- **the shared password** (stored only as a hash);
- **the email address that receives the demo notifications**.

The first time, use **"Activează și creează conturile"**. Later, **"Salvează și regenerează"** applies the changes immediately, and **"Dezactivează conturile"** turns them off. "Regenerează acum" rebuilds the data on demand, and "Intră ca proprietarul demo" impersonates the owner.

How it works:
- Showcase accounts are flagged with `users.is_showcase = 1`. The reset only touches flagged accounts and refuses an email that belongs to a real account.
- Every reset deletes the previous day's work:
  - the company (with its uploaded logo / cover), reviews, scans, QR codes and notes;
  - extra operators created during a demo;
  - payments, plan requests and print orders.

  It then recreates everything for the current dates. The data is random but stable within a day.
- Sessions survive the reset, so a demo already in progress keeps working.
- **No email is sent to the demo addresses.** `send_mail()` replaces them with the configured notification address and prefixes the subject with "[Demo]". This covers negative feedback alerts, quota alerts, ROI reports, the welcome email for a new operator, and similar emails. With no address configured, the email is dropped.
- Disabling the feature suspends both accounts.

### Automation — `cron.php`
Schedule it **every hour**. Every task is guarded, so it runs only once per day or period, and the hourly runs drain the email queue:
- rebuilds the demo accounts for presentations (first run of the day, when enabled);
- expires trials and subscriptions (+ `subscription_expired` email);
- rolls monthly quota cycles;
- sends reminders 3 days before a trial ends (`trial_reminder_sent`) or a paid subscription ends (`subscription_reminder_for` = the expiry date it reminded about);
- unlocks pending feedback;
- queues the monthly ROI reports;
- queues the Daily Digest of the previous day (first run from `daily_digest_hour`);
- runs the scheduled **Google Drive backup** when it is due;
- **sends the email queue**: at most `smtp_batch_limit_per_run` emails, `smtp_delay_ms` apart. The rest stays queued for the next run;
- cleans rate limits, reset tokens and email logs older than 90 days.

Emails triggered by visitors and users (feedback alerts, password reset, invitations, admin notifications) are sent immediately, not queued.

---

## Requirements

- PHP 8.1+ with `pdo_mysql`, `openssl`, `fileinfo`, `gd` (WebP support), `mbstring`, `curl`, `zlib`. Recommended: `zip` (`ZipArchive`) for faster files backups; a pure-PHP fallback is built in.
- MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4).
- Apache or LiteSpeed with `mod_rewrite` and `AllowOverride All` (`.htaccess`).

## Installation

1. Upload all files to your web root (or a sub-folder, e.g. `public_html/repuvio`).
2. Create a MySQL database + user (utf8mb4) and import the schema:
   ```bash
   mysql -u DB_USER -p DB_NAME < schema.sql
   ```
3. Copy `config/config.sample.php` to `config/config.php` and fill in:
   - `APP_URL` — full URL without trailing slash (e.g. `https://reviews.example.ro`).
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
   - `APP_KEY` — 32 random bytes, base64:
     ```bash
     php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
     ```
   - `CRON_TOKEN` — a long random string:
     ```bash
     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
     ```
   - `APP_DEBUG` — `false` in production.
4. Make `uploads/` and `storage/logs/` writable by PHP (755/775).
5. Log in at `/auth/login.php` with the default Super Admin:
   - **Email:** `admin@example.com`
   - **Password:** `ChangeMe-2026!`
   You are forced to change the password on first login. Change the email in *Profil*.
6. In **Admin → Setări**, configure email delivery and send a test email. Keep **PROD** mode in production.
7. Add the cron job **once per hour** (cPanel → Cron Jobs → "Once Per Hour"):
   ```bash
   php /home/USER/public_html/cron.php
   ```
   or over HTTP: `curl -s "https://your-domain/cron.php?token=CRON_TOKEN"`.

> Never change `APP_KEY` after secrets were saved — encrypted settings would become unreadable (re-enter them if you must rotate it).

### Upgrading an existing installation
Upload the new files; the database upgrades itself on the first request. `includes/migrations.php`:
- creates the new tables from `schema.sql`;
- adds missing columns, indexes and foreign keys;
- moves `plans.billing_cycle` to `users.billing_cycle`;
- renames the old Start / Business / Pro catalogue to Starter / Pro / Enterprise;
- adds the onboarding state columns (`users.onboarding_dismissed`, `users.onboarding_materials_at`; schema version 3);
- adds `companies.tax_id` (fiscal code, schema version 4). Existing companies must fill it in before the next plan request;
- creates `subscription_requests` (schema version 5);
- **Email Center (schema version 9):**
  - creates `email_templates` (seeded with the factory templates) and `email_logs`;
  - moves the old `email_log` rows into `email_logs`, then drops `email_log`;
  - adds `companies.email_delivery_mode` and `users.subscription_reminder_for`;
  - seeds the delivery, layout and throttling settings;
  - moves the old `roi_reports_enabled` switch onto the `monthly_roi_report` template;
  - **reschedule cron hourly** after this upgrade;
- **Google Drive Backup (schema version 10):** creates `backup_logs`, seeds the `gdrive_*` settings and adds the `admin_backup_failed` / `admin_backup_success` templates;
- adds `users.is_showcase` for the demo accounts for presentations (schema version 8);
- **legal & GDPR (schema version 7):** `legal_*` settings seeded, the `contact_messages` table created, `users.terms_accepted_at` and `reviews.privacy_consent` added, and the old `terms_url` setting removed;
- **RepuVio rebrand (schema version 6):**
  - Encrypted settings (SMTP password, Google secrets) are re-encrypted from the old brand-derived key labels to the brand-independent `app-secret` labels.
  - `app_name` / `mail_from_name` still holding the old default become "RepuVio".
  - The session cookie is now `repuvio_sid`, so everyone signs in once more after the upgrade.
  - The WordPress plugin slug is now `repuvio-trust-badge` (shortcode `[repuvio_badge]`). Plugins downloaded before the rebrand keep working but are a different plugin: replace them with a fresh download;
- stores `schema_version` in `settings`.

The migration is idempotent and guarded by a MySQL named lock. It logs to `storage/logs/migrations.log`.

### Google OAuth2 email (Gmail)
1. Google Cloud Console → create an OAuth client (type *Web application*).
2. Authorized redirect URI: `APP_URL/admin/google-oauth.php` (shown in Admin → Setări).
3. Enable the Gmail API; consent screen scope `https://mail.google.com/`.
4. Paste Client ID / Secret / Gmail address in Admin → Setări, save, click **Conectează contul Google**.
5. Alternatively use a Google **App Password** (driver *Google App Password*, `smtp.gmail.com:587`).

## Directory structure

```
/                         index.php (landing page / panel redirect), review.php (public funnel), cron.php,
│                         terms.php, privacy.php, cookies.php, contact.php (public legal & contact pages)
├── .htaccess             Rewrites (/r/{slug}, /help, /termeni-si-conditii, /confidentialitate,
│                         /politica-cookies, /contact), security headers, blocks private dirs & .md/.sql
├── admin/                index, users, user-edit, plans, plan-edit, payments, roi-reports, subscriptions,
│                         contact-messages, print-products, settings (incl. Cont demo), emails (Email & Șabloane),
│                         backups (Backup Google Drive), gdrive-oauth (Drive OAuth callback), email-preview (sandboxed email preview), google-oauth, impersonate
├── assets/css            app.css (panel), public.css (funnel), print.css (printables), landing.css (landing page)
├── assets/js             app.js, dashboard.js, admin.js, review.js, printables.js, studio.js,
│                         promote.js (widget/signature UI), badge.js (embeddable trust badge),
│                         onboarding.js (wizard), help.js (help center search), landing.js (landing page),
│                         subscriptions.js (admin revenue charts), team-report.js (Rapoarte echipă charts),
│                         emails.js (Email Center placeholder insertion)
├── assets/img            favicon.svg
├── auth/                 login, register, logout, forgot-password, reset-password, stop-impersonation
├── config/               config.php (secrets, not versioned), config.sample.php
├── dashboard/            index, companies, company-edit, reviews, review-view, export, printables,
│                         print, promote, wp-plugin (ZIP download), operators, print-order, billing,
│                         profile, switch-company, onboarding (3-step wizard), help (Ajutor & Ghid),
│                         team-report (Rapoarte echipă)
├── includes/             bootstrap, db, formatting, helpers, security, session, settings, mailer,
│                         emails, subscription, auth, companies, reviews, uploads, operators,
│                         antiabuse, reports (ROI + team report), print-shop, promote, onboarding, legal,
│                         showcase (demo accounts for presentations), email-templates (template registry,
│                         factory texts, demo data), google-drive-backup (OAuth, Drive API, SQL dump, ZIP,
│                         scheduler, retention), migrations
│   └── wp-plugin/        WordPress plugin template (repuvio-trust-badge: main file, uninstall.php,
│                         readme.txt); zipped with assets/js/badge.js + a generated defaults.php
├── storage/logs/         php-error.log, mail.log (log driver), cron.log, migrations.log
├── storage/backups/      Temporary / local backup archives (deny-all .htaccess, created on first backup)
├── templates/            app-header/footer, sidebar, auth-header/footer, error, feedback-form,
│                         google-link-modal (Google link helper), public-head / public-nav /
│                         public-footer (landing + legal pages, cookie banner), legal-document
├── tools/                dev-router.php (`php -S` router), seed-demo.php, email-templates-sql.php (prints the
│                         template seed for schema.sql) — CLI only, blocked on the web
├── uploads/companies/    Re-encoded logos & covers (script execution disabled)
├── schema.sql            Complete idempotent schema + seed
├── README.md / CLAUDE.md / RULES.md
```

## Database tables

| Table | Purpose |
|---|---|
| `plans` | Tiers: monthly + yearly price, "from" flag, quota, locations, alert emails, history months, employee QR tags, onboarding |
| `users` | Admins, owners and operators (`parent_id`); plan, billing cycle, custom limits, subscription, quota, ROI report state, onboarding checklist state (`onboarding_dismissed`, `onboarding_materials_at`), consent record `terms_accepted_at`, `is_showcase` (demo accounts for presentations), `subscription_reminder_for` (expiry date already reminded) |
| `companies` | Businesses per owner (fiscal code `tax_id` for invoicing, slug, branding, Google link, threshold, white-label, `email_delivery_mode` = global / instant_all / smart_hybrid / daily_digest_only) |
| `operator_companies` | Operator ↔ company assignments |
| `ref_tags` | Employee / department / table tags per company (`?ref=slug`) |
| `reviews` | Star interactions: Google redirects & private feedback, source, ref tag, IP / device hashes, lock + ticket state, `privacy_consent` (GDPR checkbox on private feedback) |
| `review_notes` | Internal resolution notes (incl. automatic status-change notes) |
| `funnel_views` | Page views / QR scans with source and ref tag |
| `contact_messages` | Public contact form inquiries (subject, message, GDPR consent, status new / read / replied / archived, email_sent flag) |
| `subscription_requests` | Plan requests (plan / cycle / price snapshot, status pending → invoiced → completed / rejected, email_sent flag, admin note) |
| `print_products` | Physical print catalogue (price in RON, visibility) |
| `print_orders` / `print_order_items` | Physical print orders and snapshotted order lines |
| `rate_limits` | Brute-force protection (IP + action) |
| `password_resets` | Hashed single-use reset tokens |
| `payments` | Manually logged invoice payments |
| `settings` | Global settings (secrets encrypted), incl. `app_environment`, `review_cooldown_hours`, `enable_physical_print_upsell`, email delivery (`review_email_delivery_mode`, `daily_digest_hour`, `last_daily_digest_run_date`, `smtp_batch_limit_per_run`, `smtp_delay_ms`), email layout (`email_header_title`, `email_logo_url`, `email_accent_color`, `email_footer_text`, `email_reply_to`), Google Drive backup (`gdrive_*`; `gdrive_client_secret`, `gdrive_refresh_token`, `gdrive_access_token` encrypted), `showcase_*` (demo accounts: emails, names, company, Google link, notification email, password hash, last reset), `schema_version` |
| `email_templates` | Every system email: editable subject + HTML body, factory defaults for reset, ON/OFF, category, placeholder list (JSON) |
| `backup_logs` | Google Drive backups, one row per archive: type db / files / full, trigger cron / manual, destination gdrive / download, file name and size, Drive file ID + web link, `gdrive_deleted_at` (retention), status success / failed / running, duration, error |
| `email_logs` | Every outgoing email per recipient: template, recipient (+ user), Reply-To, subject, final HTML, status sent / failed / queued / skipped_disabled, SMTP error, sent time |
| `audit_log` | Security audit trail |

## Security highlights

The design follows the OWASP Top 10:
- PDO prepared statements only and a global CSRF check on every POST.
- Tenant-scoped queries with an additional operator company restriction (IDOR prevention).
- Argon2id / bcrypt passwords, rate limiting and lockout, no user enumeration.
- Hardened sessions: fingerprint, idle timeout, rotation and version-based revocation.
- CSP with nonces, a secure image pipeline, encrypted secrets and a script-free uploads folder.
- Blocked private folders and a DEV/PROD-aware anti-abuse engine on the public funnel.

See `RULES.md`.

### Known trade-offs

- Registration is rate limited but not fully enumeration-proof (that would require email verification before login).
- Payments and print orders are confirmed manually by the Super Admin (bank transfer workflow); there is no card gateway.
- PDF export uses the browser's "Save as PDF" on print-ready pages with exact `@page` sizes.
- Front-end libraries are loaded from jsDelivr with pinned versions (no SRI hashes yet).
- The PROD cooldown relies on a first-party cookie and IP + User-Agent; a determined user with new devices / networks can still vote again.

## Local development

On this machine Laragon serves the folder at `http://repuman.test`, with the real `.htaccess` rules. `APP_URL` must match the URL opened in the browser.

Alternative without Apache:
```bash
php -S 127.0.0.1:8791 -t . tools/dev-router.php
```
With this server, set `APP_URL` to `http://127.0.0.1:8791`.

Switch to **DEV** mode (Admin → Setări → Mediu & Anti-abuz) to test unlimited reviews from one browser.

## Demo data

Two kinds of demo data exist:
- **Demo accounts for presentations** (`proprietar@` / `operator@`). Safe in production, rebuilt daily, configured in Admin → Setări → Cont demo. See "Demo accounts for presentations".
- **Development seeder** (below). For local testing only.

`tools/seed-demo.php` (CLI only, blocked on the web) creates demo owners on every plan. It generates:
- logos and covers;
- employee / department QR tags;
- an operator account;
- 60 days of views, Google redirects and feedback tickets with notes;
- payments.

It is re-runnable: previous demo data (`@repuvio-demo.test`) is removed first.

```bash
php tools/seed-demo.php
php tools/seed-demo.php --remove
```

All demo accounts use the password `Demo-2026!` (constant `DEMO_PASSWORD`):

| Email | Scenario |
|---|---|
| `andrei@repuvio-demo.test` | Pro, 3 companies (restaurant, cafe, pizzeria without a cover), QR tags (Chelner Mihai, Terasa, Masa 4…), operator |
| `operator@repuvio-demo.test` | Operator of Andrei, assigned to Bistro Teiul Verde and Cafeneaua Aroma |
| `ioana@repuvio-demo.test` | Enterprise (yearly), clinic + hotel, white-label ON, department tags |
| `sorin@repuvio-demo.test` | Starter, 1 company, no QR tags (upgrade prompt) |
| `elena@repuvio-demo.test` | Free trial, 12 days left, quota almost used |
| `mihai@repuvio-demo.test` | Trial expired, locked negative feedback (soft-lock demo) |

Never run the demo seeder on a production database.

## Provider (seed defaults)

AHOI DIGITAL SRL:
- Str. Sfânta Agnes, nr. 191, Vila 7, Popești-Leordeni, Ilfov, România
- CUI 46112959 · Nr. Reg. Com. J23/3075/2022 · CAEN 6311
- iftimie.s@gmail.com · 0762 82 33 33

The bank details shown to customers (`bank_details`) use IBAN RO79 BTRL RONC RT0C G824 0201 (Banca Transilvania). All of these are editable in Admin → Setări.

## License & credits

© Ahoi Digital SRL. Created by Stefan Iftimie — https://www.ahoi.ro/
