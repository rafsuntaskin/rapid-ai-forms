# WP AI Forms — Technical Specification

**Version:** 0.1.0
**Status:** Draft
**Audience:** developers, integrators, and future contributors

This document specifies the data contracts, APIs, and behaviors of the WP AI Forms plugin. For agent-oriented conventions (file layout, autoloader rules, naming), see `CLAUDE.md`.

---

## 1. Product overview

WP AI Forms is a WordPress plugin that lets site owners build forms from natural-language prompts, then embed them anywhere via shortcodes (Gutenberg block planned).

### 1.1 AI modes

| Mode | Description | Configured by | Status |
|---|---|---|---|
| `byok` | Site owner brings their own API key. | `active_provider` + per-provider config | **MVP (v0.1)** |
| `managed` | Plugin calls the vendor's managed service, billed by credits. | `license_key` | Post-wp.org launch (v1.0). Code present, UI hidden. |

> **MVP scope.** v0.1 ships as **BYOK only**. The managed/credit-based path will be enabled once the plugin is live on wp.org and the backend service is GA. The PHP `Managed` provider and the backend contract (§5A) are documented now so the path is wired ahead of time, but the Settings UI does not expose `managed` mode in MVP.

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

## 5A. Managed service backend contract — POST-MVP (v1.0)

> **Not in MVP.** This section is documented now to lock in the wire protocol ahead of time, but no UI is shipped for the managed path in v0.1. The plugin will surface the managed-service Settings card and credit-balance UI once the hosted backend is live (see §12 Roadmap).

This section specifies the HTTP contract our hosted backend must implement so the plugin's `Managed` provider can talk to it. **The plugin never holds an LLM provider key.** It only holds a per-site `license_key`. The backend is responsible for authenticating the license, debiting credits, and proxying the actual LLM call using server-held provider keys.

### 5A.1 Trust model

```
[WP site]  ──Bearer license_key──▶  [Our backend]  ──provider key──▶  [LLM provider]
```

- The `license_key` is the only credential present in the plugin.
- A leaked license key only burns *that customer's* credits — there is no shared/global secret to compromise.
- License keys must be **per-site bindable**: when first used, the backend records the site URL (`X-Site-URL`) and may refuse later requests from a different origin (configurable per plan).
- License keys are revocable from the customer dashboard and must propagate revocation within ≤ 60s.

### 5A.2 Endpoint: generate form schema

`POST {endpoint}/v1/generate-form` — endpoint URL is filterable in the plugin via `wp_ai_forms_managed_endpoint`; the default is `https://api.example.com/v1/generate-form`.

**Request**

| Header | Value |
|---|---|
| `Authorization` | `Bearer {license_key}` |
| `Content-Type` | `application/json` |
| `X-Site-URL` | `home_url()` of the calling site |
| `X-Plugin-Version` | `WP_AI_FORMS_VERSION` (recommended, for telemetry) |
| `X-Idempotency-Key` | optional, ULID/UUID — if present, the backend MUST return the same response for repeated requests within 24h without re-debiting credits |

```json
{
  "prompt": "Contact form with name, email, and a message",
  "options": {
    "preferred_model": "fast",        // optional: "fast" | "balanced" | "best"
    "locale": "en_US"                 // optional: hint for label language
  }
}
```

**Successful response (200)**

```jsonc
{
  "schema": { /* Form Schema, §2 */ },
  "usage": {
    "credits_charged": 1,
    "credits_remaining": 248,
    "model_used": "claude-haiku-4-5"  // informational, never trusted by the plugin
  },
  "request_id": "req_01HXYZ..."       // echo this back in errors/support tickets
}
```

The plugin accepts either `{ schema }` directly or `{ text: "..." }` (where `text` is raw model output to be parsed by `Schema_Prompt::extract_schema()`). New backend implementations SHOULD return `schema` to avoid double-parsing on the WP side.

**Error responses**

