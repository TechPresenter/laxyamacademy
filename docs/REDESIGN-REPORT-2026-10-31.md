# Laxyam Academy – redesign, features & QA report (31 Oct 2026)

**Company:** LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED · CIN U85491DL2026PTC470370
**Scope:** full UI/UX redesign, responsive fixes, new features, admin panel redesign, bug fixing, testing.
**Status:** **LIVE on https://laxyamacademy.com** since 31 Oct 2026 evening (package `laxyam-update-2026-10-31-redesign.zip` extracted into `public_html`; database updated to v8 by importing migrations 006, 007, 008 in phpMyAdmin). Live data was kept; a manual hPanel backup was taken first. All public pages and data feeds checked: 200 OK.

---

## 1. How the work was done
1. **Audit first** – 8 read-only auditors mapped every subsystem: 117 bugs + 161 feature gaps, documented in `docs/AUDIT-2026-10-31.md`.
2. **Build in parallel** – each builder owned a separate set of files (no overwrites); shared contracts for APIs and components.
3. **Independent verification** – WhatsApp, Gallery and Cashfree were each checked by a second agent that tried to break them, then fixed.
4. **Final QA** – 6 independent testers (public pages, student journeys, checkout & invoices, admin, security, deploy readiness) using real screenshots at phone/tablet/desktop widths and HTTP tests; their findings were fixed and re-tested.
5. **Database** – every schema change is in **one file: `database/migration-008.sql`** (10 sections), tested on a copy of the pre-update database: 75 steps, 0 errors, safe to run twice, final structure identical to the development database. It only adds tables/columns/settings; no existing data is deleted.

## 2. Completed – website (public)
- **Design system:** self-hosted fonts (Plus Jakarta Sans headings, Inter body, Caveat accents) for site and admin; brand colours, gradients, overlays, animated buttons, scroll-reveal animations, official brand icons (WhatsApp, Instagram, YouTube, Facebook, X, LinkedIn, Threads, Telegram).
- **Header:** organised menu, compact Courses mega menu (category list + top courses), highlighted **Jobs** link (no dropdown – owner choice), Highlights menu + ticker, mini search overlay (Ctrl+K, grouped results: courses, tests, resources, jobs, blog, voice input), call/WhatsApp in the top bar, real social links.
- **Mobile:** icon-based slide-in menu (no bottom tab bar – owner choice), floating call/WhatsApp/back-to-top, no horizontal scrolling at 360px.
- **Footer:** newsletter CTA (with re-subscribe consent), CIN under the logo, Free Resources highlighted (gold), Gallery link, small brand social icons, minimal copyright bar.
- **Home:** hero with the counsellor photo (no search/chips/counts – owner choice), category grid, course grid with tabs, promo banners, YouTube & Instagram sections (admin-managed), job alerts, newsletter band, callback form.
- **Courses:** catalogue with search, filters (category, type, level, language, price, rating), sort, pagination, mobile filter drawer; course page with highlights, curriculum with **Preview / lock** icons, sticky enrol card/bar, reviews (students can write), FAQs, share (WhatsApp, Facebook, LinkedIn, X, Telegram, email, copy).
- **Course material access:** free-preview lessons open for guests; locked lessons are refused **by the server** (guest 401 / not enrolled 403); resources locked by access type; staff "preview as student".
- **Enrolment:** "Congratulations, you're enrolled" page (reference ENR-…, next steps, invoice, share).
- **Quizzes & mock tests:** official-style exam room (5-state palette, accurate timer, mark for review, autosave, submit summary, auto-submit on time-out), practice quizzes with instant right/wrong + explanation, random question pools, shuffled options, negative marking, results with **green = correct, red = incorrect, yellow = unanswered**, answer key per admin rule, rank/percentile, topic analysis, history.
- **Jobs:** colour-coded alert types, NEW badges, closing-soon, job detail pages (dates, fee, age, selection, links).
- **Gallery:** `/gallery` albums + photo viewer (swipe, keyboard, captions, YouTube).
- **Enquiry popups:** 3 popups with the counsellor photo (home/general, course pages, tests/jobs), consent checkbox, never re-shown after close/submit.
- **Student area:** redesigned dashboard, profile & notification preferences (incl. WhatsApp opt-in), notifications with filters, purchases with PDF invoices, certificates with public verification, support tickets, wishlist.

