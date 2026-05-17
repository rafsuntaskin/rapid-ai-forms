=== WP AI Forms ===
Contributors: rafsuntaskin
Tags: forms, ai, openai, anthropic, gemini, form builder
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered form builder for WordPress. Describe the form you want and let AI generate the fields. Bring your own key, or use our managed credit-based service.

== Description ==

WP AI Forms lets you build forms with natural language. Describe what you need ("contact form with name, email, phone, and a message"), and the plugin asks your selected AI provider to generate the schema for you. Edit, save, and embed anywhere with the `[wp_ai_form id="123"]` shortcode.

Use your own API keys for Anthropic Claude, Google Gemini, or any OpenAI-compatible endpoint (OpenAI, OpenRouter, local LLMs, Groq, and others).

**Features**

* AI-driven form generation from a single prompt
* Field editing in a React admin SPA
* Custom DB tables for forms and submissions
* Shortcode: `[wp_ai_form id="123"]`
* Discoverable via the WordPress Abilities API (6.9+)

== Installation ==

1. Upload to `/wp-content/plugins/wp-ai-forms`.
2. Activate the plugin.
3. Visit **AI Forms → Settings** to configure your AI provider.
4. Create a form, embed with `[wp_ai_form id="123"]`.

== Changelog ==

= 0.1.0 =
* Initial release: form repository, AI providers (Anthropic, Gemini, OpenAI-compatible), REST API, admin SPA, shortcode renderer.
