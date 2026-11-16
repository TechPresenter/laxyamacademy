# SEO - how it works and what is left for the owner

Everything below is live since 16 Nov 2026 (schema v21). Nothing on the site is invented: titles,
descriptions, FAQs and structured data come from the real courses, categories, CMS pages and contact
settings, and change automatically when those change.

## One source of rules: `includes/seo.php`

`seo_resolve($path, $query)` decides, for every public URL:

| What | Where it comes from |
| --- | --- |
| `<title>`, meta description | Course / category / blog edit form → Admin → SEO → Page SEO override → built-in default |
| Canonical | The URL itself (https, no www, no trailing slash, lower case); `?page=N` kept; internal search has none |
| Robots | `index,follow`; `noindex` for account/checkout/exam pages, demo content, internal search, empty listings (blog, jobs, mock tests, live classes, gallery while nothing is published) |
| Open Graph / X card | Same title and description; course / post image, else `assets/images/og-default.jpg` (1200×630) |
| JSON-LD | Home: EducationalOrganization + LocalBusiness, WebSite. Courses: Course (offer, onsite instance, syllabus subjects) + BreadcrumbList. Category pages: CollectionPage (+ ItemList when ≥ 3 courses) + BreadcrumbList. Blog: BlogPosting. Careers: JobPosting. No FAQPage (Google limits FAQ results to government/health sites), no ratings unless approved reviews exist |
| `<noscript>` text version | The same facts as the React page, for crawlers and AI search bots that do not run JavaScript |

Users of it: `index.php` (prints the tags), `api/cms.php?action=seo` (the React app's `useRouteSeo()`
applies the same values after client-side navigation), the sitemaps, and the admin SEO Health dashboard.

## URLs

- Category landing pages: `/categories/<slug>` (intro from Admin → Courses → Categories, FAQs generated from
  the category's courses). The old `/courses?category=<slug>` answers 301 to it.
- 301 redirects: `www.` → bare domain, trailing / double slashes, upper case, `/index.php`.
- Unknown URLs answer a real 404 and are listed in Admin → SEO → 404 Monitor (90 days).

## Sitemaps and robots

- `/sitemap.xml` = sitemap index of `sitemap-pages.xml`, `sitemap-categories.xml`, `sitemap-courses.xml`
  (with images), `sitemap-blog.xml`, `sitemap-resources.xml`. Only URLs that answer 200, are indexable and
  are their own canonical are listed; `lastmod` is the database update time.
- `/robots.txt` blocks admin, account, checkout and exam-room URLs. `/api/` must stay crawlable (the pages
  load their content from it; API responses send `X-Robots-Tag: noindex`).

## Admin → SEO

SEO Health (every URL with its title, description, schema, issues and a checklist score + CSV export of the
URL sheet), SEO Audit, Global SEO, Page SEO (any URL: title, description, canonical, robots, sharing image,
focus / secondary keywords, extra JSON-LD), Course SEO, Category SEO, Blog SEO, Redirects, 404 Monitor,
Sitemap & Robots. Extra robots.txt lines: Settings → SEO & Robots. The score is a checklist of on-page
basics, not a Google ranking.

## Analytics events (GA4 / GTM)

`js/track.js` sends, only when a GA4 ID or GTM container is set in Admin → Integrations:
`generate_lead` (enquiry, Apply Now, callback, chatbot), `sign_up`, `begin_checkout`, `purchase` (paid
orders only), `phone_click`, `whatsapp_click`, `email_click`, `enroll_click`, `apply_click`.
No names, phone numbers or emails are sent.

## Performance

reCAPTCHA (~300 KB) now loads only when a visitor starts filling in a form; fonts and logo are preloaded;
hashed JS/CSS are cached for a year, images for 30 days; Brotli and HTTP/3 come from the Hostinger CDN.

## Left for the owner (needs your accounts or facts only you can confirm)

1. Google Search Console: submit `https://laxyamacademy.com/sitemap.xml` again (it is now a sitemap index)
   and use URL Inspection → Request indexing for `/` and the seven `/categories/…` pages.
2. GA4: create a property, paste the `G-…` ID in Admin → Integrations → Analytics, then mark
   `generate_lead`, `purchase`, `phone_click` and `whatsapp_click` as key events.
3. Google Business Profile: claim / verify the Badarpur centre with exactly the same name, address and
   phone as the website, and add the website link. Bing Webmaster Tools: import from Search Console.
4. Settings → Academy Information: add the PIN code to the address and, if you want opening hours in
   search, enter them in the form `Mo-Sa 09:00-19:00`.
5. Blog: no posts are published, so `/blog` is noindex. Write real articles (NTPC / Group D preparation,
   SSC CGL syllabus, bank exam strategy…) with the author's name; each post gets Article markup automatically.
6. Core Web Vitals: check PageSpeed Insights and the Search Console "Core Web Vitals" report after ~28 days
   of real-user data.
