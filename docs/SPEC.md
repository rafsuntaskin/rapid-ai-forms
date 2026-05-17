# WP AI Forms — Technical Specification

**Version:** 0.1.0
**Status:** Draft
**Audience:** developers, integrators, and future contributors

This document specifies the data contracts, APIs, and behaviors of the WP AI Forms plugin. For agent-oriented conventions (file layout, autoloader rules, naming), see `CLAUDE.md`.

---

## 1. Product overview

WP AI Forms is a WordPress plugin that lets site owners build forms from natural-language prompts, then embed them anywhere via shortcodes (Gutenberg block planned).

### 1.1 AI modes

| Mode | Description | Configured by |
|---|---|---|
| `byok` | Site owner brings their own API key. | `active_provider` + per-provider config |
| `managed` | Plugin calls the vendor's managed service, billed by credits. | `license_key` |

### 1.2 Goals
- Generate working form schemas from a single natural-language prompt.
- Render forms via shortcode `[wp_ai_form id="..."]`.
- Store submissions in dedicated DB tables for querying and export.
- Be extensible: third parties can register additional AI providers.

### 1.3 Non-goals (v0.1)
- Payment forms, conditional logic, multi-step wizards.
- File uploads, signature fields, repeaters.
- Front-end form editing.

---

## 2. Form Schema JSON contract

This is the canonical shape that AI providers must produce and that the editor/renderer consume. It lives in the `schema` column of `wp_ai_forms` (JSON-encoded).

### 2.1 Schema

```jsonc
{
  "title": "string",              // form title (also stored on the form row)
  "submit_label": "string",       // text on the submit button. Required.
  "show_title": false,            // whether the renderer prints the title above fields
  "fields": [
    {
      "name": "snake_case_id",    // unique field identifier within the form. Required.
      "label": "Human label",     // visible label
      "type": "text",             // see allowed types below. Required.
      "required": false,
      "placeholder": "",
      "options": [                // only when type is "select" or "radio"
        { "label": "Yes", "value": "yes" }
      ]
    }
  ]
}
```

### 2.2 Allowed field types

`text`, `email`, `tel`, `url`, `number`, `date`, `textarea`, `select`, `radio`, `checkbox`.

Any unknown type is coerced to `text` by `Schema_Prompt::sanitize_schema()`.

### 2.3 Validation rules
- `name` is required; fields without `name` are dropped during sanitization.
- `name` is run through `sanitize_key()` — it must be lowercase, alphanumeric, and may contain underscores/hyphens.
- `submit_label` defaults to `"Submit"` if missing.
- `options` is only kept for `select` and `radio`.
- Every `option` produces `{ label, value }`; if `value` is missing, `label` is used.

### 2.4 Example

```json
{
  "title": "Contact us",
  "submit_label": "Send message",
  "show_title": true,
  "fields": [
    { "name": "full_name", "label": "Full name", "type": "text", "required": true },
    { "name": "email",     "label": "Email",     "type": "email", "required": true },
    { "name": "topic",     "label": "Topic",     "type": "select",
      "options": [
        { "label": "Sales", "value": "sales" },
        { "label": "Support", "value": "support" }
      ]
    },
    { "name": "message",   "label": "Message",   "type": "textarea", "required": true }
  ]
}
```

---

## 3. Data model

### 3.1 `{prefix}ai_forms`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `uuid` | `VARCHAR(36)` | unique; used for public submission endpoint |
| `title` | `VARCHAR(255)` | |
| `status` | `VARCHAR(20)` | `draft` \| `published` |
| `schema` | `LONGTEXT` | JSON-encoded Form Schema |
| `settings` | `LONGTEXT` | JSON, per-form settings (notifications, etc. — future use) |
| `ai_prompt` | `LONGTEXT NULL` | last prompt used to generate this form |
| `author_id` | `BIGINT UNSIGNED` | WP user id (0 if none) |
| `created_at` | `DATETIME` | UTC |
| `updated_at` | `DATETIME` | UTC, auto-updates |

Indexes: `uuid` (unique), `status`, `author_id`.

