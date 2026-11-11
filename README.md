# Laxyam Academy

**Learn • Prepare • Achieve** — the website, student portal and admin panel of
[Laxyam Academy](https://laxyamacademy.com), a competitive-exam coaching institute in Badarpur,
New Delhi (UPSC, SSC, Banking, State PSC, Railway, Defence, Teaching and Police exams).

Laxyam Academy is a brand of **LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED**
(CIN U85491DL2026PTC470370), an **ISO 9001:2015** certified organisation.

| | |
|---|---|
| Live site | https://laxyamacademy.com |
| Frontend | React 19 single-page app, built with Vite 8 and Tailwind CSS 4 |
| Backend | Core PHP 8.3+ JSON API and a server-rendered admin panel |
| Database | MySQL 8 / MariaDB 10.11+ (InnoDB, utf8mb4) |
| Hosting | Any Apache host with `mod_rewrite` (production runs on Hostinger shared hosting) |

---

## Contents

1. [Features](#features)
2. [Tech stack](#tech-stack)
3. [Project structure](#project-structure)
4. [Requirements](#requirements)
5. [Local setup (XAMPP)](#local-setup-xampp)
6. [Configuration](#configuration)
7. [Development workflow](#development-workflow)
8. [Building and deploying](#building-and-deploying)
9. [Scheduled jobs (cron)](#scheduled-jobs-cron)
10. [Database and migrations](#database-and-migrations)
11. [Admin panel](#admin-panel)
12. [Integrations](#integrations)
13. [Public API (v1)](#public-api-v1)
14. [Security](#security)
15. [ISO certificate settings](#iso-certificate-settings)
16. [Coding conventions](#coding-conventions)
17. [Troubleshooting](#troubleshooting)
18. [Ownership](#ownership)

---

## Features

### Public website
- **Home page** driven by the CMS: hero banner, exam categories, popular courses, why-choose-us,
  testimonials, results, free resources, YouTube / Instagram video sections, newsletter sign-up.
- **Course catalogue** with filters, search (with voice input), course pages, batches by academic
  session, offers with end dates, fee items and subject-wise syllabus. Every course shows how students
  join: **Enroll Now** (create a free account and pay online), **Enroll Now + Apply Now** (pay online
  or ask for a counsellor call) or **Apply Now only** (enquiry form, no online payment).
- **Mock tests** with timed exam room, bilingual questions (English + Hindi), sections with sectional
  timing, negative marking, results with topic analysis, rank and leaderboard.
- **Daily quiz** with streaks, **live classes**, **free resources / study material**, **blog**,
  **photo gallery**, **FAQ**, policy pages and custom CMS pages (page builder).
- **Govt jobs & exam notifications** (always linking to the official source) and a **careers** page
  for faculty recruitment with online applications.
- **Admission chat assistant** that answers from the academy's courses, tests and FAQs, with optional
  AI answers (OpenAI or Anthropic Claude).
- Header highlights ticker, promo banners, popups, WhatsApp / call buttons, English / हिंदी switch.
- SEO: server-side `<title>`, meta, canonical, Open Graph and JSON-LD per page (`index.php`),
  `sitemap.xml`, `robots.txt`, managed redirects and Google Search Console indexing status.
- Installable PWA (web manifest + service worker).

### Student portal
- Sign-up and login by **email OTP** (default), password, **mobile OTP** (WhatsApp / SMS) or
  **Google sign-in**; email verification, forgot / reset password.
- Dashboard, my courses with lesson player and progress, my tests and results, live classes,
  resources, purchases and invoices, certificates, wishlist, notifications, doubts (ask a question),
  support tickets, profile, settings and notification preferences.
- **Refer & Earn** referral programme with personal coupon codes.
- Checkout with **Cashfree** (default) or Razorpay, GST invoices, coupons.

### Admin panel (`/admin/`)
- Dashboard with go-live checklist, alerts and quick stats.
- Courses, each opened as a **course workspace** with numbered tabs: 1 Details & page, 2 Curriculum
  (sections → lessons outline), 3 Study material, 4 Students, 5 Preview. Also categories, batches,
  faculty, mock tests, question bank (with paste import and bilingual questions), topics, daily
  quizzes, live classes, free resources, certificates.
- Students, enrolments, orders, payments, invoices, coupons, referrals, reviews, testimonials,
  leaderboard, doubts, support desk, enquiries / leads, chatbot, newsletter.
- Website CMS: homepage sections, pages, blog, gallery, videos, banners, highlights, popups, menus and
  social links, appearance, SEO, broken-link checker, indexing.
- Emails and WhatsApp: templates, delivery logs with retries, broadcasts.
- Jobs and careers (openings + applications), staff accounts, roles and permissions, two-step
  verification, access policy (IP allowlist / access hours), audit logs, backups, storage.
- System settings, Keys & Credentials (encrypted), integrations, API keys, Database Updates.

All admin sub-menus are on-screen tabs; big modules have one sidebar entry.

---

## Tech stack

| Layer | Technology |
|---|---|
| UI | React 19, React Router 7, Tailwind CSS 4 (via `@tailwindcss/vite`), inline SVG icon set |
| Build | Vite 8 (`npm run build` → `dist/`), route-level code splitting |
| API | Core PHP (no framework), PDO prepared statements, JSON responses `{ ok, data }` / `{ ok:false, error }` |
| Admin | Server-rendered PHP pages (`admin/`) sharing the same session and database |
| Database | MySQL 8 / MariaDB, InnoDB, `utf8mb4_unicode_ci`, Asia/Kolkata time |
| Email | Built-in SMTP mailer with queue, retries and delivery log |
| Payments | Cashfree Payment Gateway (default), Razorpay |
| Messaging | WhatsApp via Interakt (default) or Meta Cloud API; SMS OTP via MSG91 |
| Hosting | Apache + `mod_rewrite` (`.htaccess` front controller) |

---

## Project structure

```
laxyam/
├── index.php            # Front controller for every website route: redirects + SEO meta, serves dist/index.html
├── index.html           # Vite source shell (never served directly)
├── .htaccess            # Routing, blocked folders/files, security headers, caching
├── sw.js                # Service worker (cache-first for built assets; bump VERSION when /assets change)
├── vite.config.js       # Base path from VITE_APP_BASE, dev proxy to Apache
├── package.json
├── .env.example         # Copy to .env (never committed)
│
├── js/                  # React app: App.jsx (routes), api.js (fetch wrapper), auth.jsx, components.jsx
│   ├── ui/              #   header, footer, mobile nav, search overlay, popups
│   ├── home/            #   hero, media sections, newsletter
│   ├── account/ commerce/ content/ course/ exam/ gallery/
│   └── chatbot.jsx
├── pages/               # One React component per route (Home, Courses, MockTestDetails, StudentDashboard, …)
├── css/style.css        # Tailwind entry + design tokens
│
├── api/                 # JSON API used by the React app
│   ├── config.php       #   bootstrap: reads .env, loads db + helpers (blocked from the web)
│   ├── db.php           #   PDO connection + db_one/db_all/db_value/db_query helpers
│   ├── auth.php cms.php courses.php mock-tests.php orders.php payments.php students.php …
│   ├── webhooks.php     #   payment gateway webhooks
│   └── v1.php           #   server-to-server API with API keys
├── admin/               # Admin panel pages (login, dashboard, courses, settings, updates, …)
├── includes/            # Shared PHP: auth, CSRF, permissions, mailer, payments, scheduler, invoices, …
├── database/
│   ├── schema.sql       # Base schema (v1)
│   ├── seed.sql         # Seed data (rows with is_demo = 1 are demo content)
│   ├── migration-NNN.sql# Incremental changes 002 … 019
│   ├── create-admin.php # CLI: create / promote a Super Admin
│   └── cron.php         # CLI: scheduled jobs
├── assets/              # Static files served as-is (logo, icons, images, admin.css/js, fonts, vendor)
├── uploads/             # User uploads (only the folder skeleton is in git; uploads/private is never public)
└── docs/                # Internal notes (audit, redesign report)
```

`dist/`, `node_modules/`, `.env`, uploaded files and deploy zips are git-ignored.

---

## Requirements

- **PHP 8.3+** with `pdo_mysql`, `mbstring`, `openssl`, `json`, `gd` (with WebP) and `curl`
  (production is PHP 8.3 — do not use PHP 8.4+ only functions such as `array_find`).
- **MySQL 8** or **MariaDB 10.11+**.
- **Apache 2.4** with `mod_rewrite` (and ideally `mod_headers`, `mod_deflate`, `mod_expires`).
- **Node.js 22 LTS** (20.19+ works) and npm — only to build the frontend; the server does not need Node.

---

## Local setup (XAMPP)

1. **Get the code** into the web root, e.g. `C:\xampp\htdocs\laxyam`:
   ```bash
   git clone https://github.com/TechPresenter/laxyamacademy.git C:/xampp/htdocs/laxyam
   cd C:/xampp/htdocs/laxyam
   npm install
   ```
2. **Create the database** (phpMyAdmin or the MySQL CLI) and import the base files in this order:
   ```bash
   mysql -u root -e "CREATE DATABASE laxyam_academy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root laxyam_academy < database/schema.sql
   mysql -u root laxyam_academy < database/seed.sql
   ```
3. **Configure** — copy `.env.example` to `.env` and set at least:
   ```ini
   APP_ENV=development
   APP_URL=http://localhost/laxyam      # include the port if Apache is not on 80
   VITE_APP_BASE=/laxyam/
   DB_HOST=127.0.0.1
   DB_NAME=laxyam_academy
   DB_USER=root
   DB_PASS=
   ```
4. **Build the frontend** (writes `dist/`):
   ```bash
   npm run build
   ```
5. **Create the first Super Admin** (asks for a password, minimum 10 characters):
   ```bash
   php database/create-admin.php "Your Name" you@example.com
   ```
6. **Apply the remaining migrations**: sign in at `http://localhost/laxyam/admin/`, open
   **System Settings → Database Updates** and press **Apply**. This runs every pending
   `database/migration-NNN.sql` (002 … 019) in order. (Alternatively import them by hand in numeric
   order with the MySQL CLI.)
7. Open **http://localhost/laxyam/** for the website and **/laxyam/admin/** for the admin panel.

Without SMTP configured, emails are written to `uploads/private/logs/mail.log`, and in development
the API returns verification / reset links so you can keep testing.

---

## Configuration

All server configuration lives in `.env` (blocked from the web by `.htaccess`). Most integration keys
can also be entered in **Admin → Keys & Credentials**; those values are stored encrypted in the
database (`app_config`, key file in `uploads/private/`) and take precedence over `.env`.

| Group | Keys | Notes |
|---|---|---|
| Application | `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE` | `APP_ENV=production` on the live server; `APP_URL` without trailing slash |
| Frontend | `VITE_APP_BASE`, `VITE_DEV_PHP_ORIGIN` | `/` on a domain root, `/laxyam/` on XAMPP. Only `VITE_*` values reach the browser — never put secrets in them |
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | |
| Sessions | `SESSION_NAME`, `SESSION_LIFETIME` | minutes |
| Email | `MAIL_FROM`, `MAIL_FROM_NAME`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` | `tls`, `ssl` or `none` |
| Payments | `PAYMENT_PROVIDER`, `PAYMENT_MODE`, `CASHFREE_*`, `RAZORPAY_*` | `cashfree` (default) or `razorpay`; `sandbox` or `live` |
| Chat assistant | `OPENAI_API_KEY`, `OPENAI_MODEL`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `CHATBOT_AI_PROVIDER` | optional; without a key the bot answers from site data |
| WhatsApp | `INTERAKT_API_KEY`, `INTERAKT_WEBHOOK_SECRET`, `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` | optional |
| Spam protection | `RECAPTCHA_SECRET` | site key is set in admin settings |
| Search Console | `GSC_SERVICE_ACCOUNT_FILE` | or upload the key file in admin → Indexing |
| Cloud storage | `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_KEY`, `S3_SECRET`, `S3_PATH_STYLE` | optional private file storage |
| Uploads | `UPLOAD_MAX_MB` | |
| Emergency | `ADMIN_ACCESS_POLICY_OFF=1` | only if an IP allowlist / access hours lock every admin out |

Website content (contact details, company & GST details, invoice numbering, email alerts, features,
maintenance mode, ISO certificate …) is edited in **Admin → System Settings**.

---

## Development workflow

- **Production-like:** run Apache + MySQL, edit code, `npm run build`, reload `http://localhost/laxyam/`.
- **Hot reload:** `npm run dev` starts Vite on `http://localhost:5173` and proxies `/api`, `/uploads`
  and `/assets` to Apache (`VITE_DEV_PHP_ORIGIN` + `VITE_APP_BASE`), so the PHP session cookie stays
  same-origin.
- The React app talks only to `api/*.php` through `js/api.js` (`api.get`, `api.post`, `api.upload`);
  unsafe requests carry the CSRF token automatically.
- Routes are declared in `js/App.jsx`; each page lives in `pages/` and is lazy-loaded.

---

## Building and deploying

The live site is plain files on Apache — there is no build step on the server.

1. **Build for the domain root** (base `/`) into a separate folder so the local XAMPP build (base
   `/laxyam/`) stays intact. Run it from **PowerShell** (Git Bash rewrites `/` into a Windows path):
   ```powershell
   $env:VITE_APP_BASE = '/'; npx vite build --outDir ..\deploy\dist --emptyOutDir; Remove-Item Env:VITE_APP_BASE
   ```
2. **Package** what the server needs: `admin/`, `api/`, `assets/`, `includes/`, `dist/` (the root-base
   build), `database/` (`cron.php`, `create-admin.php`, `migration-*.sql`), `index.php`, `.htaccess`,
   `sw.js`, `logo.png`. Never ship `.env`, `uploads/`, `node_modules/` or the React sources
   (`js/`, `pages/`, `css/`). Small updates can ship only the changed files plus `dist/`.
3. **Upload** the zip to `public_html/` (hPanel → File Manager), **Extract** with folder name `.` and
   **Overwrite existing files** ticked, then delete the zip (move it to Trash).
4. On a first install create `public_html/.env` on the server (production values, `VITE_APP_BASE=/`,
   `APP_ENV=production`) — it is never part of a package.
5. If the update contains new `database/migration-NNN.sql` files: sign in to the admin panel and press
   **Apply** in **System Settings → Database Updates** (the dashboard shows "Update needed" until then).
6. If files under `/assets` changed, bump `VERSION` in `sw.js` so returning visitors get them
   (built files in `dist/static` are content-hashed and need no bump).
7. Check the live site: home, courses, a course page, login, `/api/cms.php?action=layout`,
   `/sitemap.xml`, `/robots.txt` and the admin dashboard's Go-Live checklist.

---

## Scheduled jobs (cron)

`database/cron.php` runs reminders, the email queue and retries, WhatsApp retries and broadcasts,
payment status checks and other housekeeping. Run it every 15 minutes (CLI only — `/database` is
blocked from the web):

```
*/15 * * * * /usr/bin/php /home/<user>/domains/<domain>/public_html/database/cron.php >> /home/<user>/domains/<domain>/public_html/uploads/private/logs/cron.log 2>&1
```

On Hostinger use hPanel → Advanced → Cron Jobs → Custom; the PHP binary path is shown there
(e.g. `/opt/alt/php83/usr/bin/php`). Admin → Go-Live Checklist → "Scheduled jobs" shows this file's exact
path until cron has run. Without cron the same jobs also run from website traffic, but less punctually.

---

## Database and migrations

- `database/schema.sql` is schema version 1; `seed.sql` adds categories, settings and demo content
  (`is_demo = 1` rows must be replaced or deleted before launch).
- Every later change is a numbered file `database/migration-NNN.sql`, recorded in
  `schema_migrations`. Current schema: **v19**.

| # | Change |
|---|---|
| 002 | Jobs & careers, newsletter, extra policies, FAQs, social settings |
| 003 | Student panel: wishlist, study and notification preferences |
| 004 | CMS revision history |
| 005 | Managed lists, question topics, student documents, notification templates, page builder, popups, analytics, broken links, API keys, 2FA |
| 006 | Faculty recruitment |
| 007 | Website chatbot (admission assistant) |
| 008 | Redesign: invoices, email delivery log, video sections, highlights, promo banners, enquiry popup |
| 009 | Keys & credentials managed from the admin panel |
| 010 | Course batches by academic session |
| 011 | Bilingual questions (English + Hindi) and test sections |
| 012 | Sign in with Google and mobile OTP login |
| 013 | Student doubts |
| 014 | Referral programme (Refer & Earn) |
| 015 | Daily quiz and streaks |
| 016 | Email queue: stop re-sending emails that were probably delivered |
| 017 | Offline classroom courses, offers, fee items and subject-wise syllabus |
| 018 | Email OTP login |
| 019 | Enroll Now on every course (admission mode "both": pay online + Apply Now form) |

**Writing a migration**
- Plain SQL that runs on both MySQL 8 and MariaDB; no `ADD COLUMN IF NOT EXISTS`. The runner skips
  "already exists" errors when a file is re-applied.
- Never compare a prepared-statement parameter with a text literal (`? = 'x'`) — MySQL 8 can raise
  "Illegal mix of collations". Compare columns with `?`, or inline a whitelisted value with
  `db()->quote()`.
- Name the file with the next number and add a header comment describing it.

---

## Admin panel

Staff sign in at `/admin/login.php` (separate from the student login, with throttling, optional
two-step verification and an optional IP / hours access policy). Staff who try the website login
(`/login`) are pointed to the admin sign-in, and the website's "Forgot password" emails them the admin
reset link.

**Managing a course:** Courses → All Courses → open a course (title or Edit). Every screen of that
course shares one header and tab bar (`includes/course-workspace.php`):

| Tab | Screen | What you do there |
|---|---|---|
| 1 Details & page | `courses.php?action=edit&id=…` | Title, category, price and offer, how students join (Enroll Now / Apply Now), course page text, highlights, fees, syllabus, FAQs, picture, SEO, publishing - one form in numbered parts with a "Jump to" strip |
| 2 Curriculum | `lessons.php?course=…` | Outline of sections with their lessons; "Add lesson" per section (video, YouTube link, PDF, text, practice test, live class) |
| 3 Study material | `resources.php?scope=course&course=…` | Notes and files for the course's students (shown in My Resources) |
| 4 Students | `enrolments.php?course=…` | Who is enrolled, progress, expiry |
| 5 Preview | `course-preview.php?id=…` | The real course page on desktop / mobile with a ready-to-publish checklist |

Online enrolment (Enroll Now) needs the payment gateway keys in **SEO & Integrations → Keys &
Credentials**; until then the checkout says online payment is unavailable and offers the phone number
and the Apply Now form.

**Built-in roles:** Super Admin, Academic Manager, Content Manager, Finance Manager, Support Manager.
Permissions are managed in **Security → Roles & Permissions**; staff accounts in
**Security → Staff accounts**.

**Recovering access**
- **Forgot password?** on the admin login emails a single-use reset link (30 minutes).
- From the server terminal: `php database/create-admin.php "Name" email@example.com` creates or
  promotes a Super Admin.
- `ADMIN_ACCESS_POLICY_OFF=1` in `.env` temporarily disables a lock-out access policy.

---

## Integrations

| Integration | Where | Purpose |
|---|---|---|
| Cashfree / Razorpay | `.env` or Keys & Credentials; webhook `https://<domain>/api/webhooks.php?provider=cashfree` | Online payments, automatic enrolment, GST invoices |
| SMTP | Keys & Credentials → Email | All transactional email (queued, logged, retried) |
| Interakt / Meta WhatsApp | Keys & Credentials, admin → WhatsApp; webhook `/api/whatsapp-webhook.php` | Notifications, OTP, broadcasts (templates must be approved first) |
| MSG91 | Keys & Credentials | SMS OTP (DLT template) |
| Google Sign-In | `GOOGLE_CLIENT_ID` in Keys & Credentials | Student login |
| OpenAI / Anthropic | `.env` or Keys & Credentials | AI answers in the chat assistant |
| reCAPTCHA v3 | site key in settings, `RECAPTCHA_SECRET` | Spam protection on forms |
| Google Search Console | service-account key | Indexing status |
| S3-compatible storage | `S3_*` | Private file storage |

---

## Public API (v1)

`api/v1.php` is a server-to-server API for CRMs, Zapier and reporting. Create keys with scopes in
**Admin → Integrations → API Keys**; every call is logged.

```
Authorization: Bearer lxa_…        (or X-API-Key: lxa_…)

GET  /api/v1.php?resource=courses                     courses.read     published catalogue
GET  /api/v1.php?resource=enquiries&since=&page=      enquiries.read   leads, newest first
POST /api/v1.php?resource=enquiries                   enquiries.write  JSON name, email, phone, message, source_note
GET  /api/v1.php?resource=orders&since=&page=         orders.read      paid orders
GET  /api/v1.php?resource=students&since=&page=       students.read    registrations (no passwords)
```

Responses are JSON `{ ok, data, meta }`; no cookies or sessions are used.

---

## Security

- `.htaccess` blocks dotfiles (`.env`), `database/`, `includes/`, `docs/`, sources, `api/config.php`,
  `api/db.php`, `uploads/private/`, and `.sql`, `.log`, `.md`, `.zip` … files.
- PHP sessions are HttpOnly; identity is never stored in `localStorage`. Unsafe requests need a CSRF
  token; logins, OTPs, resets and forms are rate limited; login responses never reveal whether an
  email exists.
- Passwords use `password_hash()`; OTP codes and reset tokens are stored hashed and are single-use.
- Secrets live only on the server (`.env` or encrypted Keys & Credentials) — never in a `VITE_*`
  variable, the repository or the frontend bundle.
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`) are set in `.htaccess`; admin pages also deny framing.
- Every admin change is written to the audit log.

Found a security issue? Please email **info@laxyamacademy.com** instead of opening a public issue.

---

## ISO certificate settings

The ISO 9001:2015 certificate (number E20261156292, Audittech Certification Pvt. Ltd., EGAC accredited,
IAF MLA member; issued 2 Nov 2026, valid until 1 Nov 2029) is shown on the About page
(`/about#iso-certification`) and as badges in the header top bar, the homepage banner and the footer.

Edit it in **Admin → System Settings → Company & Invoicing → ISO certification**: standard, system,
certificate number, issue and expiry dates, surveillance audits, certification body, accreditation,
scope, certified locations and the verification link. The block hides itself when switched off or after
the expiry date. The certificate photo (`assets/images/iso-9001-certificate*.webp`) only shows while the
certificate number is the one in the photo — replace the images when the certificate is renewed.

---

## Coding conventions

- Match the surrounding code: short comments that explain *why*, no framework magic.
- **PHP:** `declare(strict_types=1)`, prepared statements through the `db_*` helpers, output escaped
  with `e()`, PHP 8.3 compatible.
- **React:** function components and hooks; hooks before any early `return`; a `useEffect` must return
  nothing or a cleanup function — write `useEffect(() => { window.scrollTo(0, 0); }, [...])` with
  braces, because newer browsers return a Promise from `scrollTo`/`scrollIntoView` and React would
  call it as a cleanup (this once blanked every page after the first click).
- UI: compact cards (icon beside text, small gaps, 2-line clamps); admin sub-pages as on-screen tabs.
- Keep `VITE_APP_BASE` working for both `/` and `/laxyam/` — build URLs with `APP_BASE` / `app_url()`.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Every page shows *"Build missing: run npm run build"* (503) | `dist/index.html` is missing — build and upload `dist/` |
| Blank page or 404 for assets after deploy | The bundle was built with the wrong base: production needs `VITE_APP_BASE=/` (build from PowerShell) |
| API returns 500 "Something went wrong" | Check the PHP error log; locally make sure MySQL is running and `.env` DB values are right |
| A query works locally but fails live | Live is MySQL 8: avoid `? = 'literal'` comparisons (collation mix) |
| `Call to undefined function array_find()` | PHP 8.4+ function — live runs PHP 8.3 |
| Staff cannot log in on `/login` | Staff use `/admin/login.php`; the website login points them there |
| Admin dashboard says "Update needed" | System Settings → Database Updates → Apply |
| Emails not arriving | Admin → Emails → Delivery Logs; check SMTP in Keys & Credentials and the cron job |
| Locked out of the admin panel | Forgot password, `php database/create-admin.php`, or `ADMIN_ACCESS_POLICY_OFF=1` |
| Old assets after an update | Bump `VERSION` in `sw.js` when `/assets` files change |

---

## Ownership

© LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED. All rights reserved.
This repository is published for the academy's own development and hosting. No licence is granted to
copy, modify or redistribute the code or content without written permission.