| HTTP | Plugin maps to | Meaning |
|---|---|---|
| `400` | `wpaif_managed_error` | Malformed prompt, prompt too long, or schema generation failed validation server-side |
| `401` | `wpaif_managed_error` | License key invalid or revoked |
| `402` | `wpaif_no_credits` | License valid but out of credits |
| `403` | `wpaif_managed_error` | License is locked to a different site URL |
| `429` | `wpaif_managed_error` | Rate-limited; backend SHOULD include `Retry-After` |
| `5xx` | `wpaif_managed_error` | Backend or upstream provider failure |

Error body:
```json
{
  "code": "out_of_credits",
  "message": "Your account is out of credits. Top up at https://example.com/billing.",
  "request_id": "req_01HXYZ...",
  "details": { "credits_remaining": 0 }
}
```

### 5A.3 Endpoint: license introspection (optional but recommended)

`GET {endpoint}/v1/license` with `Authorization: Bearer {license_key}`.

```json
{
  "valid": true,
  "plan": "starter",
  "credits_remaining": 248,
  "credits_renew_at": "2026-06-01T00:00:00Z",
  "bound_site_url": "https://customer.com"
}
```

Used by the plugin's Settings page to display a live "credits remaining" badge. The plugin will call this at most once per page load and cache the result for 5 minutes via a transient.

**Plugin work needed:** add a Settings card that fetches and displays this — currently the plugin only stores the key and uses it on generate. Tracked in §12, v0.4.

### 5A.4 Webhook: credit balance changes (optional)

When a customer tops up credits or changes plan, the backend MAY POST to a plugin endpoint to refresh local state:

`POST /wp-json/wp-ai-forms/v1/managed/webhook` (to be added)

Body signed with `X-Signature: sha256={hmac}` where the HMAC secret is derived from the license key (so each site has a unique signing key the customer also controls). The plugin verifies and updates a cached `credits_remaining` transient.

This webhook is **not yet implemented**. Until then, the plugin polls `/v1/license`.

### 5A.5 Credit accounting rules

