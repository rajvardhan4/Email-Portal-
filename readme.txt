=== LeadSift – Smart Inquiry Filter ===
Contributors: rajvardhansinghrathore
Tags: contact form, spam, leads, inquiries, filter
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically separates genuine customer inquiries from promotional pitches and spam, and shows all of them on a React dashboard.

== Description ==

Website forms get flooded with SEO agencies, "I was checking your website" pitches, crypto bots and random junk. Real customers get lost in between.

LeadSift reads every submission from your forms, decides whether it is **Genuine**, **Promotional** or **Spam**, explains why, and keeps everything in one dashboard. Nothing is thrown away – you can see promotional and spam inquiries too, and move any inquiry with one click.

**Works with:** Contact Form 7, WPForms, Gravity Forms, Elementor Pro Forms, Fluent Forms, Ninja Forms, Formidable Forms, and any custom form through the REST API.

**Features**

* Risk score (0–100) plus a plain-language list of reasons for every verdict.
* Understands Hindi and Hinglish customer messages ("mujhe price chahiye").
* Learns from your corrections – move a sender once and future inquiries follow.
* Trusted / blocked sender lists and your own keywords.
* The dashboard lives on an auto-created "Client Portal" page (no theme header/footer, responsive) behind its own Login ID & password. wp-admin is only for setup.
* Insights: genuine rate, daily chart, per-form breakdown, top filter reasons.
* CSV export, bulk actions, re-scan, built-in tester.
* Optional email alert for genuine inquiries only.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `leadsift-x.y.z.zip` → Install → Activate.
2. Open **LeadSift → Dashboard page & login**. Copy the page link and create a Login ID & password.
3. Open the link and sign in – that page is your dashboard.
4. Optional: add rules and an AI API key in **LeadSift → Rules & AI**. Strictness, forms and AI usage are on the dashboard page.

== Frequently Asked Questions ==

= Does it block submissions? =
No. Every submission is saved and shown. LeadSift only labels them. You can optionally stop the form's notification email for promotional and spam inquiries.

= Will old submissions be imported? =
Yes. Entries stored by WPForms Pro, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Elementor Pro, Flamingo (Contact Form 7) and the Contact Form Entries plugin are imported and filtered in the background after install. Nothing is imported twice and no emails are sent.

== Changelog ==

= 1.8.0 =
* New AI usage tab: tokens, cost and every AI call; how AI billing works.
* New How it works tab. Forms and Settings moved to the dashboard page.
* The dashboard page always needs a Login ID & password. Logins: Manage or View only.
* wp-admin keeps only Dashboard page & login and Rules & AI.

= 1.7.0 =
* wp-admin is setup only: Dashboard page & login, Rules & AI, Forms, Settings.
* The dashboard is shown only on the Client Portal page.
* Type your own Login ID & password, change passwords, copy login details.

= 1.6.0 =
* New Client Portal page with its own logins (no theme header/footer, responsive).
* Earlier form entries are imported and filtered automatically.
* Simple rules: type "seo, backlinks → promotional".

= 1.5.2 =
* Your own "toward Genuine" rules can no longer cancel a clear sales pitch.

= 1.5.1 =
* Fixes found on a live site: form labels no longer count as visitor text, strict 10-digit Indian phone check, random-letter messages are junk.

= 1.5.0 =
* Much stricter filtering: new seller-vs-buyer analysis catches promotional pitches that used to slip through as Genuine.
* Strict junk checks: fake phone numbers, "hi" / "test" messages, keyboard mashing, test emails, random names.
* Form checks built from each form: required fields, real dropdown answers, same text in every field, minimum message words.
* New spam rules for phishing, romance and advance-fee scams.

= 1.4.1 =
* Live dashboard: cards, counts and the inquiry list update by themselves within seconds when a new inquiry arrives.

= 1.4.0 =
* Use any AI: OpenAI (ChatGPT), Google Gemini, Groq, Mistral, DeepSeek, OpenRouter, xAI Grok, Together, Ollama or any OpenAI-compatible API – alongside Claude and your own API.

= 1.3.0 =
* Form reader: LeadSift reads every form first – its fields, which field is name / email / phone / message, and what the form is for (contact, quote, booking, job, newsletter, support). Check or correct it in the Forms tab.
* Newsletter, job and booking forms are no longer penalised for having no message.
* After activation a notice shows how many forms and fields were found.

= 1.2.0 =
* New "Read my business" button: reads your website and suggests a business summary and rules made for your business (smarter with Claude AI).

= 1.1.0 =
* New Rules & AI tab: build your own rules, optional AI check (Claude AI or your own API), live tester.
* New Forms tab: all forms on the site with inquiry counts; filter inquiries by form.
* New "Needs a look" filter for inquiries LeadSift is not fully sure about.
* Database update for AI results (automatic).

= 1.0.0 =
* First release: capture from 7 form plugins + REST, Genuine / Promotional / Spam engine, React dashboard, insights, CSV export, learning from corrections.
