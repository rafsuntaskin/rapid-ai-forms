# Plan: Rapid AI Cloud — hosted free-quota provider (plugin + backend)

**Status:** Planned — implement after the 0.2.0 release, in a fresh session.
**Target:** plugin side ships in **0.3.0**; backend soft-launches first.
**Supersedes:** [`PLAN-free-ai-provider.md`](PLAN-free-ai-provider.md) (its handshake design is carried over verbatim; this plan adds the hybrid account model, the backend service design, usage metering, and the monetization funnel).

## Context

The plugin powers AI two ways today: BYOK keys and the WP 7.0 AI Client connector. This adds a third: **our own hosted provider** — users connect with one click, get a **free monthly quota** of AI generations, and can later buy more on our website. All paid surface lives on the website, never in the plugin (wp.org guideline: "credits / buy / upgrade" language in shipped code or readme is an automatic rejection).

**Locked decisions:**
- **Auth model: hybrid.** Anonymous auto-connect in the plugin (Jetpack-style domain-verification handshake — no signup needed for the free tier) + optional **account linking** on the website, which is where token/credit sales happen. Purchases attach to a website account; the plugin stays friction-free and wp.org-clean.
- **Scope:** plugin + backend both planned here. Backend lives in a separate private repo (working name `rapid-ai-cloud`).
- **Release:** 0.3.0, after the v0.2 work ships.

**Codebase facts to honor (as of 2026-06-11):**
- No `class-managed.php` exists — `includes/ai/providers/` has anthropic, gemini, openai-compatible, wp-ai-client.
- The `Provider` interface now requires **both** `generate_form_schema()` and `generate_text( $system, $prompt, $options )` — the hosted provider must implement both so it also powers the AI CSS editor (`POST /ai/style`).
- `docs/SPEC.md` §5A documents the wire contract (headers, error table, idempotency, credit-accounting rules, security checklist). Reuse it; the free tier replaces "license_key from a purchase" with the handshake-issued site token.
- PHPUnit runs on the official WP suite (`npm run test:php` via wp-env); HTTP can be stubbed with the `pre_http_request` filter.

---

## 1. The handshake (proof of domain ownership)

1. Admin clicks **Connect** → plugin generates a single-use high-entropy `registration_nonce` (5-min transient), POSTs to `{endpoint}/v1/register` with `home_url()`, plugin version, and the nonce.
2. Backend calls back `GET {home_url}/wp-json/rapid-ai-forms/v1/managed/verify?nonce=…` at the *claimed* URL.
3. Plugin verify route: nonce matches transient → `200 {ok:true}` (nothing else leaked), nonce deleted (single-use); mismatch/expired → `403`.
4. Callback success proves domain control → backend provisions the site row + free monthly quota and returns a **site-bound bearer token**.
5. All later calls send `Authorization: Bearer {token}` + `X-Site-URL: home_url()` + `X-Plugin-Version`.
6. **Unreachable sites** (localhost / intranet / auth-walled): hard fail with a message steering to BYOK. No callback = no free quota.

Security posture: single-use short-TTL nonce; domain↔token binding enforced server-side (`X-Site-URL` treated as untrusted); a leaked token can at worst burn that one site's quota; SSRF guards on the callback are a backend duty (§3). HMAC-signed requests deferred as future hardening.

---

## 2. Plugin side (this repo)

### `includes/ai/providers/class-managed.php` (new)
Implements `Ai\Provider`, modeled on `class-anthropic.php` (shared private HTTP helper):
- `key()` → `managed`; `label()` → `Rapid AI Cloud (Free)`.
- `endpoint()` → `apply_filters( 'rapid_ai_forms_managed_endpoint', 'https://api.rapidaiforms.com' )` (placeholder, filterable for dev/mock).
- `register( $nonce )` → `POST /v1/register`; returns token or `WP_Error` (unreachable-site → BYOK-steer message).
- `status()` → `GET /v1/status`.
- `generate_form_schema()` → `POST /v1/generate-form`; accepts `{schema}` or `{text}` → `Schema_Prompt::extract_schema()`.
- `generate_text( $system, $prompt )` → `POST /v1/generate-text` (powers `/ai/style`).
- `verify()` → token present → `status()`; else `WP_Error`.
- Error mapping per SPEC §5A: `401` → reconnect needed, `402/429` → `raif_quota_reached` with *"You've reached this month's free usage limit."* (no purchase language), `5xx` → transient error.

