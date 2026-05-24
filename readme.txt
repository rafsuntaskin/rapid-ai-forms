=== Easy AI Forms ===
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

Easy AI Forms is a contact form builder that uses AI to do the boring part. Instead of dragging fields around, you describe the form you want in plain English — *"contact form with name, email, phone, and a short message"* — and the plugin generates the fields, labels, and validation for you. Edit anything by hand, drop it on any page with a shortcode, and start receiving submissions.

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

**Security and code quality**

* All admin REST endpoints require the `manage_options` capability
* All user input is sanitized at the boundary (`sanitize_text_field`, `sanitize_email`, `sanitize_textarea_field`, `esc_url_raw`, `sanitize_key`)
* All output is escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`)
* All `$wpdb` queries use `prepare()` with placeholders (including `%i` for identifiers)
* Public submission endpoint enforces a 64 KB payload cap and validates against the form's schema
* Hidden field values are read from the schema on the server, never from the client
* Provider API keys are stored with `autoload=no` and never returned over REST (presence is signaled with a boolean)
* WordPress Coding Standards 3.1 enforced (PHPCS clean, 0 errors / 0 warnings)
* Translation-ready (`languages/easy-ai-forms.pot`)

== Privacy and external services ==

This plugin makes outbound HTTP requests to whichever AI provider the site administrator configures (Anthropic, Google Gemini, or any OpenAI-compatible endpoint). Each AI request sends only the natural-language prompt the administrator types in the form editor, plus the form's current schema when editing an existing form. No site content, user data, or form submissions are sent to the AI provider. No data is sent until an administrator configures a provider and clicks "Generate" or "Apply changes."

On WordPress 7.0+, the **WordPress AI Client** provider routes requests through the connector the site owner has configured under **Settings → Connectors** instead of a key stored by this plugin. The same data-disclosure rules apply — only the prompt and (when editing) the current schema leave your site.

Provider documentation and terms:

* Anthropic — https://www.anthropic.com/legal/privacy
* Google Gemini — https://policies.google.com/privacy
* OpenAI — https://openai.com/policies/privacy-policy
* For any other OpenAI-compatible endpoint, refer to that vendor's policy.

When a visitor submits a form, the plugin can send a notification email through your site's standard `wp_mail()` pipeline (the same mechanism WordPress uses for core notifications). The recipient, subject, and body are configured per-form in the editor and default to the site administrator email. Email is delivered by your existing SMTP / mail setup; this plugin does not contact any third party to send it.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/easy-ai-forms` or install from the WordPress.org plugin directory.
2. Activate the plugin through the **Plugins** screen.
3. Visit **AI Forms → Settings** to configure your AI provider (or pick *WordPress AI Client* on WP 7.0+ if you've already set up a connector).
4. Create a form under **AI Forms → Forms**, generate fields with a prompt, and embed it with `[easy_ai_form id="123"]`.

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

Into a dedicated `{prefix}easy_ai_form_submissions` database table. The site administrator (or any address you configure on the form) also receives a notification email on each submission with the field values.

= How do I customize the notification email? =

Open the form in the editor and scroll to **Email notifications**. You can set the To address (comma-separated for multiple recipients), Subject, and Body. The Body accepts mail-tags like `{all_fields}`, `{field_name}`, `{form_title}`, `{site_name}`, `{site_url}`, and `{admin_email}`. Picking a Reply-To field lets you hit Reply on the notification and write straight back to the visitor.

= I'm not receiving notification emails. What should I check? =

The plugin hands the message to WordPress's `wp_mail()`, which means the site itself has to be able to send mail. If `wp_mail()` does not work elsewhere on the site (e.g. password-reset emails are missing), the most common fix is to install an SMTP plugin and point it at a real mailer. Also confirm the notifications toggle is on for the form and that the *To* field contains a valid address.

== Screenshots ==

1. The Forms list. Cards show each form's title, field count, last update, and the click-to-copy shortcode.
2. The form editor with a live preview pane on the right. Describe the form in plain English; the AI generates fields and the preview updates as you edit.
3. The Email notifications panel. Choose recipients, write a subject and body with mail-tags, and optionally pick an email field as the Reply-To.
4. A finished form rendered on the frontend, picking up the active theme's button styles automatically.
5. The Settings page. Configure your AI provider once and verify the credentials with one click — or pick the WordPress AI Client on WP 7.0+ to reuse a core connector.

== Changelog ==

= 0.1.0 =
* Initial release.
* AI-driven form generation and editing via BYOK providers: Anthropic, Google Gemini, OpenAI-compatible (OpenAI, OpenRouter, Fireworks, Groq, local LLMs).
* WordPress 7.0 AI Client integration as a no-key option when a core connector is configured.
* React-based admin SPA built on `@wordpress/element` and `@wordpress/components`.
* Custom database tables for forms and submissions.
* Per-form email notifications with mail-tag templating, comma-separated recipients, and an optional Reply-To field.
* "Generate with AI" action for the notification body.
* `[easy_ai_form id="..."]` shortcode renderer with theme-aware submit button.
* REST API at `/wp-json/easy-ai-forms/v1/` with capability-gated admin routes.
* Registration with the WordPress Abilities API.
* One-click credential verification.
* Live preview, click-to-copy shortcode, card-based form list with search, pagination, and delete.
