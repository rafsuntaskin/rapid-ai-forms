# Plan: Safe, repeatable wp.org SVN release workflow

> **Status: implemented (2026-06).** `bin/svn-deploy.sh` + `bin/dist.sh --stage` shipped and
> were used for the 0.1.0 release. User-facing docs live in [`DEPLOY.md`](DEPLOY.md). This
> file is kept as the design record.

## Context

v0.1.0 is approved and SVN access is granted at
`https://plugins.svn.wordpress.org/rapid-ai-forms/`. Today the release steps in
`docs/SUBMITTING-TO-WP-ORG.md` §3 are hand-typed `svn` commands — easy to get wrong
(forget a version bump, leave a deleted file in trunk, accidentally overwrite a tag,
ship an unbuilt/dirty tree). With 0.2.0 (the free provider) coming, we want a guarded,
repeatable deploy so each version goes out correctly.

**Decisions (locked with user):** a **local bash script** (`bin/svn-deploy.sh`) that
fits the existing `bin/` tooling, and **verify-only** versioning — the human bumps the
version in source; the script refuses to run unless everything is consistent. It never
edits source files.

The script builds on the existing `bin/dist.sh`, which already stages a clean,
`.distignore`-honored copy of the plugin (note `docs/`, `bin/`, `assets/`, `vendor/`,
`node_modules/` are excluded; `src/` + build tooling ARE included for Guideline 4).

## Single source of truth for the version

The plugin file's `Version:` header is canonical. Two other places must agree:
- `readme.txt` → `Stable tag:`
- `readme.txt` → top `== Changelog ==` entry (`= X.Y.Z =`)

The script reads the header, then asserts the other two match and a changelog entry
exists — aborting otherwise.

## New: `bin/svn-deploy.sh`

```
bin/svn-deploy.sh [--svn-dir PATH] [--message "summary"]   # dry-run (default)
bin/svn-deploy.sh --commit [--svn-dir PATH] [--message …]  # actually push
```

Default SVN working copy: `~/wp-org/rapid-ai-forms` (override with `--svn-dir`).
**Dry-run is the default**; nothing is committed without `--commit`.

### Pre-flight guards (abort on any failure)
1. **Version triad consistency** — `Version:` == `Stable tag:` == top changelog entry;
   changelog entry for that version exists.
2. **Clean git tree** — refuse if there are uncommitted changes (we only ship committed
   code). Warn if `HEAD` has no matching `vX.Y.Z` git tag.
3. **Lint + build** — run `composer lint` (abort on error) and a fresh `npm run build`
   (via the staging step below) so trunk always reflects a clean build.
4. **Plugin Check reminder** — print the exact `lando wp plugin check rapid-ai-forms`
   command and require it to have passed (documented pre-req; the script can't reach
   lando reliably, so it prints a confirmation gate rather than silently skipping).

### Staging
- Add a `--stage <dir>` option to **`bin/dist.sh`** (small edit) so it can emit the clean
  staged tree to a caller-provided dir without zipping. `svn-deploy.sh` calls
  `bin/dist.sh --stage "$TMP"` to get the exact files that would ship.

### Sync trunk (deletion-safe — the classic SVN footgun)
1. `svn cleanup` + `svn update` the working copy.
2. `rm -rf trunk/*` then copy the staged tree into `trunk/`.
3. `svn add --force trunk` (picks up new files).
4. Remove files deleted since last release:
   `svn status trunk | awk '/^!/ {print $2}' | xargs -r svn rm` — without this, files
   dropped from the new build linger in trunk forever.

### Sync assets (icon / banner / screenshots)
- These live in the repo's `assets/` (which is `.distignore`d out of the plugin zip) and
  belong in SVN `assets/`, not `trunk/`. Copy `assets/*` → SVN `assets/`, then the same
  `svn add --force` + `! → svn rm` deletion handling. Skip cleanly if repo `assets/` is
  empty (assets aren't required for a release).

### Tag-never-overwrite guard
- Before tagging, `svn ls .../tags/$VERSION` — if it already exists, **hard abort**
  (wp.org rule: never re-edit a published tag; bump and re-release instead).

### Commit (only with `--commit`)
1. `svn status` summary printed for review.
2. `svn commit trunk assets -m "$VERSION: $MESSAGE"` (message defaults to the top
   changelog line if `--message` omitted).
3. `svn copy trunk tags/$VERSION && svn commit -m "Tag $VERSION"`.
4. Print post-deploy reminders: the release goes live within minutes once
   `trunk/readme.txt`'s `Stable tag` matches the new `tags/$VERSION`; verify on the
   wp.org page and bump `Tested up to` when relevant.

In dry-run, stop after the `svn status` summary and print: "re-run with --commit to push".

## Files

- **NEW `bin/svn-deploy.sh`** (`set -euo pipefail`, `--help` like `dist.sh`).
- **EDIT `bin/dist.sh`** — add `--stage <dir>` to emit the staged tree (reuses existing
  rsync/exclude logic; no behavior change to current flags).
- **EDIT `package.json`** — add `"deploy": "bash bin/svn-deploy.sh"`.
- **EDIT `docs/SUBMITTING-TO-WP-ORG.md`** §3 "Subsequent updates" — replace the manual
  command block with the `bin/svn-deploy.sh` workflow; keep the raw `svn` commands as a
  documented manual fallback. Cross-link the per-release checklist (version triad, lint,
  Plugin Check, dry-run, commit).

## Verification

1. **Dry-run, no commit:** with the SVN working copy checked out at `~/wp-org/rapid-ai-forms`,
   run `bin/svn-deploy.sh` → confirm it builds, the triad check passes, `svn status` shows
   the expected trunk diff, and it stops without committing.
2. **Guard tests:** temporarily mismatch `Stable tag` → aborts; dirty the git tree →
   aborts; point `--svn-dir` at a checkout where `tags/0.1.0` exists and set version to
   0.1.0 → tag guard aborts.
3. **Deletion handling:** delete a file from the build, dry-run, confirm it appears as a
   pending `svn rm` (status `D`), not left behind.
4. **Real release (0.2.0, when the free provider lands):** bump the triad, commit, ensure
   Plugin Check passed, `bin/svn-deploy.sh` (review dry-run) → `bin/svn-deploy.sh --commit`
   → confirm `tags/0.2.0` created and the version goes live on wp.org within minutes.

## Out of scope
- CI/GitHub-Action deploy (10up) — explicitly deferred; local script chosen.
- Automatic version bumping — verify-only by choice (no `bump-version.sh`).
