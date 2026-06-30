# Rapid AI Forms — Technical Specification

**Version:** 0.1.0
**Status:** Draft
**Audience:** developers, integrators, and future contributors

This document specifies the data contracts, APIs, and behaviors of the Rapid AI Forms plugin. For agent-oriented conventions (file layout, autoloader rules, naming), see `CLAUDE.md`.

---

## 1. Product overview

Rapid AI Forms is a WordPress plugin that lets site owners build forms from natural-language prompts, then embed them anywhere via the `[rapid_ai_form id="..."]` shortcode or the `rapid-ai-forms/form` Gutenberg block.

### 1.1 AI modes

| Mode | Description | Configured by | Status |
|---|---|---|---|
| `byok` | Site owner brings their own API key. | `active_provider` + per-provider config | **MVP (v0.1)** |
| `managed` | Plugin calls the vendor's managed service, billed by credits. | `license_key` | Post-wp.org launch (v1.0). Code present, UI hidden. |

> **MVP scope.** v0.1 ships as **BYOK only**. The managed/credit-based path will be enabled once the plugin is live on wp.org and the backend service is GA. The PHP `Managed` provider and the backend contract (§5A) are documented now so the path is wired ahead of time, but the Settings UI does not expose `managed` mode in MVP.

### 1.2 Goals
- Generate working form schemas from a single natural-language prompt.
- Render forms via the `[rapid_ai_form id="..."]` shortcode or the `rapid-ai-forms/form` block (both server-rendered through `Form_Renderer`).
- Store submissions in dedicated DB tables for querying and export.
- Be extensible: third parties can register additional AI providers.

### 1.3 Non-goals (v0.1)
- Payment forms, conditional logic, multi-step wizards.
- File uploads, signature fields, repeaters.
- Front-end form editing.

---

## 2. Form Schema JSON contract

