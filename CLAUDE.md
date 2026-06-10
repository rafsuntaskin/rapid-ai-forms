# Rapid AI Forms — agent notes

> **For product/technical spec** (data contracts, REST endpoints, AI provider contract, roadmap), see [`docs/SPEC.md`](docs/SPEC.md). This file holds agent-oriented conventions only.

## What this plugin is
A WordPress plugin that builds forms from natural-language prompts.

**MVP (v0.1) ships BYOK only.** The managed/credit-based mode is intentionally hidden from the Settings UI until after the wp.org launch — the PHP `Managed` provider and the backend contract are wired ahead of time, but no user-facing UI exists for it yet. Don't surface it in MVP work unless explicitly asked.

Modes:
- **BYOK** (MVP): user-supplied API keys for Anthropic, Gemini, or any OpenAI-compatible endpoint.
- **WP AI Client** (MVP, WP 7.0+): registers a fourth provider that delegates to core's `wp_ai_client_prompt()`. No plugin-held credentials — the site owner uses **Settings → Connectors**. Auto-hidden on older WP via `Wp_Ai_Client::is_available()`.
- **Managed** (post-launch / v1.0): our hosted service, credit-based, authenticated by license key. See `docs/SPEC.md` §5A.

Forms are stored in custom DB tables (`{prefix}rapid_ai_forms`, `{prefix}rapid_ai_form_submissions`) and rendered via the `[rapid_ai_form id="..."]` shortcode. Gutenberg block is on the roadmap.

## Architecture

### PHP (`includes/`)
- Custom PSR-4-ish autoloader. Namespace `Rapid_Ai_Forms\` maps to `includes/`, with `class-` / `interface-` / `trait-` prefixes and `kebab-case` filenames.
- `Plugin::instance()` boots feature classes on `plugins_loaded`.
- AI providers implement `Ai\Provider` and are registered in `Ai\Provider_Manager`. Add new ones via the `rapid_ai_forms_register_providers` action.
- `Ai\Schema_Prompt` holds the shared system prompt and the JSON sanitizer that every provider funnels into — keep schema validation centralized there.
- REST routes live under `rapid-ai-forms/v1/*`. Management endpoints require `manage_options`; the public submission endpoint is `/submissions/{uuid}`.
- Secrets in `rapid_ai_forms_ai_settings` are never returned over REST; `*_set` booleans signal presence instead.
- `Notifications\Email_Notifier` listens on `rapid_ai_forms_submission_created` and sends per-form email via `wp_mail()`. Mail-tags resolved in `Email_Notifier::build_tags()`; recipient defaults to `admin_email` (seeded at form-create time in `Form_Repository::create()`).
- `Forms\Form_Repository::list()` / `count()` accept `page`, `per_page`, and `search`. The REST `/forms` endpoint reads `X-WP-Total` / `X-WP-TotalPages` headers so the React list can paginate without a custom envelope. `GET /submissions` (admin) follows the same convention.
- Per-form custom CSS lives in `settings.custom_css`, sanitized by `Forms\Css_Sanitizer` on every write and at render; `Form_Renderer` emits it scoped under `.raif-form[data-form-uuid]` (CSS nesting). AI edits go through `POST /ai/style` → `Ai\Css_Prompt`.
- AI providers implement both `generate_form_schema()` and `generate_text( $system, $prompt )` — schema JSON-mode and free-form text share one HTTP helper per provider.
- `Frontend\Preview` serves `/?rapid_ai_form_preview={id}` (admin-only) — a minimal wp_head/wp_footer document the editor iframes for a theme-accurate preview.

### JS (`src/`)
- Built with `@wordpress/scripts`. Two entries: `admin` and `frontend`. Output → `build/`.
- All React goes through `@wordpress/element` (the bundled wp.element React). Do **not** add a separate React dep.
- UI uses `@wordpress/components`.
- `src/shared/` is intentionally plugin-agnostic — see its README. Anything in there must not import plugin globals or feature folders. The goal is to lift this folder into a shared npm package across plugins later.
- **`src/` ships in the wp.org dist zip** (alongside `package.json`, `package-lock.json`, `webpack.config.js`) so reviewers can verify Guideline 4 (public source access for compiled assets). Don't add `src/` to `.distignore`.

## Conventions
- Filenames: `class-foo-bar.php`, `interface-foo.php`, `trait-foo.php` — autoloader depends on this.
- Hooks/filters are prefixed `rapid_ai_forms_*`.
- DOM/CSS class prefix: `raif-`.
- JS global namespace: `RAPID_AI_FORMS` (frontend) and `RAPID_AI_FORMS_ADMIN` (admin) — set via `wp_localize_script`.

## Common tasks
- Add a new AI provider: implement `Ai\Provider` (including `verify()`), register in `Provider_Manager::__construct` or via the `rapid_ai_forms_register_providers` action, add a config block in the Settings React page.
- Add a new field type: extend `Form_Renderer::render_field` (PHP), `Rest_Controller::sanitize_submission()` (per-type submit sanitization), the `FIELD_TYPES` array in `FormEditor.js`, the allowed types in `Schema_Prompt::sanitize_schema()`, and (if the type takes a `placeholder`) `wp_ai_forms_placeholder_field_types`.
- Build: `npm run build`. Dev watch: `npm run start`.
- Lint PHP: `composer lint` (auto-fix: `composer lint:fix`).
- Run PHP tests: `npm run test:php` (official WP test suite via wp-env; start the Docker env first with `npx wp-env start`). Tests live in `tests/test-*.php` as `WP_UnitTestCase` classes.
- Regenerate translations: `wp i18n make-pot . languages/rapid-ai-forms.pot --domain=rapid-ai-forms --exclude=build,node_modules,docs,vendor,bin,dist`.
- Build dist zip: `npm run dist` → `dist/rapid-ai-forms.zip` (honors `.distignore`).
- Deploy to local wooDev: `bash bin/dist.sh --to ~/Dev/lando/sites/wooDev/wp-content/plugins --no-build`.
- Release to wp.org SVN: `npm run deploy` (dry-run) → `npm run deploy -- --commit`. Verify-only, deletion-safe, tag-guarded. See [`docs/DEPLOY.md`](docs/DEPLOY.md).
- Run wp.org Plugin Check against the installed copy: `lando wp plugin check rapid-ai-forms`. Must report `Success: Checks complete. No errors found.` before submitting.

## Not yet built (see `docs/SPEC.md` §12 for full roadmap)
- Submission anti-abuse (origin token, honeypot, per-IP rate limit) — v0.3 (see `docs/PLAN-submission-integrity.md` Part B).
- CSV/JSON export + date filter on the submissions page — deferred from v0.2.
- Gutenberg block (thin wrapper around shortcode) — v0.4.
- File upload field type — v0.5.
- Conditional logic / multi-step — v0.5.
- Managed service (UI + backend) — v1.0, post wp.org launch.
