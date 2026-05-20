# WP AI Forms — agent notes

> **For product/technical spec** (data contracts, REST endpoints, AI provider contract, roadmap), see [`docs/SPEC.md`](docs/SPEC.md). This file holds agent-oriented conventions only.

## What this plugin is
A WordPress plugin that builds forms from natural-language prompts.

**MVP (v0.1) ships BYOK only.** The managed/credit-based mode is intentionally hidden from the Settings UI until after the wp.org launch — the PHP `Managed` provider and the backend contract are wired ahead of time, but no user-facing UI exists for it yet. Don't surface it in MVP work unless explicitly asked.

Modes:
- **BYOK** (MVP): user-supplied API keys for Anthropic, Gemini, or any OpenAI-compatible endpoint.
- **Managed** (post-launch / v1.0): our hosted service, credit-based, authenticated by license key. See `docs/SPEC.md` §5A.

Forms are stored in custom DB tables (`{prefix}ai_forms`, `{prefix}ai_form_submissions`) and rendered via the `[wp_ai_form id="..."]` shortcode. Gutenberg block is on the roadmap.

## Architecture

### PHP (`includes/`)
- Custom PSR-4-ish autoloader. Namespace `WP_AI_Forms\` maps to `includes/`, with `class-` / `interface-` / `trait-` prefixes and `kebab-case` filenames.
- `Plugin::instance()` boots feature classes on `plugins_loaded`.
- AI providers implement `Ai\Provider` and are registered in `Ai\Provider_Manager`. Add new ones via the `wp_ai_forms_register_providers` action.
- `Ai\Schema_Prompt` holds the shared system prompt and the JSON sanitizer that every provider funnels into — keep schema validation centralized there.
- REST routes live under `wp-ai-forms/v1/*`. Management endpoints require `manage_options`; the public submission endpoint is `/submissions/{uuid}`.
- Secrets in `wp_ai_forms_ai_settings` are never returned over REST; `*_set` booleans signal presence instead.

### JS (`src/`)
- Built with `@wordpress/scripts`. Two entries: `admin` and `frontend`. Output → `build/`.
- All React goes through `@wordpress/element` (the bundled wp.element React). Do **not** add a separate React dep.
- UI uses `@wordpress/components`.
- `src/shared/` is intentionally plugin-agnostic — see its README. Anything in there must not import plugin globals or feature folders. The goal is to lift this folder into a shared npm package across plugins later.

## Conventions
- Filenames: `class-foo-bar.php`, `interface-foo.php`, `trait-foo.php` — autoloader depends on this.
- Hooks/filters are prefixed `wp_ai_forms_*`.
- DOM/CSS class prefix: `wpaif-`.
- JS global namespace: `WP_AI_FORMS` (frontend) and `WP_AI_FORMS_ADMIN` (admin) — set via `wp_localize_script`.

## Common tasks
- Add a new AI provider: implement `Ai\Provider`, register in `Provider_Manager::__construct` or via the action hook, add a config block in the Settings React page.
- Add a new field type: extend `Form_Renderer::render_field` (PHP), the `FIELD_TYPES` array in `FormEditor.js`, and the allowed types in `Schema_Prompt::sanitize_schema`.
- Build: `npm run build`. Dev watch: `npm run start`.

## Not yet built (see `docs/SPEC.md` §12 for full roadmap)
- Submissions admin view (data is being stored; UI to come) — v0.2.
- Gutenberg block (thin wrapper around shortcode) — v0.4.
- File upload field type — v0.5.
- Conditional logic / multi-step — v0.5.
- Managed service (UI + backend) — v1.0, post wp.org launch.
- WP AI Client SDK adoption — future, once SDK stabilizes / lands in WP 7.0 core.
