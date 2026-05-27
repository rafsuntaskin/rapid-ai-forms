# wp.org plugin assets

These files are uploaded to the **SVN `assets/` folder** at
`https://plugins.svn.wordpress.org/rapid-ai-forms/assets/`, not to
`trunk/`. They are excluded from the plugin distribution zip via
`.distignore` so they don't bloat the download.

This folder is also referenced from `readme.txt`'s `== Screenshots ==`
section and (via `raw.githubusercontent.com/...` URLs) from the
Description section so reviewers see them during initial review,
before the plugin is approved and SVN access is granted.

## Expected files

| File | Purpose | Specs |
|---|---|---|
| `icon-128x128.png` or `icon.svg` | Plugin icon (standard) | 128×128 |
| `icon-256x256.png` | Plugin icon (retina) | 256×256 |
| `banner-772x250.png` | Plugin banner (standard) | 772×250 |
| `banner-1544x500.png` | Plugin banner (retina) | 1544×500 |
| `screenshot-1.png` | Forms list with multiple cards | ≥1200px wide |
| `screenshot-2.png` | Editor mid-generation with live preview | ≥1200px wide |
| `screenshot-3.png` | Email notifications panel | ≥1200px wide |
| `screenshot-4.png` | Frontend render of a generated form | ≥1200px wide |
| `screenshot-5.png` | Settings page with provider dropdown | ≥1200px wide |

## Caption order

Captions live in `readme.txt` under `== Screenshots ==`. The order of
the captions there maps 1:1 to `screenshot-1.png`, `screenshot-2.png`,
and so on.

## Updating after launch

SVN `assets/` is not version-tied. Commit a new `screenshot-N.png` and
wp.org serves it within minutes — no plugin release or `Stable tag`
bump required.