This is the canonical shape that AI providers must produce and that the editor/renderer consume. It lives in the `form_schema` column of `{prefix}rapid_ai_forms` (JSON-encoded).

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
      "default_value": "",        // only for "hidden" fields; ignored elsewhere
      "options": [                // only when type is "select", "radio", or "checkbox_group"
        { "label": "Yes", "value": "yes" }
      ]
    }
  ],
  "notifications": {              // per-form email notification config
    "enabled": true,
    "to": "admin@example.com",    // comma-separated; empty falls back to admin_email at send time
    "subject": "",                // mail-tag template; empty = "New submission: {form_title}"
    "body": "",                   // mail-tag template; empty = "{all_fields}"
    "reply_to_field": ""          // name of an email field whose value becomes Reply-To
  }
}
```

### 2.2 Allowed field types

`text`, `email`, `tel`, `url`, `number`, `date`, `password`, `hidden`, `textarea`, `select`, `radio`, `checkbox`, `checkbox_group`.

Any unknown type is coerced to `text` by `Schema_Prompt::sanitize_schema()`.

### 2.3 Validation rules
- `name` is required; fields without `name` are dropped during sanitization.
- `name` is run through `sanitize_key()` — it must be lowercase, alphanumeric, and may contain underscores/hyphens.
- `submit_label` defaults to `"Submit"` if missing.
- `options` is only kept for `select`, `radio`, and `checkbox_group`.
- Every `option` produces `{ label, value }`; if `value` is missing, `label` is used.
- `default_value` is only retained for `hidden` fields. Used as the server-trusted submission value (never read from the client).
- `notifications` is sanitized on every save:
  - `enabled` defaults to `true`.
  - `to` / `subject` / `reply_to_field` → `sanitize_text_field` / `sanitize_key`.
  - `body` → `sanitize_textarea_field`.
  - On **create**, an empty `to` is seeded with `get_option('admin_email')` so the editor surfaces a sensible default.

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

### 3.1 `{prefix}rapid_ai_forms`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `uuid` | `VARCHAR(36)` | unique; used for public submission endpoint |
| `title` | `VARCHAR(255)` | |
| `status` | `VARCHAR(20)` | `draft` \| `published`. **Decorative in v0.1** — the UI does not expose a status control and the shortcode renders regardless. Column retained for future use (e.g. `archived`). |
| `schema` | `LONGTEXT` | JSON-encoded Form Schema |
| `settings` | `LONGTEXT` | JSON, per-form settings. Currently: `custom_css` (string, ≤50 KB, sanitized through `Css_Sanitizer` on every write — see §6.5) |
| `ai_prompt` | `LONGTEXT NULL` | last prompt used to generate this form |
| `author_id` | `BIGINT UNSIGNED` | WP user id (0 if none) |
| `created_at` | `DATETIME` | UTC |
| `updated_at` | `DATETIME` | UTC, auto-updates |

Indexes: `uuid` (unique), `status`, `author_id`.

### 3.2 `{prefix}rapid_ai_form_submissions`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `form_id` | `BIGINT UNSIGNED` | FK to `rapid_ai_forms.id` (not enforced at DB level) |
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
| `rapid_ai_forms_version` | Currently installed plugin version. |
| `rapid_ai_forms_db_version` | Schema version, used to trigger migrations. |
| `rapid_ai_forms_ai_settings` | AI mode + provider configs (see §5.1). |

### 3.4 Migration policy
- `Schema::install()` runs on activation via `dbDelta()`.
- DB version is stored in `rapid_ai_forms_db_version`. `Plugin::maybe_migrate()` (called on `plugins_loaded`) compares the stored value against `Schema::DB_VERSION` and re-runs `dbDelta()` when they diverge. `dbDelta()` is additive — bumping for new columns or indexes works; renaming or dropping columns requires a manual migration helper (not yet needed).
- Current `Schema::DB_VERSION` = **`1.0.3`**. The table prefix moved twice during pre-wp.org rebranding (initial `{prefix}ai_forms` → `{prefix}easy_ai_forms` at 1.0.2 → `{prefix}rapid_ai_forms` at 1.0.3). No public release used the older names, so no upgrade-path helper is needed. For any **post-launch** schema change, write a one-shot migration helper next to `Schema::install()` and gate it on the stored version.

---

## 4. REST API

Base URL: `/wp-json/rapid-ai-forms/v1/`
Authentication: WP cookie + `X-WP-Nonce` header for admin endpoints. Submission endpoint is public.

### 4.1 Capability matrix

| Endpoint | Method | Capability |
|---|---|---|
| `/forms` | GET, POST | `manage_options` |
| `/forms/{id}` | GET, PUT, DELETE | `manage_options` |
| `/forms-list` | GET | `edit_posts` (block form picker — id+title only) |
| `/ai/generate` | POST | `manage_options` |
| `/ai/verify` | POST | `manage_options` |
| `/ai/style` | POST | `manage_options` |
| `/settings` | GET, PUT | `manage_options` |
| `/submissions` | GET | `manage_options` |
| `/submissions/{uuid}` | POST | public (with payload cap, see §4.2) |

### 4.2 Endpoints

#### `GET /forms`
Query params: `page` (default 1), `per_page` (default 20, max 100), `search` (LIKE on `title` and `uuid`, optional).
Returns: array of form objects (see §4.3). Response headers carry `X-WP-Total` (total count after search) and `X-WP-TotalPages` (computed against `per_page`), mirroring WP core's `wp/v2` collection convention so the admin UI can render pagination without parsing a custom envelope.

#### `POST /forms`
Body: partial form object (`title`, `status`, `schema`, `settings`, `ai_prompt`). Schema is normalized through `Schema_Prompt::sanitize_schema()` on insert, and the notifications block is seeded with `admin_email` when `to` is empty.
Returns: created form object.

#### `GET /forms/{id}`
Returns: form object or `404 raif_not_found`.

#### `PUT /forms/{id}`
Body: any subset of `title`, `status`, `schema`, `settings`, `ai_prompt`. Schema is normalized through `Schema_Prompt::sanitize_schema()` when present.
Returns: updated form object.

#### `DELETE /forms/{id}`
Returns: `{ "deleted": true }`.

#### `GET /forms-list`
Capability: `edit_posts` (not `manage_options`) so any content editor can pick a form in the block editor without admin rights. Returns a compact `[{ id, title }]` list — no schema, settings, or submission data. Backs the `rapid-ai-forms/form` block's form picker. The block itself is a dynamic block (`save → null`, PHP `render_callback`) that reuses `Form_Renderer`, so its editor preview (`ServerSideRender`) and front-end output match the shortcode exactly.

#### `POST /ai/generate`
Body: `{ "prompt": "string", "current_schema": { /* optional */ } }`.
When `current_schema.fields` is non-empty, the provider treats the call as an **edit** and is instructed to preserve existing fields/labels/options unless explicitly asked to change them; otherwise it's a from-scratch generation.
Returns: a sanitized Form Schema (§2). On failure returns `WP_Error` with HTTP 400.
Error codes: `raif_no_provider`, `raif_missing_key`, `raif_empty_response`, `raif_invalid_json`, plus provider-specific (`raif_anthropic_error`, `raif_gemini_error`, `raif_openai_error`, `raif_wp_ai_client_*`).

#### `POST /ai/verify`
Body: `{ "provider": "anthropic|gemini|openai_compatible|wp_ai_client", "api_key": "...", "base_url": "...", "model": "..." }`. Any empty field falls back to the saved value, so an admin can verify before saving.
Returns: `{ "ok": true, "latency_ms": 412 }` on success. On failure returns a `WP_Error` with `latency_ms` attached. Used by the Settings UI to gate Save until the credentials are confirmed working. For `wp_ai_client`, "verified" means at least one configured connector reports `is_supported_for_text_generation()` is true.

#### `POST /ai/style`
Body: `{ "form_id": 42, "prompt": "string", "current_css": "/* optional */" }`.
Generates/edits the form's custom CSS via the active provider's `generate_text()`. `Css_Prompt` assembles the model context server-side: the request, the current CSS, the rendered form HTML (real selectors), a documented selector list, and theme.json design tokens (`wp_get_global_settings()` palette/fonts/spacing) so output matches the site. The system prompt requires **nested rules relative to the form root** because the renderer wraps the CSS in the `data-form-uuid` scope (§6.5).
Returns: `{ "css": "..." }` — already fence-stripped, sanitized (`Css_Sanitizer`), and brace-validated. The client fills the editor; persisting still goes through `PUT /forms/{id}`.
Error codes: `raif_not_found` (404), `raif_empty_css`, `raif_invalid_css`, `raif_not_supported`, plus the provider errors listed under `/ai/generate` (all 400).

#### `GET /submissions`
Query params: `page` (default 1), `per_page` (default 20, max 100), `form_id` (optional filter).
Returns: array of submission rows across **all** forms, newest first. Each row carries decoded `data`/`meta`, a `form_title` (LEFT JOIN against the forms table), and `email` — the notification subject/body rendered by `Email_Notifier::compose()` using the form's **current** template (`null` when notifications are disabled). Headers carry `X-WP-Total` / `X-WP-TotalPages` like `GET /forms`.

#### `GET /settings`
Returns the settings object (§5.1) with **secrets stripped**: `api_key` is always an empty string; `api_key_set` indicates whether a secret is stored. Each provider also carries a generic `configured` boolean:
- BYOK providers: `configured === api_key_set`.
- `wp_ai_client`: `configured === Wp_Ai_Client::is_available()` (the core AI Client functions exist and `wp_supports_ai()` returns true). The plugin holds no key for this provider.

The response also includes `available_providers: [{ key, label }, ...]`. Providers are only registered on hosts that support them, so `wp_ai_client` only appears on WordPress 7.0+.

#### `PUT /settings`
Body: `{ active_provider, providers: { ... } }`.
Empty `api_key` strings are **ignored** (existing value preserved). Non-empty strings replace.
Returns: the same shape as `GET /settings`.

#### `POST /submissions/{uuid}`
Public. Body: arbitrary key/value pairs matching the form's `fields[].name`.
Behavior:
1. Reject payloads larger than `Rest_Controller::MAX_SUBMISSION_BYTES` (64 KB) before any work — returns `413 raif_payload_too_large`.
2. Look up the form by UUID; 404 if missing.
3. Iterate `fields`; for each known `name`, sanitize the incoming value by type:
   - `email` → `sanitize_email`
   - `url` → `esc_url_raw`
   - `textarea` → `sanitize_textarea_field`
   - `number` → numeric coercion (or `null`)
   - `checkbox_group` → values are intersected against the field's option allowlist
   - `hidden` → ignored from the client; the value is read from `field.default_value` on the server
   - everything else → `sanitize_text_field` (array values are mapped)
4. Unknown keys are dropped.
5. **Validate required fields** (`Rest_Controller::validate_required()`): every schema field with `required: true` must be non-empty *after* sanitization (so `email`/`url` that sanitized to `''`, `number` that coerced to `null`, and `checkbox_group` that intersected to `[]` all count as missing). Hidden fields are exempt (server-populated). On failure: `422 raif_validation` with `data.fields = { name: message }` — **no row is written**. The renderer emits `required` + `aria-required="true"` and the frontend shows inline `.raif-field-error` messages from the 422.
6. Insert into `{prefix}rapid_ai_form_submissions`.
7. Fire `do_action( 'rapid_ai_forms_submission_created', $submission_id, $form, $data )` — the built-in `Email_Notifier` listens at priority 10.

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

### 5.1 Settings storage (`rapid_ai_forms_ai_settings`)

```jsonc
{
  "active_provider": "openai_compatible",
  "providers": {
    "anthropic":         { "api_key": "...", "model": "claude-sonnet-4-6" },
    "gemini":            { "api_key": "...", "model": "gemini-2.0-flash" },
    "openai_compatible": { "api_key": "...", "base_url": "https://api.openai.com/v1", "model": "gpt-4o-mini" },
    "wp_ai_client":      {}              // present only on WP 7.0+; holds no credentials
  }
}
```

Stored with `update_option(..., false)` (autoload off) so provider keys aren't loaded on every page render — only when an admin invokes AI features.

### 5.2 Provider contract

```php
interface Provider {
  public function key(): string;     // stable identifier, e.g. "anthropic"
  public function label(): string;   // human-readable name
  public function generate_form_schema( string $prompt, array $options = [] ); // array | WP_Error
  public function generate_text( string $system, string $prompt, array $options = [] ); // string | WP_Error
  public function verify( array $options = [] );                                // true | WP_Error
}
```

**Behavior requirements:**
- `generate_form_schema()` must return either an array conforming to the Form Schema (§2) or a `WP_Error`.
- Must call `Schema_Prompt::system()` as the system instruction.
- Must funnel raw model text through `Schema_Prompt::extract_schema()` for validation/sanitization.
- `generate_text()` is the free-form path (caller supplies the system prompt; used by the AI CSS editor). Built-in providers implement both through one shared HTTP helper, with JSON mode (`response_format` / `responseMimeType` / `as_json_response`) enabled only for schema generation. `Provider_Manager::generate_text()` guards with `method_exists()` so third-party providers written before this method existed degrade to `raif_not_supported` instead of fataling.
- Must propagate HTTP failures as `WP_Error` (do not throw).
- HTTP timeout: 60s recommended (current default).
- `verify()` must perform the cheapest possible round-trip that proves the credentials/connector work — typically a `GET /models` style probe. Returns `true` on success, `WP_Error` otherwise. The REST `/ai/verify` endpoint calls this and reports `latency_ms` back to the client.

### 5.3 Built-in providers

| Key | Endpoint | Auth | Notes |
|---|---|---|---|
| `anthropic` | `https://api.anthropic.com/v1/messages` | `x-api-key` header | `anthropic-version: 2023-06-01` |
| `gemini` | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` | `?key=` query | Uses `responseMimeType: application/json` |
| `openai_compatible` | `{base_url}/chat/completions` | `Authorization: Bearer` (optional) | OpenAI, OpenRouter, Groq, Ollama, LM Studio, etc. Uses `response_format: { type: json_object }`. |
| `wp_ai_client` | — (delegates to core `wp_ai_client_prompt()`) | core Connectors (Settings → Connectors) | **WP 7.0+ only.** No plugin-held credentials. Registered iff `function_exists('wp_ai_client_prompt')` and `wp_supports_ai()`. Settings UI special-cases this provider to show a Notice instead of API key / model / base URL fields. |

### 5.4 Email notifications

On `rapid_ai_forms_submission_created`, `Email_Notifier::maybe_send()` reads `schema.notifications` and, if `enabled`, sends a `wp_mail()` to the configured recipients.

Supported mail-tags inside `subject` / `body`:

- `{all_fields}` — formatted `Label: value` lines for every field
- `{<field_name>}` — value of a specific field (e.g. `{full_name}`, `{email}`)
- `{form_title}`, `{site_name}`, `{site_url}`, `{admin_email}`

The empty `to` field falls back to `get_option('admin_email')` at send time (and is seeded with the same value on form create). If `reply_to_field` is set and the submission contains a valid email at that field, a `Reply-To` header is added.

Filterable hooks:

```php
apply_filters( 'rapid_ai_forms_send_notification_email', $send, $submission_id, $form, $data );
apply_filters( 'rapid_ai_forms_notification_recipients', $recipients, $form, $data );
apply_filters( 'rapid_ai_forms_notification_subject',    $subject,    $form, $data );
apply_filters( 'rapid_ai_forms_notification_body',       $body,       $form, $data );
apply_filters( 'rapid_ai_forms_notification_headers',    $headers,    $form, $data );
```

### 5.5 Extension points

```php
// Register a custom provider:
add_action( 'rapid_ai_forms_register_providers', function ( $manager ) {
    $manager->register( new My_Custom_Provider() );
} );

