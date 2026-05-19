# Security review — WP AI Forms v0.1.0

**Reviewed:** 2026-05-19
**Status:** Pre-wp.org submission audit.
**Coverage:** OWASP-style sweep of plugin-specific concerns.

This file is **not** shipped to wp.org (`.distignore` excludes `docs/`).

---

## TL;DR

| Area | Status |
|---|---|
| Authentication & authorization | ✅ All admin endpoints require `manage_options`. Public submission is intentional. |
| SQL injection | ✅ Every query uses `$wpdb->prepare()` with `%i`/`%d`/`%s` placeholders. |
| XSS / output escaping | ✅ All output escaped at point of use. AI-generated content sanitized through `Schema_Prompt::sanitize_schema()` before storage. |
| CSRF | ✅ Admin: WP nonces. Public submission: see Known limitations. |
| Direct file access | ✅ Every PHP file in `includes/` has `defined( 'ABSPATH' ) || exit;`. |
| Secrets handling | ✅ API keys never returned over REST. Stored autoload=no. |
| SSRF | ⚠️ User-configurable `base_url`. Risk requires already-compromised admin. Documented; mitigation deferred. |
| File uploads | n/a — no file fields yet. |
| Rate limiting / DoS | ⚠️ Submission endpoint not rate-limited. Payload size now capped at 64KB. Full rate limiting in v0.3. |
| AI prompt injection | ✅ Admin-only input; AI output re-sanitized server-side regardless. |
| Capability checks | ✅ `manage_options` for everything except the public submit endpoint. |
| Information disclosure | ✅ Upstream provider error messages relayed only on admin-authenticated paths. |
| Third-party deps | ✅ Composer `require` is empty; only dev tooling. |

Two cheap mitigations added during this audit:
- `wp_ai_forms_ai_settings` is now saved with `autoload=false`.
- `/submissions/{uuid}` now returns 413 for payloads > 64 KB.

---

## 1. Authentication & authorization

### REST endpoints

| Endpoint | Method | permission_callback | Capability | Verdict |
|---|---|---|---|---|
| `/forms` | GET, POST | `can_manage` | `manage_options` | ✅ |
| `/forms/{id}` | GET, PUT, DELETE | `can_manage` | `manage_options` | ✅ |
| `/ai/verify` | POST | `can_manage` | `manage_options` | ✅ |
| `/ai/generate` | POST | `can_manage` | `manage_options` | ✅ |
| `/settings` | GET, PUT | `can_manage` | `manage_options` | ✅ |
| `/submissions/{uuid}` | POST | `__return_true` | none | ✅ (intentional — anonymous visitors must be able to submit forms) |

### Abilities API

All four registered abilities (`generate-form-schema`, `create-form`, `list-forms`, `get-form`) carry `permission_callback` returning `current_user_can( 'manage_options' )`. ✅

### Admin pages

Both `wp-ai-forms` and `wp-ai-forms-settings` are registered with `manage_options` capability. ✅

---

## 2. SQL injection

After the WPCS pass we audited every `$wpdb` call. All queries are parameterized:

| File | Pattern |
|---|---|
| `Form_Repository::get` | `prepare( 'SELECT * FROM %i WHERE id = %d', table, id )` |
| `Form_Repository::get_by_uuid` | `prepare( 'SELECT * FROM %i WHERE uuid = %s', table, uuid )` |
| `Form_Repository::list` | `prepare( 'SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d', table, limit, offset )` |
| `Form_Repository::create`/`update`/`delete` | `$wpdb->insert/update/delete` (auto-escaping) |
| `Submission_Repository::create` | `$wpdb->insert` |
| `Submission_Repository::list_for_form` | `prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY ... LIMIT %d OFFSET %d', table, form_id, limit, offset )` |

Table names go through `%i` (WP 6.2+ identifier placeholder). Our `Requires at least: 6.4` means `%i` is always available. ✅

---

## 3. Cross-site scripting (XSS)

### PHP output

`Form_Renderer::render()` and `render_field()` were refactored during the WPCS pass to escape at point of output:

- All HTML emitted via `printf()` with `esc_html()`/`esc_attr()` arguments, OR via inline `esc_*()` in template tags
- The two `echo $attrs` lines use `phpcs:ignore` with a comment because `$attrs` is `sprintf()` of pre-escaped values; the rationale is documented at each call site

### AI-generated content

Adversarial prompts cannot get HTML into the database:

1. Provider returns text → `Schema_Prompt::extract_schema()` parses as JSON
2. `Schema_Prompt::sanitize_schema()` runs every field through `sanitize_key()` / `sanitize_text_field()` and clamps `type` to an allowlist
3. The sanitized schema is what's stored
4. At render time, every value is re-escaped via `esc_html()` / `esc_attr()`

