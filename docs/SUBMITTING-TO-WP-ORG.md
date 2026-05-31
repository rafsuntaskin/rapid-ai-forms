# Submitting Rapid AI Forms to WordPress.org

A practical, checklist-driven guide for getting v0.1.0 onto the WordPress.org plugin directory and then keeping it updated.

---

## 0. TL;DR

```bash
# Verify nothing is broken.
composer install
composer lint                             # must say 0 errors
npm install
npm run build                             # must finish without errors

# Generate fresh translations.
wp i18n make-pot . languages/rapid-ai-forms.pot --domain=rapid-ai-forms \
  --exclude=build,node_modules,docs,vendor,bin,dist

# Build the upload zip.
npm run dist                              # writes dist/rapid-ai-forms.zip

# Run the official Plugin Check ruleset (requires the Plugin Check plugin
# installed on a local WP — wooDev in our setup).
bash bin/dist.sh --to ~/Dev/lando/sites/wooDev/wp-content/plugins --no-build
cd ~/Dev/lando/sites/wooDev && lando wp plugin check rapid-ai-forms
# must report: Success: Checks complete. No errors found.
```

Upload `dist/rapid-ai-forms.zip` at <https://wordpress.org/plugins/developers/add/>. Wait for review (1–14 days). Once approved, SVN access is granted to the assigned slug; push the tagged release there.

---

## 1. Pre-submission checklist

Run through this top-to-bottom *before* touching the submission form.

