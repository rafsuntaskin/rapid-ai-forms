# Handoff — Rapid AI Forms (next session)

> Living resume doc for the next agent/session. Last updated **2026-08-13**.
> Pairs with the auto-memory note `project-state-2026-06.md`. When you finish a
> chunk of work, update this file (and the memory) so the next session starts warm.

## TL;DR

Two repos, both on `develop`, both clean and fully pushed. A large body of work
(v0.3 anti-abuse, v0.4 block, CSV export, the whole Rapid AI Cloud stack, a full
Playwright e2e suite, a plain-permalink REST fix) sits on `develop` but is **not
released** — the wp.org Stable tag is still **0.2.0**. `develop` is **31 commits
ahead of `master`** (plugin) and **13 ahead** (backend). The Rapid AI Cloud /
Managed provider is wired but **gated off** (`rapid_ai_forms_managed_enabled`)
until after the wp.org launch.

## Repos & branches

| Repo | Path | Branch | State |
|---|---|---|---|
| Plugin `rapid-ai-forms` | `~/Dev/MyPlugins/wp-ai-forms` (primary) | `develop` | clean, pushed; 31 ahead of `master` |
| Backend `rapid-ai-cloud` | `~/Dev/MyPlugins/rapid-ai-cloud` (sibling) | `develop` | clean, pushed; 13 ahead of `master` |

Old feature branches are **kept, not pruned** (user preference). Merge feature
work into `develop`; run the test suites before merging.

## Shipped on `develop`, not yet released

**Plugin (`rapid-ai-forms`):**
- **v0.3 anti-abuse** — `Forms\Submission_Guard` (signed cache-safe `/form-token/{uuid}`, Origin check, per-IP rate limit, honeypot, time-trap, `rapid_ai_forms_submission_pre_store` CAPTCHA hook). Browser-verified on wooDev.
- **v0.4 block** — `rapid-ai-forms/form` Gutenberg block (form picker via `GET /forms-list`, `ServerSideRender` preview, reuses `Form_Renderer`).
- **CSV/JSON export + date filter** — `Forms\Submission_Exporter` + `GET /submissions/export`; date-range filter on the Submissions page.
- **Rapid AI Cloud provider** — `Ai\Providers\Managed` + `/managed/*` routes + Settings card. Gated off behind `rapid_ai_forms_managed_enabled`.
- **Plain-permalink REST fix** (`c085958`) — `createApiClient` now folds query args via `@wordpress/url` `addQueryArgs` so `?rest_route=` roots don't 404.
- **Playwright e2e suite** — `tests/e2e/`, 18/18 green, covers every SMOKE-TEST scenario. See `tests/e2e/README.md`.

**Backend (`rapid-ai-cloud`, Hono/TS + Postgres):**
- Phases A–D: register/verify handshake, ledger metering, generate-form/text, magic-link auth + dashboard + claim-code linking, 80%/100% usage emails (Resend, MAIL_DEV fallback), LemonSqueezy checkout + webhook.
- **Upstash-backed rate limiter** (fail-open to in-memory), and **`order_refunded`** idempotent credit clawback (migration 005).

## wp.org status

The SEO title/tags readme retune **is deployed to wp.org SVN** (done). Nothing
else has been released — a real 0.3.0 release means merging `develop` → `master`,
bumping the version + Stable tag, updating the readme changelog, running Plugin
Check, and `npm run deploy`. See [`DEPLOY.md`](DEPLOY.md) and
[`SUBMITTING-TO-WP-ORG.md`](SUBMITTING-TO-WP-ORG.md).

## Run it locally

**Backend** (`~/Dev/MyPlugins/rapid-ai-cloud`):
- `npm run dev` → Hono on `:8787` with `--watch` (hot-reload). Env: `MOCK_LLM=1`, `MAIL_DEV=1`, `ALLOW_PRIVATE_CALLBACK=1`, `PUBLIC_URL=http://localhost:8787`.
- Postgres (Docker) on `:54330`, DB `rapid_ai_cloud`. Migrations: `npm run db:migrate` (idempotent). Quota tool: `npm run usage [show|reset|topup N|clear]`.
- Typecheck: `npm run typecheck`.

**Plugin dev env** (`~/Dev/MyPlugins/wp-ai-forms`):
- Needs Docker running. `npx wp-env start` → dev site `http://localhost:8888` (admin/`password`), test site `:8889`.
- Build JS: `npm run build` (watch: `npm run start`).
- PHP tests: `npm run test:php` (start wp-env first). Lint PHP: `composer lint` / `composer lint:fix`.
- e2e: `npm run test:e2e` (needs wp-env up + one-time `npx playwright install chromium`).
- wooDev (Lando) also has the plugin deployed with `dev/raif-cloud-dev.php` mu-plugin (endpoint → `host.docker.internal:8787`, provider gate ON) for manual cloud testing.

## Blocked on the user (external inputs)

These gate the cloud go-live; nothing to build until they arrive:
- **`AI_GATEWAY_KEY`** — a real gateway/provider key. Without it every production
  generate returns `502 upstream_error` (quota is refunded, so nobody is charged).
  This is the one input that decides whether a backend deploy is real or just a
  healthcheck — it was missing from this list until 2026-08-13.
