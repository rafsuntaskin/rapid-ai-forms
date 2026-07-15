# Smoke test — Rapid AI Forms

Manual release checklist for the admin + frontend flows. The core form-flow
scenarios (2, 3 partial, 6, plus AI generation) are now automated in `tests/e2e/`
(Playwright against `wp-env` — see `tests/e2e/README.md`), including a
plain-permalink REST regression; the rest remain manual for now. Run it
against the **installed release zip** (`npm run dist` → install via
`wp plugin install <zip> --force --activate`), not the rsync dev copy, so the test
exercises exactly what ships.

> Status: partly automated. **Playwright suite: 9/9 green (2026-07-15)** on
> `wp-env` — covers AI form creation (stub provider), frontend render +
> required-field validation (client + server), a stored submission surfacing in
> the admin list, the submissions filter-by-form + detail modal / email preview,
> and a plain-permalink REST regression. Run with `npm run test:e2e`.
>
> Last full **manual** pass: **0.2.0 (2026-06-15)** on the local Lando *wooDev*
> site (WP 7.0) — all scenarios passed, no console errors; covers the v0.2
> surface (BYOK/WP-AI-Client providers, submissions, required-field validation,
> custom CSS). Rapid AI Cloud flows were browser-verified on wooDev (Connect
> handshake, live quota meter, generation debiting purchased credits); fold
> those into a spec when the provider is un-gated.

## Conventions

- **Base:** `{SITE}/wp-admin/`. Admin pages are addressed by `?page=` slug; sub-views
  inside the Forms page use a hash route.
- **Auth:** logged-in administrator (`manage_options`). In Playwright, log in once and
  reuse `storageState`.
- **AI:** these scenarios need **no AI key** — generation is exercised separately and
  requires a configured provider. Everything below works with AI unconfigured.
- **Selectors:** prefer the stable `raif-` classes and visible text below over DOM
  position. Where a real generation is needed, stub the provider HTTP with a mu-plugin
  (`pre_http_request`) rather than calling a live API.

| View | URL |
|---|---|
| Forms list | `?page=rapid-ai-forms` |
| Form editor | `?page=rapid-ai-forms#/forms/{id}` |
| Submissions | `?page=rapid-ai-forms-submissions` (optional `&form_id={id}`) |
| Settings | `?page=rapid-ai-forms-settings` |
| Frontend preview (admin-only) | `{SITE}/?rapid_ai_form_preview={id}` |

## Preconditions / fixtures

Playwright should seed via WP-CLI in `globalSetup` rather than depend on existing data:

- At least one published form with a `textarea`/`message` field (e.g. "Contact Form":
  name*, email*, message) — exercises required validation + message excerpt.
- At least one form **without** a message field (e.g. "Event RSVP": full_name, email,
  guests) — exercises the labeled summary digest.
- A few submissions across ≥2 forms, with `notifications.enabled = true` on at least one
  — exercises the cross-form list, filter, and email preview.

---

## Scenarios

### 1. Plugin loads clean
- **Go to** Forms list. **Expect:** page renders, no PHP notice, no console error.
- **Assert:** `RAPID_AI_FORMS_ADMIN` global present; `wp plugin get rapid-ai-forms
  --field=version` equals the release version.

### 2. Forms list — ✅ partly automated (`admin-form-flow.spec.ts`: created form appears in list)
- **Go to** Forms list.
- **Expect:** `.raif-list__grid` with one `.raif-card` per form; each card shows field
  count, relative "Updated", a click-to-copy shortcode, and **Edit / Submissions /
  Delete** buttons. Header count matches `X-WP-Total`.
- **Playwright:** assert `.raif-card` count == seeded form count; "Submissions" link
  href contains `page=rapid-ai-forms-submissions&form_id=`.

### 3. Submissions dashboard (cross-form list) — ✅ partly automated (`frontend-submission.spec.ts`: stored row surfaces in the list)
- **Go to** Submissions.
- **Expect:** `.raif-submissions__list` with a `.raif-submissions__row` per entry,
  newest first. Each row: `#id` + `.raif-submissions__form-pill` (form title),
  `.raif-submissions__excerpt`, relative/absolute timestamp, a **View** button.
- **Assert summary logic** (`.raif-submissions__excerpt`):
  - message-style form → shows the textarea/`message` value verbatim.
  - form without a message field → labeled digest `Label: val · Label: val` (≤3 fields).
  - empty submission → `(no content)`.

### 4. Submissions — filter by form — ✅ automated (`submissions-dashboard.spec.ts`)
- On Submissions, choose a form in the **"Filter by form"** select (`SelectControl`).
- **Expect:** list narrows to that form; header count updates; the form pill is hidden
  while filtered. Deep link `&form_id={id}` applies the filter on load.

