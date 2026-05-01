---
name: blade-styling
description: Use when styling, restyling, or improving the visual layout of Blade templates in this Laravel project. Trigger phrases include «свёрстай», «причеши», «сделай красиво», «поправь UI», «адаптив», «таблица/карточка/шапка криво», applied to files under resources/views/. Defines the visual-feedback loop using Playwright MCP — without this loop the assistant verstaет blindly and burns iterations.
---

# Blade styling — visual-feedback workflow

Stack: Laravel 11 + Filament 3 (admin) + Blade + Livewire + Tailwind 3 (Vite).
Public shop views: `resources/views/shop/`. Layout: `resources/views/layouts/shop.blade.php`.
Livewire components: `app/Livewire/Shop/` + `resources/views/livewire/shop/`.

## The loop

1. **Confirm dev server is up.** Ask the user — do not start `php artisan serve` yourself
   (it's a long-running process; user usually has it running already on `127.0.0.1:8000`).
   If Vite dev mode is needed for hot CSS reload, also ask about `npm run dev`.

2. **Edit the `.blade.php` file.** Stick to Tailwind utility classes. Do not introduce
   custom CSS unless utilities genuinely cannot express the rule (rare). Do not invent
   classes — if unsure, look them up via context7 MCP first.

3. **Verify in browser via Playwright MCP** — this is the non-skippable step:
   - `browser_navigate` → `http://127.0.0.1:8000/<route>`
   - `browser_resize` → mobile (375×812), tablet (768×1024), desktop (1280×800)
   - `browser_take_screenshot` (fullPage: true) at each breakpoint
   - Look at the screenshots before claiming the change works.

4. **Iterate.** Watch for:
   - horizontal overflow (mobile especially)
   - text too small / too low contrast
   - misaligned grid/flex
   - CTAs that aren't obviously interactive
   - broken images / missing assets (404 in console — use `browser_console_messages`)

5. **Stop condition.** Page renders cleanly at all three breakpoints, no console
   errors, primary actions are visible above the fold on mobile. Only then report
   the task as done.

## Project-specific gotchas

- **Don't break `layouts/shop.blade.php`.** It's the global wrapper for shop pages.
  Per-page tweaks go in the `@section`/`@push` of the leaf template, not the layout.
- **Livewire reactivity.** Components in `app/Livewire/Shop/` re-render on state
  changes — full page reload doesn't reset their internal state. After editing a
  Livewire view, also check the wired-up actions still work (click them in Playwright).
- **Filament admin is hands-off for CSS.** Don't override Filament's compiled
  classes; if the admin needs visual tweaks, use Filament's `register()` API
  in the relevant `*PanelProvider` (see `app/Providers/Filament/`).
- **Lecture editor is a separate world.** `public/lecture-editor.js` and
  `public/lecture-styles/` are built by `lecture-builder/` (Python service) — they
  are NOT Tailwind, do NOT touch them when styling the regular shop. Lecture
  templates live in `lecture-ui/templates/template.html.j2`.
- **Cyrillic in URLs.** If a route slug contains кириллица, URL-encode it before
  passing to Playwright `browser_navigate`, otherwise it 404s silently.

## Doc lookup

Before using any non-trivial Tailwind / Alpine / Filament / Livewire feature,
fetch current docs via context7 MCP (`resolve-library-id` → `get-library-docs`).
The model's training cutoff predates several Tailwind 3.x and Filament 3.x
additions — verify, don't guess.

## What this skill is NOT for

- Logic/data changes in controllers/models (use normal flow).
- Filament resource form/table schema changes (those are PHP, not «вёрстка»).
- Lecture HTML rendering (handled by `lecture-builder` Jinja templates, not Blade).