### 3.2 `{prefix}ai_form_submissions`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `form_id` | `BIGINT UNSIGNED` | FK to `ai_forms.id` (not enforced at DB level) |
| `data` | `LONGTEXT` | JSON-encoded sanitized submission |
| `meta` | `LONGTEXT NULL` | JSON, reserved for future use |
| `ip_address` | `VARCHAR(45)` | IPv4 or IPv6 |
| `user_agent` | `VARCHAR(255)` | truncated |
| `user_id` | `BIGINT UNSIGNED` | logged-in user, 0 if anonymous |
| `created_at` | `DATETIME` | UTC |

Indexes: `form_id`, `user_id`, `created_at`.

### 3.3 Options

| Option | Purpose |
|---|---|
| `wp_ai_forms_version` | Currently installed plugin version. |
| `wp_ai_forms_db_version` | Schema version, used to trigger migrations. |
| `wp_ai_forms_ai_settings` | AI mode + provider configs (see §5.1). |

### 3.4 Migration policy
- `Schema::install()` runs on activation via `dbDelta()`.
- DB version is stored in `wp_ai_forms_db_version`. When `Schema::DB_VERSION` is bumped, the installer is re-run on `plugins_loaded` if the option lags behind. (Hook not yet wired; planned.)

---

## 4. REST API

Base URL: `/wp-json/wp-ai-forms/v1/`
Authentication: WP cookie + `X-WP-Nonce` header for admin endpoints. Submission endpoint is public.

### 4.1 Capability matrix

| Endpoint | Method | Capability |
|---|---|---|
| `/forms` | GET, POST | `manage_options` |
| `/forms/{id}` | GET, PUT, DELETE | `manage_options` |
| `/ai/generate` | POST | `manage_options` |
| `/settings` | GET, PUT | `manage_options` |
| `/submissions/{uuid}` | POST | public |

### 4.2 Endpoints

#### `GET /forms`
Query params: `page` (default 1), `per_page` (default 20, max 100).
Returns: array of form objects (see §4.3).

#### `POST /forms`
Body: partial form object (`title`, `status`, `schema`, `settings`, `ai_prompt`).
Returns: created form object.

#### `GET /forms/{id}`
Returns: form object or `404 wpaif_not_found`.

#### `PUT /forms/{id}`
Body: any subset of `title`, `status`, `schema`, `settings`, `ai_prompt`.
Returns: updated form object.

#### `DELETE /forms/{id}`
Returns: `{ "deleted": true }`.

#### `POST /ai/generate`
Body: `{ "prompt": "string" }`.
Returns: a sanitized Form Schema (§2). On failure returns `WP_Error` with HTTP 400.
Error codes: `wpaif_no_provider`, `wpaif_missing_key`, `wpaif_missing_license`, `wpaif_empty_response`, `wpaif_invalid_json`, plus provider-specific (`wpaif_anthropic_error`, `wpaif_gemini_error`, `wpaif_openai_error`, `wpaif_managed_error`, `wpaif_no_credits`).

#### `GET /settings`
Returns the settings object (§5.1) with **secrets stripped**: `api_key` and `license_key` are always empty strings; `api_key_set` / `license_key_set` booleans indicate whether a secret is stored. Also includes `available_providers: [{ key, label }, ...]`.

#### `PUT /settings`
Body: `{ mode, active_provider, providers: { ... } }`.
Empty `api_key` / `license_key` strings are **ignored** (existing value preserved). Non-empty strings replace.
Returns: the same shape as `GET /settings`.

#### `POST /submissions/{uuid}`
Public. Body: arbitrary key/value pairs matching the form's `fields[].name`.
Behavior:
1. Look up the form by UUID; 404 if missing.
2. Iterate `fields`; for each known `name`, sanitize the incoming value by type:
   - `email` → `sanitize_email`
   - `url` → `esc_url_raw`
   - `textarea` → `sanitize_textarea_field`
   - `number` → numeric coercion (or `null`)
   - everything else → `sanitize_text_field` (array values are mapped)
3. Unknown keys are dropped.
4. Insert into `ai_form_submissions`.
5. Fire `do_action( 'wp_ai_forms_submission_created', $submission_id, $form, $data )`.

Returns: `{ "ok": true, "id": 123 }`.

### 4.3 Form object shape

```jsonc
{
  "id": 42,
  "uuid": "...",
  "title": "Contact us",
  "status": "published",
  "schema": { /* Form Schema, parsed */ },
  "settings": { /* parsed */ },
  "ai_prompt": "Contact form with name and email",
  "author_id": 1,
  "created_at": "2026-05-18 12:00:00",
  "updated_at": "2026-05-18 12:00:00"
}
```