// Point the managed client at your own backend:
add_filter( 'rapid_ai_forms_managed_endpoint', fn() => 'https://my-saas.example/v1/generate-form' );

// Observe submissions:
add_action( 'rapid_ai_forms_submission_created', function ( $id, $form, $data ) {
    // send email, sync to CRM, etc.
}, 10, 3 );
```

---

## 5A. Managed service backend contract — Rapid AI Cloud

> **Status (2026-06).** The **free tier** (v0.3) is implemented: the `Managed` provider, the domain-verification handshake, the Settings card, and the backend service all exist — see [`PLAN-rapid-ai-cloud.md`](PLAN-rapid-ai-cloud.md) for the build state and the backend repo (`rapid-ai-cloud`) for the implementation. It ships **gated off** behind `rapid_ai_forms_managed_enabled` until post wp.org launch. The **paid credits** path (§5A.2–5A.6 below, license-key framing) is the original design that the free tier evolved from; purchased credits now layer onto the same ledger. §5A.8 documents the **implemented** endpoints.

This section specifies the HTTP contract the hosted backend implements so the plugin's `Managed` provider can talk to it. **The plugin never holds an LLM provider key.** For the free tier it holds a per-site **bearer token** issued by the domain-verification handshake (§5A.8); the backend authenticates the token, debits generations (free pool first, then purchased), and proxies the LLM call using server-held provider keys.

### 5A.1 Trust model

```
[WP site]  ──Bearer site_token──▶  [Our backend]  ──provider key──▶  [LLM provider]
                  (issued by the domain-verification handshake, §5A.8)