### `includes/ai/class-provider-manager.php`
- Register `new Managed()` unconditionally in the constructor.
- `settings()` defaults: `'managed' => array( 'site_token' => '', 'site_url' => '' )`.

### `includes/api/class-rest-controller.php`
- **Generalize secret masking**: treat `site_token` exactly like `api_key` (`*_set` boolean out, empty-string-preserves in). Expose `connected` = token present && stored `site_url === home_url()`.
- New routes:
  - `GET /managed/verify` — public; nonce-vs-transient, delete on match, return only `{ok:true}` / 403.
  - `POST /managed/register` — `can_manage`; mints nonce + transient, calls `Managed::register()`, persists token + `home_url()`.
  - `GET /managed/status` — `can_manage`; 5-min transient cache; returns quota numbers + `account_linked` + `manage_url`.
  - `POST /managed/disconnect` — `can_manage`; clears token/site_url.

### `src/admin/pages/Settings.js`
New `managed` branch (sibling of the `wp_ai_client` special case — no api_key field):
- **Not connected:** explainer + **Connect** button (spinner; failure shows BYOK-steer or retry).
- **Connected:** quota meter ("18 of 30 free generations left — renews July 1", progress bar), **Refresh**, **Disconnect**, neutral **"Manage account ↗"** link (URL from status; no pricing copy).

### Docs / metadata (with the 0.3.0 release)
- `readme.txt`: *Privacy and external services* — name the endpoint, when it's called (only on Connect / Generate with this provider selected), data sent (prompt, home_url, plugin version, site token), data NOT sent (submissions, site content, visitor data), ToS/privacy links; FAQ entry. Frame the cap as a free fair-use limit.
- `docs/SPEC.md` §5A: add register/verify handshake, `/v1/generate-text`, claim-code account linking; update §12.
- Regenerate `languages/rapid-ai-forms.pot`.

---

## 3. Backend service (separate private repo)

**Stack:** Node/TypeScript on Vercel (Hono or Next.js route handlers) + Postgres (Neon) + Upstash Redis (rate limits, idempotency) + **Vercel AI Gateway** for multi-provider LLM routing. Provider keys only in env vars.

### Data model
- `sites` — id, `home_url` (unique), `token_hash` (Argon2id; token shown once), status (active/revoked), `account_id` (nullable FK), created_at.
- `accounts` — website users (email magic-link auth is enough to start).
- `usage_ledger` — site_id, kind (`free_monthly` | `purchased`), delta, request_id, created_at.
- `requests` — audit: site_id, task (`form`|`css`), model, token counts, status, request_id.

### Endpoints (JSON; error table per SPEC §5A)
- `POST /v1/register` — `{ home_url, nonce, plugin_version }`. **SSRF guard before callback:** resolve host; reject private/loopback/link-local IPs and non-standard ports; https only for public hosts. Per-IP and per-domain registration rate limits. Re-registering an existing `home_url` rotates the token (old revoked) — same domain proof required, so it's safe.
- `POST /v1/generate-form` — Bearer auth; enforce site binding; atomic debit-then-call with refund on 5xx/invalid output; honor `X-Idempotency-Key` (24h). Returns `{ schema, usage, request_id }`.
- `POST /v1/generate-text` — `{ system, prompt }` (plugin sends `Css_Prompt` context); same auth/debit; prompt-size cap.
- `GET /v1/status` — `{ valid, free_remaining, free_renews_at, purchased_remaining, account_linked, manage_url }` (`manage_url` carries a short-lived claim code when unlinked).
- `POST /v1/claim-code` — Bearer auth; 15-min single-use code for account linking.
- `402` message must be neutral ("monthly free limit reached") because the plugin displays it verbatim.

### Usage metering — how quota is calculated
- **Unit of charge: 1 generation = 1 unit** (form or CSS). Token-weighted pricing is a later refinement — `requests` already records real token counts for analysis.
- **Every charge is a ledger row**, written in the same transaction as the request audit row. Debits `-1`; purchases positive `purchased` rows; refunds are compensating `+1` rows referencing the same `request_id`.
- **Balances are computed, not stored** (no drift, no reset cron):
  - `free_used_this_month` = `-SUM(delta) WHERE kind='free_monthly' AND created_at >= date_trunc('month', now())`
  - `free_remaining` = `FREE_MONTHLY_ALLOWANCE − free_used_this_month` (config constant, e.g. 30; per-site override for promos)
  - `purchased_remaining` = `SUM(delta) WHERE kind='purchased'` (never expires)
  - The monthly "reset" is just the calendar window moving.