## 3. Completed – payments, invoices, emails, WhatsApp
- **Cashfree** payment gateway (owner choice): server-created orders, Cashfree hosted checkout, **server-side verification** (Get Order + Get Payments – amount and currency must match), signed webhooks (signature + timestamp checked, de-duplicated), automatic recovery of missed payments by the scheduler, invoice shows the Cashfree payment id. Razorpay code kept for compatibility.
- **PDF invoices:** consecutive numbering per financial year (LXA/26-27/0001), legal name + CIN, GSTIN/PAN when set, billing details captured at checkout, GST (CGST/SGST or IGST) when enabled, amount in words, signed download link, sent to the billing email too; admin Invoices screen with PDF, resend and CSV.
- **Emails:** every email logged (Admin → Emails → Delivery Logs) with automatic retries; branded template with company name; welcome, enrolment, payment success (with invoice), payment failed (delayed, re-checked with the gateway), test result, course completed, certificate, password changed, newsletter welcome/unsubscribe, enquiry auto-reply and staff alerts (fall back to the contact email).
- **WhatsApp (Interakt):** 25 event templates managed in admin (preview, parameter mapping, test send, on/off), opt-in on registration/profile/careers/enquiries, delivery logs with sent/delivered/read/failed, retries, broadcasts to opted-in students, STOP = opt-out, admin alerts. Templates are OFF until each is approved in Interakt.

## 4. Completed – admin panel
- Topbar exactly like the reference: search (Ctrl+K), **Academic Session** selector (changes dashboard/report ranges), quick-create, **dark mode**, notifications, enquiries, apps launcher, profile menu.
- Compact sidebar (one short link per module, 17 modules); every sub-page is a **tab on the screen** and tab rows wrap (nothing hidden). Help & Documentation and Quick links removed.
- Redesigned login with logo fix and **staff Forgot password / Reset password**.
- New/rebuilt screens: YouTube & Instagram media, highlights, promo banners, newsletter subscribers, email logs, invoices, attempts & answer sheets, gallery, WhatsApp, Reports (growth, course performance, enrolments, revenue, payments, tests, progress, attendance, downloads, traffic, conversions, enquiries, staff activity, export), Integrations, Settings (15 tabs incl. Company & Invoicing), Security, staff & roles.
- All admin pages re-checked: no PHP warnings, no console errors, phone layouts as cards, role-based access (e.g. faculty sees only academic modules).

## 5. Remaining / needs you
- **Policy pages still say "DRAFT – review with a legal adviser"** (Privacy, Terms, Refund, Cancellation, Disclaimer, Cookie Policy). This is placeholder content – replace it with your final legal text in Admin → Website → Pages.
- **Not tested with real services** (no keys locally): live Cashfree payments, real SMTP delivery, real Interakt sending. Everything was tested against realistic simulators.
- Partial refunds keep the payment row as "captured" (the order correctly shows "partially refunded").
- Old migrations 003/005 use MariaDB-only syntax; harmless for laxyamacademy.com (already past them), relevant only for a brand-new MySQL install.
- Shipping & Delivery policy was removed at your request – Razorpay/Cashfree KYC sometimes asks for one; the text is kept in the database if needed.
- Upload the headset counsellor photo for the callback section (Admin → Website → Homepage sections → CTA → Image), or send it as a file.

