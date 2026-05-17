# WP AI Forms — agent notes

## What this plugin is
A WordPress plugin that builds forms from natural-language prompts. Two AI modes:
- **BYOK**: user-supplied API keys for Anthropic, Gemini, or any OpenAI-compatible endpoint.
- **Managed**: our hosted service, credit-based, authenticated by license key.

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

## Not yet built
- Gutenberg block (planned: thin wrapper around shortcode).
- Submissions admin view (data is being stored; UI to come).
- Managed service backend (PHP client points at a placeholder endpoint; override with the `wp_ai_forms_managed_endpoint` filter).
- File upload field type.
- Conditional logic / multi-step.
