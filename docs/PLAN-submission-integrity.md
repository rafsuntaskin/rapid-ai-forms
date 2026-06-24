# Plan: Submission integrity — required-field enforcement + form-origin validation

## Context

The public submission endpoint `/submissions/{uuid}` is intentionally unauthenticated
(`permission_callback => __return_true`, `class-rest-controller.php:113`). Two gaps follow
from that:

1. **No required-field enforcement.** `Rest_Controller::sanitize_submission()`
   (`:338`) only *shapes* data against the schema whitelist and silently drops missing
   fields — a POST with an empty body still creates a near-empty submission row. There is
   no server-side check that `required: true` fields are actually present.
2. **No proof the request came from the form.** The `wp_rest` nonce that the renderer
   emits (`class-form-renderer.php:18`) is **not verified** on submit (core only enforces
   it for cookie-authenticated users, and `permission_callback` returns `true` regardless).
   Anyone who reads the form UUID from the page HTML can POST directly to the API.

This plan covers both. **Part A (required-field enforcement) is higher priority** — it's a
data-integrity correctness fix, small, and unblocks the v0.2 submissions admin view from
showing junk rows. **Part B (form-origin validation)** is the anti-abuse hardening and
lands with the v0.3 anti-abuse work (honeypot + rate limit), since the layers reinforce
each other.

> Honest framing: no client-issued token can *prove* a human used the form — that's
> CAPTCHA/Akismet territory (a pluggable hook, below). What Part B does is make the
> request follow the form's issuance flow, which kills naive direct-POST and replay bots
> and raises the cost for everything else.

---

## Part A — Required-field enforcement — ✅ SHIPPED in v0.2 (2026-06)

Implemented as specified below (`validate_required()` in `Rest_Controller`, `aria-required`
in the renderer, inline `.raif-field-error` rendering in the frontend JS) and covered by
PHPUnit tests in `tests/test-rest-submissions.php`. Part B remains open for v0.3.

### Server (authoritative)
- In `Rest_Controller::submit()` (`:297`), after `sanitize_submission()`, validate each
  schema field with `required === true`:
  - missing key, empty string, or empty array → collect a field-level error;
  - type-aware emptiness (`email`/`url` that sanitized to `''`, `number` that came back
    `null`, `checkbox_group` that intersected to `[]`).
- On any error, return `WP_Error( 'raif_validation', …, [ 'status' => 422, 'fields' => { name: message } ] )`
  **before** `Submission_Repository::create()` — no row is written.
- Keep this in a small private helper `validate_required( $form, $data )` so `submit()`
  stays readable.

### Client (UX, not a security boundary)
- `Form_Renderer::render_field()` — emit `required` + `aria-required="true"` on required
  inputs so the browser blocks empty submits natively (progressive enhancement).
- `src/frontend/index.js` (`:38`) — on the `422` response, read `data.fields` and render
  inline messages next to each field (add a `raif-field-error` element per field; clear on
  re-submit). Keep the existing happy-path behavior otherwise.

### Verify
- POST missing a required field → `422`, body lists the field, **no DB row created**.
- POST with all required present → `200`, row created.
- JS shows inline errors; native HTML5 validation blocks empty submit in-browser.

---

## Part B — Form-origin validation — ✅ SHIPPED in v0.3 (2026-06)

Implemented in `includes/forms/class-submission-guard.php` (token mint/verify, Origin check, per-IP rate limit, honeypot, time-trap), wired into `Rest_Controller::submit()` cheapest-first with the new `GET /form-token/{uuid}` endpoint and the `rapid_ai_forms_submission_pre_store` CAPTCHA hook. Honeypot markup in `Form_Renderer`; token fetch/retry in `src/frontend/index.js`. Covered by `tests/test-submission-guard.php` (12 tests). The signing secret is generated lazily (`rapid_ai_forms_submit_secret`, autoload off) rather than in the migration, so existing installs self-heal. Original spec below.

Goal: a submission must follow the form's issuance flow recently, not be a cold direct
POST. Designed to be **full-page-cache safe** — the form HTML stays cacheable; nothing
per-request is baked into it.

### The signed, lazily-issued submission token

1. **Don't bake a token into cached HTML.** The rendered `<form>` stays cache-safe (drop
   reliance on the baked `wp_rest` nonce for auth; it can remain only as a no-op/PE hint).
