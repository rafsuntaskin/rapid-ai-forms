# Plan: Free hosted AI provider ("Rapid AI Cloud")

## Context

The plugin (v0.1.0) is approved on wp.org and ships BYOK-only. The next feature is a
**free, hosted AI provider** backed by our own server: users select it, the plugin
auto-registers their site (no signup, no API key to paste), and they get a capped free
quota of AI generations. The server holds all real LLM provider keys; the plugin never
does. This lays the groundwork for selling additional usage later — but **none of that
monetization surface ships in the public plugin now** (wp.org §5: any "credits / buy
tokens / paid" language in shipped code or readme is an automatic rejection).

The repo stays public. Safety comes from the design, not secrecy: the plugin contains
no shared secret. Each site gets a runtime-issued, domain-bound token via a
proof-of-domain-ownership handshake (the same model Jetpack uses).

### Decisions locked with the user
- **Auth:** anonymous auto-signup + domain-verification callback handshake.
- **Unreachable sites** (localhost / behind auth / intranet): **hard requirement** — no
  successful callback = no free quota; tell the user to use BYOK instead.
- **Rollout:** ship as a genuinely-free provider in the next public update (**0.2.0**),
  disclosed in the privacy section, no paid language anywhere.
- **Backend:** filterable placeholder endpoint (`rapid_ai_forms_managed_endpoint`),
  **plugin-side only** in this plan; the backend is a separate private repo whose
  contract is documented here and in SPEC §5A.

## The handshake (proof of domain ownership)

1. Admin selects "Rapid AI Cloud (Free)" and clicks **Connect** in Settings.
2. Plugin generates a single-use high-entropy `registration_nonce`, stores it in a
   5-min transient, and `POST`s to `{endpoint}/v1/register` with `home_url()`, plugin
   version, and the nonce.
3. Backend calls back to the plugin's **public** route
   `GET /wp-json/rapid-ai-forms/v1/managed/verify?nonce=…` at the *exact* claimed
   `home_url`.
4. Plugin verify route matches the nonce against the transient → `200 {ok:true}` (and
   nothing else); mismatch/expired → `403`. Nonce is deleted on use (single-use).
5. Backend (callback succeeded ⇒ caller controls that domain) provisions free quota and
   returns a **per-site token bound to that `home_url`**.
6. Plugin stores the token; all later `/v1/generate-form` calls send
   `Authorization: Bearer {token}` + `X-Site-URL: home_url()`.

Security posture: nonce is single-use + short-TTL; verify route leaks nothing (same as
the existing public `/submissions/{uuid}` route); domain↔token binding is enforced
server-side (the `X-Site-URL` header is treated as untrusted); a leaked token can at
worst burn that one site's free quota. SSRF guards on the callback are a backend concern
(noted in SPEC). HMAC-signed requests are deferred as future hardening.

## Implementation

### PHP

**NEW `includes/ai/providers/class-managed.php`** — implements `Ai\Provider`. Centralizes
all backend comms (mirrors the self-contained pattern of `class-anthropic.php`):
- `key()` → `'managed'`; `label()` → `__( 'Rapid AI Cloud (Free)', 'rapid-ai-forms' )`.
- `endpoint()` → `apply_filters( 'rapid_ai_forms_managed_endpoint', 'https://api.example.com' )` (placeholder).
- `register( $nonce )` → `POST {endpoint}/v1/register`; returns the issued token or
  `WP_Error` (map "site unreachable" to a clear message steering to BYOK).
- `status( $token )` → `GET {endpoint}/v1/license`; returns quota info (cached by caller).
- `generate_form_schema( $prompt, $options )` → `POST {endpoint}/v1/generate-form` with
  Bearer token + `X-Site-URL` + `X-Plugin-Version`; parse `schema` (or `text` →
  `Schema_Prompt::extract_schema()`). Map `401`→re-connect needed, `402/429`→
  `raif_quota_reached` ("You've reached the free usage limit." — **no "credits/buy"**),
  `5xx`→transient error.
- `verify( $options )` → token present? then `status()`; else error.

**EDIT `includes/ai/class-provider-manager.php`**
- Register `new Managed()` in the constructor (always — it's a public free feature).
- Add to `settings()` defaults: `'managed' => array( 'site_token' => '', 'site_url' => '' )`.

**EDIT `includes/api/class-rest-controller.php`**
- **Generalize secret masking:** `get_settings()` currently masks only `api_key`. Extend
  the same `*_set` treatment to `site_token` (never return the token over the wire; expose
  `connected` = token present & `site_url` matches current `home_url()`).
- New routes under `rapid-ai-forms/v1`:
  - `GET /managed/verify` — `permission_callback => __return_true`; the public callback
    target. Reads `nonce`, compares to transient, deletes on match, returns `{ok:true}` or
    `403`. Leaks nothing else.
  - `POST /managed/register` — `can_manage`; generates nonce + transient, calls
    `Managed::register()`, persists the returned token + `home_url()` into settings.
  - `GET /managed/status` — `can_manage`; calls `Managed::status()`, caches result in a
    5-min transient for the quota badge.
  - `POST /managed/disconnect` — `can_manage`; clears the stored token/site_url.

### JS — `src/admin/pages/Settings.js`

Add a `managed` special-case branch (sibling to the existing `wp_ai_client` Notice branch),
since this provider shows no api_key field:
- **Not connected:** a "Connect to Rapid AI Cloud (Free)" button → `POST managed/register`;
  spinner while the handshake runs; on the unreachable-site error show the BYOK-steer message.
- **Connected:** status line (connected as `{site_url}`, "free generations remaining: N"),
  a Refresh button (`GET managed/status`), and a Disconnect button (`POST managed/disconnect`).
- Reuse the existing `api` helper and `__()` from `@wordpress/i18n`.

### Docs / metadata

- **`readme.txt`** — extend `== Privacy and external services ==`: name the Rapid AI Cloud
  endpoint; state it's triggered only when an admin clicks **Connect** or **Generate** with
  this provider selected; data sent = the admin's prompt + `home_url()` + plugin version +
  the site token; data NOT sent = submissions, site content, user/visitor data; link our
  ToS/privacy. Add a FAQ entry ("Is there a free option / what's sent?"). Frame the cap as a
  free fair-use limit — **no "credits", "buy", "upgrade", or "pro"**. Bump `Stable tag: 0.2.0`
  + changelog entry.
- **`rapid-ai-forms.php`** — `Version: 0.2.0`.
- **`docs/SPEC.md`** §5A — add the free-tier `/v1/register` + verify-callback handshake and
  domain-bound token issuance (current §5A only documents the license_key model); note the
  backend SSRF guard. Update §12 roadmap. Document the new public `/managed/verify` route.
- Regenerate `languages/rapid-ai-forms.pot` (the make-pot command in CLAUDE.md).

## Verification

Backend doesn't exist yet, so test the plugin against a **mock**:
1. `add_filter( 'rapid_ai_forms_managed_endpoint', … )` in a tiny mu-plugin pointing at a
   local stub that (a) on `/v1/register` calls back to `…/managed/verify?nonce=` and echoes
   a fake token, (b) on `/v1/generate-form` returns a canned schema, (c) on `/v1/license`
   returns a fake quota. Confirms the full handshake + binding end-to-end.
2. Unit-confirm the verify route: set the transient, hit `/managed/verify?nonce=` → 200;
   wrong/expired nonce → 403; confirm it's single-use.
3. Golden path on a publicly-reachable test site: Connect → quota shows → create form →
   Generate via Rapid AI Cloud → schema renders. Then exhaust/forced-402 → friendly
   "free usage limit" message.
4. Negative: point the filter at an unreachable `home_url` scenario → Connect fails with the
   BYOK-steer message.
5. `composer lint` (0 errors), `npm run build`, `npm run dist`, then
   `lando wp plugin check rapid-ai-forms` → **No errors found** (must pass before the 0.2.0
   wp.org update; reviewers re-check the new external endpoint + privacy disclosure).

## Out of scope (future)
- Backend service implementation (separate private repo).
- Any paid/token-purchase UI — stays on our website until a later, carefully-scoped release.
- HMAC-signed generate requests; webhook receiver for quota sync; manual-key path for
  unreachable sites.
