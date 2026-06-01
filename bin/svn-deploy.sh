#!/usr/bin/env bash
#
# Safe, repeatable deploy of a tagged release to the wp.org SVN repository.
#
# Verify-only versioning: you bump the version in source (rapid-ai-forms.php +
# readme.txt) and commit; this script refuses to run unless everything agrees.
# It never edits source files. Dry-run by default — nothing is committed to SVN
# without --commit.
#
# Usage:
#   bin/svn-deploy.sh                          # dry-run against ~/wp-org/rapid-ai-forms
#   bin/svn-deploy.sh --svn-dir <path>         # dry-run against a specific checkout
#   bin/svn-deploy.sh --message "summary"      # override the commit summary
#   bin/svn-deploy.sh --commit                 # actually push trunk + assets + tag
#   bin/svn-deploy.sh --skip-lint              # skip composer lint (not recommended)
#
# Pre-requisites (the script reminds you, but cannot run them for you):
#   - `lando wp plugin check rapid-ai-forms` reported "No errors found."
#   - The release commit is already in git (clean working tree).
#
# Examples:
#   bin/svn-deploy.sh
#   bin/svn-deploy.sh --commit --message "Add free Rapid AI Cloud provider"

set -euo pipefail

SLUG="rapid-ai-forms"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

SVN_DIR="${HOME}/wp-org/${SLUG}"
MESSAGE=""
DO_COMMIT=0
RUN_LINT=1

while [[ $# -gt 0 ]]; do
	case "$1" in
		--svn-dir)
			SVN_DIR="$2"
			shift 2
			;;
		--message)
			MESSAGE="$2"
			shift 2
			;;
		--commit)
			DO_COMMIT=1
			shift
			;;
		--skip-lint)
			RUN_LINT=0
			shift
			;;
		-h|--help)
			grep '^#' "$0" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		*)
			echo "Unknown option: $1" >&2
			exit 1
			;;
	esac
done

die() { echo "✗ $*" >&2; exit 1; }
info() { echo "▶ $*"; }
ok() { echo "✓ $*"; }

# ---------------------------------------------------------------------------
# 1. Resolve the canonical version and assert the triad agrees.
# ---------------------------------------------------------------------------
PLUGIN_FILE="$REPO_ROOT/${SLUG}.php"
README="$REPO_ROOT/readme.txt"

[[ -f "$PLUGIN_FILE" ]] || die "Plugin file not found: $PLUGIN_FILE"
[[ -f "$README" ]] || die "readme.txt not found: $README"

VERSION="$(grep -iE '^\s*\*?\s*Version:' "$PLUGIN_FILE" | head -1 | sed -E 's/.*Version:\s*//I' | tr -d '[:space:]')"
[[ -n "$VERSION" ]] || die "Could not read Version: header from $PLUGIN_FILE"

STABLE_TAG="$(grep -iE '^\s*Stable tag:' "$README" | head -1 | sed -E 's/.*Stable tag:\s*//I' | tr -d '[:space:]')"
[[ -n "$STABLE_TAG" ]] || die "Could not read 'Stable tag:' from readme.txt"

[[ "$VERSION" == "$STABLE_TAG" ]] \
	|| die "Version mismatch: plugin header is $VERSION but readme Stable tag is $STABLE_TAG"

# Changelog must carry an entry for this version: a line like "= 0.2.0 ="
grep -qE "^=\s*${VERSION//./\\.}\s*=" "$README" \
	|| die "No '= $VERSION =' entry found in the readme.txt changelog"

ok "Version triad consistent: $VERSION"