2. **New public, never-cached endpoint** `GET /form-token/{uuid}` in `Rest_Controller`:
   returns `{ token, ts }` where `token = base64( ts . '.' . HMAC_sha256( uuid|ts, secret ) )`,
   short TTL (e.g. 15 min). Send `nocache_headers()` so page caches/CDNs don't serve a
   stale token. Because it's dynamic, it bypasses the page cache that the form HTML sits in.
   - `secret` = a dedicated option `rapid_ai_forms_submit_secret` (random, generated on
     install/migration; rotatable), not a hard-coded value.
3. **Frontend fetches the token** on load or first interaction (`src/frontend/index.js`)
   and includes it in the submit body/header. This is the cache-safe equivalent of a nonce
   refresh (the pattern CF7 uses for cached pages).
4. **`submit()` validates the token first**: recompute the HMAC, check signature + TTL +
   that it's bound to this `uuid`. Missing/forged/expired → `WP_Error( 'raif_bad_token', …, 403 )`.
5. **Optional single-use (replay defense):** record the token's `ts|nonce` in a short
   transient and reject reuse. Deferred to a follow-up if concurrency proves fiddly; the
   short TTL is the first-line defense.

### Cheap complementary layers (defense in depth, same release)
- **Origin/Referer allowlist:** in `submit()`, if an `Origin`/`Referer` header is present,
  reject when its host isn't the site host. Cheap first filter (skipped when headers absent
  — non-browser clients — so it never blocks legit no-referer cases on its own).
- **Honeypot field:** `Form_Renderer` injects a visually-hidden input (e.g. `raif_hp`);
  any non-empty value → silently accept-and-discard (don't tip off the bot).
- **Time-trap:** the token carries its issue time; reject submissions that arrive
  implausibly fast (< ~2s) after issuance — kills headless form-fillers.
- **Per-IP rate limit:** transient-backed counter keyed by hashed IP on `submit()` →
  `429` with `Retry-After` over the threshold (configurable via filter).
- **Pluggable CAPTCHA/Akismet hook:** `apply_filters( 'rapid_ai_forms_submission_pre_store', $ok, $form, $data, $request )` so site owners can bolt on Turnstile/hCaptcha/Akismet
  without us shipping a third-party dependency. **Any provider that adds an external call
  must be disclosed in the readme privacy section** before shipping.

### Where the code goes
- New `includes/forms/class-submission-guard.php` — token mint/verify, honeypot check,
  time-trap, Origin check, rate limit. Keeps `submit()` lean; each layer is a small method
  returning `true|WP_Error`.
- `includes/api/class-rest-controller.php` — register `GET /form-token/{uuid}`; call the
  guard methods at the top of `submit()` in cost order (Origin → token → honeypot/time-trap
  → rate limit) before validation/persist.
- `includes/forms/class-form-renderer.php` — honeypot markup; keep form HTML cache-safe.
- `includes/frontend/class-frontend.php` + `src/frontend/index.js` — fetch the token from
  the new endpoint; include token + elapsed-time + honeypot in the submit; handle `403`
  (re-fetch token once and retry) and `429` (show "try again shortly").
- Install/migration: generate `rapid_ai_forms_submit_secret` in `Plugin::maybe_migrate()`.

### Verify
- Cold direct `POST` with no token → `403`. Forged/expired token → `403`.
- Token from `GET /form-token/{uuid}` then submit within TTL → `200`.
- Submit < 2s after issuance → rejected (time-trap). Honeypot filled → accepted-but-discarded.
- Hammer the endpoint past the per-IP threshold → `429` with `Retry-After`.
- Full-page-cache smoke test: cached form page still submits successfully (token came from
  the uncached endpoint, not the cached HTML).
- Confirm `GET /form-token/{uuid}` sends no-cache headers and isn't served from cache.

---

## Roadmap impact

- **v0.2:** add Part A (required-field server-side enforcement) — higher priority.
- **v0.3:** Part B joins the existing anti-abuse line (honeypot + per-IP rate limit), which
  this plan now specifies concretely.

## Out of scope
- Shipping a bundled CAPTCHA/Akismet provider (only the hook is in scope).
- Server-side single-use token store (deferred unless replay proves to be a problem).
- Authenticated/logged-in submission flows.
