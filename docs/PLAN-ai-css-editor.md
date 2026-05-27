# Plan: AI-driven per-form CSS editor with live frontend preview

**Status:** Deferred (post wp.org submission)
**Target:** v0.3 (after submissions UI + anti-abuse land)
**Author:** initial draft 2026-05-19

This document captures the design for letting users style each form individually using a natural-language prompt, with a faithful frontend preview rendered inside the editor.

---

## 1. User-facing goal

A user opens a form in the editor and sees, side-by-side:

- **Left**: a Styling panel with the current custom CSS and a "describe the look you want" prompt box.
- **Right**: an `<iframe>` preview that renders the form using **the active theme's frontend CSS** — i.e. exactly how visitors will see it.

The user types: *"Make required fields show a red asterisk, full-width inputs, my theme's accent color on focus, and 8px rounded corners."* They click **Apply**. A second later the iframe reloads with the new styling. They iterate without leaving the page.

---

## 2. Architecture

```
┌─────────────── Admin SPA ────────────────┐
│                                          │
│  Styling panel   │   iframe preview      │
│  ──────────────  │   ──────────────      │
│  CodeMirror      │   src="/?rapid_ai_form_  │
│  (current CSS)   │       preview={id}"   │
│                  │                       │
│  Prompt box      │   ← renders form      │
│      │           │     with theme CSS    │
│      ▼           │                       │
│  POST            │   on css save:        │
│   /ai/style      │   iframe reload       │
│      │           │                       │
│      ▼           │                       │
│  New custom_css  │                       │
│      │           │                       │
│      ▼           │                       │
│  PUT /forms/{id} │                       │
│                  │                       │
└──────────────────┴───────────────────────┘
```

---

## 3. Storage

No new DB columns. Reuse the existing `settings` JSON blob on `rapid_ai_forms`:

```jsonc
{
  "settings": {
    "custom_css": "string"   // <= 50KB, sanitized
  }
}
```

---

## 4. Components to build

### 4.1 Frontend preview route

`includes/frontend/class-preview.php` (new)

- Registers a query var: `rapid_ai_form_preview`.
- Hooks `template_redirect`. When the var is set:
  1. `current_user_can( 'manage_options' )` — else 403.
  2. Load the form by id.
  3. Output a minimal HTML doc:
     ```html
     <!doctype html>
     <html <?php language_attributes(); ?>>
     <head>
       <meta charset="utf-8">
       <?php wp_head(); ?>   <!-- theme CSS loads here -->
     </head>
     <body class="<?php echo esc_attr( implode( ' ', get_body_class() ) ); ?>">
       <div class="raif-preview-frame">
         <?php echo $renderer->render( $form ); ?>
       </div>
       <?php wp_footer(); ?>
     </body>
     </html>
     ```
  4. `exit` so no theme template runs.

URL: `/?rapid_ai_form_preview={id}`.

### 4.2 Custom CSS rendering

Extend `Form_Renderer::render()` to emit a scoped style block immediately before the `<form>`:

```html
<style id="raif-css-{uuid}">
  .raif-form[data-form-uuid="{uuid}"] {
    <?php echo $sanitized_custom_css; ?>
  }
</style>
```

The CSS nesting wrapper means even un-scoped user CSS can only affect this one form (CSS nesting is supported in ~95% of browsers as of 2026-05).

### 4.3 Sanitization

`includes/forms/class-css-sanitizer.php` (new). Run on **every** custom CSS write (human or AI):

- Strip: `</style`, `<script`, `javascript:`, `expression(`, `@import`, `url(javascript:`.
- Cap length at 50 KB.
- Validate it parses with a minimal balanced-brace check (reject obvious garbage).
- Preserve everything else verbatim — admins can write CSS, same trust model as Customizer Additional CSS.

### 4.4 Styling panel (React)

`src/admin/components/StylingPanel.js` (new). Two stacked sections inside a new "Styling" card in the editor:

1. **AI prompt** — `TextareaControl` + an **Apply** button that posts to `/ai/style`.
2. **CSS** — CodeMirror via `@wordpress/codemirror` (registered handle `wp-codemirror`) or fall back to a monospace `TextareaControl`. Below it: a **Save styling** button.

State: live-edit CSS triggers iframe refresh after a 600ms debounce.

### 4.5 AI style endpoint

`POST /rapid-ai-forms/v1/ai/style`

**Request:**
```json
{
  "form_id": 42,
  "prompt": "uppercase labels, full-width inputs, blue focus rings",
  "current_css": "/* ... existing ... */"
}
```

**Server-side context assembly** (key part — better context = better output):