## 6. Remaining go-live steps (Hostinger)
Steps 1-3 (backup, code upload, database update) are **done**. Still to do:
1. **Admin access:** the live admin password is not known. In hPanel → Advanced → SSH Access → Terminal run `cd ~/domains/laxyamacademy.com/public_html && php database/create-admin.php "Your Name" your@email` (or `cd ~/public_html`) and set a new password when asked. Then delete/rename the demo admin account (admin.demo@example.com) in Admin → Security → Staff accounts.
2. Delete `laxyam-update-2026-10-31-redesign.zip` from `public_html` (it is blocked from the web, but keep the folder clean).
3. **Server `.env` additions** (you type these in hPanel File Manager; never in chat):
   - `PAYMENT_PROVIDER=cashfree`, `PAYMENT_MODE=sandbox` (first), `CASHFREE_APP_ID=…`, `CASHFREE_SECRET_KEY=…` (Cashfree Dashboard → Developers → API Keys). After one successful sandbox purchase switch to `PAYMENT_MODE=live` with the production keys.
   - `INTERAKT_API_KEY=…`, `INTERAKT_WEBHOOK_SECRET=…` (Interakt → Settings → Developer Settings).
   - SMTP: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` (Hostinger email account) so emails are delivered.
5. **Cashfree dashboard:** webhook `https://laxyamacademy.com/api/webhooks.php?provider=cashfree` (Success, Failed, User Dropped); keep convenience fees OFF; get the domain whitelisted.
6. **Interakt:** webhook `https://laxyamacademy.com/api/whatsapp-webhook.php` with the same secret; create + get approved each template, type its name in Admin → Emails → WhatsApp, test, switch on; add support + admin alert numbers.
7. **Cron (hPanel → Advanced → Cron Jobs), every 15 minutes:** `/usr/bin/php /home/u623595918/public_html/database/cron.php` (path may differ – the Go-Live Checklist shows it).
8. Admin → Settings → Company & Invoicing: check legal name, CIN, address; add GSTIN/PAN when available and switch GST on if registered.
9. Replace the policy DRAFT texts; review the Go-Live Checklist in admin until everything is green.

## 6a. Keys & Credentials from the admin panel (added 31 Oct, late)
- New screen **Admin → SEO & Integrations → Keys & Credentials** (`admin/keys.php`): OpenAI / Claude chatbot, Cashfree / Razorpay, SMTP, Interakt / Meta WhatsApp, reCAPTCHA secret, S3. No `.env` editing needed for these any more.
- Values are AES-256-GCM encrypted in the new `app_config` table (`database/migration-009.sql`); the encryption key is `uploads/private/.config-key` (created on first save) or `CONFIG_KEY` in `.env`. A saved value overrides the same name in `.env`; removing it falls back to `.env`.
- Secrets are never shown in full or sent to the browser; only super admins can change them; every change is in the Audit Trail (key names only).
- Chatbot now supports **OpenAI** (`gpt-6-luna` by default - `gpt-5-mini` is retired by OpenAI on 11 Dec 2026; Chat Completions with a strict JSON schema, low reasoning effort) as well as Claude; OpenAI is used automatically when its key is set.
- Reviewed by 4 independent reviewers + 4 verifiers before deploy; all 14 confirmed findings fixed (key-name-bound encryption, no secret fragments for passwords, SMTP password must be re-entered when the server changes, unreadable values fail closed, billing errors reported correctly, current OpenAI models). Tests: 41 code checks + 18 admin-page checks.
- **LIVE since 31 Oct 2026 ~20:15** (`laxyam-update-2026-10-31-keys2.zip`, 17 files, extracted over public_html; all 3 update zips moved to the File Browser Trash bin). Opening Keys & Credentials as a super admin applies database update 009 automatically. 14 live checks passed (pages/APIs 200, private paths 403).
- Still to do by the owner: paste the OpenAI key (and Cashfree / SMTP / Interakt keys) in Keys & Credentials. Note: the live `.env` has `PAYMENT_PROVIDER=razorpay` - choose **Cashfree** under Payment gateway when adding the Cashfree keys.

## 7. Upload package contents
`admin/`, `api/`, `assets/` (incl. `assets/fonts/`, `assets/images/hero-counsellor.webp`), `includes/`, `dist/` (production build with base `/`), `database/cron.php`, `database/create-admin.php`, `database/migration-*.sql`, `index.php`, `.htaccess`, `sw.js`, `logo.png`.
Never upload: `.env`, `uploads/`, `docs/`, `node_modules/`, `js/`, `pages/`, `css/`, `.backup-*.zip`.

## 8. Local-only items
- Test logins: `docs/LOCAL-TEST-ACCOUNTS.md` (local database only).
- Source backup taken before the work: `.backup-before-redesign-2026-10-31.zip` (project root).