Rules the backend MUST enforce (the plugin can't):

1. **Atomic debit.** Credits are debited *before* the upstream LLM call is initiated. If the upstream call fails with a 5xx, credits are refunded. If it returns invalid JSON that fails schema validation, credits are refunded (one retry permitted at backend's discretion).
2. **Idempotency.** Requests carrying `X-Idempotency-Key` must produce identical responses for 24h with **at most one** debit.
3. **Rate limits.** Per-license rate limits return `429` with `Retry-After`.
4. **Abuse handling.** Repeated `400`s with unparseable prompts should not silently burn credits; after N failures the backend SHOULD `400` without invoking the LLM.

### 5A.6 What the backend implementation needs (out of scope for the plugin repo)

These belong in the separate `wp-ai-forms-backend` service, not this plugin:

- License issuance & billing (Stripe, Paddle, LemonSqueezy, etc.).
- Multi-provider routing (try Anthropic first, fall back to OpenAI on rate limit, etc.). Vercel AI Gateway is a natural fit here.
- Provider key vaulting (env vars on the host; never in source).
- Per-prompt audit log (for support and abuse review).
- Credit ledger with refundable debits.
- Webhook signing keys derived per-license.

### 5A.7 Security checklist before going live

- [ ] All upstream provider keys live only in backend env vars, never in any plugin artifact.
- [ ] License keys are at least 128 bits of entropy, prefixed for type detection (e.g. `wpaif_live_…`).
- [ ] License keys are hashed at rest (Argon2id / bcrypt) — never stored plaintext server-side.
- [ ] Site-URL binding enabled for paid plans.
- [ ] `429`s on per-license, per-IP, and global tiers.
- [ ] `X-Idempotency-Key` honored.
- [ ] All errors return a `request_id` to aid customer support.
- [ ] Webhook signature verification implemented before the webhook is announced.

---

## 5B. WordPress Abilities API integration

Starting with WordPress 6.9, the [Abilities API](https://developer.wordpress.org/apis/abilities-api/) provides a discovery registry for plugin capabilities. WP AI Forms registers its high-value verbs there so AI agents, automation tools, and other plugins can find and call them with input/output schema validation and capability checks.

Registration is guarded with `function_exists( 'wp_register_ability' )`, so the plugin still loads cleanly on WP < 6.9 — the abilities simply aren't published there.

### 5B.1 Category

```
wp-ai-forms — "AI Forms"
```

### 5B.2 Registered abilities

| Ability name | Purpose | Permission |
|---|---|---|
| `wp-ai-forms/generate-form-schema` | Turn a natural-language prompt into a Form Schema (§2). | `manage_options` |
| `wp-ai-forms/create-form` | Persist a form (with an optional pre-generated schema). | `manage_options` |
| `wp-ai-forms/list-forms` | Paginated form list including the shortcode string. | `manage_options` |
| `wp-ai-forms/get-form` | Fetch a single form by `id` or `uuid`. | `manage_options` |

Each ability carries a full `input_schema` / `output_schema` (JSON Schema) so callers can introspect what to send and what they'll get back.

### 5B.3 Extension point

After WP AI Forms registers its abilities, it fires:

```php
do_action( 'wp_ai_forms_abilities_registered' );
```

Other plugins can use this to register related abilities or extend the `wp-ai-forms` category (e.g. add `wp-ai-forms/export-submissions` from a companion plugin).

### 5B.4 Not done as abilities (intentional)

- **Public form submission.** Submissions are intentionally a public REST endpoint (`POST /submissions/{uuid}`), not an ability — anonymous visitors shouldn't need agent-tier permissions to fill in a contact form.
- **Settings management.** Provider credentials are a UI concern, not an agent-facing verb.

### 5B.5 Future: WP AI Client SDK adoption

The companion [WordPress AI Client SDK](https://make.wordpress.org/ai/2025/11/21/introducing-the-wordpress-ai-client-sdk/) (proposed for merge into WP 7.0 core) provides shared BYOK credential storage and a unified provider abstraction across plugins. Once it stabilizes (currently 0.1.0) or lands in core, our `Ai\Provider_Manager` and Settings BYOK UI can be swapped to consume the SDK — users would then manage AI keys once at the site level instead of per-plugin. Tracked in §12 (Roadmap).

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

### v0.1 — MVP (BYOK only)
- [x] BYOK with Anthropic, Gemini, OpenAI-compatible providers.
- [x] Form CRUD + custom DB tables.
- [x] Shortcode renderer + frontend submission.
- [x] React admin SPA.
- [x] Abilities API registration.
- [ ] Submit to wp.org plugin directory.
  *Done when:* the plugin passes wp.org review and is listed.

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

### v1.0 — Managed service GA (post wp.org launch)
- [ ] Managed backend live with credit billing (separate `wp-ai-forms-backend` service).
- [ ] Settings UI: re-enable mode selector, surface managed card with license key + live credit balance.
- [ ] Usage dashboard inside the plugin admin.
- [ ] Webhook receiver for billing → settings sync.
- [ ] Pre-launch security checklist (§5A.7) signed off.

### Future
- [ ] WP AI Client SDK adoption (once 7.0 lands or SDK stabilizes) — replace our `Provider_Manager` and BYOK Settings UI with the SDK's shared credential store. See §5B.5.

---

## 13. Glossary

- **BYOK** — "Bring Your Own Key." The site owner supplies their own provider API key; the plugin makes the request directly from the WP server.
- **Managed** — The vendor-hosted service. The plugin calls a single endpoint; credits are tracked and billed centrally.
- **Form Schema** — The JSON structure defined in §2 that describes a form's fields.
- **Provider** — A class implementing `WP_AI_Forms\Ai\Provider` that knows how to talk to an LLM.