Even if the AI returns `<script>alert(1)</script>` as a field label, by the time it's rendered it's `&lt;script&gt;alert(1)&lt;/script&gt;`.

### Submissions

`sanitize_submission()` runs every incoming value through the type-appropriate sanitizer:
- `email` → `sanitize_email`
- `url` → `esc_url_raw`
- `textarea` → `sanitize_textarea_field`
- `number` → numeric coercion
- `checkbox_group` → intersected with allowed option values
- everything else → `sanitize_text_field`

Submissions aren't rendered back to visitors in this version (no submissions UI yet), so reflected XSS isn't a current risk. When the submissions admin view ships in v0.2, the rendering path will need a re-audit. ✅

---

## 4. CSRF

### Admin operations

`@wordpress/api-fetch` automatically adds `X-WP-Nonce` to every REST call. WP REST verifies the nonce against the admin's cookie session. All state-changing admin endpoints (POST/PUT/DELETE) are covered. ✅

### Public submission endpoint

This is the nuanced one. The endpoint is `__return_true` permission and accepts JSON from anyone. The form renderer embeds a `wp_create_nonce('wp_rest')` value in the form. **For anonymous visitors, WP's nonce is essentially deterministic** (uses a 0-user-id session token), so it provides cosmetic CSRF protection at best.

This is the same trade-off every contact form plugin makes. The endpoint is idempotent (just inserts a row), causes no privileged side effect, and only writes to a dedicated submissions table. The realistic abuse vector is spam, not privilege escalation — which is addressed by the v0.3 anti-abuse work (honeypot, Turnstile, rate limit).

**Verdict:** acceptable for v0.1.0. Tracked in the roadmap.

---

## 5. Direct file access

All 19 PHP files under `includes/` and the main `wp-ai-forms.php` start with `defined( 'ABSPATH' ) || exit;`. Verified via grep. ✅

---

## 6. Secrets handling

### Storage

- API keys live in the `wp_ai_forms_ai_settings` WP option.
- **Updated during this review:** option now stored with `autoload=false` so secrets are not loaded into memory on every page request.
- They are stored in plaintext, like virtually every WordPress plugin that talks to third-party APIs (Jetpack, Yoast, WPForms, all of them). Encrypting them in a database where the encryption key would also need to live in the database doesn't increase the security posture.

### REST transit

`Rest_Controller::get_settings()` strips secrets before returning:

```php
foreach ( $settings['providers'] as $key => $cfg ) {
    if ( ! empty( $cfg['api_key'] ) ) {
        $settings['providers'][ $key ]['api_key_set'] = true;
        $settings['providers'][ $key ]['api_key']     = '';
    }
}
```

So the REST GET only ever returns booleans. The frontend cannot read a stored API key — it can only test or replace it. ✅

### Update behavior

In `update_settings()`, an empty `api_key` in the request is **ignored** (preserves the saved value), and a non-empty value replaces it. This lets the admin save the model name without re-typing the key.

---

## 7. SSRF

`Openai_Compatible` providers let the admin set `base_url`. The `verify` and `generate` endpoints then `wp_remote_get`/`wp_remote_post` to that URL.

**Threat:** an admin (or someone with admin access) could point `base_url` at:
- Internal services: `http://localhost:9200`, `http://127.0.0.1`, `http://[::1]`
- Cloud metadata: `http://169.254.169.254/latest/meta-data/`
- Internal RFC1918 ranges

…and use the verify endpoint as an oracle, getting back HTTP status codes from arbitrary internal URLs.

**Why it's acceptable here:**
- Reaching the SSRF requires `manage_options` capability. A user with that already controls the WP install, including its outbound network egress, file system, secrets, and database.
- The plugin is the legitimate intended use case for "configure an OpenAI-compatible endpoint" — Ollama at `http://localhost:11434`, LM Studio, on-prem inference. Restricting localhost would break valid deployments.
- The endpoint only reads response bodies and returns them to the admin who initiated the request. No data is leaked to an external party.

**Verdict:** documented design trade-off, not a vulnerability. If the threat model ever expands to multi-tenant WP installs where admins shouldn't be fully trusted, we'd need to allowlist outbound hosts. Not relevant for v0.1.0.

---

## 8. File uploads

There are no file-upload field types in v0.1.0. The `file` type is on the v0.5 roadmap; security review for that path will include MIME validation, size limits, storage location decisions, and direct-access protection on uploaded files.

---

## 9. Rate limiting / DoS

