# @rapid-ai-forms/shared (portable)

UI primitives and hooks designed to be lifted into other WordPress plugins.

**Rules for code in this folder:**

1. Depend only on `@wordpress/*` packages — never on plugin-specific globals like `RAPID_AI_FORMS_ADMIN`.
2. Take REST base URL, nonce, and labels as props. Do not read them from `window`.
3. No imports from `../admin/*` or `../frontend/*`. The dependency direction is one-way: feature folders import from `shared/`, never the reverse.
4. Keep components small and prop-driven so they can be dropped into any plugin's admin SPA.

When this set stabilizes, publish it as `@your-org/wp-react-kit` and consume it as an npm dependency across plugins.
