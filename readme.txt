=== Rapid AI Forms ===
Contributors: rafsuntaskin
Tags: contact form, ai builder, form builder, forms
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered contact form builder. Describe the form you need and let AI build it — name, email, message, the works. No drag-and-drop required.

== Description ==

Rapid AI Forms is a contact form builder that uses AI to do the boring part. Instead of dragging fields around, you describe the form you want in plain English — *"contact form with name, email, phone, and a short message"* — and the plugin generates the fields, labels, and validation for you. Edit anything by hand, drop it on any page with a shortcode, and start receiving submissions.

Every form sends a notification email to whoever you choose, with a fully customizable Subject and Body using mail-tags (Contact Form 7 style). Replies go straight to the visitor's email address when you pick a Reply-To field.

**Typical uses**

* A site contact form with name, email, and message
* A sales / inquiry form with phone, company, and a service dropdown
* An RSVP form with guest count and dietary restrictions
* A newsletter signup with a hidden campaign tag
* A support intake form with a priority radio group and a description textarea
* Anything else you can describe in a sentence — the AI handles the schema

**Bring your own AI**

Use your own API keys for Anthropic Claude, Google Gemini, or any OpenAI-compatible endpoint (OpenAI, OpenRouter, Fireworks, Groq, local LLMs, and others). On WordPress 7.0+ you can also use a connector configured under **Settings → Connectors** — no per-plugin API key needed.

**Features**

* Build contact forms from a single natural-language prompt
* Edit forms with follow-up prompts: *"add a phone field after email"* and the AI applies just that change
* Field editor with live preview that mirrors the active theme's button styles
* Field types: text, email, password, phone, URL, number, date, textarea, select, radio, single checkbox, checkbox group, hidden
* Per-form email notifications with mail-tag templating, comma-separated recipients, and an optional Reply-To field
* "Generate with AI" button to rewrite the notification body from your current form
* Verify provider credentials in one click before saving them
* Reorder fields up / down, search and delete forms from a card-based list
* Click-to-copy shortcode for each form
* Submissions stored in a dedicated database table
* REST API and registration with the WordPress Abilities API (6.9+)
* No tracking, no telemetry, no advertising, no upsells

== Privacy and external services ==

This plugin contacts a third-party AI service **only** when an administrator clicks **Generate** or **Apply changes** in the form editor. The request is sent to whichever provider the administrator configured: Anthropic ([privacy](https://www.anthropic.com/legal/privacy)), Google Gemini ([privacy](https://policies.google.com/privacy)), OpenAI ([privacy](https://openai.com/policies/privacy-policy)), any OpenAI-compatible endpoint of your choice, or — on WordPress 7.0+ — a connector configured under **Settings → Connectors**. The request body contains only the natural-language prompt the administrator typed, plus the form's current schema when editing an existing form. No site content, user data, or visitor submissions are ever sent to the AI provider.

Form submissions stay on your site: they're stored in a custom database table and, if enabled per form, sent as a notification email through WordPress's own `wp_mail()` (no third party involved).

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New**, search for *Rapid AI Forms*, and click **Install Now**, then **Activate**.
2. Open **AI Forms → Settings** and configure your AI provider.
3. Create a form under **AI Forms → Forms**, describe what you want, and embed it on any page with `[rapid_ai_form id="123"]`.

== Frequently Asked Questions ==

= Is this really just a contact form builder? =

That's the primary use case and the one the AI defaults are tuned for. Under the hood the field set is general enough to build inquiry forms, RSVPs, newsletter signups, support intakes, and similar lightweight submission forms. It is not a replacement for payment forms, multi-page applications, or anything with conditional logic (yet).

= Do I need an AI provider account? =

You need credentials for at least one provider. Either bring your own API key (Anthropic, Google Gemini, or any OpenAI-compatible service), or on WordPress 7.0+ configure a connector under **Settings → Connectors** and pick *WordPress AI Client* in the plugin settings — no per-plugin key needed in that case.

= Can I use it without the AI? =

Yes. Every field is fully editable by hand and you can build the whole form manually if you prefer. The AI is a shortcut, not a requirement.

= Does the plugin send my form submissions to the AI provider? =

No. Submissions stay in your own database. Only the prompts you type in the form editor are sent to your chosen AI provider, and only when you explicitly click Generate or Apply.

= Where do form submissions go? =

Into a dedicated `{prefix}rapid_ai_form_submissions` database table. The site administrator (or any address you configure on the form) also receives a notification email on each submission with the field values.

= How do I customize the notification email? =

Open the form in the editor and scroll to **Email notifications**. You can set the To address (comma-separated for multiple recipients), Subject, and Body. The Body accepts mail-tags like `{all_fields}`, `{field_name}`, `{form_title}`, `{site_name}`, `{site_url}`, and `{admin_email}`. Picking a Reply-To field lets you hit Reply on the notification and write straight back to the visitor.

= I'm not receiving notification emails. What should I check? =

The plugin hands the message to WordPress's `wp_mail()`, which means the site itself has to be able to send mail. If `wp_mail()` does not work elsewhere on the site (e.g. password-reset emails are missing), the most common fix is to install an SMTP plugin and point it at a real mailer. Also confirm the notifications toggle is on for the form and that the *To* field contains a valid address.

== Development ==

The full plugin source — including the un-minified JSX/SCSS that produces the files in `build/` — ships inside this plugin under `src/`, along with `package.json`, `package-lock.json`, and `webpack.config.js`. You can rebuild the compiled assets at any time:

`npm install && npm run build`

This regenerates `build/admin.js`, `build/admin.css`, `build/frontend.js`, `build/frontend.css`, and the matching `.asset.php` dependency manifests. No third-party libraries are bundled inside the compiled output — every external dependency is referenced via WordPress's own `wp.element` / `wp.components` / `wp.apiFetch` / `wp.i18n` globals, which WordPress loads separately.

Development happens on GitHub at https://github.com/rafsuntaskin/rapid-ai-forms — issues, pull requests, and discussions are welcome there.

== Changelog ==

= 0.1.0 =
* Initial release.
* AI-driven form generation and editing via BYOK providers: Anthropic, Google Gemini, OpenAI-compatible (OpenAI, OpenRouter, Fireworks, Groq, local LLMs).
* WordPress 7.0 AI Client integration as a no-key option when a core connector is configured.
* React-based admin SPA built on `@wordpress/element` and `@wordpress/components`.
* Custom database tables for forms and submissions.
* Per-form email notifications with mail-tag templating, comma-separated recipients, and an optional Reply-To field.
* "Generate with AI" action for the notification body.
* `[rapid_ai_form id="..."]` shortcode renderer with theme-aware submit button.
* REST API at `/wp-json/rapid-ai-forms/v1/` with capability-gated admin routes.
* Registration with the WordPress Abilities API.
* One-click credential verification.
* Live preview, click-to-copy shortcode, card-based form list with search, pagination, and delete.