---

## 5. AI providers

### 5.1 Settings storage (`wp_ai_forms_ai_settings`)

```jsonc
{
  "mode": "byok",                       // "byok" | "managed"
  "active_provider": "openai_compatible",
  "providers": {
    "anthropic":         { "api_key": "...", "model": "claude-sonnet-4-6" },
    "gemini":            { "api_key": "...", "model": "gemini-2.0-flash" },
    "openai_compatible": { "api_key": "...", "base_url": "https://api.openai.com/v1", "model": "gpt-4o-mini" },
    "managed":           { "license_key": "..." }
  }
}
```

### 5.2 Provider contract

```php
interface Provider {
  public function key(): string;     // stable identifier, e.g. "anthropic"
  public function label(): string;   // human-readable name
  public function generate_form_schema( string $prompt, array $options = [] ); // array | WP_Error
}
```

**Behavior requirements:**
- Must return either an array conforming to the Form Schema (§2) or a `WP_Error`.
- Must call `Schema_Prompt::system()` as the system instruction.
- Must funnel raw model text through `Schema_Prompt::extract_schema()` for validation/sanitization.
- Must propagate HTTP failures as `WP_Error` (do not throw).
- HTTP timeout: 60s recommended (current default).
- Use HTTP `402` to signal credit exhaustion from the managed service → mapped to `wpaif_no_credits`.

### 5.3 Built-in providers

| Key | Endpoint | Auth | Notes |
|---|---|---|---|
| `anthropic` | `https://api.anthropic.com/v1/messages` | `x-api-key` header | `anthropic-version: 2023-06-01` |
| `gemini` | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` | `?key=` query | Uses `responseMimeType: application/json` |
| `openai_compatible` | `{base_url}/chat/completions` | `Authorization: Bearer` (optional) | OpenAI, OpenRouter, Groq, Ollama, LM Studio, etc. Uses `response_format: { type: json_object }`. |
| `managed` | filterable via `wp_ai_forms_managed_endpoint` (default `https://api.example.com/v1/generate-form`) | `Authorization: Bearer {license_key}` | Sends `X-Site-URL` header for license validation |

### 5.4 Extension points

```php
// Register a custom provider:
add_action( 'wp_ai_forms_register_providers', function ( $manager ) {
    $manager->register( new My_Custom_Provider() );
} );

// Point the managed client at your own backend:
add_filter( 'wp_ai_forms_managed_endpoint', fn() => 'https://my-saas.example/v1/generate-form' );

// Observe submissions:
add_action( 'wp_ai_forms_submission_created', function ( $id, $form, $data ) {
    // send email, sync to CRM, etc.
}, 10, 3 );
```

---

## 6. Frontend rendering

### 6.1 Shortcode

```
[wp_ai_form id="42"]
[wp_ai_form uuid="..."]
```

`id` and `uuid` are mutually exclusive (uuid wins if both supplied). Returns an empty string if the form is not found.

### 6.2 HTML contract

The PHP renderer emits a `<form class="wpaif-form" data-form-uuid="..." data-nonce="...">` element. Frontend JS (`build/frontend.js`) auto-binds submission for every `form.wpaif-form` not yet flagged with `data-wpaif-bound`.

### 6.3 Client behavior
- On submit, JS collects `FormData`, POSTs JSON to `/submissions/{uuid}`, includes the nonce as `X-WP-Nonce`.
- On success: form is reset and a success message is shown in `.wpaif-form__message`.
- On error: error message shown; submit button re-enabled.

### 6.4 Styling

All classes prefixed with `wpaif-`. Default styles are minimal and intended to be overridable by the theme.

---

## 7. Admin SPA

- Mounted in `wp-admin` under menu slug `wp-ai-forms` (capability `manage_options`).
- Single root: `#wp-ai-forms-admin-root`.
- Hash-based routing: `#/` (forms list), `#/forms/{id}` (editor), `#/settings`.
- All React via `@wordpress/element` only — no separate React dependency.
- UI primitives from `@wordpress/components`.

### 7.1 Portable shared kit (`src/shared/`)

