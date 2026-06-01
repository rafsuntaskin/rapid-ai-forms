#!/usr/bin/env bash
#
# Build a distributable copy of the plugin, honoring .distignore.
#
# Usage:
#   bin/dist.sh                       # builds and writes ./dist/rapid-ai-forms.zip
#   bin/dist.sh --to <path>           # builds and copies to <path>/rapid-ai-forms/
#   bin/dist.sh --to <path> --no-build  # skip npm run build (use existing build/)
#   bin/dist.sh --zip <file>          # build and write zip to <file>
#   bin/dist.sh --stage <dir>         # build and emit the clean staged tree to <dir>/
#                                     #   (used by bin/svn-deploy.sh to sync trunk/)
#
# Examples:
#   bin/dist.sh --to ~/Dev/lando/sites/wooDev/wp-content/plugins
#   bin/dist.sh --zip ~/Desktop/rapid-ai-forms-0.1.0.zip

set -euo pipefail

SLUG="rapid-ai-forms"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

TARGET_DIR=""
ZIP_PATH=""
STAGE_OUT=""
RUN_BUILD=1

while [[ $# -gt 0 ]]; do
	case "$1" in
		--to)
			TARGET_DIR="$2"
			shift 2
			;;
		--zip)
			ZIP_PATH="$2"
			shift 2
			;;
		--stage)
			STAGE_OUT="$2"
			shift 2
			;;
		--no-build)
			RUN_BUILD=0
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

# Default: write a zip into ./dist/ (only when no other output was requested).
if [[ -z "$TARGET_DIR" && -z "$ZIP_PATH" && -z "$STAGE_OUT" ]]; then
	ZIP_PATH="$REPO_ROOT/dist/$SLUG.zip"
fi

# 1. Build JS bundles.
if [[ "$RUN_BUILD" -eq 1 ]]; then
	echo "▶ npm run build"
	npm run build
fi

# 2. Translate .distignore into rsync excludes.
#    .distignore uses gitignore-ish syntax; rsync's --exclude is close enough
#    for the patterns we use (directory names, file globs, leading-slash anchors).
EXCLUDE_ARGS=()
if [[ -f .distignore ]]; then
	while IFS= read -r line; do
		# Strip comments and blanks.
		line="${line%%#*}"
		line="$(echo "$line" | tr -d '[:space:]')"
		[[ -z "$line" ]] && continue
		EXCLUDE_ARGS+=(--exclude="$line")
	done < .distignore
fi

# 3. Stage into a temp dir.
STAGE="$(mktemp -d -t rapid-ai-forms-dist.XXXXXX)"
trap 'rm -rf "$STAGE"' EXIT
STAGED="$STAGE/$SLUG"
mkdir -p "$STAGED"

echo "▶ rsync → $STAGED"
rsync -a "${EXCLUDE_ARGS[@]}" "$REPO_ROOT/" "$STAGED/"

# 4. Copy to a target plugins dir, or zip, or both.
if [[ -n "$TARGET_DIR" ]]; then
	mkdir -p "$TARGET_DIR"
	DEST="$TARGET_DIR/$SLUG"
	echo "▶ copy → $DEST"
	rm -rf "$DEST"
	cp -R "$STAGED" "$DEST"
fi

if [[ -n "$ZIP_PATH" ]]; then
	mkdir -p "$(dirname "$ZIP_PATH")"
	rm -f "$ZIP_PATH"
	echo "▶ zip → $ZIP_PATH"
	( cd "$STAGE" && zip -qr "$ZIP_PATH" "$SLUG" )
fi

# Emit the clean staged plugin tree (contents of the rapid-ai-forms/ folder) into
# STAGE_OUT. Used by bin/svn-deploy.sh to sync trunk/ without round-tripping a zip.
if [[ -n "$STAGE_OUT" ]]; then
	echo "▶ stage → $STAGE_OUT"
	rm -rf "$STAGE_OUT"
	mkdir -p "$STAGE_OUT"
	cp -R "$STAGED/." "$STAGE_OUT/"
fi

echo "✓ done"