```

- The site-bound token is the only credential present in the plugin; it is obtained by proving domain ownership, not by a purchase.
- A leaked token can at worst burn *that one site's* quota — there is no shared/global secret to compromise.
- Tokens are **per-site bound**: the backend records the site URL at registration and rejects requests whose `X-Site-URL` doesn't match (§5A.8).
- Re-registering a domain rotates the token (the old one is revoked); the same domain proof is required, so rotation is safe.
- **Hybrid accounts:** the free tier needs no signup. Buying more attaches purchases to a *website account* the owner links via a claim code (§5A.8) — the plugin stays friction-free and wp.org-clean (no "buy/upgrade" copy).

### 5A.2 Endpoint: generate form schema

`POST {endpoint}/v1/generate-form` — endpoint URL is filterable in the plugin via `rapid_ai_forms_managed_endpoint`; the default is `https://api.example.com/v1/generate-form`.

**Request**

| Header | Value |
|---|---|
| `Authorization` | `Bearer {license_key}` |
| `Content-Type` | `application/json` |
| `X-Site-URL` | `home_url()` of the calling site |
| `X-Plugin-Version` | `RAPID_AI_FORMS_VERSION` (recommended, for telemetry) |
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
| `400` | `raif_managed_error` | Malformed prompt, prompt too long, or schema generation failed validation server-side |
| `401` | `raif_managed_error` | License key invalid or revoked |
| `402` | `raif_no_credits` | License valid but out of credits |
| `403` | `raif_managed_error` | License is locked to a different site URL |
| `429` | `raif_managed_error` | Rate-limited; backend SHOULD include `Retry-After` |
| `5xx` | `raif_managed_error` | Backend or upstream provider failure |

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

