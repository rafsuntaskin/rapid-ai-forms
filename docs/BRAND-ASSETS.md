# Brand assets — image-generation prompts

Internal reference for producing the logo and banner for Rapid AI Forms.
Use either prompt with Midjourney, DALL·E, Imagen, or any modern image
model. Generate the logo first, settle on the mark and colors, then bake
the same mark into the banner so the two pieces feel like a set.

---

## Logo prompt

```
Minimalist app icon for a WordPress plugin called "Rapid AI Forms".
The icon represents an AI-powered contact form builder: the central motif
combines a simple form/document outline with a clean sparkle or chat-bubble
mark suggesting AI generation. Flat vector style, geometric shapes,
single accent color on a soft solid background. No text, no letters, no
words anywhere in the image. Centered, symmetrical, balanced negative
space, friendly and modern but professional. Looks crisp at 128x128 and
256x256, readable when shrunk to a 40px favicon. Color palette: deep
indigo or violet accent (#5B21B6 or #7C3AED) on a pale neutral background
(#F4F4F5 or #FFFFFF). Square 1:1 aspect ratio. Flat icon, no shadows,
no gradients, no 3D.
```

**Variant cues** to try if the first batch isn't right:

- Swap "sparkle" for "magic wand", "lightning bolt", or "spark plus
  document corner".
- Swap "indigo" for "emerald (#10B981)" or "amber (#F59E0B)" if the brand
  feels too cold.
- Add "rounded square frame" if you want the iOS-app-style chiclet look.

---

## Banner prompt

```
Promotional banner for a WordPress plugin called "Rapid AI Forms" — an
AI-powered contact form builder. Wide horizontal composition, the plugin
icon (a flat geometric form-with-sparkle mark in deep indigo) sits on the
LEFT third with comfortable negative space around it. The RIGHT two-thirds
shows a stylized abstract illustration: a clean line-art contact form
floating on a soft background with subtle AI-generation cues — small
sparkles, a chat bubble drifting toward the form, or a single typed prompt
line dissolving into form fields. Flat vector style, generous whitespace,
modern and friendly but understated. Pale neutral background (#FAFAFA or
#F4F4F5), deep indigo accents (#5B21B6 / #7C3AED), one warm accent color
for highlights (e.g. coral #FB7185 or amber #F59E0B). Leave the upper-right
quarter mostly empty so wp.org can overlay the plugin name and version
without visual conflict. No text in the image, no letters, no words. Crisp
at 1544×500 retina, still readable at 772×250. Aspect ratio 1544:500
(about 3.09:1).
```

**Variant cues**:

- For a visual reference to the actual editor: *"subtle screenshot of the
  editor floating in the right two-thirds, with the live preview pane
  visible"*.
- For a darker theme: swap background to `#0F172A` (slate-900) and use
  indigo `#A78BFA` on dark.
- If a generator keeps inserting unwanted text, append:
  *"absolutely no typography, no signs, no labels, no UI text"*.

---

## Output specs

| File | Aspect | Size | Notes |
|---|---|---|---|
| `icon-128x128.png` | 1:1 | 128×128 | Standard density |
| `icon-256x256.png` | 1:1 | 256×256 | Retina |
| `banner-772x250.png` | ~3.09:1 | 772×250 | Standard density |
| `banner-1544x500.png` | ~3.09:1 | 1544×500 | Retina |

Place the finals in `assets/` at the repo root. That folder is
`.distignore`d from the plugin zip — they'll be uploaded to SVN
`assets/` separately (see `docs/SUBMITTING-TO-WP-ORG.md` §4).

## Color palette (in case you build outside the prompt)

| Use | Hex | Name |
|---|---|---|
| Primary accent | `#5B21B6` | indigo-800 |
| Primary accent (lighter) | `#7C3AED` | violet-600 |
| Background — light | `#FAFAFA` | neutral-50 |
| Background — light alt | `#F4F4F5` | zinc-100 |
| Background — dark variant | `#0F172A` | slate-900 |
| Accent on dark | `#A78BFA` | violet-400 |
| Warm highlight (optional) | `#FB7185` | rose-400 / coral |
| Warm highlight (alt) | `#F59E0B` | amber-500 |

## Constraints worth restating in any prompt

- **No text in the image.** wp.org overlays the plugin name at certain
  breakpoints; baked-in typography clashes with that.
- **Vector / flat-art look.** Avoids artifacts when scaled, matches the
  plugin directory's visual language.
- **Centered, symmetrical** for the icon (it gets cropped into circles
  in some directory views).
- **Negative space upper-right** on the banner so the wp.org overlay
  has somewhere to land.
