# Releasing with `bin/svn-deploy.sh`

How to push a new version of Rapid AI Forms to the WordPress.org SVN repository.

This script is the day-to-day release path. It is **verify-only** (you bump the version
in source; the script refuses to run unless everything agrees — it never edits your
files) and **dry-run by default** (nothing is committed to SVN without `--commit`).

> First-time SVN setup (checkout layout, the very first 0.1.0 release) lives in
> [`SUBMITTING-TO-WP-ORG.md`](SUBMITTING-TO-WP-ORG.md) §3. This doc covers the repeatable
> per-version workflow.

---

## Prerequisites (once)

- **SVN installed** — `svn --version` (macOS: `brew install svn`).
- **wp.org SVN access** for the `rapid-ai-forms` slug (granted on approval).
- **A local SVN working copy.** The script defaults to `~/wp-org/rapid-ai-forms` and will
  `svn checkout` it for you on first run. To use a different path, pass `--svn-dir`.
- A clean `npm install` + `composer install` in the git repo (the script runs
  `npm run build` and `composer lint`).

---

## The release, step by step

### 1. Bump the version in source — all three, one commit

The plugin file's `Version:` header is the source of truth. Two other places must match:

| File | What to change |
|---|---|
| `rapid-ai-forms.php` | `* Version:           X.Y.Z` |
| `readme.txt` | `Stable tag: X.Y.Z` |
| `readme.txt` | a new `= X.Y.Z =` block at the top of `== Changelog ==` |

Commit them. (Optionally tag git too: `git tag vX.Y.Z` — the script warns if this is
missing but doesn't require it.)

If any of the three disagree, or the changelog entry is absent, the script aborts before
touching SVN.

### 2. Confirm Plugin Check passes

This is the same ruleset wp.org's bot runs. The script can't reach lando, so do it first:

```bash
bash bin/dist.sh --to ~/Dev/lando/sites/wooDev/wp-content/plugins --no-build
cd ~/Dev/lando/sites/wooDev && lando wp plugin check rapid-ai-forms
# must report: Success: Checks complete. No errors found.
```

### 3. Dry-run the deploy

From the git repo root. This builds, runs every guard, syncs a throwaway view of
`trunk/` + `assets/`, and prints the pending `svn status` — **but commits nothing.**

```bash
npm run deploy
# ≡ bash bin/svn-deploy.sh
```

Read the `svn status` block carefully:
- `A` = file added, `D` = file removed, `M` = modified.
- A removed source file should show as `D` in trunk (the script handles deletions).

### 4. Push it

Once the dry-run looks right:

```bash
npm run deploy -- --commit
```

The script commits `trunk/` + `assets/`, then creates and commits `tags/X.Y.Z`. The
release goes live on wp.org within minutes once `trunk/readme.txt`'s `Stable tag` matches
the new tag folder. Verify at <https://wordpress.org/plugins/rapid-ai-forms/>.

---

## What the script does (and guards against)

| Step | Behavior |
|---|---|
| Version triad | Asserts plugin `Version:` == `Stable tag:` == top changelog entry, and that the entry exists. Aborts otherwise. |
| Clean git tree | Refuses to run with uncommitted changes — only committed code ships. Warns if no `vX.Y.Z` git tag. |
| Lint + build | Runs `composer lint` and a fresh `npm run build` (via `dist.sh --stage`), so trunk always reflects a clean build. |
| Trunk sync | Replaces `trunk/` with the staged build, **deletion-safe** — files dropped from the build are `svn rm`'d, not left behind. |
| Assets sync | Copies the repo's `assets/` (icon/banner/screenshots) into SVN `assets/`. Skips cleanly if there are none. |
| Tag guard | **Refuses to overwrite an existing `tags/X.Y.Z`.** A published tag is immutable — bump the version and re-release instead. |
| Commit | Only with `--commit`. Commits trunk+assets, then copies trunk to the tag and commits that. |

---

## Options

```
bin/svn-deploy.sh [options]

  --commit              Actually push to SVN (default is dry-run).
  --svn-dir <path>      SVN working copy location (default: ~/wp-org/rapid-ai-forms).
  --message "summary"   Commit summary (default: first changelog bullet for the version).
  --skip-lint           Skip composer lint. Not recommended; for quick re-runs only.
  -h, --help            Print usage.
```

Pass options through npm with `--`:

```bash
npm run deploy -- --commit --message "Add free Rapid AI Cloud provider"
```

---

## Troubleshooting

- **"Working tree has uncommitted changes"** — commit or stash first. The script ships
  committed code only.
- **"Version mismatch" / "No '= X.Y.Z =' entry"** — step 1 is incomplete; reconcile the
  three locations.
- **"tags/X.Y.Z already exists"** — that version is already published and tags are
  immutable. Bump to the next version and release that.
- **SVN auth prompt** — enter your wp.org credentials; `svn` caches them for later runs.
- **A deleted file still shows in trunk** — shouldn't happen (the script `svn rm`s missing
  files), but the manual fallback in `SUBMITTING-TO-WP-ORG.md` §3 documents the same
  `svn status | awk '/^!/' | xargs svn rm` step if you ever deploy by hand.

---

## Don't

- **Never edit anything under `tags/*`** after committing it. Bump and re-release.
- **Never let `Stable tag` point at a tag that doesn't exist yet** — users get a broken
  release. The script's ordering (commit trunk, then create the tag) avoids this.