- **Live LemonSqueezy** store keys (`LEMONSQUEEZY_API_KEY`/`STORE_ID`/`WEBHOOK_SECRET`/`VARIANTS`) + a webhook tunnel (cloudflared/ngrok) for a real checkout e2e. BD bank payout confirmed. See `rapid-ai-cloud/docs/lemonsqueezy.md`.
- **Resend** live API key + verified sending domain (drop `MAIL_DEV`). Gates
  magic-link sign-in, so the dashboard is unusable without it.
- **A domain** for the backend. The plugin defaults to `https://api.rapidaiforms.com`
  (filterable via `rapid_ai_forms_managed_endpoint`); a `*.vercel.app` URL works
  for testing as long as `PUBLIC_URL`/`DASHBOARD_URL` match it.

Not actually blocked: **`SESSION_SECRET`** is self-serve (`openssl rand -hex 32`),
and HTTPS comes free with Vercel.

## Suggested next steps (no external deps)

1. **Cut the 0.3.0 release** — the biggest open item. `develop` → `master`, version/Stable-tag bump, readme changelog, Plugin Check, SVN deploy. (Managed provider stays gated.)
2. **Deploy the backend to Vercel** — the code is deploy-ready and the path is
   written up step-by-step in [`rapid-ai-cloud/docs/DEPLOY-VERCEL.md`](../../rapid-ai-cloud/docs/DEPLOY-VERCEL.md):
   provision pooled Neon + Upstash, set env vars, run the (idempotent) migrations,
   `vercel deploy`. Roughly an afternoon. The **first build is the unknown** —
   `tsconfig` uses `moduleResolution: "Bundler"` with `.js` specifiers pointing at
   `.ts` sources, which has never been built on Vercel; the doc has the NodeNext
   fallback if it fails.
3. **v0.5 features** — file upload field type; conditional logic / multi-step (SPEC §12).
4. **Backend prod polish** — productionize sessions/email behind the real secrets once supplied; `request_id` in support flows; periodic re-verification of long-lived sites (SPEC §5A.7).
5. **e2e follow-ups** — a spec for the Managed/cloud flow once un-gated; optionally run the suite against the built dist zip.

## Landmines / gotchas (learned the hard way)

- **Submission time-trap:** the frontend must prefetch the submit token on page load; a lazily-fetched token makes every genuine submit look "too fast" and get silently discarded. e2e specs wait ~2.5s before submitting.
- **Plain permalinks:** `rest_url()` becomes `?rest_route=…`; any query-string REST path must go through `addQueryArgs` (fixed, regression-tested). Don't reintroduce naive `restUrl + path` concatenation.
- **wp-env / Docker:** the daemon and disk have bitten us — a full Docker VM disk caused MySQL "No space left on device" (fix: `docker image prune -a`); Docker being down looks like "wp-env start failed". First-boot after a restart takes ~1–2 min.
- **Playwright:** WP login needs `input.value` set directly (fill/type is flaky on the show/hide-password field); the editor renders **duplicate top/bottom action-bar** buttons (Save/Back) so use `.first()`; anon/logged-out contexts need an explicit `baseURL`.
- **`Form_Repository::delete()` doesn't cascade** submissions — `seed.php` purges them on reseed.
- **`Provider_Manager::settings()`** does a per-provider deep merge (wp_parse_args is shallow) so late-added providers get their defaults on sites that saved settings earlier.
- **Backend: no post-response work on serverless.** A Vercel instance can be frozen the moment the response flushes, so a fire-and-forget promise may never run — and this is invisible locally, where nothing freezes. The usage-alert call in `rapid-ai-cloud/src/routes/generate.ts` is `await`ed for exactly this reason (it does no I/O unless a threshold is crossed, so it's free). Note `c.executionCtx` is **not** available: `hono/vercel`'s adapter is a bare `(req) => app.fetch(req)` and passes no ExecutionContext — use `waitUntil` from `@vercel/functions` if real deferral is ever needed.
- **Backend: dev-only env vars that must never reach prod** — `ALLOW_PRIVATE_CALLBACK` (disables the SSRF guard), `NODE_TLS_REJECT_UNAUTHORIZED=0`, `MOCK_LLM` (fake output, real quota debits), `MAIL_DEV` (sign-in links to stdout). They live in `.env.example` because local dev needs them, which is what makes them easy to paste in by accident.

## Doc map

- Product/technical spec: [`SPEC.md`](SPEC.md) (§5A = cloud contract, §12 = roadmap).
- Roadmap cheatsheet: [`ROADMAP.md`](ROADMAP.md).
- Cloud plan + resume note: [`PLAN-rapid-ai-cloud.md`](PLAN-rapid-ai-cloud.md) §0.
- Anti-abuse plan: [`PLAN-submission-integrity.md`](PLAN-submission-integrity.md).
- Manual/automated test checklist: [`SMOKE-TEST.md`](SMOKE-TEST.md); e2e harness: `tests/e2e/README.md`.
- Release: [`DEPLOY.md`](DEPLOY.md), [`SUBMITTING-TO-WP-ORG.md`](SUBMITTING-TO-WP-ORG.md).
- Backend: `rapid-ai-cloud/README.md` (handoff), `rapid-ai-cloud/docs/DEPLOY-VERCEL.md` (production deploy), `rapid-ai-cloud/docs/lemonsqueezy.md`.
- Agent conventions: [`../CLAUDE.md`](../CLAUDE.md).
