=== WP AI Forms ===
Contributors: rafsuntaskin
Tags: forms, ai, openai, anthropic, gemini, form builder
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered form builder for WordPress. Describe the form you want in plain English and let AI generate the fields for you.

== Description ==

WP AI Forms lets you build forms with natural language. Describe what you need ("contact form with name, email, phone, and a message"), and the plugin asks your selected AI provider to generate the schema for you. Edit, save, and embed anywhere with the `[wp_ai_form id="123"]` shortcode.

Use your own API keys for Anthropic Claude, Google Gemini, or any OpenAI-compatible endpoint (OpenAI, OpenRouter, Fireworks, Groq, local LLMs, and others).

**Features**

* AI-driven form generation from a single prompt
* AI-driven form editing — say "add a phone field after email" and the AI applies just that change
* Field editor with live preview that mirrors the active theme's button styles
* Field types: text, email, password, phone, URL, number, date, textarea, select, radio, single checkbox, checkbox group, hidden
* Verify provider credentials in one click before saving them
* Custom DB tables for forms and submissions (built to scale)
* Per-form email notifications with mail-tag templates (Contact Form 7 style)
* Click-to-copy shortcode pill for each form
* Search and delete forms from a modern card-based list
* REST API and registration with the WordPress Abilities API (6.9+)
* No tracking, no telemetry, no upsells

== Privacy and external services ==

This plugin makes outbound HTTP requests to whichever AI provider the site administrator configures (Anthropic, Google Gemini, or any OpenAI-compatible endpoint). Each AI request sends only the natural-language prompt the administrator types in the form editor, plus the form's current schema when editing an existing form. No site content, user data, or form submissions are sent to the AI provider. No data is sent until an administrator configures a provider and clicks "Generate" or "Apply changes."

When a visitor submits a form, the plugin can send a notification email through your site's standard `wp_mail()` pipeline (the same mechanism WordPress uses for core notifications). The recipient, subject, and body are configured per-form in the editor and default to the site admin email. Email is delivered by your existing SMTP / mail setup; this plugin does not contact any third party to send it.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-ai-forms` or install from the WordPress.org plugin directory.
2. Activate the plugin through the **Plugins** screen.
3. Visit **AI Forms → Settings** to configure your AI provider and verify the connection.
4. Create a form under **AI Forms → Forms**, generate fields with a prompt, and embed it with `[wp_ai_form id="123"]`.

== Frequently Asked Questions ==

= Do I need an AI provider account? =

Yes. The plugin doesn't include hosted AI — you supply your own API key for Anthropic, Google Gemini, or any OpenAI-compatible service.

= Does the plugin send my form submissions anywhere? =

No. Form submissions are stored in your own database (`wp_ai_form_submissions`). Only the prompts you type in the form editor are sent to your chosen AI provider, and only when you explicitly click Generate or Apply.

= Can I edit fields manually? =

Yes. Every field in the editor is fully editable by hand — the AI is optional. You can build the entire form manually if you prefer.

= Where do form submissions go? =

Into a dedicated `{prefix}ai_form_submissions` database table. The site admin (or any address you configure on the form) also gets an email on each submission with the field values. A full submissions admin view is on the roadmap for v0.2.

= How do I customize the notification email? =

Open the form in the editor and scroll to **Email notifications**. You can set the To address (comma-separated for multiple recipients), Subject, and Body. The Body accepts mail-tags like `{all_fields}`, `{field_name}`, `{form_title}`, `{site_name}`, `{site_url}`, and `{admin_email}`. Picking a Reply-To field lets you reply directly to whoever submitted the form.

== Changelog ==

= 0.1.0 =
* Initial release.
* AI-driven form generation and editing via BYOK providers: Anthropic, Google Gemini, OpenAI-compatible (OpenAI, OpenRouter, Fireworks, Groq, local LLMs).
* React-based admin SPA built on `@wordpress/element` and `@wordpress/components`.
* Custom DB tables for forms and submissions.
* Per-form email notifications with mail-tag templating, comma-separated recipients, and an optional Reply-To field.
* `[wp_ai_form id="..."]` shortcode renderer with theme-aware submit button.
* REST API at `/wp-json/wp-ai-forms/v1/`.
* Registration with the WordPress Abilities API.
* One-click credential verification.
* Live preview, click-to-copy shortcode, card-based form list with search and delete.
