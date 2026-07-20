# Rapid AI Forms — internal roadmap

> Internal-only. Not shipped in the wp.org distribution (see `.distignore`).
> Keep public-facing copy in `readme.txt` strictly limited to *what already works*.

The canonical, detailed roadmap lives in [`SPEC.md`](SPEC.md) §12. This file is a
short-form cheatsheet so we don't accidentally leak forward-looking statements
into the readme, FAQ, or in-product copy that reviewers see.

## Why we keep it out of the readme

- wp.org plugin reviewers reject "coming soon" language and feature promises.
- Roadmap details give competitors a free preview.
- Users see promises and file support tickets about features that don't exist
  yet.

If something belongs on this list, it does *not* belong in `readme.txt`,
in-product help text, or the plugin description.

## v0.2 (feature-complete, unreleased)

All implemented on the release branch; move to readme.txt changelog when 0.2.0 ships:

- Required-field server-side enforcement (422 + field-level errors; no junk rows).
- Submissions admin page (cross-form list, form filter, detail modal with email preview).
- Per-form custom CSS with iframe preview and AI-prompt-driven editing — see
  [`PLAN-ai-css-editor.md`](PLAN-ai-css-editor.md).
- PHPUnit on the official WP test suite (`npm run test:php` via wp-env).

(Database migration runner was descoped — dbDelta-on-activation is enough at the
current install base.)

- ~~CSV export + date filter deferred from v0.2.~~ **Shipped on develop:**
  `Forms\Submission_Exporter` + `GET /submissions/export?format=csv|json` +
  a date-range filter on the Submissions page.

## v0.3 — ✅ shipped (on develop)

- ~~**Rapid AI Cloud** — hosted free-quota provider (hybrid: anonymous auto-connect +
  optional website account for purchases).~~ Built plugin-side (`Managed` provider +
  `/managed/*` + Settings card) **and** backend (`rapid-ai-cloud`, Phases A–D:
  handshake, ledger, generate, magic-link auth/dashboard, usage emails,
  LemonSqueezy checkout/webhook, Upstash rate limiter, `order_refunded` clawback).
  **Gated off** behind `rapid_ai_forms_managed_enabled` until post wp.org launch.
  Plan + checklist: [`PLAN-rapid-ai-cloud.md`](PLAN-rapid-ai-cloud.md).
- ~~Anti-abuse / submission-origin validation~~ **Shipped:** `Forms\Submission_Guard`
  — signed cache-safe per-render token, honeypot + time-trap, per-IP rate limit,
  pluggable CAPTCHA/Akismet hook. See
  [`PLAN-submission-integrity.md`](PLAN-submission-integrity.md) Part B.

## Test infrastructure — ✅ shipped (on develop)

- Playwright e2e suite (`tests/e2e/`, `npm run test:e2e`) — 18/18 green, covers
  every [`SMOKE-TEST.md`](SMOKE-TEST.md) scenario (1–12). Complements the PHPUnit
  REST/sanitizer coverage.
- REST client hardened for plain-permalink sites (query-string `?rest_route=`
  collision) + a dedicated regression spec.

## v0.4 — ✅ shipped (on develop)

- ~~Gutenberg block — a thin wrapper around the existing shortcode.~~ Shipped: `rapid-ai-forms/form` block with a form picker + live `ServerSideRender` preview, reusing `Form_Renderer`.

## v0.5

- File upload field type.
- Conditional logic and multi-step forms.

## v1.0 (post wp.org launch)

- Managed AI service (credit-based, license-key authenticated). Backend
  contract is documented in `SPEC.md` §5A and the PHP `Managed` provider class
  exists but is not registered at runtime in MVP. Keep this **fully out of the
  readme and Settings UI** until the wp.org listing is established.

## Future / undated

- Adoption of the WordPress 7.0 AI Client SDK as a third provider option
  (gated on `function_exists('wp_ai_client_prompt')`), keeping BYOK as the
  default path. Adding it does not change `Requires at least:`.

## Edit rules

- Anything added here is **invisible to the public** by default. Do not copy
  bullets from this file into `readme.txt`.
- When a roadmap item ships, move its description into the *Features* /
  *Changelog* sections of `readme.txt` in past tense, and delete the bullet
  here.