| Endpoint | Authenticated? | Rate limit? | Mitigation |
|---|---|---|---|
| `/forms` (CRUD) | Yes (`manage_options`) | No, admin-trusted | n/a |
| `/ai/generate`, `/ai/verify` | Yes | No, admin-trusted; outbound calls cost money but only the admin's own | Trust |
| `/settings` | Yes | No | Trust |
| `/submissions/{uuid}` | **No** | **No** rate limit yet | **NEW:** 413 on payloads > 64 KB |

The submission endpoint is the realistic attack surface:

**Threat:** an attacker scripts thousands of submissions to fill the DB and/or saturate the WP request handler.

**Current mitigations:**
- Payload size capped at 64 KB (added in this audit).
- Each submission requires resolving a real form by UUID — if the attacker doesn't know any UUIDs, they hit 404 cheaply.
- The submission row is small; the practical DB pressure is bounded by available disk.

**Deferred to v0.3** (per `docs/SPEC.md` §12 roadmap):
- Honeypot field auto-injection
- Optional Cloudflare Turnstile / hCaptcha
- Per-IP and per-form rate limit

For the wp.org submission this is acceptable — many established form plugins ship without built-in rate limiting and pass review. ✅

---

## 10. AI prompt injection

The AI endpoints take administrator-provided prompts. There is no end-user input that flows to the AI. The threat model would be a malicious admin (already trusted), so prompt injection isn't a privilege boundary.

**Defense in depth even so:** the AI's output is *always* re-validated by `Schema_Prompt::sanitize_schema()` before storage. A jailbroken AI cannot inject:
- Unknown field types (clamped to allowlist)
- HTML in labels (`sanitize_text_field` strips tags)
- Non-snake_case field names (`sanitize_key`)
- Risky `default_value` for hidden fields (`sanitize_text_field`)

Even if the model produced literal SQL injection strings, they'd never reach a query — the sanitizer runs first, then `$wpdb->prepare()` runs again at insert. ✅

---

## 11. Capability checks recap

| Action | Required cap | Comment |
|---|---|---|
| View admin pages | `manage_options` | Standard for site-wide settings |
| Manage forms (CRUD) | `manage_options` | Could later relax to `edit_posts` for editor roles |
| Configure AI providers | `manage_options` | Settings are site-wide |
| Verify / generate via REST | `manage_options` | Cost / SSRF surface |
| Submit a form | none (anonymous) | Intentional |

Using `manage_options` everywhere is the safer default. If editors need form access later, we'll introduce a custom cap (`edit_wp_ai_forms`) mapped from `edit_posts`.

---

## 12. Information disclosure

- Provider error messages from upstream APIs are returned verbatim to the admin. These can leak provider-internal details (model ids, request ids, vendor error codes). Acceptable — only authenticated admins see them, and the data is helpful for debugging.
- The `available_providers` REST response lists only provider keys and labels — public information.
- No stack traces are returned. `WP_Error` codes are stable identifiers, not internal data.
- Failed login attempts, brute-force attempts, etc. — n/a, the plugin doesn't add auth surfaces.

✅

---

## 13. Third-party dependencies

`composer.json`:

```json
"require": { "php": ">=7.4" },
"require-dev": {
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "squizlabs/php_codesniffer": "^3.10"
}
```

The `vendor/` folder is excluded from the wp.org dist zip (`.distignore`). At runtime the plugin loads zero composer packages. Only its own custom autoloader is used.

`package.json` dev deps (`@wordpress/scripts`) are also dev-only; the built bundles inline only what they need from `@wordpress/element` etc., which are externalized to WP's bundled handles.

✅

---

## 14. Things explicitly NOT addressed in v0.1.0

These are deferred to later milestones and tracked in `docs/SPEC.md` §12:

- **Submissions admin view** (v0.2): when this lands, the rendering of stored submission data needs the same escape audit Form_Renderer got here.
- **Email notifications** (v0.2): outbound mail with form data must escape per-header (`To`, `Subject`) and per-body, and respect WP `wp_mail` filters.
- **Honeypot + CAPTCHA + rate limit** (v0.3): the public submission attack surface gets proper anti-abuse.
- **File uploads** (v0.5): full upload security review when added.
- **Managed service** (v1.0): the `Managed` provider is removed from v0.1.0 entirely. When re-introduced, the credit/license/webhook path gets its own review (see `docs/SPEC.md` §5A.7).

---

## 15. Sign-off

Plugin passes my audit for v0.1.0 submission to wp.org. The two cheap fixes from this review are in the same commit as this document:

- `update_option( ..., false )` for AI settings (no autoload)
- 64 KB hard cap on submission payloads with 413 response

If wp.org Plugin Review flags anything I missed, update this file and re-audit.