- **Debit order:** free pool first, then purchased; `kind` decided at write time inside the transaction (`SELECT … FOR UPDATE` on the site row); `402` only when both pools are empty.
- **Plugin freshness:** every generate response carries `usage: { free_remaining, purchased_remaining, renews_at }`; the plugin updates its cached status transient from it (live countdown). `/v1/status` is the cold-load source.

### The upsell funnel (wp.org-safe)
Rule: **the plugin states facts; the website sells.**
1. **Quota meter** (plugin, always visible when connected) — count + renewal date + progress bar.
2. **Soft threshold** (plugin) — at ≤20% remaining the meter turns amber: *"Running low — your free quota renews {date}."* + "Manage account ↗".
3. **Hard stop** (plugin) — 402 shows *"You've reached this month's free limit. It renews {date}."* + "Manage account ↗" (opens the 402 body's `manage_url`).
4. **The sell** (website) — dashboard with usage graph and "Buy N generations" checkout (Stripe/LemonSqueezy); purchases insert `purchased` ledger rows; the plugin needs zero changes to reflect them.
5. **Email** (website, linked accounts only) — usage emails at 80%/100% with buy CTA; this is why the hybrid model exists. Unlinked sites only ever see in-plugin facts.

### Abuse controls (free tier = attack surface)
- Registration: per-IP (e.g. 5/day) + burst limits; disposable/wildcard-DNS deny-list; one active token per `home_url`.
- Generation: per-site rate limit (e.g. 10/min) on top of quota; global circuit breaker; prompt cap (~8 KB); after N consecutive 400s, reject without invoking the LLM.
- Periodic re-verification (~90 days or on anomaly) by re-running the callback.
- SPEC §5A.7 checklist signed off before launch.

---

## 4. Implementation checklist

### Phase A — backend skeleton
- [ ] Repo + Vercel project + Neon Postgres + Upstash; schema migrations for `sites`, `accounts`, `usage_ledger`, `requests`.
- [ ] `POST /v1/register` with SSRF-guarded callback verification + token issuance (hash at rest) + registration rate limits.
- [ ] `GET /v1/status` computing balances from the ledger.
- [ ] Request-id + structured error envelope on every response.

### Phase B — plugin provider (this repo, branch off after 0.2.0 ships)
- [ ] `includes/ai/providers/class-managed.php` (both generate methods + register/status + error mapping).
- [ ] Register in `Provider_Manager` + settings defaults.
- [ ] REST: `GET /managed/verify` (public, single-use nonce), `POST /managed/register`, `GET /managed/status` (cached), `POST /managed/disconnect`.
- [ ] Generalize secret masking to `site_token`; expose `connected`.
- [ ] Settings card: Connect / quota meter / Refresh / Disconnect / "Manage account ↗".
- [ ] Tests (wp-env suite): verify-route single-use semantics; error mapping + handshake via `pre_http_request` stubs; `site_token` never in `GET /settings`.

### Phase C — backend generation + metering
- [ ] `POST /v1/generate-form` + `POST /v1/generate-text` via Vercel AI Gateway.
- [ ] Atomic debit/refund ledger writes; idempotency keys; per-site rate limits; prompt caps; consecutive-400 breaker.
- [ ] End-to-end on wooDev against the dev backend (mu-plugin filters `rapid_ai_forms_managed_endpoint`): Connect → quota badge → form gen → `/ai/style` gen → forced 402 (allowance=2 on dev) → friendly message. Negative: localhost `home_url` → BYOK-steer; callback to `http://10.0.0.1` refused.

### Phase D — accounts + monetization (website)
- [ ] Magic-link auth + dashboard (linked sites, usage graph).
- [ ] `POST /v1/claim-code` + `/claim?code=` linking flow.
- [ ] Checkout (Stripe or LemonSqueezy) → `purchased` ledger credits.
- [ ] 80%/100% usage emails for linked accounts.

### Phase E — 0.3.0 release gate
- [ ] readme privacy section + FAQ; SPEC §5A + §12 updates; `.pot` regen.
- [ ] §5A.7 security checklist signed off on the backend.
- [ ] `composer lint`, `npm run build`, `npm run test:php`, `lando wp plugin check rapid-ai-forms` → no errors.
- [ ] SVN deploy (`npm run deploy` dry-run → `--commit`).