Rules (enforced by convention, see `src/shared/README.md`):
1. May depend only on `@wordpress/*` packages.
2. May not read plugin globals (`WP_AI_FORMS_ADMIN`, etc.) — accept config as props/args.
3. Feature folders (`src/admin/*`, `src/frontend/*`) may import from `shared/`; the reverse is forbidden.

Eventual plan: publish as `@your-org/wp-react-kit` and consume across plugins via npm.

---

## 8. Security model

- All management endpoints require `manage_options`.
- Submission endpoint is intentionally public; rate limiting and spam protection are **out of scope for v0.1** and tracked on the roadmap.
- Secrets (`api_key`, `license_key`) are never returned over the REST API; only `*_set` booleans.
- All inputs go through WP sanitization functions (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `sanitize_textarea_field`, `sanitize_key`).
- AI-generated schemas are passed through `Schema_Prompt::sanitize_schema()` — never trusted raw.
- Nonces (`wp_rest`) are required for the admin SPA's REST calls.

### 8.1 Known gaps (planned)
- No rate limiting on `/submissions/{uuid}`.
- No CAPTCHA / honeypot.
- No CSRF protection on the public submission endpoint beyond the per-form nonce (which is short-lived). Anonymous submissions may use a generated nonce that doesn't tie to a user session.
- No audit log for settings changes.

---

## 9. Internationalization

- Text domain: `wp-ai-forms`.
- All user-visible strings in PHP use `__()` / `esc_html__()` / `_e()`.
- JS uses `@wordpress/i18n` (`__`) with `wp_set_script_translations()` registered for the admin bundle.
- `.pot` generation: TBD (not yet wired).

---

## 10. Build & release

| Task | Command |
|---|---|
| Install JS deps | `npm install` |
| Production build | `npm run build` |
| Dev watch | `npm run start` |
| Lint JS | `npm run lint:js` |
| Format | `npm run format` |

Build outputs:
- `build/admin.js`, `build/admin.css`, `build/admin.asset.php`
- `build/frontend.js`, `build/frontend.css`, `build/frontend.asset.php`

The PHP loaders fall back to a sensible default dependency list if `*.asset.php` is missing, so a fresh checkout doesn't fatal — but assets won't be enqueued until you run `npm run build`.

---

## 11. Versioning

- Plugin version: `WP_AI_FORMS_VERSION` constant in `wp-ai-forms.php`.
- DB schema version: `Schema::DB_VERSION`. Bump when columns change; migration runner is planned.
- Public REST namespace: `wp-ai-forms/v1`. Breaking changes will move to `/v2`.
- Form Schema contract: changes that drop or rename top-level keys are breaking. Adding optional fields is allowed.

---

## 12. Roadmap (with acceptance criteria)

### v0.2 — Operability
- [ ] Submissions admin view: paginated list per form, JSON & CSV export.
  *Done when:* admin can browse submissions, filter by date, and download a CSV.
- [ ] Email notification on submission, configurable per form.
  *Done when:* form's `settings.notifications.to_email` triggers a sanitized email on `wp_ai_forms_submission_created`.
- [ ] DB migration runner.
  *Done when:* bumping `Schema::DB_VERSION` runs `dbDelta` on next admin load.

### v0.3 — Anti-abuse
- [ ] Honeypot field auto-injected into the renderer.
- [ ] Optional Cloudflare Turnstile / hCaptcha integration.
- [ ] Per-IP submission rate limit (configurable).

### v0.4 — Block editor
- [ ] Gutenberg block `wp-ai-forms/form` selecting a form by id.
- [ ] Server-side render via the existing shortcode renderer.

### v0.5 — Richer forms
- [ ] File upload field type (with size/MIME constraints).
- [ ] Conditional logic (show/hide fields based on other field values).
- [ ] Multi-step forms with progress indicator.

### v1.0 — Managed service GA
- [ ] Managed backend live with credit billing.
- [ ] Usage dashboard inside the plugin admin.
- [ ] Public API for billing webhook → settings sync.

---

## 13. Glossary

- **BYOK** — "Bring Your Own Key." The site owner supplies their own provider API key; the plugin makes the request directly from the WP server.
- **Managed** — The vendor-hosted service. The plugin calls a single endpoint; credits are tracked and billed centrally.
- **Form Schema** — The JSON structure defined in §2 that describes a form's fields.
- **Provider** — A class implementing `WP_AI_Forms\Ai\Provider` that knows how to talk to an LLM.
