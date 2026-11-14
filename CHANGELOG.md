# Changelog

All notable changes to **LeadSift – Smart Inquiry Filter** are documented here.
Versions follow [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`.

- **PATCH** (1.0.x) – bug fixes, rule tweaks.
- **MINOR** (1.x.0) – new features that don't break anything.
- **MAJOR** (x.0.0) – changes that need action from the site owner.

Bump with `npm run version:bump -- patch|minor|major`.

## [1.8.0] - 2026-11-14

### Added
- **AI usage tab on the dashboard.** Every call to the AI is logged with its model, input/output tokens (incl. cached and "thinking" tokens), answer time, the AI's answer and the estimated cost. Shows Today / Last 7 days / This month / All time, the average per check ("about N checks for $1"), how many inquiries went to the AI, how often it agreed with the rules, a 30-day chart, the latest 30 calls, and a red warning when the last call failed (e.g. no credit left).
  - Token numbers come straight from the AI's answer (`usage`), so they match the provider's bill. Cost = tokens × price: OpenAI prices are built in (gpt-5.x, gpt-5, gpt-5-mini, gpt-5-nano, gpt-4.1, gpt-4o, o3…); for other AIs enter the price per 1M tokens in Rules & AI.
  - A plain-language "How the AI is used" section (what is sent, what comes back, what tokens are).
  - New database table `wp_leadsift_ai_log` (DB 1.3.0, created automatically; entries older than 400 days are removed).
- **How it works tab** on the dashboard: the whole system explained for owners and bosses.
- **Forms and Settings are on the dashboard page** (Fields editor, filter strength, form plugins, emails, phone check, earlier entries, data).
- Login access: **Manage** (see everything, change form setup & settings on the dashboard) or **View only** (see everything, change nothing, no AI calls). Logins from earlier versions become Manage.

### Changed
- **The dashboard page always needs a Login ID & password** – also for WordPress admins.
- wp-admin → LeadSift now has only **Dashboard page & login** and **Rules & AI** (rules, quick keywords, sender lists, Read my business, AI key, model, price).
- OpenAI reasoning models (gpt-5…, o3…) are asked for low reasoning effort, which keeps output tokens (and cost) down.
- The dashboard remembers the open tab (link with #usage, #settings…).

## [1.7.0] - 2026-11-14

### Changed
- **wp-admin is now setup only.** LeadSift in wp-admin has four screens: **Dashboard page & login** (the page name and link, and the Login ID & password for it), **Rules & AI** (your rules, Read my business, any AI API), **Forms** (which field is the name, email, phone, message; required-field checks) and **Settings** (earlier entries, phone check, strictness, notifications). Plus an "Open dashboard ↗" link.
- **The dashboard is shown only on the Client Portal page** – Inquiries, Forms and Insights with live counts. Rules, AI and settings are never shown there.
- Logins: type your own Login ID and password (or leave the password empty to generate one), copy the login details in one click, **Change password** inline, Delete. Every portal login is view-only (see and sort inquiries).
- Saving rules or settings re-checks all saved inquiries automatically.
- Clicking a form on the dashboard's Forms tab opens its inquiries.

### Fixed
- A view-only login could be signed out after pressing a button it was not allowed to use.

## [1.6.0] - 2026-11-14

### Added
- **Client Portal.** On install LeadSift creates a public page "Client Portal" (/client-portal/) that shows the dashboard full-screen with its own blank template – no theme header, footer, menus or styles – built in React and responsive down to phone size.
  - Separate sign-in, not WordPress accounts: create logins in LeadSift → Settings → Client Portal (name, username, password or a generated one, shown once with "Copy login details"). Passwords are hashed; sign-in returns a 30-day token; 8 wrong attempts lock the IP for 15 minutes; "New password" signs the client out everywhere.
  - Access per login: "See & sort inquiries" (Inquiries, Forms, Insights) or "Everything" (also Rules & AI and Settings – never portal logins).
  - WordPress admins who open the portal are let straight in. The page is noindex and kept out of the WordPress sitemap.
  - The page name and link are shown after install (admin notice), in Settings and on the Plugins screen.
- **Earlier entries are imported and filtered.** On install (and on update), LeadSift reads entries stored before it was installed – WPForms (Pro entries), Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Elementor Pro submissions, Contact Form 7 via Flamingo, and the "Contact Form Entries" plugin (which also stores WPForms Lite / CF7 submissions) – and sorts them with the same engine. Runs in the background (WP-Cron) and faster while the dashboard is open, with a progress bar. No emails or AI calls; nothing is imported twice; entries LeadSift already caught live are skipped.
- **Simple rules.** Type a rule the way you would say it: `seo, backlinks → promotional`, `@mailinator.com → spam`, `form: careers → genuine`, `lean: discount → promotional`, `not: price → promotional`. Rules are shown as plain sentences; editing uses two dropdowns ("If the inquiry … then put it in …") with advanced options hidden.

### Changed
- "How LeadSift decides" step numbers are white.
- Database 1.2.0: `external_ref` column for imported entries (added automatically).
- The old private "Inquiry Dashboard" page becomes the Client Portal.
- Client Portal logins with view access do not see actions they cannot use (Re-scan, form settings).

## [1.5.2] - 2026-11-13

Found while testing live on a salon demo site.

### Fixed
- Your own "points toward Genuine" rules could pull a clear sales pitch back to Genuine (a salon pitch about "more bookings and appointments" scored only 41). When a message is clearly a pitch, scam or junk, those rules now count for at most 10 points. "Always Genuine" rules are unchanged.

## [1.5.1] - 2026-11-13

Found while testing on a live WordPress site (WPForms).

### Fixed
- Form **labels** (e.g. "What can we help with?") and dropdown choices were read as if the visitor wrote them, adding false "writes as a seller" points. Pitch / customer detection now only reads what the visitor typed (message and subject).
- India phone check: numbers must have exactly 10 digits (after +91 / 0). 9-digit numbers like 900234915 were accepted. Numbers written with "+" and another country code are not checked.
- One- or two-word messages of random letters ("sdfsd", "fd") are now junk.
- "Reply now", "act now" and "within 7 days" count as pitch signals.
- The AI still receives field labels for context.

## [1.5.0] - 2026-11-13

### Changed – much stricter filtering
- **New promotional detection ("who is talking to whom").** LeadSift now reads whether the sender writes as a *seller* ("we offer…", "our services", "I can fix your website", "we're a studio that helps…", Hinglish "hum agency hai…") aiming at *your* website/business with a sales hook ("book a call", "can I send…", "reply YES", "save our number"), or as a *buyer* describing their own need. Advertising-style commands ("Get 100 reviews…", "Try it free") and result promises ("cut costs by 40%") are caught too.
- When a message clearly reads as a pitch, scam or junk, customer-like words inside it ("I am interested…", "price") can no longer cancel it out.
- **Strict junk checks:** fake phone numbers (1234567890, 9999999999, 9898989898, too short, invalid Indian mobile), placeholder messages ("hi", "test", "ok ok", "nice"), keyboard mashing ("asdfgh", "jdjdjd"), repeated words, symbols-only / numbers-only messages, test emails (test@test.com), placeholder or random names.
- New spam rules: delivery/payment phishing, romance scams, advance-fee (419) scams, blackmail.

### Added
- **Form checks built from each form:** required fields left empty (form bypassed by a bot), dropdown/radio answers that are not real options (skipped when the field has an "Other" choice), number fields without numbers, the same text typed into every field, and an optional minimum number of words per form.
- Forms → Fields panel: Required column, option counts, strict-check toggles and a plain-language list of the rules applied to each form.
- Setting: phone number check (India strict / any country), auto-detected from the site timezone/language.
- Test sets in `tests/`: 166 labelled messages (`cases.json`, `holdout.json`, `blind.json`), `run.php` (real PHP) and `run-js.mjs` (Node, reads the rules straight from the PHP file), plus `form-checks.mjs`.

### Fixed
- "Your package delivery failed" was read as a price question ("your package").
- The word "cctv" was flagged as keyboard mashing.

## [1.4.1] - 2026-11-13

### Added
- **Live dashboard.** The dashboard checks for changes every 10 seconds (and as soon as you come back to the tab) with a tiny `/pulse` request. New inquiries, AI verdicts and category changes update the cards, counts and list automatically – no page reload.
- "🔔 N new inquiries just arrived" banner and a short highlight on new rows.
- "Live · just now" indicator in the header (click to check immediately).

### Changed
- Background refreshes keep your ticked rows and do not show a loading spinner.

## [1.4.0] - 2026-11-13

### Added
- **Any AI provider.** New "Any other AI" option in Rules & AI → AI check: use your own key with OpenAI (ChatGPT), Google Gemini, Groq, Mistral AI, DeepSeek, OpenRouter, xAI (Grok), Together AI, Ollama (self-hosted) or any other OpenAI-compatible API.
  - Provider presets fill in the API address; "Load models" lists the models your key can use.
  - Works for the inquiry AI check, "Ask AI now", the tester and "Read my business".
  - JSON answers are requested in JSON mode, with an automatic retry without it for providers that do not support it.

### Changed
- AI labels in the dashboard show the provider actually used.

## [1.3.0] - 2026-11-13

### Added
- **Form reader.** LeadSift reads every form before inquiries arrive: all fields (label, input type, required), the role of each field (name, email, phone, subject, message, company, other info, ignore) and the form type (contact, quote, booking, job application, newsletter, support, other).
  - Supported: Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable. Elementor and custom forms: fields learned from received inquiries.
  - Forms tab: "Fields" panel per form to check/correct each field and the form type; warnings such as "no email or phone field".
  - Saved maps are used for every new submission of that form, so name / email / message are always taken from the right field and ignored fields (consent boxes, uploads) are not judged.
- Admin notice after activation: how many forms and fields were read.
- New rule field **Form type**; the AI also receives the form type.

### Changed
- Newsletter, job and booking forms are not penalised for an empty or short message.
- Dashboard can open a tab from the URL (`admin.php?page=leadsift&tab=forms`).

## [1.2.0] - 2026-11-13

### Added
- **Read my business** (Rules & AI tab): one click reads the website the plugin is installed on – pages, menus, product categories, products and forms – and suggests:
  - a short business summary (editable; it is given to the AI with every inquiry check);
  - rules made for this business (its products/services and customer words → genuine, industry-specific pitches → promotional, request forms → genuine, and protection for businesses that themselves sell SEO/marketing/web services).
  - With Claude AI the site is understood by the AI; without a key a basic scan is used. Suggestions are only added after you tick and save them; duplicates are skipped.

### Changed
- Claude API calls now share one helper (`LeadSift_AI::claude_json`).

## [1.1.0] - 2026-11-13

### Added
- **Rules & AI tab.**
  - **My rules:** build your own rules – *If [field] [contains / is / starts with / ends with / regex / is empty] [value] then [Always Genuine / Promotional / Spam] or [± points]*. Rules run top to bottom and can be reordered, switched off and tested before saving.
  - **AI check (optional):** second opinion from Claude AI (Opus 5.5 / Sonnet 5.5 / Haiku 5.5) or from your own API URL. Runs in the background after submission; used when ≥70% sure. Modes: only unsure inquiries, or every inquiry. "What does your business do?" context for better accuracy.
  - "How LeadSift decides" explainer and a tester that shows rules + AI verdict.
- **Forms tab:** every form on the site (Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable; Elementor and custom forms after their first inquiry) with total / genuine / promotional / spam counts and genuine rate. Click a form to see its inquiries.
- **Form filter** on the Inquiries tab (per form or per form plugin), also used by CSV export.
- **⚠ Needs a look** filter: inquiries close to the line or where the AI disagrees, so nothing slips through unchecked.
- "Ask AI now" button and AI verdict in the inquiry panel.
- `leadsift_extra_forms` filter for developers.

### Changed
- Database schema 1.1.0: new `ai_category`, `ai_confidence`, `ai_reason`, `ai_checked_at` columns and a form index (added automatically on update).
- Re-scan keeps earlier AI verdicts.
- Strong scam signals (phishing, lottery, adult) now mark spam on their own.
- Claude API key can also be set in wp-config.php: `define( 'LEADSIFT_CLAUDE_API_KEY', '...' );`

## [1.0.0] - 2026-11-13

### Added
- Automatic capture from Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms, Ninja Forms and Formidable Forms.
- Public REST endpoint `/wp-json/leadsift/v1/submit` and `leadsift_capture` action for custom forms.
- Filtering engine that sorts every inquiry into **Genuine**, **Promotional** or **Spam** with a 0–100 risk score and the reasons behind it.
  - 50 promotional rules (SEO / backlinks / agency pitches / cold-outreach openers / "free audit" offers…).
  - Spam rules (crypto, casino, adult, phishing, gibberish names, disposable emails, link dumps, copy-paste campaigns, IP flooding).
  - Genuine signals (price & quotation questions, booking, support, phone number, Hindi & Hinglish).
- Learns from your corrections per sender email; trusted and blocked sender lists; custom keywords.
- React dashboard in wp-admin (LeadSift menu) plus an auto-created private "Inquiry Dashboard" page (`[leadsift_dashboard]`).
- Insights: genuine rate, 30-day stacked chart, per-form breakdown, top filter reasons.
- CSV export, bulk actions, re-scan with new settings, built-in filter tester.
- Optional: email alert for genuine inquiries only, stop form emails for promotional/spam, auto-delete old spam.
- Version-aware upgrader (`leadsift_version`, `leadsift_db_version`).