### 5. Submission detail modal + email preview — ✅ automated (`submissions-dashboard.spec.ts`)
- Click **View** on a row from a form with notifications enabled.
- **Expect** `.raif-submission-modal`: form pill + timestamp, a field table covering
  every non-hidden/non-password schema field (empty → `—`), then **IP address** and
  **User agent** rows.
- **Expect** `.raif-submission-modal__email`: an **Email notification** section with the
  rendered subject and a `<pre>` body with mail-tags resolved (e.g. `Name: <value>`).
  Absent when the form has notifications disabled.

### 6. Required-field validation (frontend, the integrity fix) — ✅ automated (`frontend-submission.spec.ts`: native + server-side, plus stored success)
- Render a form with required fields on a page (`[rapid_ai_form id="{id}"]`); submit
  empty or with only some required fields filled.
- **Expect:** request returns **HTTP 422**; per-field `.raif-field-error` messages
  appear inline next to the offending fields; **no submission row is created**.
- Fill all required fields, submit → **200**, success message, row stored.
- **API-level assert** (also a good fast test): `POST
  /wp-json/rapid-ai-forms/v1/submissions/{uuid}` with a missing required field →
  `422 raif_validation` with `data.fields`; submission count unchanged.

### 7. Form editor — Form / Style tabs
- **Go to** the form editor for a form.
- **Expect:** a `.raif-editor__tabs` TabPanel with **Form** (default) and **Style** tabs;
  Save / Back action bar sits outside the tabs. Form tab shows the AI prompt card, form
  details, fields, notifications, and a live mock preview.

### 8. Style tab — custom CSS + theme iframe preview
- Click the **Style** tab.
- **Expect:** prompt box + **Apply with AI** button, a `.raif-css-editor` textarea, and
  an `<iframe>` (`.raif-styling__preview`) loading `/?rapid_ai_form_preview={id}`
  rendered with the active theme — **no admin bar** inside the frame.
- Type CSS (e.g. `label { color: crimson; font-size: 22px; }`) into the textarea.
- **Expect:** after ~600ms debounce, the iframe's labels restyle live (no reload).
- **Playwright:** assert inside `frameLocator('.raif-styling__preview iframe')` that a
  label's computed `color` is `rgb(220, 20, 60)`.

### 9. Custom CSS persistence + scoping
- In the Style tab, enter CSS and click **Save**; reload the editor.
- **Expect:** CSS persists (it round-trips through `settings.custom_css`).
- On the frontend, **expect** a `<style id="raif-css-{uuid}">` block scoping rules under
  `.raif-form[data-form-uuid="{uuid}"]`; a second form on the same page is unaffected.
- **Security assert:** saving `</style><script>alert(1)</script>` results in stripped
  output — no `<script>` reaches the rendered page.

### 10. Preview route is admin-only
- Logged out (or non-admin), **GET** `/?rapid_ai_form_preview={id}`.
- **Expect:** HTTP **403**. As admin: 200 with the form inside a minimal themed document,
  `X-Frame-Options: SAMEORIGIN`, no-cache headers.

### 11. Settings
- **Go to** Settings.
- **Expect:** provider dropdown lists the available providers; selecting a BYOK provider
  shows API key / model (and base URL where applicable); **Verify connection** is
  disabled until a key is present. `GET /settings` never returns a stored `api_key`
  (only `api_key_set`).

### 12. Plain-permalink REST calls — ✅ automated (`plain-permalinks.spec.ts`)
- Switch the site to **plain** permalinks (`?rest_route=/…/v1/` REST root), submit a
  form entry, then open the admin **Submissions** list filtered by `form_id`.
- **Expect:** the filtered list loads (no "Failed to load submissions"); the row is
  visible. Regression for the query-string collision fixed in `c085958` — the admin
  SPA's `submissions?form_id=` call must not 404 under plain permalinks.

---

## Notes on the Playwright harness

Built under `tests/e2e/` — see `tests/e2e/README.md` for the run instructions.

- Runs WP via `@wordpress/env`; targets `http://localhost:8888`.
- `global-setup.ts`: activates the plugin, sets pretty permalinks, seeds a form +
  page (`seed.php` → `.fixtures.json`), and saves an admin `storageState`.
- The stub AI provider is a mu-plugin registering an `e2e_stub` provider
  (`tests/e2e/mu-plugins/raif-e2e.php`, mounted via `.wp-env.json` mappings, dist-
  excluded) so generation is deterministic and never hits a real provider.
- Mirrors rather than duplicates PHPUnit: PHPUnit covers the REST contracts
  (`tests/test-rest-submissions.php`) and sanitizers; Playwright's job is the React
  UI and the rendered frontend.
- **Still manual (candidates to automate next):** scenarios 7–9 (editor tabs, Style
  tab + iframe preview, CSS persistence/scoping), 10–11 (preview route auth, Settings).