```php
$context = [
  'prompt'       => $prompt,
  'current_css'  => $current_css,
  'form_html'    => $renderer->render( $form ),   // gives the model real selectors
  'selectors'    => self::available_selectors(),  // documented allow-list
  'theme_tokens' => self::theme_tokens(),         // primary color, radius, fonts from theme.json
];
```

`self::theme_tokens()` pulls from `wp_get_global_settings()`:
- `color.palette` → primary, accent, background, text
- `typography.fontFamilies` → headings/body
- `spacing.spacingSizes` → preset sizes
- `border.radius` if set

These get passed to the model as available CSS variables (`var(--wp--preset--color--primary)` etc.) so the output naturally matches the theme.

**System prompt:**
```
You are a CSS editor for a WordPress form. Output ONLY CSS, no
prose, no markdown fences. Scope every rule under the form's
data-form-uuid attribute — never write unscoped selectors. Prefer
the theme's CSS custom properties when they fit the request. If
current_css is supplied, EDIT it: preserve rules the user didn't
ask to change, modify or add only what's needed. Available
selectors are listed below. Theme tokens are listed below.
```

**Response:** sanitized CSS string. Plugin returns it; React fills the editor; user clicks "Save styling" to persist.

---

## 5. Iframe lifecycle

- Initial mount: `iframe src="/?rapid_ai_form_preview={id}"`.
- On save (`PUT /forms/{id}` succeeds with new `custom_css`): `iframeRef.current.contentWindow.location.reload()`.
- A "Refresh" button next to the preview for the rare case it gets stuck.

CORS not an issue — same origin.

---

## 6. Security & safety

| Threat | Mitigation |
|---|---|
| User-typed CSS includes `<script>` or `</style>` to break out | Sanitizer strips these tokens |
| User-typed CSS leaks to other elements | Scoped via `[data-form-uuid="…"]` wrapper at render time |
| AI hallucinates `expression()` or other risky CSS | Same sanitizer runs on AI output |
| AI returns prose around the CSS | `Schema_Prompt`-style extraction (strip markdown fences, isolate the CSS) |
| Preview URL exposes form to public | Capability check on `template_redirect` — 403 if not `manage_options` |
| Iframe used for clickjacking | Set `X-Frame-Options: SAMEORIGIN` on the preview response |
| Custom CSS grows unbounded | 50 KB hard cap |

---

## 7. Cost considerations

- One LLM call per **Apply** click. CSS rewrites are small (a few hundred tokens). At `gpt-oss-120b` speeds that's ~2-3 seconds and pennies of cost.
- The AI editor is opt-in — manual CSS editing remains free of any AI roundtrip.

---

## 8. Implementation phases

Ship in three independent commits so each can be reverted in isolation:

1. **CSS storage + manual editor + scoped rendering + sanitization.**
   No AI yet. Users can write their own CSS. ~2 hours.

2. **Iframe-based frontend preview route.**
   Replaces the admin-side mock preview with a real-theme iframe. ~1.5 hours.

3. **AI style endpoint + prompt UI + theme.json context extraction.**
   ~2.5 hours.

Total: ~6 hours of focused work.

---

## 9. Open questions

- **Per-field overrides** vs. global form CSS — start global only. If users ask, add a `css_class` field per field afterwards.
- **CodeMirror vs. plain textarea** — start with `wp-codemirror` since WP already ships it; cheap. Plain textarea is the fallback for non-admin contexts.
- **Should AI also be allowed to edit field-level CSS classes** (i.e. modify the schema, not just the CSS)? Out of scope for v1; restrict to CSS output only.
- **Library of presets** ("Minimal", "Material", "Brutalist", etc.) generated by AI as starter templates — possible v2, would let users get to a good baseline without writing a prompt.

---

## 10. Acceptance criteria

The feature is done when:

- [ ] A logged-in admin can open the editor and see a working iframe of the form rendered with the active theme's styles.
- [ ] Editing the manual CSS textarea + clicking Save updates the form and the iframe reloads to reflect changes.
- [ ] Typing a prompt → clicking Apply → ~3 seconds later the textarea contains new CSS, the iframe shows the change, and the user can click Save to keep it (or undo to revert).
- [ ] Anonymous visitors hitting `/?rapid_ai_form_preview={id}` get a 403.
- [ ] Custom CSS for one form never affects another form on the same page.
- [ ] Pasting `</style><script>alert(1)</script>` into the CSS textarea results in stripped output, no script execution.
- [ ] `manage_options` is enforced for the AI endpoint too.

---

## 11. Cross-references

- `docs/SPEC.md` §2 (Form Schema) — `settings.custom_css` added here.
- `docs/SPEC.md` §4.2 — new endpoint `POST /ai/style`.
- `docs/SPEC.md` §12 — roadmap entry under v0.3.
