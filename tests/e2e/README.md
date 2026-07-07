# Playwright e2e — Rapid AI Forms

Browser-level tests for the plugin's form flow, run against the local
`@wordpress/env` **dev** site (`http://localhost:8888`). They complement the
PHPUnit suite (REST contracts + sanitizers) by driving the React admin UI and
the rendered frontend form.

## Prerequisites

- Docker running.
- `npm install` (installs `@playwright/test`).
- `npx playwright install chromium` (one-time browser download).

## Run

```bash
npx wp-env start          # boots the dev site + mounts the stub-provider mu-plugin
npm run test:e2e          # headless run
npm run test:e2e:ui       # interactive UI mode
npm run test:e2e:report   # open the last HTML report
```

`globalSetup` (see `global-setup.ts`) runs once per invocation: it activates the
plugin, selects the stub AI provider, seeds a contact form + a published page
that embeds it (`seed.php` → `.fixtures.json`), and saves an authenticated admin
`storageState` the specs reuse.

## How it works

- **Stub AI provider** (`mu-plugins/raif-e2e.php`, mounted via `.wp-env.json`
  `mappings`) registers an `e2e_stub` provider that returns a fixed schema, so
  the "Generate with AI" flow is deterministic and never calls a real API. The
  mu-plugin exists only in the wp-env site — it is excluded from the dist zip.
- **Fixtures** are written to `tests/e2e/.fixtures.json` (gitignored) and read by
  specs via `helpers.ts` → `readFixtures()`.
- **Time-trap awareness:** the frontend specs wait ~2.5s before submitting,
  because `Submission_Guard`'s time-trap silently discards anything submitted
  within `MIN_FILL_SECONDS` of the token being issued.

## Specs

| File | Covers |
|---|---|
| `specs/admin-form-flow.spec.ts` | New form → AI-generate fields (stub) → save → appears in list |
| `specs/frontend-submission.spec.ts` | Render, native + server-side required validation, successful stored submission |

Maps onto the manual scenarios in [`docs/SMOKE-TEST.md`](../../docs/SMOKE-TEST.md).