# ---------------------------------------------------------------------------
# 2. Clean git tree (we only ship committed code).
# ---------------------------------------------------------------------------
if [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
	die "Working tree has uncommitted changes — commit or stash before deploying."
fi
if ! git -C "$REPO_ROOT" rev-parse "v$VERSION" >/dev/null 2>&1; then
	echo "  (warning: no git tag 'v$VERSION' at this commit — consider tagging the release in git too.)"
fi
ok "Git working tree is clean"

# ---------------------------------------------------------------------------
# 3. Lint (build happens in the staging step).
# ---------------------------------------------------------------------------
if [[ "$RUN_LINT" -eq 1 ]]; then
	info "composer lint"
	composer lint || die "composer lint failed — fix before deploying."
fi

# ---------------------------------------------------------------------------
# 4. Plugin Check gate (can't reach lando from here — confirm it passed).
# ---------------------------------------------------------------------------
echo
echo "  Pre-flight reminder: confirm the official ruleset passed against the installed copy:"
echo "    lando wp plugin check ${SLUG}    →  'Success: Checks complete. No errors found.'"
echo

# ---------------------------------------------------------------------------
# 5. Stage a clean build (runs npm run build inside dist.sh).
# ---------------------------------------------------------------------------
STAGE_TMP="$(mktemp -d -t raif-deploy.XXXXXX)"
trap 'rm -rf "$STAGE_TMP"' EXIT
info "building + staging clean tree"
bash "$REPO_ROOT/bin/dist.sh" --stage "$STAGE_TMP/plugin" >/dev/null
ok "staged $(find "$STAGE_TMP/plugin" -type f | wc -l | tr -d ' ') files"

# ---------------------------------------------------------------------------
# 6. Ensure the SVN working copy exists and is up to date.
# ---------------------------------------------------------------------------
if [[ ! -d "$SVN_DIR/.svn" ]]; then
	info "checking out $SVN_URL → $SVN_DIR"
	svn checkout "$SVN_URL" "$SVN_DIR"
fi
info "svn cleanup + update"
svn cleanup "$SVN_DIR" || true
svn update "$SVN_DIR" >/dev/null
mkdir -p "$SVN_DIR/trunk" "$SVN_DIR/assets"

# ---------------------------------------------------------------------------
# 7. Tag-never-overwrite guard.
# ---------------------------------------------------------------------------
if svn ls "$SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
	die "tags/$VERSION already exists on wp.org — never re-edit a published tag. Bump the version and re-release."
fi

# ---------------------------------------------------------------------------
# 8. Sync a directory in the SVN working copy from a source tree, deletion-safe.
#    sync_dir <source-tree> <svn-subdir>
# ---------------------------------------------------------------------------
sync_dir() {
	local src="$1" dst="$SVN_DIR/$2"
	# Clear existing contents (glob-free so an already-empty dir is fine). The
	# versioned dir itself stays; SVN's single .svn lives at the WC root, not here.
	find "${dst:?}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
	# Copy contents (dotfiles included) when the source has any.
	if [[ -n "$(ls -A "$src" 2>/dev/null || true)" ]]; then
		cp -R "$src/." "$dst/"
	fi
	# Stage adds, then remove anything SVN now reports as missing ('!').
	svn add --force "$dst" >/dev/null 2>&1 || true
	svn status "$dst" | awk '/^!/ {print $2}' | while read -r gone; do
		svn rm --force "$gone" >/dev/null 2>&1 || true
	done
}

info "syncing trunk/"
sync_dir "$STAGE_TMP/plugin" "trunk"

if [[ -n "$(ls -A "$REPO_ROOT/assets" 2>/dev/null || true)" ]]; then
	info "syncing assets/ (icon / banner / screenshots)"
	sync_dir "$REPO_ROOT/assets" "assets"
else
	echo "  (no repo assets/ — skipping; assets are optional and can be added later)"
fi

# ---------------------------------------------------------------------------
# 9. Show the pending change set.
# ---------------------------------------------------------------------------
echo
echo "==== svn status ($SVN_DIR) ===="
( cd "$SVN_DIR" && svn status )
echo "================================"

# Default commit summary: the first bullet under the changelog entry.
if [[ -z "$MESSAGE" ]]; then
	MESSAGE="$(awk -v v="$VERSION" '
		$0 ~ "^=[[:space:]]*"v"[[:space:]]*=" {f=1; next}
		f && /^=[[:space:]]/ {exit}
		f && /^\*/ {sub(/^\*[[:space:]]*/,""); print; exit}
	' "$README")"
	[[ -n "$MESSAGE" ]] || MESSAGE="release"
fi

# ---------------------------------------------------------------------------
# 10. Commit (only with --commit) — trunk+assets, then the tag.
# ---------------------------------------------------------------------------
if [[ "$DO_COMMIT" -ne 1 ]]; then
	echo
	echo "DRY RUN — nothing committed. Review the status above, then re-run with --commit:"
	echo "    bin/svn-deploy.sh --commit --svn-dir \"$SVN_DIR\""
	exit 0
fi

cd "$SVN_DIR"
info "svn commit trunk + assets"
svn commit trunk assets -m "$VERSION: $MESSAGE"

info "tagging tags/$VERSION"
svn copy trunk "tags/$VERSION"
svn commit "tags/$VERSION" -m "Tag $VERSION"

ok "deployed $VERSION"
echo
echo "  The release goes live within minutes once trunk/readme.txt 'Stable tag: $VERSION'"
echo "  matches tags/$VERSION. Verify at https://wordpress.org/plugins/${SLUG}/"