### Plugin identity
- [ ] **Plugin Name does not contain "WordPress" or "WP"** — wp.org's trademark policy forbids both. We hit this on the original "WP AI Forms" name and had to rebrand.
- [ ] **Plugin Name does not lead with a generic adjective.** The review bot also rejects names that start with common adjectives like *Easy*, *Simple*, *Advanced*, *Best*, *Ultimate*, *Smart* — it treats them as non-distinctive and as potential trademark-lookalikes. We hit this on "Easy AI Forms" → bounced for "Easy AI" reading like a brand. Lead with a coined term or your personal-brand prefix instead.
- [ ] **Code prefix is at least 4 characters and not a common word.** The bot flags `easy_*`, `simple_*`, etc. as too generic. Derive a short prefix from the plugin name (we use `raif`) for CSS classes and error codes, and use the full slug for namespaces, hooks, and options. See [Plugin Guidelines §17](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#17-plugins-must-respect-trademarks-copyrights-and-project-names).
- [ ] `rapid-ai-forms.php` has no placeholder `Plugin URI:` (we removed ours — `example.com/...` URIs get flagged).
- [ ] `Author` is the wp.org **username** (matches `Contributors:` in readme.txt). For us: `rafsuntaskin`.
- [ ] `Author URI` points at the wp.org profile (`https://profiles.wordpress.org/<user>/`).
- [ ] `Text Domain: rapid-ai-forms` matches the slug exactly.
- [ ] `Domain Path: /languages` exists and contains `rapid-ai-forms.pot`.
- [ ] `load_plugin_textdomain()` is **not called** — wp.org auto-loads translations for hosted plugins (Plugin Check flags it as discouraged).
- [ ] License header reads `GPL-2.0-or-later` (or another GPL-compatible).
- [ ] **Slug `rapid-ai-forms` is available** on wp.org — re-check at the Add page right before submitting; slugs are first-come, first-served.

### readme.txt header
- [ ] `Contributors:` lists real wp.org usernames.
- [ ] `Tags:` no more than 5, none misleading or competitor names. Lead with the highest-intent search term (for us: `contact form`).
- [ ] `Requires at least: 6.4` matches reality.
- [ ] `Tested up to: 7.0` — bump to the latest stable WP after smoke-testing on it. Plugin Check raises `outdated_tested_upto_header` if this lags behind the current major release.
- [ ] `Requires PHP: 7.4` matches `composer.json`.
- [ ] `Stable tag: 0.1.0` matches the plugin file's Version header.
- [ ] Short description ≤ 150 characters, no marketing fluff, no upsells.

### readme.txt body
- [ ] `== Description ==` describes what the plugin does, not what it might do.
- [ ] `== Privacy and external services ==` discloses *every* outbound HTTP call the plugin can make, when, with what data, to which third party. (Already present — see §1.5 below.)
- [ ] `== Installation ==` lists steps using only the WP-admin UI.
- [ ] `== Frequently Asked Questions ==` covers at least: "do I need an account?", "what data is sent externally?", "can I use it without AI?".
- [ ] `== Changelog ==` first entry matches `Stable tag` exactly.
- [ ] No links to paid services, no "PRO" or "Premium" upsell mentions, no advertising or affiliate links.

### Privacy & external services disclosure
This is the most common reason new plugins get held in review. Our readme already has it — but **before each submission, re-read the section and confirm**:

- [ ] Every external endpoint the plugin can hit is named.
- [ ] The trigger for each call is named (e.g. "when an admin clicks Generate").
- [ ] What data is transmitted is named (e.g. "the user's natural-language prompt, plus the current form schema when editing").
- [ ] What data is NOT transmitted is also stated (form submissions, site content, user data).
- [ ] A link to the third party's privacy policy or terms is offered, if reasonable.
- [ ] No external calls happen without explicit administrator configuration.

For this plugin: Anthropic, Google Gemini, OpenAI-compatible endpoints. Triggered by admin actions in the form editor and Settings page. Not triggered on activation, not on plugin install, not on the frontend.

### Code review
- [ ] `composer lint` → 0 errors, 0 warnings. (`composer install` first if missing deps.)
- [ ] `lando wp plugin check rapid-ai-forms` → `No errors found.` This is the same ruleset wp.org's automated bot runs against your submission.
- [ ] No `error_log()`, `var_dump()`, `print_r()`, `console.log()` left in shipped files (search the dist zip after building).
- [ ] No `eval()`, no `create_function()`, no `assert()` on dynamic input.
- [ ] All user input is sanitized at the boundary (`sanitize_text_field`, `sanitize_email`, `esc_url_raw`, etc.).
- [ ] All output is escaped at point of output (`esc_html`, `esc_attr`, `esc_url`).
- [ ] All `$wpdb` queries use `prepare()` with placeholders (we use `%i` for table names — requires WP 6.2+, we require 6.4+ so fine). Custom-table repositories carry a file-level `phpcs:disable WordPress.DB.DirectDatabaseQuery.*` block with a docblock explaining why direct queries are legitimate for plugin-owned tables.
- [ ] All REST endpoints have a `permission_callback`. The submission endpoint is intentionally public; its permission_callback returns `__return_true` with a comment explaining why.
- [ ] All admin REST endpoints require `manage_options` or stricter.
- [ ] All AJAX/REST writes verify a nonce.
- [ ] No use of `wp_die()` for output without `esc_html()`.

### JS/build
- [ ] `npm run build` produces `build/admin.js`, `build/admin.css`, `build/admin.asset.php`, `build/frontend.js`, `build/frontend.css`, `build/frontend.asset.php`.
- [ ] No source maps in the dist zip.
- [ ] No `// TODO`, debug logs, or unused devtools-only code in shipped JS.

### i18n
- [ ] Every user-visible PHP string uses `__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()` with the `'rapid-ai-forms'` text domain.
- [ ] Every user-visible JS string uses `@wordpress/i18n`'s `__`, `_n`, `_x`, `sprintf` with the `'rapid-ai-forms'` text domain.
- [ ] Every string containing `%s`, `%d`, `%1$s` etc. has a `/* translators: ... */` comment immediately above it.
- [ ] `wp_set_script_translations( 'rapid-ai-forms-admin', 'rapid-ai-forms' )` is called for the admin bundle.
- [ ] `languages/rapid-ai-forms.pot` regenerated and committed.

### Assets (uploaded separately to wp.org, not in the plugin zip)

**Not required for approval.** The Plugin Review Team reviews code, not visuals — submitting without screenshots, icon, or banner won't trigger a hold. These are about how the plugin **page** looks after it's live in the directory. You can add them within minutes of approval via SVN — no plugin re-release needed.

- [ ] **Icon** at `assets/icon-128x128.png` and `assets/icon-256x256.png` (or `icon.svg`).
- [ ] **Banner** at `assets/banner-772x250.png` and `assets/banner-1544x500.png` (retina).
- [ ] **Screenshots** at `assets/screenshot-1.png`, `screenshot-2.png`, etc. — numbered to match captions in `readme.txt` `== Screenshots ==`.
- [ ] If shipping without a `== Screenshots ==` section initially, **don't leave dangling captions in the readme** — the section renders as plain text under no images. Add the section back when the SVN files are committed.
- [ ] Screenshots reflect the current UI, not older versions.
- [ ] No screenshots show "coming soon" or paid-tier mentions.

These files live in **SVN `/assets/`**, not in `trunk/`. The plugin zip excludes the local `assets/` folder via `.distignore`.

### Smoke test on a clean install
- [ ] Spin up a fresh WordPress 6.4 install (lowest supported).
- [ ] Install the dist zip, activate.
- [ ] Confirm no PHP errors in `wp-content/debug.log` with `WP_DEBUG = true`.
- [ ] Walk the golden path: configure provider → verify connection → create form → generate fields → embed shortcode → submit on frontend → row appears in `wp_rapid_ai_form_submissions` → admin notification email is dispatched.
- [ ] Deactivate. Confirm no fatal errors. Submissions and form rows are preserved (correct behavior).
- [ ] Re-activate. Confirm everything still works.
- [ ] Repeat on WordPress 7.0 (`Tested up to`). On 7.0 also confirm the **WordPress AI Client** provider appears in the Settings dropdown and that selecting it shows the Connectors notice instead of API key fields.

### Build the upload artifact
- [ ] `npm run dist` produces a zip in `dist/rapid-ai-forms.zip`.
- [ ] Zip is < 10 MB (we're at ~253 KB with source bundled).
- [ ] Zip's top-level entry is exactly `rapid-ai-forms/` (verify with `unzip -l dist/rapid-ai-forms.zip | head -3`).
- [ ] Zip contains: `rapid-ai-forms.php`, `readme.txt`, `LICENSE`, `includes/`, `build/`, `languages/`, **`src/`**, **`package.json`**, **`package-lock.json`**, **`webpack.config.js`**. The `src/` + build tooling is bundled to satisfy wp.org Guideline 4 (public source access for compiled assets) — see the "Source code accessibility" callout below.
- [ ] Zip does NOT contain: `assets/`, `node_modules/`, `vendor/`, `docs/`, `bin/`, `.git/`, `.claude/`, `.distignore`, `composer.json`, `phpcs.xml.dist`, `CLAUDE.md`.
- [ ] JS bundles are minified (look at `unzip -p ... build/admin.js | head -c 200` — should be a single line of dense code).
- [ ] No `*.map` source maps in the zip.

### Source code accessibility (Guideline 4)
wp.org's review bot specifically flags `build/*.js` as minified artifacts with no discoverable source counterpart. There are two ways to satisfy this. We do **both** for belt-and-suspenders:

- [ ] **Bundle source in the zip.** `src/`, `package.json`, `package-lock.json`, and `webpack.config.js` ship inside the plugin so `npm install && npm run build` reproduces `build/` from a fresh extract. The `== Development ==` section of `readme.txt` documents this.
- [ ] **Public repo URL in the readme.** The `== Development ==` section links to `https://github.com/rafsuntaskin/rapid-ai-forms`. The repo must be public — a private repo URL fails the check silently when the reviewer clicks through.
- [ ] **`Plugin URI:` header** also points at the public repo so the link is visible directly from the Plugins screen in wp-admin.
- [ ] **`LICENSE` file** at the plugin root (canonical GPL-2.0 text). Plugin Check doesn't require it, but GitHub uses it to auto-detect the project license; the wp.org review team appreciates seeing it too.

---

## 2. Submission steps

1. Sign in at <https://wordpress.org/plugins/developers/> with your wp.org account.
2. Go to <https://wordpress.org/plugins/developers/add/>.
3. Fill the form:
   - **Plugin Name**: `Rapid AI Forms`
   - **Description**: paste the short description from `readme.txt` (line 11).
   - **Plugin ZIP**: upload `dist/rapid-ai-forms.zip`.
4. Submit. You'll receive an immediate confirmation email and a tracking link.
5. The Plugin Review Team will run automated checks first. If those pass, a human reviewer is assigned.
6. **Review SLA is 1–14 days.** Most replies arrive within 5 business days. Replies come from `plugins@wordpress.org`.

### If the review team replies

- Treat their email as actionable. Don't argue stylistic choices — fix and resubmit.
- If they ask for a code change: edit the source, re-run `composer lint`, `npm run build`, `npm run dist`, then **reply to the same email thread with the new zip attached**. Do not start a new submission.
- Common holds for our plugin: privacy disclosure must mention all three providers by name; readme.txt must not promise features not yet implemented.

---

## 3. Once approved: SVN setup

> **You are here (2026-05-31).** v0.1.0 was approved by the wp.org Plugin Review Team and SVN access has been granted at `https://plugins.svn.wordpress.org/rapid-ai-forms/`. The next concrete step is the first SVN commit using the workflow below.

Pre-flight (before the first svn commit):

- [ ] `dist/rapid-ai-forms.zip` is the freshly built artifact — same one approved by the review team, or a strict superset (we can include the LICENSE addition and any post-approval doc tweaks).
- [ ] `Stable tag: 0.1.0` in `readme.txt` matches what we're about to tag.
- [ ] Screenshots are ready (see §4). They go in SVN `assets/`, not `trunk/`, and can be added/updated independently without re-releasing.
- [ ] Confirm the SVN URL in the approval email matches: `https://plugins.svn.wordpress.org/rapid-ai-forms/`. wp.org sends the URL once; the slug is locked at this point and cannot be changed.

```bash
# Check out the wp.org SVN (separate from the git repo — keep them in different folders).
svn checkout https://plugins.svn.wordpress.org/rapid-ai-forms ~/wp-org/rapid-ai-forms
cd ~/wp-org/rapid-ai-forms

# Layout (already created by wp.org):
#   trunk/        ← bleeding-edge code, often matches the next release
#   tags/         ← one folder per released version
#   assets/       ← icon, banner, screenshots (NOT shipped with the plugin)
```

### First release (0.1.0)

```bash
cd ~/wp-org/rapid-ai-forms

# 1. Copy the contents of dist/rapid-ai-forms/ into trunk/.
rm -rf trunk/*
unzip -q /path/to/git-repo/dist/rapid-ai-forms.zip -d /tmp/raif-release
cp -R /tmp/raif-release/rapid-ai-forms/* trunk/

# 2. Copy assets (icon, banner, screenshots) into assets/.
#    Local assets/ folder is excluded from the plugin zip via .distignore,
#    so this is the first time these files leave the dev repo.
cp /path/to/git-repo/assets/screenshot-*.png assets/
cp /path/to/git-repo/assets/icon-*.png assets/      # or icon.svg
cp /path/to/git-repo/assets/banner-*.png assets/

# 3. Add new files, then commit trunk + assets.
svn add --force trunk assets
svn status                              # review before committing
svn commit -m "0.1.0: initial release"

# 4. Tag the release. Copying from trunk creates the tag folder server-side.
svn copy trunk tags/0.1.0
svn commit -m "Tag 0.1.0"
```

The release becomes downloadable on wp.org within minutes once the `Stable tag` in `trunk/readme.txt` matches the tag folder name.

### Subsequent updates (0.2.0, etc.)

```bash
# Update Stable tag and changelog in readme.txt, bump Version in rapid-ai-forms.php.
# Rebuild and re-zip in the git repo.

# Sync trunk to the new code.
cd ~/wp-org/rapid-ai-forms
rm -rf trunk/*
unzip -q /path/to/git-repo/dist/rapid-ai-forms.zip -d /tmp/raif-release
cp -R /tmp/raif-release/rapid-ai-forms/* trunk/
svn add --force trunk
svn status                              # check
svn commit -m "0.2.0: <one-line summary>"

# Tag it.
svn copy trunk tags/0.2.0
svn commit -m "Tag 0.2.0"
```

**Important rules:**
- Never edit anything in `tags/*` after committing it. If you need to fix a tag, bump the version and release a new one.
- The `Stable tag` in `trunk/readme.txt` is what wp.org actually serves to users. If `Stable tag: 0.2.0` but no `tags/0.2.0/` exists yet, users see a broken release.
- Increment `Version` in `rapid-ai-forms.php` *and* `Stable tag` in `readme.txt` *and* the changelog entry — all three together.

---

## 4. Assets (icon, banner, screenshots)

These live in the SVN `assets/` folder, not the plugin zip. wp.org serves them from a CDN once committed.

| File | Size | Notes |
|---|---|---|
| `icon-128x128.png` or `icon.svg` | 128×128 | Used in admin Plugins screen, in directory listings |
| `icon-256x256.png` | 256×256 | Retina version. PNG only if you can't produce SVG. |
| `banner-772x250.png` | 772×250 | Plugin page header (standard density) |
| `banner-1544x500.png` | 1544×500 | Retina banner |
| `screenshot-1.png` through `screenshot-N.png` | any | Order matches `== Screenshots ==` section in readme.txt |

When adding screenshots, also add a section to `readme.txt`:

```
== Screenshots ==

1. The form editor with the live preview pane.
2. Settings page showing the verify-connection button.
3. The card-based forms list with search.
```

Then update the file in `trunk/` and re-commit. The screenshots in `assets/` will not appear in the live directory until the next release pushes a new version.

---

## 5. Things that will NOT pass review

Based on the WP plugin guidelines, these will get rejected outright:

- **Mentioning paid features anywhere in shipped code or readme.** We scrubbed this — no "managed service", "credits", "coming soon: paid". Keep it that way.
- **External services not disclosed.** Adding a new AI provider in the future means updating the privacy section before submitting the update.
- **Loading external JS/CSS from a CDN.** We don't. All assets are local.
- **Phoning home with site data on activation.** We don't.
- **Bundling minified code without source.** Reviewers run an automated check. Our `build/*.js` is minified but the `src/` is in git and `package.json` documents the build command — that satisfies the rule.
- **GPL-incompatible code.** All dependencies are GPL or MIT. Composer dev deps don't ship.
- **Trademark violations.** `Rapid AI Forms` doesn't infringe. Provider names appear in feature lists, which is fine; they don't appear in the plugin name.

---

## 6. Post-launch maintenance

- **Watch the support forum** at `https://wordpress.org/support/plugin/rapid-ai-forms/` daily for the first week, weekly after.
- **Respond to security reports** via `plugins@wordpress.org`, never publicly. Security patches must ship through SVN within 14 days or wp.org may pull the plugin.
- **Bump `Tested up to`** when each new WP major lands. Run the smoke test on the new version first.
- **Translation contributions** flow through translate.wordpress.org once approved — no need to merge PRs manually for translations.

---

## 7. Repository entry points

| Need | Where |
|---|---|
| Source code | git repo at the plugin root |
| Build the dist zip | `npm run dist` → `dist/rapid-ai-forms.zip` |
| Run PHPCS | `composer lint` (auto-fix: `composer lint:fix`) |
| Regenerate translations | `wp i18n make-pot . languages/rapid-ai-forms.pot --domain=rapid-ai-forms --exclude=build,node_modules,docs,vendor,bin,dist` |
| Architecture and contracts | [`docs/SPEC.md`](SPEC.md) |
| Deferred features | [`docs/PLAN-ai-css-editor.md`](PLAN-ai-css-editor.md), SPEC.md §12 roadmap |
| Agent notes / conventions | `CLAUDE.md` |