`POST /wp-json/rapid-ai-forms/v1/managed/webhook` (to be added)

Body signed with `X-Signature: sha256={hmac}` where the HMAC secret is derived from the license key (so each site has a unique signing key the customer also controls). The plugin verifies and updates a cached `credits_remaining` transient.

This webhook is **not yet implemented**. Until then, the plugin polls `/v1/license`.

### 5A.5 Credit accounting rules

Rules the backend MUST enforce (the plugin can't):

1. **Atomic debit.** Credits are debited *before* the upstream LLM call is initiated. If the upstream call fails with a 5xx, credits are refunded. If it returns invalid JSON that fails schema validation, credits are refunded (one retry permitted at backend's discretion).
2. **Idempotency.** Requests carrying `X-Idempotency-Key` must produce identical responses for 24h with **at most one** debit.
3. **Rate limits.** Per-license rate limits return `429` with `Retry-After`.
4. **Abuse handling.** Repeated `400`s with unparseable prompts should not silently burn credits; after N failures the backend SHOULD `400` without invoking the LLM.

### 5A.6 What the backend implementation needs (out of scope for the plugin repo)

These belong in the separate `rapid-ai-forms-backend` service, not this plugin:

- License issuance & billing (Stripe, Paddle, LemonSqueezy, etc.).
- Multi-provider routing (try Anthropic first, fall back to OpenAI on rate limit, etc.). Vercel AI Gateway is a natural fit here.
- Provider key vaulting (env vars on the host; never in source).
- Per-prompt audit log (for support and abuse review).
- Credit ledger with refundable debits.
- Webhook signing keys derived per-license.

### 5A.7 Security checklist before going live

- [ ] All upstream provider keys live only in backend env vars, never in any plugin artifact.
- [ ] License keys are at least 128 bits of entropy, prefixed for type detection (e.g. `raif_live_…`).
- [ ] License keys are hashed at rest (Argon2id / bcrypt) — never stored plaintext server-side.
- [ ] Site-URL binding enabled for paid plans.
- [x] `429`s on per-license, per-IP, and global tiers. Rate limiter is **Upstash Redis-backed** (REST) so limits hold across serverless instances; fails open to a per-instance memory window if Redis is unreachable. Set `UPSTASH_REDIS_REST_URL`/`_TOKEN` in prod.
- [x] `X-Idempotency-Key` honored.
- [x] All errors return a `request_id` to aid customer support.
- [x] Webhook signature verification implemented before the webhook is announced. Both `order_created` (credit) and `order_refunded` (idempotent clawback) are handled.

### 5A.8 Implemented endpoints (free tier — v0.3)

The shipped contract. Endpoint base is filterable via `rapid_ai_forms_managed_endpoint`; the plugin sends `Authorization: Bearer {site_token}`, `X-Site-URL: home_url()`, and `X-Plugin-Version` on authenticated calls.

**Handshake (proves domain ownership; no signup)**

1. `POST {endpoint}/v1/register` `{ home_url, nonce, plugin_version }` — backend calls back `GET {home_url}/wp-json/rapid-ai-forms/v1/managed/verify?nonce=…` (SSRF-guarded: public IPs, standard ports, no redirects). On a single-use nonce match the plugin returns `{ ok: true }` and the backend issues `{ token }` (sha256-hashed at rest). Unreachable/loopback sites fail → plugin steers to BYOK.

**Generation (Bearer + `X-Site-URL` binding)**

- `POST /v1/generate-form` `{ prompt }` → `{ schema | text, usage, request_id }`.
- `POST /v1/generate-text` `{ system, prompt }` → `{ text, usage, request_id }` (powers the AI CSS editor).
- Atomic debit (free pool → purchased) with refund on 5xx/invalid output; `X-Idempotency-Key` honored 24h; per-site rate limit + consecutive-failure breaker.
- Errors map in `Managed::map_error()`: `401`→`raif_reconnect`, `402/429`→`raif_quota_reached` (neutral copy, shown verbatim), `5xx`→`raif_managed_server`.

**Status & account linking (hybrid model)**

- `GET /v1/status` → `{ valid, free_remaining, free_allowance, free_renews_at, purchased_remaining, account_linked, manage_url }`. When unlinked, `manage_url` is a fresh `/claim?code=…` link. Plugin caches it 5 min (transient), busted after a generation.
- `POST /v1/claim-code` (Bearer) → `{ code, claim_url }` — 15-min single-use code.
- `POST /v1/claim` `{ code, email }` → links the site to an account (created/reused by email); also the dashboard's `GET /claim?code=` does this against the signed-in session.
- Dashboard + passwordless auth (`POST /v1/auth/request`, `GET /auth/verify`, `GET /dashboard`) are served by the backend app; see the `rapid-ai-cloud` README.
- **Usage emails:** 80%/100% free-pool alerts fire to linked accounts (once per threshold per month).
- **Billing (LemonSqueezy):** `POST /v1/billing/checkout` (session) creates a hosted checkout; `POST /v1/billing/webhook` (HMAC-verified) credits the site once on `order_created` and claws credits back on `order_refunded` (both idempotent), as `purchased` ledger rows — so the plugin's quota meter reflects purchases and refunds with **no plugin changes**. Subscribe the webhook to both events. Scaffolded; needs live store keys for the real checkout e2e. See `rapid-ai-cloud/docs/lemonsqueezy.md`.

Balances are **computed from the ledger** (no stored totals); the monthly free reset is just the calendar window; purchased units never expire.

---

## 5B. WordPress Abilities API integration

Starting with WordPress 6.9, the [Abilities API](https://developer.wordpress.org/apis/abilities-api/) provides a discovery registry for plugin capabilities. Rapid AI Forms registers its high-value verbs there so AI agents, automation tools, and other plugins can find and call them with input/output schema validation and capability checks.

Registration is guarded with `function_exists( 'wp_register_ability' )`, so the plugin still loads cleanly on WP < 6.9 — the abilities simply aren't published there.

### 5B.1 Category

```
rapid-ai-forms — "AI Forms"
```

### 5B.2 Registered abilities

| Ability name | Purpose | Permission |
|---|---|---|
| `rapid-ai-forms/generate-form-schema` | Turn a natural-language prompt into a Form Schema (§2). | `manage_options` |
| `rapid-ai-forms/create-form` | Persist a form (with an optional pre-generated schema). | `manage_options` |
| `rapid-ai-forms/list-forms` | Paginated form list including the shortcode string. | `manage_options` |
| `rapid-ai-forms/get-form` | Fetch a single form by `id` or `uuid`. | `manage_options` |

Each ability carries a full `input_schema` / `output_schema` (JSON Schema) so callers can introspect what to send and what they'll get back.

### 5B.3 Extension point

After Rapid AI Forms registers its abilities, it fires:

```php
do_action( 'rapid_ai_forms_abilities_registered' );
```

Other plugins can use this to register related abilities or extend the `rapid-ai-forms` category (e.g. add `rapid-ai-forms/export-submissions` from a companion plugin).

### 5B.4 Not done as abilities (intentional)

- **Public form submission.** Submissions are intentionally a public REST endpoint (`POST /submissions/{uuid}`), not an ability — anonymous visitors shouldn't need agent-tier permissions to fill in a contact form.
- **Settings management.** Provider credentials are a UI concern, not an agent-facing verb.

### 5B.5 Future: WP AI Client SDK adoption

The companion [WordPress AI Client SDK](https://make.wordpress.org/ai/2025/11/21/introducing-the-wordpress-ai-client-sdk/) (proposed for merge into WP 7.0 core) provides shared BYOK credential storage and a unified provider abstraction across plugins. Once it stabilizes (currently 0.1.0) or lands in core, our `Ai\Provider_Manager` and Settings BYOK UI can be swapped to consume the SDK — users would then manage AI keys once at the site level instead of per-plugin. Tracked in §12 (Roadmap).

---

## 6. Frontend rendering

### 6.1 Shortcode

```
[rapid_ai_form id="42"]
[rapid_ai_form uuid="..."]
```

`id` and `uuid` are mutually exclusive (uuid wins if both supplied). Returns an empty string if the form is not found.

### 6.2 HTML contract

The PHP renderer emits a `<form class="raif-form" data-form-uuid="..." data-nonce="...">` element. Frontend JS (`build/frontend.js`) auto-binds submission for every `form.raif-form` not yet flagged with `data-raif-bound`.

### 6.3 Client behavior
- On submit, JS collects `FormData`, POSTs JSON to `/submissions/{uuid}`, includes the nonce as `X-WP-Nonce`.
- On success: form is reset and a success message is shown in `.raif-form__message`.
- On error: error message shown; submit button re-enabled.

### 6.4 Styling

All classes prefixed with `raif-`. Default styles are minimal and intended to be overridable by the theme.

### 6.5 Per-form custom CSS

`settings.custom_css` is emitted by `Form_Renderer` as a scoped style block immediately before the `<form>`:

```html
<style id="raif-css-{uuid}">
  .raif-form[data-form-uuid="{uuid}"] {
    /* custom_css, verbatim */
  }
</style>
```

The CSS-nesting wrapper scopes even un-prefixed rules to this one form instance. `Css_Sanitizer` runs on every write (`Form_Repository`) **and** again at render: 50 KB cap, then strips `</style`, `<script`, `javascript:`, `expression(`, `@import`, `behavior:` case-insensitively until stable (so split tokens can't reassemble). `Css_Sanitizer::validate()` adds a brace-balance check used by the AI endpoint. Trust model matches the Customizer's Additional CSS — admins may write arbitrary (safe) CSS.

### 6.6 Admin preview route

`GET /?rapid_ai_form_preview={id}` (query var registered by `Frontend\Preview`):
- `manage_options` required — anonymous gets a bare 403; missing form → 404.
- Outputs a minimal document that still runs `wp_head()` / `wp_footer()`, so the active theme's CSS applies — the editor iframes this for a faithful frontend preview.
- Sent with `nocache_headers()` and `X-Frame-Options: SAMEORIGIN`; `<meta name="robots" content="noindex, nofollow">`.
- The Styling panel live-injects CSS edits into the iframe's scoped style element (same-origin) after a 600 ms debounce; saving the form reloads the frame.

---

## 7. Admin SPA

- Mounted in `wp-admin` under menu slug `rapid-ai-forms` (capability `manage_options`), with submenus **Forms** (`rapid-ai-forms`), **Submissions** (`rapid-ai-forms-submissions`), and **Settings** (`rapid-ai-forms-settings`) — the `?page=` slug picks the top-level view.
- Single root: `#rapid-ai-forms-admin-root`.
- Hash-based routing within the Forms page: `#/` (forms list), `#/forms/{id}` (editor).
- The Submissions page reads an optional `&form_id=` query param as its initial filter (the Forms list's "Submissions" button deep-links with it).
- All React via `@wordpress/element` only — no separate React dependency.
- UI primitives from `@wordpress/components`.

### 7.1 Portable shared kit (`src/shared/`)

Rules (enforced by convention, see `src/shared/README.md`):
1. May depend only on `@wordpress/*` packages.
2. May not read plugin globals (`RAPID_AI_FORMS_ADMIN`, etc.) — accept config as props/args.
3. Feature folders (`src/admin/*`, `src/frontend/*`) may import from `shared/`; the reverse is forbidden.

Eventual plan: publish as `@your-org/wp-react-kit` and consume across plugins via npm.

---

## 8. Security model

- All management endpoints require `manage_options`.
- Submission endpoint is intentionally public; rate limiting and spam protection are **out of scope for v0.1** and tracked on the roadmap.
- Secrets (`api_key`, `license_key`) are never returned over the REST API; only `*_set` booleans.
- All inputs go through WP sanitization functions (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`, `sanitize_textarea_field`, `sanitize_key`).
- AI-generated schemas are passed through `Schema_Prompt::sanitize_schema()` — never trusted raw.
- AI-generated CSS is passed through `Css_Prompt::extract_css()` → `Css_Sanitizer` — same sanitizer as human-typed CSS (§6.5), and the render-time scope wrapper means a form's CSS cannot affect anything outside that form.
- Required-field validation is enforced server-side before any submission row is written (§4.2).
- The preview route is capability-gated (`manage_options`) and framed same-origin only (§6.6).
- Nonces (`wp_rest`) are required for the admin SPA's REST calls.

### 8.1 Known gaps (planned)
- No rate limiting on `/submissions/{uuid}`.
- No CAPTCHA / honeypot.
- No CSRF protection on the public submission endpoint beyond the per-form nonce (which is short-lived). Anonymous submissions may use a generated nonce that doesn't tie to a user session.
- No audit log for settings changes.

---

## 9. Internationalization

- Text domain: `rapid-ai-forms`.
- All user-visible strings in PHP use `__()` / `esc_html__()` / `_e()`.
- JS uses `@wordpress/i18n` (`__`) with `wp_set_script_translations()` registered for the admin bundle.
- `.pot` lives at `languages/rapid-ai-forms.pot` and is regenerated via:
  ```
  wp i18n make-pot . languages/rapid-ai-forms.pot --domain=rapid-ai-forms --exclude=build,node_modules,docs,vendor,bin,dist
  ```
- `load_plugin_textdomain()` is **not called** — WordPress.org auto-loads translations for hosted plugins (WP 4.6+).

---

## 10. Build & release

| Task | Command |
|---|---|
| Install JS deps | `npm install` |
| Install PHP dev deps | `composer install` |
| Production build | `npm run build` |
| Dev watch | `npm run start` |
| Lint PHP (WPCS 3.1) | `composer lint` (auto-fix: `composer lint:fix`) |
| Start test env (Docker) | `npx wp-env start` |
| Run PHP tests (WP test suite) | `npm run test:php` (PHPUnit 9.6 + `WP_UnitTestCase`; tests in `tests/test-*.php`) |
| Regenerate POT | `wp i18n make-pot . languages/rapid-ai-forms.pot --domain=rapid-ai-forms --exclude=build,node_modules,docs,vendor,bin,dist` |
| Build wp.org dist zip | `npm run dist` → `dist/rapid-ai-forms.zip` (honors `.distignore`) |
| Copy dist to local plugins dir | `bash bin/dist.sh --to ~/Dev/lando/sites/wooDev/wp-content/plugins --no-build` |
| Run wp.org Plugin Check | `lando wp plugin check rapid-ai-forms` (against the installed copy) |

Build outputs:
- `build/admin.js`, `build/admin.css`, `build/admin.asset.php`
- `build/frontend.js`, `build/frontend.css`, `build/frontend.asset.php`

The PHP loaders fall back to a sensible default dependency list if `*.asset.php` is missing, so a fresh checkout doesn't fatal — but assets won't be enqueued until you run `npm run build`.

---

## 11. Versioning

- Plugin version: `RAPID_AI_FORMS_VERSION` constant in `rapid-ai-forms.php`.
- DB schema version: `Schema::DB_VERSION`. Bump when columns change; migration runner is planned.
- Public REST namespace: `rapid-ai-forms/v1`. Breaking changes will move to `/v2`.
- Form Schema contract: changes that drop or rename top-level keys are breaking. Adding optional fields is allowed.

---

## 12. Roadmap (with acceptance criteria)

### v0.1 — MVP (BYOK + core AI Client)
- [x] BYOK with Anthropic, Gemini, OpenAI-compatible providers.
- [x] WordPress AI Client provider (WP 7.0+, uses core Settings → Connectors).
- [x] Form CRUD + custom DB tables.
- [x] Shortcode renderer + frontend submission.
- [x] React admin SPA with paginated forms list, server-side search, and field reorder.
- [x] Abilities API registration.
- [x] Per-form email notifications with mail-tag templating + Reply-To.
- [x] DB migration runner (`Plugin::maybe_migrate()`).
- [x] One-click credential verification.
- [x] Submit to wp.org plugin directory. **Shipped (2026-06):** approved, first SVN release committed (`trunk/` + `tags/0.1.0/`), live at https://wordpress.org/plugins/rapid-ai-forms/.

### v0.2 — Operability + Styling (feature-complete, unreleased)
- [x] **Required-field server-side enforcement** (422 + field-level errors; no junk rows). See [docs/PLAN-submission-integrity.md](PLAN-submission-integrity.md) Part A.
- [x] Submissions admin page: dedicated submenu, cross-form list with form filter, relative timestamps, message/summary excerpt, detail modal (all fields, IP, UA, rendered notification email). Backed by `GET /submissions`.
- [x] **AI-driven per-form CSS editor with live iframe preview** — pulled forward from v0.3. See [docs/PLAN-ai-css-editor.md](PLAN-ai-css-editor.md).
- [x] PHPUnit on the official WP test suite via wp-env (30 tests covering the above).
- [ ] CSV/JSON export of submissions and date filtering — deferred (not blocking 0.2.0).

### v0.3 — Anti-abuse — ✅ shipped (see `Forms\Submission_Guard`, [docs/PLAN-submission-integrity.md](PLAN-submission-integrity.md) Part B)
- [x] **Submission-origin validation** — signed, cache-safe token via never-cached `GET /form-token/{uuid}`, verified on submit; + Origin/Referer allowlist.
- [x] Honeypot field auto-injected into the renderer + time-trap (silent accept-and-discard).
- [x] Pluggable CAPTCHA/Akismet hook (`rapid_ai_forms_submission_pre_store`) — no bundled provider; external calls a site owner adds must be disclosed in the readme privacy section.
- [x] Per-IP submission rate limit (transient, hashed IP, `rapid_ai_forms_submission_rate_limit` filter). Covered by 12 guard tests.

### v0.3 — Rapid AI Cloud (hosted free tier) — see [docs/PLAN-rapid-ai-cloud.md](PLAN-rapid-ai-cloud.md), §5A.8
Ships **gated off** behind `rapid_ai_forms_managed_enabled` until post wp.org launch.
- [x] `Managed` provider + `/managed/*` handshake routes + Settings card (Connect / quota meter / Disconnect). Phase B; e2e verified on wooDev.
- [x] Backend service (`rapid-ai-cloud`): register/verify handshake, ledger metering, generate-form/text. Phase A/C.
- [x] Account model: claim-code linking, magic-link auth + dashboard served from the backend app. Phase D (partial).
- [x] Checkout (LemonSqueezy) → `purchased` credits + `order_refunded` clawback; 80%/100% usage emails. Phase D. ⚠️ Real checkout e2e still needs live store keys + a webhook tunnel.
- [x] Prod hardening: in-memory rate limiter → Upstash Redis (§5A.7). Fails open to memory if Redis is unreachable.
- [ ] Readme *Privacy & external services* disclosure + FAQ when the feature is un-gated.

### v0.4 — Block editor — ✅ shipped (`Blocks\Form_Block`, `blocks/form/block.json`)
- [x] Gutenberg block `rapid-ai-forms/form` — form picker (`SelectControl` fed by `GET /forms-list`) + live `ServerSideRender` preview. Core block.json structure, apiVersion 3.
- [x] Server-side render via the existing `Form_Renderer` (block and shortcode output match). Browser-verified in the editor; 5 block tests.

### v0.5 — Richer forms
- [ ] File upload field type (with size/MIME constraints).
- [ ] Conditional logic (show/hide fields based on other field values).
- [ ] Multi-step forms with progress indicator.

### v1.0 — Managed service GA (post wp.org launch)
- [ ] Managed backend live with credit billing (separate `rapid-ai-forms-backend` service).
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
- **Provider** — A class implementing `Rapid_Ai_Forms\Ai\Provider` that knows how to talk to an LLM.
