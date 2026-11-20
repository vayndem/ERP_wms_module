# Frontend / UI Rules

Loaded when working on views (`resources/views`), JS (`resources/js`) or CSS (`resources/css`, `tailwind.config.js`). Inherits language and error-handling rules from the root `CLAUDE.md`. User-facing text here is Indonesian (override of the Landing AI default, see `CLAUDE.md` section 1).

## 1. Stack

The UI was fully migrated off Bootstrap 5 / jQuery / DataTables.js. **YOU MUST NOT reintroduce any of them**: zero jQuery (`$.ajax`) or Bootstrap JS (`data-bs-toggle="modal"`) in the codebase.

- Tailwind CSS + daisyUI components, compiled by Vite (`resources/css/app.css`, `resources/js/app.js`). Interactivity is Alpine.js.
- **Server-side tables**: list pages talk to the Yajra (`datatables()->of(...)`) JSON endpoints through the `wmsDataTable()` Alpine factory (`resources/js/data-table.js`). Row markup is a Blade `<template x-for>`, not server-built HTML strings. The sales lists are a deliberate exception: plain paginated Blade tables.
- **Modals**: native `<dialog class="modal">` + `.modal-box`, opened with `openAjaxModal()` and closed with `closeAjaxModal()` (`resources/js/ajax-modal.js`), not `data-bs-toggle`.
- **Simple CRUD forms**: `submitAjaxForm($event)` / `confirmAjaxDelete($event, message)` instead of a new AJAX handler per view.
- **Searchable `<select>`**: add `data-app-picker`; `resources/js/smart-picker.js` enhances it.
- **Checkbox read with `$request->boolean()`** needs an `<input type="hidden" value="0">` companion.
- **Destructive actions** (delete, void) ask for confirmation. No `alert()`, `confirm()` or `prompt()`: use SweetAlert2 through the project's helper.

## 2. Data and states

- **Column names the JS reads are load-bearing.** A Yajra column renamed server-side breaks the UI silently; `php artisan test` does not touch `x-for` row templates. Never rename one without changing the template in the same change.
- Every list handles empty, loading and error: a designed empty message, a visible loading indicator, and a non-technical error message. Never render a raw API/exception string to the user. `KualitasTampilanTest` fails on a silently empty listing.
- Wide tables sit in an `overflow-x-auto` wrapper.
- Do not add search, filter and sort to every table by default; state which you add and why.
- `.modal-box` must scroll, never clip: it has a global `max-height` + `overflow-y: auto` in `app.css` so a tall form's footer stays reachable. Do not override it with a fixed height or `overflow-hidden`.

## 3. Responsiveness is a hard requirement

Every new or edited view must be checked at mobile width, not only desktop.

- Use `sm:`/`md:`/`lg:` prefixes on grids and flex layouts instead of one fixed multi-column layout.
- Wrap any wide table in `overflow-x-auto`.
- Actually resize the browser (or devtools responsive mode) before calling a view done. A desktop-only render is not enough. The tests do not draw CSS.

## 4. Theming: two themes, no hardcoded colour

`tailwind.config.js` defines exactly two daisyUI themes: `wms` (light) and `wms-dark`. The active one is set as `data-theme` on `<html>` inline in the `<head>` of `layouts/app.blade.php`, before any CSS loads, so a dark-mode user never sees a white flash. `app-shell.js` keeps the toggle and `localStorage` in step and fires `inventory:theme-changed`. The guest layout (`layouts/guest.blade.php`) follows the same theme mechanism.

- **YOU MUST NOT write a colour literal in a view.** No `bg-white`, `text-gray-500`, `bg-slate-800`, no inline `style="color:#…"`, no arbitrary `[#hex]`. Use the tokens: `base-100` for surfaces, `base-200` for the page, `base-300` for borders, `base-content` for text, `primary`/`primary-content` and friends for accents. `KualitasTampilanTest::test_no_page_hardcodes_colors_outside_the_theme` (rendered pages) and `KonsistensiTemaTest` (sources) fail on a regression.
- The only exception is `resources/views/reports/**` and `stock_opname/pdf`, which are printed on paper and are correctly hardcoded.
- **Do not force a content colour onto a themed component.** `badge text-white` is fine in light mode and white-on-amber, unreadable, once `badge-warning` renders on `wms-dark` (whose warning is a light `#fbbf24` with dark content). daisyUI already picks the right content colour per variant.
- **The elevation ramp must read the same way in both themes.** Light: page `base-200` `#f4f7fc`, cards `base-100` `#ffffff` above it. Dark: page `#0b1220` → cards `#17233d` → borders `#2a3a56`, with the sidebar's `neutral` `#0f172a` distinct from all three. (Until 2026-09-18 dark was inverted and `base-100` was byte-identical to `neutral`, so the sidebar and cards were the same colour.) Contrast is about 13.3:1 dark and 14.6:1 light, far past the 4.5:1 AA floor; keep it that way if you touch the palette.
- **Canvas cannot inherit a CSS class**, so charts read the tokens themselves. `task-chart.js` pulls daisyUI's HSL custom properties (`--bc`, `--n`, `--nc`, `--b1`) via `getComputedStyle`, applies them to ticks, grid, legend and tooltip, **and re-applies them on `inventory:theme-changed`**. Without that a chart keeps Chart.js's mid-grey axes, invisible on dark, and toggling the theme does not repaint.
- **SweetAlert2 injects its own light stylesheet**, so its popups ignore the theme. The overrides at the bottom of `app.css` re-skin it from the tokens, and its buttons are real daisyUI buttons (`buttonsStyling: false` + `customClass`). **Those rules sit outside `@layer components` on purpose**: Tailwind purges custom classes declared inside a layer when it cannot find them in scanned content, and `swal2-*` only appears inside the vendored JS bundle. Put them in a layer and they silently vanish from the build; the tell is that `app.css` output does not grow.

## 5. Blade

- **Use `@php ... @endphp` for anything beyond a trivial assignment.** `@php($x = ...)` is matched by a balanced-parenthesis regex that fails silently on a long expression: Blade emits a bare `<?php` with no closing tag and the page dies with a parse error pointing at some unrelated `@endforeach` far below. A Blade parse error naming a directive you did not touch usually means an earlier directive failed to compile. `KualitasTampilanTest` compiles and lints every view.
- Prefer a gate to a raw role check in a view (`@can('operateWarehouse')`).
- A new page: token colours, responsive grid, scroll-wrapped tables, a sidebar entry behind the right gate, and a row in `AksesPerRoleTest` if roles are restricted. See [menambah halaman](../docs/guides/adding-a-page.md) and [frontend dan tema](../docs/architecture/frontend-dan-tema.md).

## 6. Verification

A change to how something looks is not done until it has been looked at in a real browser at mobile width and in both themes. Tests catch hardcoded colour, uncompilable views and missing scroll wrappers; they do not catch a layout that is ugly while using the right tokens.
