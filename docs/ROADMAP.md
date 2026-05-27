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

## v0.2 (next)

- Submissions admin view (data is already stored; UI to come).
- Database migration runner for schema changes.

## v0.3

- Per-form custom CSS with iframe preview and AI-prompt-driven editing — see
  [`PLAN-ai-css-editor.md`](PLAN-ai-css-editor.md).

## v0.4

- Gutenberg block — a thin wrapper around the existing shortcode.

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
