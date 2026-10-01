# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**ERP - Modul WMS** (product/app name, `APP_NAME` in `.env`; rebranded 2026-09-06 — was "WMS FullSpec"). This is not a standalone WMS: it's an ERP module built as a Laravel 12 / PHP 8.2+ / MySQL modular monolith connecting warehouse, procurement, sales, finance, and accounting into one controlled flow. Buy side: request → PO → LPB (receipt) → multi-warehouse stock → NPK (usage) → supplier invoice → payment → GL journal. Sell side (added 2026-09-16): pelanggan → pesanan penjualan → surat jalan (FIFO + HPP) → faktur penjualan (piutang + PPN Keluaran) → penerimaan pembayaran, with sales returns. **It was procure-to-pay only until 2026-09-16** — correct any older note that still says so. Stock movements and financial postings are not administrative side notes — they drive inventory value, FIFO layers, payables, and the general ledger together (perpetual inventory, not periodic — every movement posts a journal entry immediately, the hallmark that puts this in ERP territory rather than a bare warehouse-execution WMS). The rebrand is naming/branding only (`.env` `APP_NAME`, login/sidebar UI text, README.md/feed.MD titles) — no internal code was touched: `wms_` table prefix, `Wms*` class names (e.g. `WmsAccountingService`), the `wmsDataTable()` JS helper, and the "WMS Control Center" feature name all stay exactly as-is, since those are functional/architectural identifiers, not the product's outward branding.

Business-level documentation is bilingual Indonesian/English in [README.md](README.md) (product overview) and [feed.MD](feed.MD) (technical reference — role matrix, domain flows, database tables, WMS control framework, technical debt; kept in step with this file). Read `feed.MD` section 15 ("Source of truth") when docs and code disagree: migration > policy > controller+form request > service > model > tests.

## How to read this file

Five things carry the value; the rest is record. **Condensed 2026-09-16** from 899 lines — the per-feature build notes were compressed to the decisions and the reasoning behind them, since the *what* is discoverable from the code, routes and tests but the *why* is not.

| If you are about to… | Read |
|---|---|
| write any code here | **Architecture rules** and **Traps this codebase has already sprung** — the traps already cost debugging time, several of them more than once |
| change something a user chose | **Decisions the user has already made** — changing one is a new conversation, not a refactor |
| pick up work | **Open-items index** — the single authoritative list of what is unbuilt, and **Dropped from the plan** for what is deliberately never coming back |
| touch a feature | its entry under **Feature notes** — several decisions there look wrong if you only read the code |
| trace a historical name | **History** at the bottom |
| add tests, or wonder what is already guarded | **Measured debt and coverage** — which net catches which class of defect, and where the blind spots are |

**When a note here conflicts with the code, the code wins and the note should be corrected.** `feed.MD` section 15 gives the precedence order: migration > policy > controller+form request > service > model > tests.

**Detail is wanted, but only durable detail** (owner's call, 2026-09-18 — this replaces an earlier "keep it lean" instruction). Write down anything a future reader cannot re-derive cheaply: *why* a decision was made, what invariant protects it, what breaks if it is undone, and **measurements with the date they were taken**. Do not write down what the next `grep` answers better — no build logs, no per-commit narration, no "full suite N passing" as a standing claim (put the number in a dated measurement instead, where it reads as a snapshot rather than a promise). Completed items move out of the Open-items index rather than accumulating struck-through history.

Measurements age. Every figure in **Measured debt and coverage** carries the date it was taken; re-measure before trusting one, and update it in place rather than adding a second number beside it.

## Commands

**PHP 8.2+ is required since 2026-09-18** (Laravel 12). On a box whose `php` on PATH is older, call the newer binary explicitly — `artisan` refuses to boot otherwise, because `vendor/composer/platform_check.php` enforces it. The same applies to `composer`: run it with the 8.2+ binary, or a plain `composer dump-autoload` will rebuild the autoloader against the wrong platform.

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed     # dev/test only — full seeder mixes master + demo data
npm run build                  # REQUIRED before php artisan test — see Traps #10
php artisan serve
npm run dev                    # Vite dev server for frontend
php artisan queue:work         # REQUIRED since 2026-09-12 — notifications are queued
php artisan schedule:work      # REQUIRED since 2026-09-12 — reminders/replenishment/depreciation/invariant watchdog
```

Testing:

```bash
php artisan test                              # full suite (unit + feature)
php artisan test --filter=TestClassName       # single test class
php artisan test --filter=test_method_name    # single test method
composer test:case-sensitive                  # strict PSR-4 autoload + ArchitectureConventionTest (case-sensitivity check, matches Linux CI runner on Windows)
```

CI (`.github/workflows/ci.yml`) runs against real MySQL: `composer test:case-sensitive` → `php artisan migrate:fresh --seed --force` → `php artisan test`. Case-sensitivity bugs only surface on Linux/CI, so always run `composer test:case-sensitive` locally before pushing on Windows.

## Architecture rules (enforced by `tests/Unit/ArchitectureConventionTest.php`)

These are hard constraints, not style suggestions — the architecture test fails the build if violated:

- **Class/file casing**: class name must exactly match its filename (case-sensitive), checked against both PHP reflection and actual Git-tracked paths — not just the local filesystem (Windows is case-insensitive, so a local mismatch won't show until CI/Linux).
- **No inline validation in controllers**: controllers must not call `->validate(...)` directly; all validation goes in `app/Http/Requests` Form Request classes.
- **Routes are split by domain** and required from `routes/web.php`: `auth.php`, `administration.php`, `warehouse.php`, `procurement.php`, `finance.php`, `accounting.php`, `assets-and-services.php`. Routes must stay `route:cache`-able.
- **Status columns are uppercase machine codes** (e.g. `PENDING`, `APPROVED`, `POSTED`, `VOID`) — never lowercase or mixed case in the database. Indonesian-language labels belong only in the presentation layer.
- **No floating point for business data** in migrations (`$table->double(...)` is banned) — use fixed-point: quantities `DECIMAL(18,6)`, unit cost `DECIMAL(18,4)`, money `DECIMAL(18,2)`, rates `DECIMAL(8,4)`.
- **No legacy table names** (`admin_namagudang`, `invoicelpbs`, `invoicelpbdetails`, `lpbdetails`, `kategoribahan`) may reappear in migrations.
- Table names are plural `snake_case`; new foreign keys follow `<model>_id`.

**Naming conventions settled by the 2026-09-04 rename sweep — apply these to anything new:**

- **Domain/business tables carry the `wms_` prefix**; Laravel framework tables (`users`, `jobs`, `notifications`, `sessions`, `password_reset_tokens`, `personal_access_tokens`, `failed_jobs`, `migrations`) do **not** — renaming those fights framework and package conventions for no business value. `user_roles` is also left alone, being tightly coupled to the auth scaffolding.
- **Class suffixes stay English** — `Controller`, `Policy`, `Request`, `Service`, `Factory`, `Seeder` are generator conventions, not business jargon. Only the domain noun in the middle is Indonesian (`AsetController`, not `AsetPengendali`).
- **Column names are generally not translated.** Rename a column only when it embeds a renamed entity's old abbreviation. Generic English columns (`name`, `status`, `amount`, `book_value`) stay — translating every column costs an order of magnitude more reach (every Eloquent access, Blade echo, Yajra key, seeder key, JS `row.xxx`) for little gain. **And never rename a column a Yajra endpoint exposes to JS** — see the Traps section.
- When a resource's URL segment changes, keep the internal binding name with `->parameters(['new-segment' => 'oldName'])` so controller bodies do not all have to change.

## Application skeleton (Laravel 12, since 2026-09-18)

The app was converted from Laravel 10 to **Laravel 12.69.0 on PHP 8.3** and now uses the Laravel 11/12 skeleton. If you are looking for a file that used to exist, it is gone on purpose:

- **`app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`, `app/Providers/RouteServiceProvider.php` and every stub in `app/Http/Middleware/` were deleted.** Middleware groups/aliases, the guest/auth redirects, trusted proxies, string trimming, exception `dontFlash`, the schedule and route loading all live in **`bootstrap/app.php`**.
- **Providers are listed in `bootstrap/providers.php`**, not `config/app.php` (whose `providers` key is now just `ServiceProvider::defaultProviders()`). Yajra registers itself through package discovery.
- **The `login`/`api` rate limiters moved to `AppServiceProvider::boot()`** — the two-tier login throttle described under Platform hardening is unchanged, only relocated.
- **`->withEvents(discover: false)` is deliberate and load-bearing.** Laravel 12 registers its own `EventServiceProvider`, which auto-discovers listeners in `app/Listeners`. Left on, every listener in `App\Listeners\CatatAktivitasAutentikasi` is bound **twice** — each failed login then writes two audit rows and the throttle test trips at half the attempts. The app keeps its explicit `$listen` map in `App\Providers\EventServiceProvider`, so discovery must stay off.
- **Broadcasting stays dormant.** `routes/channels.php` is deliberately *not* passed to `withRouting()`, matching the old commented-out `BroadcastServiceProvider` (now deleted). Wiring it is a decision, not a cleanup.
- `public/index.php` uses the slim `$app->handleRequest(...)` form.
- **The `config/` directory was deliberately left in its fuller Laravel 10 shape** rather than trimmed to Laravel 12 defaults — the files carry real settings (including `konstanta_ppn`) and trimming them buys nothing but risk.

Where things live now, so you do not go looking for a Kernel:

| To do this | Edit |
|---|---|
| add or alias a middleware | `bootstrap/app.php` → `withMiddleware()` (`$middleware->alias([...])`, `->web(append: …)`, `->api(prepend: …)`) |
| register a service provider | `bootstrap/providers.php` |
| add a scheduled command | `bootstrap/app.php` → `withSchedule()` |
| change exception handling | `bootstrap/app.php` → `withExceptions()` |
| add a rate limiter | `AppServiceProvider::boot()` |
| add a route file | create it, require it from `routes/web.php`, **and** add it to `ArchitectureConventionTest`'s required-file list |
| add an artisan command | drop it in `app/Console/Commands` — it is auto-discovered, there is no `commands()` to update |

Two upgrade notes that are easy to trip over: `php artisan` **will not boot** on PHP below 8.2 because `vendor/composer/platform_check.php` enforces it, and running `composer` with an older PHP rebuilds the autoloader against the wrong platform. Also, the numbers in **Measured debt and coverage** were taken on this stack — a framework upgrade invalidates them.

## Document numbering — allocate at save, never at render (corrected 2026-09-18)

`DocumentNumberService` has two modes and they are not interchangeable:

- **`external()` / `financial()` / `internal()` allocate** — they increment `document_sequences.last_number` and persist it. Call them **only at the moment a document is actually written**, inside the same transaction.
- **`preview(DocumentNumberService::EXTERNAL|FINANCIAL|INTERNAL, …)` reads** — it returns what the next number *would* be and writes nothing. That is what a create form or a "next number" JSON field shows.

Until 2026-09-18 thirteen display sites called the allocating methods, so **merely opening a create form and walking away burned a document number permanently**, leaving gaps in a sequence that exists to be auditable, and eating into the hard 999-per-period ceiling. The same forms then submitted that number back as a readonly input and the controllers trusted it — the audit backbone of every document was **client-supplied and trivially editable**.

Both halves are fixed and must stay fixed:

- Every `store()` now allocates its own number server-side and ignores anything the client sent. The number rules (`required` + `regex` + `unique`) were removed from the `Store*Request` classes, because they only existed to police client input that no longer reaches the model.
- The number inputs on those forms are `disabled` display fields, not form data.
- **A test that posts to one of these store routes cannot choose the number** — it must read it back from the response (`->json('data.no_po')`, `->json('data.id_lpb')`). Six tests were doing exactly that and had to be rewritten.

This is also why **`HalamanTanpaEfekSampingTest`** exists: it fires every parameterless GET page and fails if any of them issues an `insert`/`update`/`delete`. That test is what found this, after a green 245-test suite had hidden it for as long as the feature existed. It also caught `CrossDockService::saran()` calling `StokGudangService::saldo()`, which looks like a read but does `insertOrIgnore` plus `lockForUpdate()` — **`saldo()` is a write helper for write paths; read paths must query `StokGudang` directly.**

## Traps this codebase has already sprung

Every one of these cost real debugging time at least once, several more than once. They are collected here because they were previously scattered across the build notes below, where nobody reads them until after the damage. **Read this before writing code, not after.**

### 1. Mass assignment drops fields silently — it never errors

Hit **four times**: `users.is_active`/`deactivated_at`/`deactivated_by`, `wms_stock_opname.jenis`, `wms_pembayaran_faktur.supplier_id`, and `wms_jurnal_detail.gudang_id` (2026-09-16 — this one would have dropped an entire new reporting dimension while every test stayed green). Each time a field was written, accepted without complaint, and simply absent from the row — the DB default won instead.

Models here are inconsistent: some use `$guarded = []`, some declare a real `$fillable` list. **Check which one before adding a column**, and add the column to `$fillable` if that is what the model uses. Where a field must *never* come from request input (an account-state flag, a posting timestamp), deliberately keep it **out** of `$fillable` and write it with `forceFill()->save()` — that is the pattern on `UserAccountService`, and it is intentional.

Note the escape hatches: Eloquent **factories** bypass guarding (`Factory::make()` wraps in `Model::unguarded()`), and a **query-builder** `Model::where(...)->update([...])` bypasses it too. Only *model* `update()`/`fill()` is guarded — which is why a test can set a guarded field but production code cannot.

### 2. Read a value, write it after a sibling already changed it

Hit **twice**, in the same shape both times. Bin capacity (2026-09-09) kept a running accumulator *and* re-read the bin's fill, double-counting two lines into one bin. Service capitalization (2026-09-14) gave each receipt line its own hydrated `Aset`, so two lines onto one asset both read the same stale `acquisition_cost` and the second write clobbered the first — the GL moved by A+B while the asset moved by only B.

**When several lines of one document touch the same row, re-read it under `lockForUpdate()` inside the transaction, or use `increment()`.** Never compute from an attribute loaded before the loop started.

### 3. Renaming a model silently re-points its `hasMany`/`hasOne`

Eloquent derives the FK for these from **the declaring class name**, not from the related model and not from the column that exists. A relation that omitted its FK argument because the old class name happened to match the real column starts guessing a different, wrong column the moment the class is renamed — with no error until something queries through it. This bit four relations during the rename sweep, two of which only surfaced a cluster later, during a seeder run.

`belongsTo()` is immune (its FK comes from the *method* name). **Always pass the FK explicitly.**

### 4. A COA code collision hijacks a live account instead of failing

`DatabaseSeeder` uses `updateOrCreate(['kode_akun' => …])`. Adding an account with a code already in use **renames the existing account** — no error, no duplicate. This silently renamed "Peralatan dan Inventaris" (carrying a 4,000,000 balance and referenced by `KategoriAset.akun_aset_id`) into a deferred-tax account. **Check the code is genuinely unused before adding any COA.**

Related: `AccountingSetting::accountId()` validates against an `$expected` whitelist keyed by constant. **A key missing from that array is rejected even when its mapping row exists and is perfectly valid** — a new setting must be added in both places.

### 5. MySQL's 64-character identifier limit

`wms_`-prefixed table names plus auto-generated index names cross it easily (hit twice). If `migrate:fresh` reports "Identifier name … is too long", pass an explicit short name as the second argument to `unique()`/`index()`.

### 6. Column names the JS layer reads are load-bearing

Yajra `datatables()->of(...)` endpoints hand column names straight to the `wmsDataTable()` Alpine layer. Renaming one breaks the UI **silently**, and `php artisan test` never catches it because PHPUnit does not touch the Blade `x-for` row templates. This is why `id_lpb`, `lpb_id` and `no_po` were deliberately left un-renamed through the whole rename sweep.

### 7. A bulk word-boundary replace will hit display text

A `\bPembelian\b` replace over `.php` files corrupted three COA seed labels and four user-facing flash messages, because the entity name is also an ordinary Indonesian word. The test suite does **not** catch corrupted display strings. **Eyeball every hit after any bulk replace** — and note that raw-string table joins with aliases (`DB::table('x as d')`) escape a naive `['"]x['"]` grep.

### 9. `@php(...)` with a long expression silently stops compiling the rest of the file

`@php($x = ...)` is matched by a balanced-parenthesis regex. Feed it a long enough expression and the match **fails silently**: Blade emits a bare `<?php` with no closing tag, so everything after it stays in PHP mode and the page dies with a parse error pointing at some unrelated `@endforeach` far below. The reconciliation page was broken this way from commit `a6f8694` and no test caught it, because no test opened the page.

**Use the block form `@php ... @endphp` for anything beyond a trivial assignment.** And note the failure mode: a Blade parse error naming a directive you did not touch usually means an *earlier* directive failed to compile. This bit twice in one session — the second time on a freshly written `@php($presisi = abs($value - round($value)) > 0.000001 ? 6 : 0)`, minutes after the trap was documented. `KualitasTampilanTest::test_every_blade_view_compiles_to_valid_php` now compiles every view and lints the output, so it cannot reach a browser again.

### 10. A missing Vite manifest fails ~170 tests with no code defect in sight

Without `public/build/manifest.json`, every page that renders the layout throws `ViteManifestNotFoundException` and returns **500**, so the feature suite collapses into a wall of "Expected 200, got 500" plus a `SmokeSemuaHalamanTest` listing most of the application. Nothing is wrong with the code. **Run `npm install && npm run build` before `php artisan test`** — the assets are not committed, so a fresh clone or a cleaned `public/build` reproduces it every time. Seen on 2026-09-18: 174 failed, 53 passed before the build; 226 passed, 1 failed after, and that last one was a genuine half-finished edit.

### 11. A renamed table leaves raw name strings behind, and nothing says so until that line runs

Found 2026-09-18, a year after the rename sweep: `PesananPembelianController::archiveHistoryIfNeeded()` still wrote to `pembelian_histories` and `pembelian_detail_histories`. Those tables became `wms_riwayat_pesanan_pembelian` and `wms_riwayat_pesanan_pembelian_detail` in 2026-09-04. **Every revision of an already-printed purchase order threw `SQLSTATE[42S02] Base table not found`** — for as long as the rename had existed.

Three things let it survive: the string sits inside `DB::table('…')`, so no class, model or IDE rename touches it; the code path only fires when `cetak >= 1`, which no seeder or test produced; and `pembelian.update` was one of the mutating routes no test named. Trap 7 already warned that raw-string table references escape a naive grep — this is that warning coming true.

`ArchitectureConventionTest::test_every_table_name_written_as_a_string_actually_exists` now resolves every table named in `DB::table()`, `join()`, `from()`, `$table`, and the `exists:`/`unique:` validation rules against the live schema, so a stale name fails the build instead of a user's save. `RevisiPesananPembelianTest` covers the revision path itself; reverting the fix makes it fail, which is how I checked the test is not vacuous.

### 12. A foreign key dropped in a migration and never re-added lets bad ids accumulate for months

Found 2026-09-18. `2026_07_29_000001_harden_wms_accounting_schema` drops `wms_pembayaran_faktur.finance_user_id`'s foreign key in `up()` and re-adds it only in `down()` — so a rollback restored a constraint the upgrade had silently removed for good. With nothing enforcing it, **three seeders wrote `finance_user_id => 13` at four call sites, a user id that has never existed** (the role seeder creates seven users), and every demo payment's "diproses oleh" resolved to null. No test read that relation, so the suite stayed green.

The seeders now resolve the user by **role** (`User::where('type', User::ROLE_FINANCE)`), not by email — an email is an editable attribute and a seeder must not key on one. `2026_09_18_000006_restore_finance_user_foreign_key` repoints any surviving orphan and restores the constraint, and `PaymentAndInvoiceTest::test_every_payment_names_a_finance_user_that_actually_exists` fails if it is dropped again.

Two lessons worth generalising: **`down()` re-adding something `up()` removed is a smell, not symmetry** — read it as a question about whether the removal was intended; and a hardcoded id in a seeder is a dangling reference waiting for the constraint that was supposed to catch it. Note that the orphan sweep in the audit only inspects columns that *have* a constraint, so a dropped one hides from it too — the `*_id`-without-a-foreign-key listing is the check that finds these.

### 13. A route can point at a controller method that does not exist, and only 404-adjacent luck hides it

`pembelian.update-so-term` and `pembelian.update-financials` named `PesananPembelianController::updateSoTerm()` and `::updateFinancials()`, neither of which has ever existed in this repo's history. Both were `PATCH`, so `SmokeSemuaHalamanTest` (GET only) could not reach them, and no Blade form, JS call or test referenced the names — they were routes to nowhere that would have thrown on resolution. They were deleted rather than implemented, on the same reasoning that retired the `debits`/`kredits` stubs.

`ArchitectureConventionTest::test_every_route_points_at_a_controller_method_that_exists` now walks the route table and resolves every `Controller@method` action. Re-adding one of the dead routes makes it fail, which is how the check was verified as non-vacuous.

### 14. Thin demo data hides whole classes of defect, and every one of them is in a code path no seeder ever ran

Measured 2026-09-18: **47 domain tables were empty** after `migrate:fresh --seed`, including transfers, kitting, QC, picking waves, returns, period locks and approvals. Enriching the seeders to fill them surfaced **five defects in a single pass**, none of which any test had ever reached:

1. **A transfer shortage never left `bahans.stok_onhand`.** `keluar()`/`masuk()` pass `affectGlobal = false` for transfers, which is right for goods that arrive — but on an under-receipt the missing units are genuinely gone and nothing decremented the master. `terima()` now decrements by `jumlah_selisih`.
2. **The `transit` invariant contradicted its own feature note.** It compared `1304` strictly against `IN_TRANSIT` layers, while **Goods in transit** documents that a shortage deliberately leaves its value parked in 1304. Any under-receipt therefore turned the invariant permanently red. Expected is now in-transit layers **plus** the unresolved shortage value, derived from `alokasi_transfer_gudangs` because a `TRANSFER_SHORTAGE` layer is zeroed and no longer carries it.
3. **`RekonsiliasiGudangService::summary()` still counted in-transit layers in its value total** while the GL had moved that value to 1304 — a leftover from before the two-phase transfer. `perGudang()` already excluded them; `summary()` did not.
4. **Disassembling a kit with more than one component always threw.** `hasilkanKomponen()` wrote one layer per component, all keyed `('PERAKITAN_KIT', perakitan_id)`, against a unique index on that pair. Single-component kits worked, so nothing noticed. Component layers now key on the `wms_perakitan_kit_detail` row (`PERAKITAN_KIT_DETAIL`), following the `TRANSFER_ALLOCATION` precedent.
5. **A controlled reversal permanently skewed per-warehouse inventory value.** `RekonsiliasiGudangService` filtered `status = 'POSTED'`, so the original journal (now `REVERSED`) dropped out while the reversing entry stayed in — the report overstated by the reversed amount forever. `AccountingReconciliationService` had always used `whereIn(['POSTED','REVERSED'])`; both now do.

The lesson generalises past this list: **an empty table is a code path with no test, and the defects hide in exactly the branches a thin dataset never reaches** — the shortage branch, the multi-component branch, the reversed-journal branch. `KekayaanDataDemoTest` now asserts that 24 named tables are non-empty and that the enriched dataset keeps all eight invariants valid, so the dataset cannot quietly thin out again.

It also broke six tests whose premises were accidents of thin data, and each fix made the test stronger rather than looser: the FX tests assumed exactly one foreign-currency invoice and are now data-driven across every open one; the CALK tests assumed no narrative existed anywhere (the seeder now writes **last** year's, leaving the current period showing its template badge); the supplier scorecard asserted "reject ratio is null" when what it meant was "null only without inspection data"; and the traceability test asserted zero untraced value when the documented design says kit assembly records no link back. That last one is now an **if-and-only-if**: untraced value may exist exactly when kitting or a transfer shortage is present, which is strictly stronger than the zero it replaced.

### 8. Tests that stop at the service boundary miss most defects

Of 11 defects a review found in the jasa build, **ten lived outside the service layer** — controller guards, Blade forms, the void/reversal path, cross-line arithmetic inside one document — and every one of them survived a green test suite because the tests called services directly. **A feature spanning HTTP → service → ledger needs at least one test that enters through the route.**

Two smaller test traps: a PDF endpoint streams binary, so `assertSee` against it is meaningless and a *failing* one dumps the whole binary and exhausts PHP's memory limit (assert on the Excel export's collection instead); and `actingAs()` leaves the session authenticated, so a later `post(route('login.attempt'))` hits the `guest` middleware and redirects to `/home` without ever exercising login.

## Layered structure

| Layer | Location | Responsibility |
|---|---|---|
| Routes | `routes/*.php` | endpoints + middleware, split per domain |
| Controllers | `app/Http/Controllers` | HTTP orchestration only |
| Form Requests | `app/Http/Requests` | validation, and some authorization |
| Policies | `app/Policies` | role + document-state guards, auto-discovered by Laravel convention |
| Services | `app/Services` | FIFO/inventory cost, accounting postings, document numbering, opname, asset, reconciliation, transfer, three-way match, landed cost, reversal logic |
| Models | `app/Models` | relations, casts, domain helpers |
| Migrations | `database/migrations` | schema + constraints |
| Seeders | `database/seeders` | roles, users, master data, demo data |
| Views | `resources/views` | Blade + Tailwind CSS + daisyUI + Alpine.js |

## Frontend conventions (Tailwind + daisyUI + Alpine, since 2026-09-01)

The UI was fully migrated off Bootstrap 5 / jQuery / DataTables.js — there is zero jQuery or Bootstrap JS in the codebase now. Keep it that way for any new view or edit:

- **Stack**: Tailwind CSS + daisyUI components, compiled via Vite (`resources/css/app.css`, `resources/js/app.js`). Interactivity is Alpine.js, not jQuery.
- **Server-side tables**: list pages talk to the existing Yajra (`datatables()->of(...)`) JSON endpoints through the reusable `wmsDataTable()` Alpine factory (`resources/js/data-table.js`) — do not reintroduce DataTables.js. Row markup is a Blade `<template x-for>`, not server-built HTML strings.
- **Modals**: use a native `<dialog class="modal">` + `.modal-box`, opened via `openAjaxModal()` / closed via `closeAjaxModal()` (`resources/js/ajax-modal.js`), not Bootstrap's `data-bs-toggle="modal"`.
- **Simple CRUD forms**: use `submitAjaxForm($event)` / `confirmAjaxDelete($event, message)` instead of writing a new `$.ajax` handler per view.
- **Searchable `<select>`**: add `data-app-picker` — `resources/js/smart-picker.js` auto-enhances it (no jQuery involved).
- **`.modal-box` must scroll, never clip**: it has a global `max-height` + `overflow-y: auto` in `app.css` specifically so a tall form's footer buttons stay reachable instead of being cut off — don't override that with a fixed height or `overflow-hidden` on a per-view basis.
- **Responsiveness is a hard requirement, not an afterthought.** Every new or edited view must be checked at mobile width, not just desktop: use Tailwind's responsive prefixes (`sm:`/`md:`/`lg:`) on grids and flex layouts instead of a single fixed multi-column layout, wrap any wide table in `overflow-x-auto`, and actually resize the browser (or check devtools' responsive mode) before calling a view done — do not assume a desktop-only render is good enough.

### Theming — two themes, and colour never gets hardcoded (tightened 2026-09-18)

`tailwind.config.js` defines exactly two daisyUI themes, `wms` (light) and `wms-dark`. The active one is set as `data-theme` on `<html>` **inline in the `<head>` of `layouts/app.blade.php`**, before any CSS loads, so a dark-mode user never sees a white flash; `app-shell.js` only keeps the toggle and `localStorage` in step and fires `inventory:theme-changed`.

- **Never write a colour literal in a view.** No `bg-white`, `text-gray-500`, `bg-slate-800`, no inline `style="color:#…"`. Use the daisyUI tokens: `base-100` for surfaces, `base-200` for the page, `base-300` for borders, `base-content` for text, `primary`/`primary-content` and friends for accents. `KualitasTampilanTest::test_no_page_hardcodes_colors_outside_the_theme` renders every page and fails on any of these, so a regression is caught the same day. The **only** exception is `resources/views/reports/**` and `stock_opname/pdf`, which are printed on paper and are correctly hardcoded — the test never opens them because it skips `.pdf`/`.excel`/`report` routes.
- **Do not force a content colour onto a themed component.** `badge text-white` looks fine in light mode and becomes white-on-amber — unreadable — the moment `badge-warning` renders on `wms-dark`, whose warning is a light `#fbbf24` with dark content. daisyUI already picks the right content colour per variant; adding one overrides it.
- **The elevation ramp must read the same way in both themes.** Light: page `base-200` `#f4f7fc` with cards `base-100` `#ffffff` sitting *above* it. Dark was inverted until 2026-09-18 — cards were darker than the page, and `base-100` was byte-identical to `neutral`, so the sidebar and the cards were literally the same colour and the boundary vanished. Dark now runs page `#0b1220` → cards `#17233d` → borders `#2a3a56`, with the sidebar's `neutral` `#0f172a` distinct from all three. Text contrast is 13.3:1 dark and 14.6:1 light, both far past the 4.5:1 AA floor; keep it that way if you touch the palette.
- **Canvas cannot inherit a CSS class, so charts must read the tokens themselves.** `task-chart.js` pulls daisyUI's HSL custom properties (`--bc`, `--n`, `--nc`, `--b1`) via `getComputedStyle`, applies them to ticks, grid, legend and tooltip, **and re-applies them on `inventory:theme-changed`**. Without that a chart keeps Chart.js's default mid-grey axes, which are invisible on the dark background, and toggling the theme does not repaint them.
- **SweetAlert2 ships and injects its own light stylesheet**, so its popups ignore the theme entirely. The overrides at the bottom of `app.css` re-skin it from the tokens, and its buttons are real daisyUI buttons (`buttonsStyling: false` + `customClass`). **Those rules sit outside `@layer components` on purpose** — Tailwind purges custom classes declared inside a layer when it cannot find them in the scanned content, and `swal2-*` only ever appears inside the vendored JS bundle. Put them in a layer and they silently disappear from the build; the tell is that `app.css` output does not grow.

Cross-model processes (posting a document, reversing it, computing FIFO cost) belong in `app/Services`, not controllers. Gates (`AuthServiceProvider`) are used only for capabilities that span multiple models (e.g. `viewWmsControl`, `operateWarehouse`); everything else uses per-model Policies.

Stock and accounting mutations are wrapped in `DB::transaction()`; operations prone to races use `lockForUpdate()`.

## Authorization model

- `users.type` is a foreign key into `user_roles` (roles are no longer bare integers). Seven roles: `super_admin`(0), `purchasing`(1), `finance`(2), `warehouse`(3), `accounting`(4), `production`(5), `accounting_manager`(6, added 2026-09-05 — approves supplier invoices only, see the Open-items index).
- `SuperAdmin` bypasses all policies via `Gate::before` in `app/Providers/AuthServiceProvider.php` — do not add redundant SuperAdmin checks inside individual policies.
- `User` model exposes role helpers: `isSuperAdmin()`, `isPurchasing()`, `isFinance()`, `isWarehouse()`, `isAccounting()`, `isProduction()`, `isAccountingManager()`, `isWarehouseOperator()`, plus warehouse-scoping helpers `accessibleGudangIds($ability)` and `canAccessGudang($gudangId, $ability)`.
- `Produksi` (production) role is scoped to specific warehouses via the `pembagian_gudangs` table (fields: `boleh_menerima`, `boleh_npk`, `boleh_transfer`, `boleh_opname`). `Warehouse` role has access to all active warehouses; `Produksi` only to assigned ones. Controllers filter warehouse-scoped lists through `accessibleGudangIds()`.
- Newer policies (`PemakaianBarangPolicy`, `TransferGudangPolicy`, `StockOpnamePolicy`, and others) follow a pattern of private "who is allowed" / "warehouse access" helper methods, with public `view/create/update/...` methods below — follow this pattern for new policies.

## Domain concepts to know before editing warehouse/inventory code

- **Warehouse types** (`Gudang` model constants): `NORMAL`, `CONSIDER` (quarantine), `RUSAK` (damaged, terminal state).
- **Document lifecycles**: Material Request `PENDING → APPROVED/REJECTED`; Purchase Order `OPEN → CLOSED`; LPB/BAP and NPK `DRAFT → POSTED → REVERSED` (or `CANCELLED`); Invoice `UNPAID → PARTIALLY_PAID → PAID` (or `VOID`); Payment `POSTED → VOID`; Transfer `DRAFT → DIAJUKAN → DIKIRIM → DITERIMA` (or `DIBATALKAN`); Stock Opname `DRAFT → SUBMITTED → APPROVED → POSTED` (or `REJECTED`). **Posted documents are immutable** — corrections always go through a controlled reversal/void with audit metadata and a reversing journal entry, never edit/delete.
- **FIFO inventory layers** (`LayerPersediaan`): LPB posting creates layers; NPK consumes them FIFO (FEFO-then-FIFO for picking). NPK only consumes layers that are `AVAILABLE`, not blocked, and not expired. Layer statuses: `AVAILABLE`, `QC_HOLD`, `IN_TRANSIT`, `REVERSED`, `TRANSFER_SHORTAGE`.
- **Units**: `Bahan` (material) may have a small/transaction unit distinct from its main stock unit. NPK stores `jumlah` (transaction qty), `jumlah_stok` (converted to stock unit), and `satuan_transaksi` (snapshot of the input unit) — conversion always happens before FIFO consumption.
- **Transfers** preserve layer value across warehouses and use an idempotency key to reject duplicate requests. In-transit stock sits in an `IN_TRANSIT` layer; global company balance is unaffected until received. Internal transfers currently post no journal entry.
- **Journals**: LPB posts debit persediaan (inventory) / credit GRNI. NPK posts debit beban (expense) / credit persediaan. Journal creation is service-layer, not controller-layer.
- **Three-way match**: supplier invoices reconcile PO–LPB–Invoice before an AP journal posts (`ThreeWayMatchService`).
- **Landed cost**: capitalized into active layers and posted balanced to GL (`LandedCostService`).
- **Reconciliation invariants**: master quantity = warehouse + in-transit; warehouse balance = sum of layers; layer value = GL inventory balance.
- The **WMS Control Center** (menu: Multi Gudang → WMS Control Center) is the operational home for traceability (bin/lot/serial/expiry, block/release), warehouse execution (QC, putaway, reservation, FEFO/FIFO picking), financial control (three-way match, landed cost, controlled reversal), and planning (reorder point, safety stock, replenishment).
- **Gudang Produksi** (production warehouse): `Gudang Utama → Gudang Produksi` moves via ordinary Transfer Gudang. Since 2026-09-16 an NPK can be tagged to a **work order** (`wms_data_pesanan`), and when it is, its cost accumulates in Barang Dalam Proses instead of hitting expense directly — see Feature notes → Production work orders and WIP. An NPK **without** a work order still behaves exactly as before. Note the internal transfer now does post a journal, since the warehouse dimension arrived the same day.

## Decisions the user has already made — do not re-litigate these

These are product and accounting calls, not engineering ones. Each was asked and answered explicitly. Changing one is a new conversation, not a refactor.

| Area | Decision | Date |
|---|---|---|
| Cycle counting | Cadence A monthly, B quarterly, C yearly (`AbcAnalysisService::SIKLUS_BULAN`) | 2026-09-13 |
| Warehouse scoping | Warehouses are separate physical sites, each with its own admin — scoping is **enforced**, not opt-in | 2026-09-13 |
| Material Request | Approval auto-locks stock up to whatever is free; a shortage locks the partial amount and **never blocks approval** | 2026-09-13 |
| Barcode / QR | Deferred. If revisited: **per bundling/kit, not per item** | 2026-09-13 |
| Fiscal classification | On the COA, with a per-journal-line override (`COALESCE(line, account)`) | 2026-09-11 |
| Fiscal depreciation | Dual schedule computed automatically per asset, not typed by an accountant | 2026-09-11 |
| Tax scope | Full stack including deferred tax (PSAK 46) | 2026-09-11 |
| Permanent differences | Sanksi & denda pajak, sumbangan & natura, penghasilan PPh Final. Entertainment **excluded** | 2026-09-11 |
| Per-warehouse inventory value | A **warehouse dimension on the journal line**, not separate inventory COAs per warehouse | 2026-09-16 |
| CALK | Hybrid: figures pulled from the GL, narrative stored per period and editable | 2026-09-16 |
| Maker-checker scope | Manual journals, controlled reversals, COA/mapping changes. **Period unlock was offered and declined** | 2026-09-16 |
| Production costing | Material enters WIP at NPK issue (not backflush); WIP absorbs material + subcontract services only | 2026-09-16 |
| Production output | Goes **straight to the customer** — never becomes finished-goods stock | 2026-09-16 |
| Unshipped output | Stays in WIP; released proportionally per shipment; order closes when fully shipped | 2026-09-16 |
| Production scope | **Always for a customer order**, so a work order always has a shipment path | 2026-09-16 |
| API/EDI integration | **Dropped from the plan** — see Dropped from the plan | 2026-09-16 |

## Feature notes — the decisions that look wrong if you only read the code

Grouped by area, newest first within each. These are deliberately terse: they carry *why*, not *what*. The what is in the code, the routes, and the tests.

### FX revaluation and production routing (2026-09-18)

- **PSAK 10 revaluation covers monetary items only, and that is the whole simplification.** An older note here claimed reopening multi-currency "means FX-denominated FIFO layers" — that is **wrong**: inventory is a *non-monetary* item and stays at the historical rate forever. Only payables, receivables and cash get restated, so no layer, no HPP and no cost basis is touched. A test asserts the inventory accounts do not move during a revaluation.
- **`4204 Laba Selisih Kurs` and `5303 Rugi Selisih Kurs` already existed**, unused and unmapped, since long before this work. Only the `AccountingSetting` mapping was missing. Check for an existing account before inventing one — trap 4 cuts both ways.
- **The closing rate is typed per currency per period** into `wms_kurs_penutup`; there is no rate feed. A period cannot be posted while any open foreign-currency invoice lacks its rate, and a period can only be posted **once**.
- **Revaluation does not touch `sisa_tagihan`.** It records the movement in `wms_revaluasi_kurs` and posts the journal; the carrying amount of an invoice is therefore `sisa_tagihan` plus the sum of its prior revaluations. **The `ap` invariant was widened to add cumulative revaluation**, otherwise it would have gone red the moment the first revaluation posted. Restating `sisa_tagihan` instead would have disturbed payment allocation, which is why it was not done.
- **Routing is descriptive, not costed.** `wms_pusat_kerja` plus `wms_bom_operasi` give a BOM an ordered operation list with a standard time, and the work-order view scales it by the same basis the material variance uses. There is deliberately **no rate column anywhere** — labour and overhead absorption into WIP is still open and still needs the user's rates; a test asserts no column named `tarif`/`biaya`/`rate`/`cost` exists, so nobody half-builds costing by adding one.
- A work centre may not claim more than **1,440 minutes a day**, and one that is still referenced by a routing cannot be retired. Capacity load is reported as minutes and as equivalent days, and says "kapasitas belum diisi" rather than inventing a denominator.
- **The FX journal's `reff_id` is a period key (`YYYYMM`), not a row id.** Every other `sumber_transaksi` in `wms_jurnal` references a real row, but a revaluation posts **one journal for the whole period** against many `wms_revaluasi_kurs` rows, so there is no single row to point at — and the rows are written *after* the journal, so there could not be one anyway. The choice is load-bearing rather than lazy: `jurnals_source_reference_unique` on `(sumber_transaksi, reff_id)` turns the period key into a database-level guarantee that a period can only carry one FX journal, backing up the service's own "sudah pernah diposting" refusal. `RevaluasiKursTest::test_the_exchange_journal_is_keyed_by_period_not_by_a_row_id` pins this, so nobody "corrects" it into a row id and quietly removes the guard.
- **Saving a routing replaces the whole operation list, so an empty submission is refused** — in `RoutingProduksiService::simpanOperasi()`, not only in the Form Request, because the service is where this repo enforces rules that a policy or validator alone could be bypassed around. Before that guard an empty `operasi` array wiped an existing routing with no confirmation and no way back. Clearing a routing is not offered in the UI and nothing needs it; if it ever does, it wants its own explicit action, not a save that happens to be empty.

### Goods in transit and cancelled work orders (2026-09-18)

Two accounts the user had been asked for and finally chose, both built the same day.

- **`1304 Persediaan Dalam Perjalanan` makes the transfer journal two-phase.** Shipment now posts debit 1304 / credit the source warehouse's inventory account; receipt posts debit the destination's inventory account / credit 1304. Before this there was **one journal at receipt**, so a warehouse that had already shipped goods still carried their value in the ledger — the limitation the reconciliation page used to apologise for. Per-warehouse inventory value is now correct from the moment the truck leaves.
- **Both 1304 lines are tagged with the destination warehouse**, on both legs. Tagging the shipment leg with the source would read as "warehouse A still holds this", which is the very thing being fixed; tagging with the destination makes the balance answer a useful question — *what is inbound to warehouse B* — and nets to zero there on receipt.
- **An under-receipt leaves the difference sitting in 1304.** That is deliberate: goods that left and never arrived are still somebody's problem, and parking them in a named account keeps them visible instead of letting them vanish between two warehouses. The `TRANSFER_SHORTAGE` layer marks the same event on the stock side.
- **`RekonsiliasiGudangService` stopped adding in-transit value back onto the source warehouse.** That compensation existed *because* the ledger kept the value there; with the ledger fixed, leaving it in place would have made every in-flight transfer show a red variance. Removing it is part of the same change, not a separate cleanup.
- **New invariant `transit`**: the GL balance of 1304 must equal the value of every `IN_TRANSIT` layer. Written at the same time as the feature, per the rule under **Reconciliation invariants**.
- **`5308 Kerugian Pembatalan Produksi` lets a work order be cancelled after it has absorbed cost.** It posts debit 5308 / credit `1303 Barang Dalam Proses Produksi` and writes a **negative** `wms_data_pesanan_biaya` row so the subledger reaches zero alongside the ledger. A separate loss account was chosen over dumping it into Beban Pokok Penjualan so that a cancellation is visible in Laba Rugi as its own line and stays traceable per work order.
- **A cancelled work order leaves `DataPesanan::berjalan()`**, which is why the `wip` invariant keeps holding without extra work. Completion and double cancellation are still refused; only the "has cost" refusal is gone, and the test that used to assert it now asserts the write-off instead.

### Receivables ageing, salesperson, cross-dock and BOM (2026-09-18)

Four of the remaining backlog rows, built in one pass. Each one leans on a mechanism that already existed rather than adding a parallel one.

- **Umur piutang reconstructs a past date instead of reading `sisa_tagihan`.** A sales return **mutates `grand_total` in place** (`ReturPenjualanService::kurangiFaktur()`), so an as-of-date report that trusted today's `grand_total` would understate every period before the return. The service adds back returns dated *after* the as-of date and subtracts only payments dated on or before it. **VOID invoices are excluded outright**, matching `apAgingBuckets()` — an invoice voided after the as-of date will therefore be missing from a backdated run; that is a known boundary, not an oversight.
- **The ageing report ties out to the GL and says so on screen**, like the PSAK 1 statements. The tie-out is **suppressed when a single customer is selected**, because `wms_jurnal_detail` carries no customer dimension and a partial subledger against a full GL balance would render a meaningless "Selisih".
- **The salesperson is snapshotted onto the invoice, not joined through four hops.** `wms_faktur_penjualan.sales_user_id` is copied from the order when the invoice is created, so reassigning an order later cannot move revenue that has already been billed. It **defaults to the document's creator**, and **no eighth role was invented** — any active user can be named; adding a role is the user's call.
- **A cross-dock is a promise, not a movement.** It reuses `ReservasiPersediaan` instead of a new stock state or layer status, so "warehouse balance = sum of layers" is untouched. The consequence drives the design: `StokGudangService::keluar()` enforces *available − reserved ≥ qty*, so a mark would block the very shipment it exists for. `postingSuratJalan()` therefore **releases the marks for its order lines before consuming stock**. A **partial** shipment splits the mark — the shipped part becomes DIKIRIM and the remainder is re-reserved as a new row — rather than releasing the whole promise on the first truck.
- **Cross-dock is warehouse-scoped on both sides.** `CrossDockPolicy` guards viewing and cancelling per warehouse, and `tandai()` refuses a warehouse the user cannot receive into. An earlier version checked the warehouse only on cancel, so an unassigned operator could create a mark it could not then release.
- **`WarehouseExecutionService::reserve()` gained an optional explicit `$userId`.** `wms_reservasi_persediaan.created_by` is NOT NULL and the method read `Auth::id()`, which is null in a seeder, a queue worker, or any service-level call. Pass the user when you have one.
- **BOM variance is reporting only — it posts no journal.** Production costs on *actual* consumption and material already hit WIP at NPK issue; journalising a variance on top would double-count. One BOM may be active per `bahan_hasil`, enforced in the service. Materials consumed that the BOM never listed are shown as **DI LUAR BOM** instead of being dropped, so the report cannot quietly hide unplanned usage. Variance is valued at the **actual average unit cost**, falling back to the newest layer cost for a component never consumed, and **null when neither exists** — never zero, which would read as "no variance".
- **Seeder ordering matters**: `PenjualanDanProduksiDemoSeeder` runs *after* `syncMultiWarehouseDemoData()`, because `StokGudang` is only filled there and the sales flow needs saleable balances. Its backdated trading sale is dated from **an actual layer's `transaction_date`**, since `ambilLayer()` refuses layers newer than the document date. Each scenario is wrapped so a thin demo dataset warns and continues instead of breaking `migrate:fresh --seed`. The cross-dock scenario must run as a **warehouse** user, not the purchasing user that drives the sales scenarios — `tandai()` enforces warehouse access and will refuse otherwise.

**Mechanics worth knowing before editing these four:**

- **Ageing buckets** are `Belum Jatuh Tempo`, `1-30`, `31-60`, `61-90`, `> 90` Hari, and they are deliberately identical to `ExecutiveDashboardService::apAgingBuckets()` so payables and receivables read on the same scale. An invoice with **no due date** falls into `Belum Jatuh Tempo`, matching the payables side. Outstanding as of a date = `grand_total` + returns dated after that date − payments dated on or before it; a row whose result is ≤ 0.005 is dropped rather than shown as zero.
- **Cross-dock lifecycle** is `DIRESERVASI → DIKIRIM` or `DIRESERVASI → DIBATALKAN`. Only `DIRESERVASI` holds a reservation, and `reservasi_id` is nulled on both exits so a released reservation can never be released twice. A suggestion is bounded by three numbers at once — remaining order quantity (minus marks already active on that line), remaining quantity on the receipt line, and free stock — and the smallest wins. The suggestion window is `CrossDockService::HARI_TERAKHIR` (14 days).
- **Variance row statuses** are `SESUAI`, `BOROS`, `HEMAT`, `BELUM DIPAKAI`, and `DI LUAR BOM`. The basis is `jumlah_selesai` once the work order is complete, `jumlah_rencana` before that, so the standard tracks what was actually produced rather than what was planned. Standard quantity is `BOM line qty × basis ÷ bom.jumlah_hasil` — `jumlah_hasil` exists so a BOM can describe a set (e.g. components per 10 units) instead of forcing per-unit fractions.
- **Sales performance excludes cancelled orders** (`DIBATALKAN`) and draft/void invoices, counts revenue as **DPP** rather than grand total so PPN never inflates a salesperson's figure, and attributes returns through the invoice's own `sales_user_id`. A null salesperson is reported as `Tanpa sales` rather than hidden, so unattributed revenue stays visible.

### Sales, receivables and output VAT (2026-09-16)

`Pelanggan → PesananPenjualan → SuratJalan → FakturPenjualan → PenerimaanPembayaran`, with `ReturPenjualan` off the delivery. This closed the biggest hole in the system: until then Laba Rugi had no revenue side and Neraca had no receivables.

- **Delivery is separate from invoice**, mirroring `PenerimaanBarang → FakturPembelian` on the buy side. Goods can leave before they are billed.
- **Surat Jalan is where inventory and the GL move.** It consumes layers through the same `StokGudangService::ambilLayer()` FIFO path as NPK and transfers — no second costing implementation — records each draw in `wms_surat_jalan_alokasi`, and posts debit Beban Pokok Penjualan / credit Persediaan **with the warehouse dimension**. Draft deliveries touch nothing.
- **Output VAT reuses `2109` and `AccountingSetting::PPN_KELUARAN`** from the 2026-09-14 trade-in build. There is no second output-VAT account.
- **A PPN invoice cannot post without its NSFP**, matching the purchase side.
- **Over-payment is refused** rather than silently creating a customer advance — no customer-advance mechanism exists, and inventing one silently would be worse than a clear error.
- **A sales return creates a NEW layer** at the delivery's own unit cost (`source_type='RETUR_PENJUALAN'`) instead of un-consuming the originals. The originals may already be partly consumed by other documents, and resurrecting them would corrupt FIFO ordering. `4102 Retur Penjualan` is **PENDAPATAN/DEBIT** — contra-revenue, so its normal balance is deliberately the opposite of its category.
- New COAs `1201`/`4101`/`4102`/`5401`, all checked unused first (trap 4); their settings went into **both** the constants and the `accountId()` `$expected` whitelist.
- **New route file `routes/sales.php`**, registered in `web.php` *and* in `ArchitectureConventionTest`'s required-file list.
- **Deliberate deviation:** the sales lists use plain paginated Blade tables, not Yajra + `wmsDataTable()`. The hard rule (no jQuery, no DataTables.js) still holds. Converting them later is mechanical.
- Not built, deliberately: customer advances, credit-limit enforcement (`plafon_kredit` is recorded and displayed but **not enforced** — blocking an order on credit is a policy decision nobody has made), AR aging, salesperson tracking.

### Production work orders and WIP (2026-09-16)

Job costing on **actual** consumption for make-to-order production. See the decisions table above for the four policy calls behind it.

- **The spine already existed informally**: `kode_datapesanan` on NPK was free text (every seeded NPK had it filled), and `wms_penerimaan_jasa_alokasi.datapesanan_code` allocated service cost to the same tag. Both were tagged, nothing accumulated. `KittingService::rakit()` had also already proved the value mechanic — **a work order is a kit assembly that stays open over time**, which is why this reuses `ambilLayer()` rather than adding a second costing path.
- **WIP balance is derived from `wms_data_pesanan_biaya` rows, never a stored running field** — same reasoning as the loss carry-forward and the deferred-tax "read the GL, don't store it" decisions.
- **A completed work order cannot accept further cost.** Unit cost is frozen at completion (`WIP ÷ jumlah_selesai`) and every shipment relieves `qty × biaya_per_unit`. Cost arriving later would change unit cost retroactively and mis-cost units whose journals are already posted. Legitimate later cost goes on a **new** work order. The same rule makes `reverseNpk()` refuse an NPK whose work order is sealed.
- **The cost row is written inside `postNpk()` itself**, not at the four controller call sites, so journal and subledger cannot drift.
- **An NPK without a work order is completely unchanged** — there is a test guarding exactly that.
- `buatSuratJalan()` **auto-links** the delivery line to a completed work order for that sales-order line, so there is no extra operator decision to forget.
- **Why `1303` and not `1302`:** the plan was to reuse `1302 Barang Dalam Proses Jasa`, but measuring showed it already carried a 6,000,000 balance from an `INVOICE_SUPPLIER` journal (the "Jasa Produksi" category maps `coa_persediaan_id` to it). Mixing would have made the WIP invariant permanently unverifiable. Production got `1303 Barang Dalam Proses Produksi`; 1302 keeps its meaning and balance. While there, that category's `coa_beban_id` was corrected from `1302` (an ASET account) to `5202` — **that was the mapping-validation bug**, itself a symptom of the missing WIP domain.
- **New invariant** in `AccountingReconciliationService::checks()`: `wip` — the GL WIP balance must equal the summed WIP of running work orders. It is what caught the 1302 problem.
- **Known gap, deliberate:** a work order can only be cancelled while its cost is zero. If a customer cancels mid-production the WIP needs writing off to a loss account, and **no account was invented for that** — it needs the user's decision.
- Not built: BOM as a standard (so there is no material-usage variance yet), labour, overhead, routing.

### Analytics, CALK, maker-checker, warehouse dimension (2026-09-16)

**Lacak Pembelian** walks a *layer graph*, not one layer: QC rejection splits off `QC_REJECT_{id}` (keyed to the LPB detail), Consider inspection creates `CONSIDER_*` layers (keyed to the source layer), and a transfer's destination layer is reachable through `AlokasiTransferGudang`. The anchor value is the **LPB detail**, not the origin layer's `initial_quantity`, because QC inspection *mutates* that field. `tidak_terlacak` is a deliberate residual: **kit assembly is the one consumption path that records no link back to the layer it consumed**, so rather than pretend the buckets always sum, the report shows the gap and names kitting as its cause. PPN is excluded from all buckets — input VAT is a recoverable asset, not part of cost basis.

**Supplier scorecard**: there is **no promised-delivery-date in the schema** (`term_pengiriman` is a delivery *mode*, `term` is the *payment* term), so "on-time vs promise" is not computable and claiming otherwise would invent data. What is computed is lead time plus a "within target" percentage against a target the user types in. **QC reject ratio returns `null`, not `0`**, when no inspection data exists — a zero would read as "never sends rejects", the opposite of the truth. Retur ratio is offered as the quality signal that does have data.

**CALK** completes PSAK 1. Figures come from the GL every time; four narrative sections live in `wms_catatan_laporan_keuangan`, unique per period. `kebijakan_akuntansi` falls back to `CalkService::TEMPLATE_KEBIJAKAN` and the UI badges it as boilerplate until saved.

**Maker-checker**: manual journals use the status column (`DRAFT → PENDING_APPROVAL → POSTED`) — the safety property is free, since every report already filters `status='POSTED'`. Reversals and COA changes use `wms_permintaan_persetujuan` + `PersetujuanOperasiService`. **The payload is data, never code**: `jalankan()` dispatches on a `match` over five fixed constants; there is no serialized callable. **Self-approval is refused in the service, not only the policy** — `Gate::before` gives Super Admin every policy, so a policy-only check would defeat the point. **COA edits are gated only when the account is load-bearing** (mapped or carrying journal lines); a brand-new unused account still saves directly, keeping the friction where the risk is. `updateMapping()` always requires approval.

**Warehouse dimension** (`wms_jurnal_detail.gudang_id`): every posting site that knows a warehouse tags **all** lines of that document, not just the inventory line. **Internal transfers now post a journal.** It was a single entry at receipt until 2026-09-18, when it became two-phase through `1304`; the receipt leg still values from the destination layers, so an under-receipt books only what arrived. That limitation — in-transit goods staying attributed to the source warehouse — **was fixed on 2026-09-18** when `1304 Persediaan Dalam Perjalanan` arrived and the transfer journal became two-phase; see **Goods in transit and cancelled work orders**. Migration `2026_09_16_000004` **backfills** the dimension onto historical LPB/NPK/opname/kit/retur journals — without it the new report reads "Tanpa dimensi gudang" for everything and is useless on day one.

### Services: capitalization, gate pass, loaner, trade-in (2026-09-14)

- **Capitalization hooks `postInvoice()`, not the BAP.** A service BAP posts no journal in this system; cost hits the GL at invoice time. `KAPITALISASI` categories debit the target asset's `akun_aset_id` **per line**, because two lines can point at different assets.
- **Double capitalization is impossible by schema, not convention** — `wms_kapitalisasi_aset` has a unique on `(sumber_type, sumber_id)`, and the service returns null rather than throwing, so re-posting an invoice cannot inflate an asset.
- **Gate pass posts no journal, by design.** Custody moves, ownership does not; the asset stays `ACTIVE` and keeps depreciating, which is correct under PSAK 16. **Sending *inventory* out is deliberately excluded** — it would have to touch `LayerPersediaan` and break "warehouse balance = sum of layers". Use a Transfer Gudang to a dedicated warehouse instead.
- **Vendor loaner writes no journal, no layer, no asset row.** The goods belong to the vendor; booking them would overstate both stock and assets.
- **Trade-in needed one COA, not the AR domain.** The outgoing leg is an asset disposal and the supplier's credit rides the existing supplier-advance mechanism. Gain/loss is measured against **DPP only** — per UU PPN Pasal 1A the DPP of a barter is the *nilai wajar* of the goods handed over, and VAT is not part of proceeds. Two schema facts it forced: `wms_pembayaran_faktur.invoice_lpb_id` became nullable (an advance can predate its invoice) and the table gained a direct `supplier_id`, because attribution previously ran only through `whereHas('invoice')`.
- Fixes whose reasoning must not be undone: `postInvoice()` **partitions** service lines so flipping a `KategoriJasa` to `KAPITALISASI` cannot strand in-flight POs without an `aset_id`; a gate pass can only be cancelled while `DRAFT` (otherwise it drops out of the overdue report that exists to chase it); `tambahanUmurBelumTerpakai()` applies a life extension **once per PO line**, not per progress receipt; `catatDariJasa()` re-reads the asset under `lockForUpdate()`.

### Warehouse execution (2026-09-13)

- **ABC Pareto edge case**: classify on the cumulative share **before** adding the item. Including it means nothing is class A when the top item alone exceeds 80% — the seed data produced exactly that. `kontribusi_kumulatif` still stores the *after* value, because that is what a human reads on a Pareto table.
- **Cycle counting** reuses the existing opname lifecycle; due dates need no new table (`MAX(posted_at)` of POSTED `SIKLUS` opnames per warehouse+class vs the cadence).
- **Slotting is read-only advice.** A physical move still goes through transfer/putaway, which is what keeps layer values and the reconciliation invariants honest.
- **Warehouse scoping rollout:** migration `2026_09_13_000004` granted every existing Warehouse user every active warehouse, so nobody's access changed on day one. **A newly created Warehouse user starts with zero warehouses** and the work queue says so explicitly instead of rendering an empty page.
- **QC re-inspection blind spot, fixed:** a partial rejection puts held stock in a `QC_REJECT_{id}` layer, not `LPB_DETAIL`, so the end-of-inspection "anything still on hold" check matches across **both** source types. Note `putaway()`'s own hold guard still filters `LPB_DETAIL` only — same blind spot if it ever matters.
- **Kitting is a mini-BOM, not WIP**: no routing, labour, or staged work-in-process. `postPerakitanKit()` **returns null when every line nets to zero** (kit and components mapping to the same COA); the assembly is still recorded, there is simply nothing to journalise.
- Auto-reservation on Material Request approval is **partial and non-fatal** by the user's choice.

### Lot control, putaway, picking, serials (2026-09-09)

- `bahans.wajib_lot`/`wajib_expiry` make lot/expiry enforceable per material. Without them the `expires_at` field was purely optional, so one skipped entry silently switched FEFO and every expired-stock guard back off for that receipt. **`wajib_expiry` without `wajib_lot` is rejected**, not coerced — expiry lives on the lot, so that combination is unsatisfiable at receiving time. The checkboxes need an `<input type="hidden" value="0">` companion because the controller reads them with `$request->boolean()`.
- **Putaway is per line**, and `wms_lokasi_gudang.capacity` — which existed but was read by nothing — is now enforced. Capacity is total units in the bin: dimensionally loose across mixed materials, but that is what one decimal column can express. A null capacity means unlimited. Volumetric capacity was added 2026-09-13 and **either check is skipped when its data is absent**, so bins with only `capacity` and materials with no volume keep working.
- **A completed pick becomes a DRAFT NPK, deliberately not a posted one** — the journal/FIFO/period-lock path already lives in the DRAFT→POSTED branch, so none of that tested logic is duplicated. `picking_order_id` is also the duplicate guard.
- **Serials are capped at what the lot actually received**, skipped when the lot has no layers yet so manually pre-created lots still work. This is deliberately *not* serial-level issue tracking inside NPK — NPK is quantity-based.

### Platform hardening (2026-09-12/13)

- **`is_active`, `deactivated_at`, `deactivated_by` are deliberately NOT in `$fillable`** — an account-state flag must never come from request input. `UserAccountService` writes them with `forceFill()->save()`.
- **`UserPolicy` returns false from every method except `view` (self)** — that is not dead code. Super Admin reaches everything through `Gate::before`, so an all-false policy is precisely how "Super Admin only" is expressed under this repo's rule against redundant Super Admin checks inside policies.
- **Deactivated accounts are checked *before* `Auth::attempt`.** Doing it after a failed attempt would write **two** audit rows for one attempt and hash twice. The "akun dinonaktifkan" message only appears when the password is also correct, so it leaks no account state.
- **Auth auditing hangs off Laravel's own events**, not the controller, so every authentication path is covered rather than one form.
- `throttle:login` has **two tiers** — 5/min per `email|ip` and 20/min per IP — so one attacker cannot lock out an office by hammering one address.
- **`php artisan schedule:work` and `queue:work` are deployment requirements.** Without the scheduler the system is back to "only notices when a human clicks". With a queue driver and no worker, notifications sit in `jobs` and the `notifications` table stays empty — that is correct behaviour, which is why the command says "diantrikan", not "terkirim".
- `wms:penyusutan-bulanan` defaults to **last** month, since it runs on the 1st.
- **Reminder sources were extracted, not duplicated**: `PengingatService` serves both the dashboard panels and the scheduled digest.
- **Attachment delete is restricted to the uploader, not `update` on the parent.** An earlier version tied it to the parent's `update`, which is false for posted documents — making it impossible to attach or tidy evidence on exactly the documents that need it most, and an invoice scan normally arrives *after* posting. **Evidence is not document content; do not re-couple these.**
- Attachments: private `lampiran` disk, swappable via `LAMPIRAN_DISK`, max 5 MB, `pdf/jpg/jpeg/png/webp`, served only through the authorized download route. `LampiranService::INDUK` is the whitelist — the request never names a class.
- **`PenerimaanBarangService` was extracted for reusability, not line count.** One deliberate behaviour change: the over-receive race used to `abort(422)` while a category mismatch threw and produced a **500**. Both now throw `RuntimeException` and the controller catches it into 422 — a client-data problem is a client error. `storeDetail()`/`updateDetail()` stayed in the controller on purpose: they never touch `LayerPersediaan`.
- **The dashboard's reconciliation count is cached 5 minutes; the reconciliation page itself is deliberately NOT** — it must stay live.

### Accounting and tax (2026-09-11/12)

- **Declining balance** is 2× the straight-line rate on running book value, reproducing Indonesian saldo-menurun rates. The pre-existing terminal-period clamps needed no change.
- **Arus Kas** classifies each journal by its **dominant non-cash counterpart line**. **Known limitation:** long-term borrowings cannot be told from trade payables with current COA metadata, so bank-loan flows land in Operasi; fixing it needs a current/non-current sub-classification, an accounting-policy decision.
- **Perubahan Ekuitas** splits movement into **gross credits and gross debits**, because the statement must show an injection and a withdrawal separately even when they offset. **Retained earnings is a pseudo-component with a null `account`, not a COA row** — `balanceSheet()` derives equity as EKUITAS accounts *plus* cumulative net income, and the statement mirrors that. **Do not invent a Laba Ditahan COA**; the balance sheet would double-count.
- Both statements, and CALK, carry a **self tie-out** and render a red "Selisih" row if it diverges. They tie out by construction today — that is the point; they are tripwires for a future change, not discoveries.
- **BEDA_WAKTU corrections are never the account's book amount** — always the *difference* between commercial and fiscal treatment. Accounts with no fiscal basis report `koreksi = 0` with `MENUNGGU_JADWAL_FISKAL`. Do not "fix" that by adding the book amount.
- **Fiscal depreciation ignores residual value entirely** and buildings may only use straight-line. It is **not journalized** — it is a tax computation, stored alongside the commercial figure so both schedules advance on the same periods.
- The timing difference is grouped by `KategoriAset.depreciation_expense_coa_id`, **not globally** — a global total would be attributed to every account row and double-counted.
- **Deferred tax uses the balance-sheet liability method** (PSAK 46), and the already-recognised amount is read **from the GL balances rather than a stored field**, so it cannot drift.
- **`posting()` deliberately does not refuse when there is nothing to journalise.** A loss year produces no PPh, yet its record must exist for the loss to be claimable later, so the row is always created and `journal_id` stays null. Reinstating a "nothing to post" exception would make losses uncarryable.
- Loss carry-forward consumes **oldest-first** (statutory order, and wastes the least before expiry), and the offset applies **before** the thousands rounding.

### Service tax treatment — the durable part

Verified against the regulations on 2026-09-11; this does not change when the code does.

- **Jasa repair/pemeliharaan** — PPh 23 at 2% (mesin, peralatan, listrik, AC, bangunan). A repair that merely restores is an expense; one that extends useful life or capacity must be **capitalized** (PSAK 16).
- **Sewa** — withholding splits by object: **selain** tanah/bangunan → PPh 23 2%; tanah dan/atau bangunan → **PPh 4(2) Final**.
- **Tukar tambah** — **not a service.** Penyerahan barang via tukar-menukar; per UU PPN Pasal 1A barter is a PPN object and the DPP is **nilai wajar**, not book value. Classifying it as jasa gets both the DPP and the tax type wrong.

### Demo dataset: what each seeder is for, and the order that matters (2026-09-18)

`DatabaseSeeder` runs seven demo seeders in a fixed order, because each leans on what the previous one left behind. The last three were added when the dataset was enriched from 47 empty domain tables down to 20.

| Seeder | Adds | Depends on |
|---|---|---|
| `WmsDemoSeeder` → `FinancialStatementDemoSeeder` | purchases, receipts, invoices, payments, assets, services, multi-month ledger | master data |
| `PenjualanDanProduksiDemoSeeder` | sales, deliveries, work orders, BOM, cross-dock | `syncMultiWarehouseDemoData()` having filled `StokGudang` |
| `OperasiGudangDemoSeeder` | transfers (received / short / still in transit), gate passes, consignment, work centres and routing, FX closing rate and revaluation | warehouse stock, an active BOM, an open supplier invoice |
| `EksekusiGudangDemoSeeder` | kit definition and assembly/disassembly, reservations and picks, sales return, locked period, CALK, approval requests | posted deliveries, free stock |
| `KontrolMutuDanPajakDemoSeeder` | QC inspection, serials, picking wave, controlled reversal, PPh Badan, replenishment run | an LPB whose layers are untouched, a posted NPK without a work order |

Things that will bite whoever edits these:

- **Every scenario is wrapped so it warns and continues.** A scenario that cannot run must never break `migrate:fresh --seed`, because the dataset it needs may legitimately be absent. The wrapper's message says which scenario was skipped and why.
- **QC needs an LPB whose layers are still whole.** `inspect()` refuses a receipt whose stock has been consumed, so the seeder picks a receipt where every layer still has `remaining_quantity = initial_quantity` rather than simply the first one.
- **The reversal scenario deliberately picks an NPK with no work order**, because `reverseNpk()` refuses one whose work order is sealed.
- **CALK is written for last year, not the current period.** Writing the current period would make the "defaults to template" behaviour untestable and would hide the template badge that the UI is supposed to show until a narrative is saved.
- **Landed cost is deliberately absent, and it is a decision, not an oversight.** `LandedCostService` requires its credit account to be `LIABILITAS/KREDIT` or `ASET/KREDIT`, so freight cannot be credited to Kas (`ASET/DEBIT`), and the only fitting liability is `2101 Hutang Usaha` — which the `ap` invariant compares against supplier invoices, so crediting it without an invoice would turn that invariant red. There is no accrued-expense account in the COA. See the Open-items index.
- **The demo data is small on purpose** — three stocked materials — so a scenario that consumes several units can starve a later one. Add new scenarios at the end and keep quantities low.

### Reminders and the invariant watchdog (2026-09-18)

Both exist for the same reason, stated in **Platform hardening**: without something scheduled, the system is back to only noticing when a human clicks. Two halves of that were still open.

- **`PengingatService` was blind to everything built after 2026-09-16.** It imported exactly three models — `FakturPembelian`, `LayerPersediaan`, `MaterialRequest` — so the entire sell side, production and custody had no reminder at all. This is the same asymmetry the `ar` invariant closed on the ledger side: the buy side had watchdogs and the sell side had none. Four reminders now cover it — `piutangJatuhTempo()` (the deliberate mirror of `invoiceJatuhTempo()`, so payables and receivables are chased on one scale), `barangKeluarBelumKembali()`, `crossDockBasi()` and `perintahKerjaMandek()`.
- **`barangKeluarBelumKembali()` reuses `PengeluaranBarangService::terlambat()` rather than re-querying**, which is why `PengingatService` now takes a constructor dependency. The overdue gate-pass figure already existed and was already correct — it simply never left the page that computed it. Extracting rather than duplicating is the rule that produced this service in the first place; do not inline a second copy of that query.
- **A cross-dock mark had no expiry of any kind.** `CrossDockService::HARI_TERAKHIR` (14) bounds only the *suggestion* query; a `DIRESERVASI` mark holds its reservation forever until somebody ships or cancels it, and reserved stock blocks other shipments. `AMBANG_CROSS_DOCK_BASI_HARI` is **7** — half the suggestion window, because a cross-dock is a promise of an *imminent* shipment, so a week unshipped is already past its own premise. It is a nudge, not enforcement: nothing is released automatically, because releasing someone's promise without asking is worse than leaving it stale.
- **"Mandek" is measured from the last cost row, not from `updated_at`.** `AMBANG_PERINTAH_KERJA_MANDEK_HARI` is 14, and the basis is `MAX(wms_data_pesanan_biaya.tanggal)` falling back to the work order's own date, pulled in **one grouped query** rather than per work order. `updated_at` moves for reasons that are not progress; a cost row is evidence that something actually happened. Only `DRAFT` and `DIRILIS` count — a `SELESAI` work order waiting on shipment is a different problem with a different owner.
- **New reminders must be added to the dashboard panel too, not only the digest.** `DashboardService` and `KirimPengingatHarian` both consume `PengingatService`, and wiring only the command leaves the panel blind while the email is correct — a split that is invisible until somebody compares them. A test asserts the finance panel sees receivables.
- **`wms:periksa-invarian` turns the eight invariants from a report into an alarm.** It runs at **06:30, deliberately before the 07:00 digest**, so a broken ledger is the first thing accounting hears about rather than the last. It **returns a non-zero exit code** when an invariant is red, which is what makes it usable as a health check in CI or a monitor; the schedule does not care either way.
- **`InvarianMenyimpang` is a third notification class rather than a reuse of `PengingatTenggat`.** A breached invariant has no deadline, and the shared `rincian` renderer hardcoded "Lewat N hari"/"N hari lagi" against a `hari` key. That renderer now falls back to a `catatan` string when `hari` is absent, so the component serves both event kinds instead of forcing a fake date onto one of them.

### Safety nets added after the 2026-09-16 review

A code review found 11 defects that all survived a green suite, because every one lived in a layer the tests never entered — controller guards, Blade forms, policies, and cross-document arithmetic. Two standing tests now cover that blind spot:

- **`SmokeSemuaHalamanTest`** fires every named GET route as Super Admin and fails on any 5xx. On its first run it found three more breaks nobody had reported: the reconciliation page's Blade parse error (trap 9), a `request.show`/`edit` route pointing at controller methods that do not exist, and three report PDFs whose columns omit `align`, which the shared `table-pdf` view dereferenced unguarded. It also asserts a **ceiling on how many routes get skipped** for unresolvable parameters, so coverage cannot quietly rot.
- **`HalamanTanpaEfekSampingTest`** (added 2026-09-18) fires every parameterless GET page and fails if it issues an `insert`, `update` or `delete`. On its first run it found that twelve create forms were burning real document numbers and that the cross-dock page was creating `stok_gudangs` rows — see **Document numbering** above. A green suite had hidden both for as long as those features had existed, because every test only asserted the status code.
- **`KualitasTampilanTest`** renders every parameterless page and checks the HTML itself, not just the status: every view must compile to valid PHP, no raw Blade directive or uncompiled `{{ $x }}` may reach the browser, no listing may be silently empty without saying so, and every table must have a scroll wrapper. It also caught a Blade break within minutes of that break being introduced.
- **`AksesPerRoleTest`** is an explicit contract of which pages each role must be able to open and which must be refused. Super Admin smoke-testing cannot catch this class at all — `Gate::before` opens everything — and two of the eleven defects were exactly this shape: the sales menu buried inside the Accounting-only sidebar section, and Accounting Manager getting 403 on the journal list it is supposed to approve.

A UI sweep in the same pass fixed what those nets then measured: four views whose tables could not scroll horizontally and four with grids that never stacked on mobile (both are hard requirements in **Frontend conventions**); a warehouse-assignment delete with no confirmation; the "Match ulang" button rendered for roles that lack `matchSupplierInvoice`; and **eight raw role checks in Blade replaced with the gates they were duplicating** (`isWarehouseOperator() || isSuperAdmin()` is just `@can('operateWarehouse')` — `Gate::before` already covers Super Admin, and the duplicate would drift the moment a gate changed). The reconciliation listing was also unbounded — it rendered every (gudang × bahan) pair — and now defaults to showing only the rows in exception, capped, with the summary counts still computed from the full set.

Decisions from those fixes worth keeping: a sales return on an **uninvoiced** delivery posts only the cost side and never touches Piutang Usaha; a return worth more than the invoice's outstanding is **refused** rather than clamped, because there is no customer-refund mechanism; delivery and invoice quantities are counted from the **documents that exist** (including drafts) rather than from `jumlah_terkirim`/`jumlah_terfaktur`, which only move at posting; and a draft sales invoice can now be **deleted**, since drafts legitimately claim quantity and there was otherwise no way to release a mistaken one.

### Earlier work, in one line each

- **Material Request** (2026-09-05): any role except Purchasing may submit; non-reviewers see only their own; `FULFILLED` is centralized in `MaterialRequestFulfillmentService::syncRealisasi()`.
- **Role dashboards** (2026-09-05): task grid + Chart.js, additive to the existing curated cards. All data building lives in `DashboardService`, not `AuthController`.
- **Executive dashboard + Excel everywhere** (2026-09-06): every flat-table PDF report gained an Excel sibling sharing `GenericTableExport`; financial statements use `FinancialStatementExport` because they have grouped sections. Each `reportExcel()` **deliberately duplicates** its PDF sibling's query rather than risking a refactor across 13 tested exports.
- **Expiry chain** (2026-09-09): receiving finally sets `expires_at`, which had made five separate guards inert; the back-fill matters because the lot lookup key is `(bahan_id, lot_number)`.
- **Replenishment → Material Request** (2026-09-09): suggestions were a dead end; the link is carried across days so a next-day recalculation cannot invite a duplicate request.

## Dropped from the plan

Things that were once on the backlog and are **no longer wanted**. They are recorded here rather than deleted, because a gap that is simply removed gets rediscovered by the next read-through of the code and quietly re-added. **Do not put these back without the user asking.**

- **The `debits` and `kredits` master tables and their CRUD API.** Removed on 2026-09-18 after an audit found them unreachable: two `(id, kode, nama)` lookup tables scaffolded on 2026-07-21, never linked from any menu, empty in every environment, referenced by no domain code, and — being domain tables without the `wms_` prefix — in breach of the naming rule as well. They nevertheless exposed ten routed CRUD endpoints that accepted writes. Deleted with their models, policies, form requests, controllers, routes and migration. **If debit/credit notes are wanted later, build them as a real document type with a journal path, not by restoring these stubs.**

- **External API/EDI integration** (to suppliers, marketplaces, or other ERPs), beyond the bare Sanctum auth base that already exists. **Dropped by the user on 2026-09-16.** The absence of an integration layer is therefore a deliberate product decision, not an unfinished item — if a future survey of the code notices there is no public API, that is the expected state. Sanctum stays where it is; nothing needs removing.

## Open-items index (current as of 2026-09-18)

**The single authoritative list of what is unbuilt.** Do not assemble a backlog by reading the code or the Feature notes — things that look missing are often deliberate, and the deliberate ones are in **Dropped from the plan** or noted as boundaries in their Feature note. When an item here is built, delete the line; its reasoning belongs in Feature notes, not here.

**Needs a decision from the user before it is code**

- **A counter-account for landed cost** — `LandedCostService` demands `LIABILITAS/KREDIT` or `ASET/KREDIT`, so freight cannot be credited to Kas, and the only fitting liability (`2101 Hutang Usaha`) is what the `ap` invariant measures against supplier invoices. Either add an accrued-expense COA ("Biaya Masih Harus Dibayar"), or widen the rule to accept a cash credit. Until then landed cost cannot be seeded or realistically used, which is why the demo dataset has none.
- **Customer credit limit** — `pelanggans.plafon_kredit` is recorded and displayed but **not enforced**. Blocking an order on credit is a policy call.
- **Service categories vs the mapping rules** — `UpdateAccountingMappingRequest` validates every `KategoriBahan` with goods rules, which the seeded "Jasa Operasional" category cannot satisfy (its `coa_persediaan_id` is `5202`, a BEBAN account, where the rule demands ASET/DEBIT). "Jasa Produksi" stopped failing on 2026-09-16 when its mapping moved to `1302`. Either exempt service categories or give the last one a conforming account.
- **Customer advances** — over-payment is still refused rather than parked. Building this needs a "Uang Muka Pelanggan" COA and reverses a deliberate 2026-09-16 decision, so it is a policy call, not a refactor.
- **Labour and overhead absorption into WIP** — BOM now gives a material standard, but labour needs hourly rates, time capture, and an allocation basis. All three are policy inputs nobody has supplied.

**Externally specified — verify the spec first**

- **DJP outputs**: e-Faktur CSV, SPT Masa PPN 1111, e-Bupot, and the trade-in's outgoing leg. The data is complete (NSFP + PPN Keluaran on real invoices), so only the format work remains. **Verify the current DJP spec before building; never infer the layout** — this is why the 2026-09-18 pass left it alone rather than guessing a layout that would look finished and be wrong.

**Ordinary unbuilt work**

- **Lacak Pembelian has no bucket for goods lost in transit.** A transfer shortage now shows up as untraced value rather than as a named disposition, alongside kit assembly. Both are real residuals the report is honest about, but a "hilang dalam perjalanan" bucket would make the shortage one explainable on screen.
- **Sending inventory (not assets) out for subcontract** — deliberately excluded: a custody document must not touch FIFO layers. Interim path is a Transfer Gudang to a dedicated warehouse.

**Deferred by the user**

- **Barcode / QR** for putaway and picking. Design note to keep: **per bundling/kit, not per item**.

**Nothing open**: platform (all eight production-readiness items built 2026-09-12/13), PSAK 1 statements (all five), maker-checker in the three areas chosen, per-warehouse inventory valuation, the sales, production and services domains as scoped, and the receivables/salesperson/cross-dock/BOM cluster built 2026-09-18.

## Measured debt and coverage

Numbers here are **snapshots taken 2026-09-18** on Laravel 12.69 / PHP 8.3. Re-measure before relying on one; the commands that produced them are given so you can.

### What each safety net actually catches

The suite is not uniform — each net covers a different failure class, and knowing which is which saves you from writing a test that duplicates one and leaves the real gap open. Counts are tests in the class, taken 2026-09-18.

| Net | Enters through | Catches | Cannot catch |
|---|---|---|---|
| `ArchitectureConventionTest` (14) | static analysis, `git ls-files`, and the route table | class/file casing; inline validation in controllers; route file split and `route:cache`-ability; lowercase status codes; floating point in migrations; legacy table names; **document numbers allocated on a display path**; **Blade forms pointing at a missing route, the wrong verb, or omitting `@csrf`**; **dangling `route()`/`view()` references**; **a Form Request neither it nor its controller authorizes**; **a destructive action reachable by GET**; **a `hasMany`/`hasOne` that does not name its foreign key**; **a table name written as a string that no longer exists**; **a route whose controller method does not exist** | anything about runtime behaviour |
| `SmokeSemuaHalamanTest` (2) | HTTP GET as Super Admin; HTTP mutations as a guest | any 5xx on a named GET route; a ceiling on how many routes may be skipped for unresolvable parameters; **any mutating route a guest can reach, or that 5xx's instead of refusing** | a page that returns 200 while being wrong; per-role access, because `Gate::before` opens everything for Super Admin |
| `KualitasTampilanTest` (6) | HTTP GET + raw HTML | views that do not compile; raw Blade directives or uncompiled expressions reaching the browser; a silently empty listing; a wide table with no scroll wrapper; **hardcoded colour classes on any rendered page** | correctness of the numbers on the page |
| `KonsistensiTemaTest` (3) | Blade/JS/CSS sources + `tailwind.config.js` | **hardcoded colour anywhere in source**, including files no test ever renders — numbered Tailwind palette classes, `white`/`black`, arbitrary `[#hex]`, `theme('colors.*')`, inline styles, raw colours in CSS; a chart that stops reading theme tokens or stops re-applying them on `inventory:theme-changed`; both themes' elevation ramp and WCAG contrast | a layout that is ugly while using the right tokens |
| `HalamanTanpaEfekSampingTest` (1) | HTTP GET + query log | a GET page that issues `insert`/`update`/`delete` | writes on mutating routes, which are legitimate |
| `AksesPerRoleTest` | HTTP GET, per role | pages a role must reach and pages it must be refused — the one class Super Admin smoke-testing is blind to | mutating routes |
| `PengingatDanWatchdogTest` (11) | service layer, artisan commands, HTTP | a reminder that stops firing or fires when it should not, on both sides of its threshold; the daily digest losing a section; the dashboard panel drifting apart from the digest; the invariant watchdog failing to fail on a broken ledger; a notification that renders a breach as a deadline; the stock opname Excel export | whether a notification is actually delivered, which needs a queue worker |
| `KekayaanDataDemoTest` (26) | the seeded database | a demo dataset that stops exercising a document type, the enriched dataset breaking an invariant, and the two-phase transfer losing any of its three states | whether the seeded values are business-sensible |
| `CrudMasterDataTest` (3) | full HTTP round trip | create → update → delete on master data, per-role policy enforcement on each verb, duplicate and required-field validation | every other domain, which has no CRUD test of its own yet |
| `AturanJenisGudangTest` (5) | service layer + policy | Gudang Rusak being terminal in both directions, Consider stock landing on hold, warehouse flags agreeing with their type's meaning, and issuing from quarantine or damage being closed | warehouse rules that are only in a controller |
| `RevisiPesananPembelianTest` (2) | full HTTP round trip | a printed PO's revision being archived into the real history tables, and an unprinted one not being archived | other document revision paths |
| domain feature tests | service layer, and HTTP where it matters | business rules, ledger invariants, FIFO, WIP, tax | see the route coverage gap below |

### Route coverage gap

Measured with `php artisan route:list --json` against the named routes in `tests/`:

- **148** named GET routes take no parameter — covered by the four GET nets above.
- **52** named GET routes take parameters — covered only where `SmokeSemuaHalamanTest::nilaiParameter()` can resolve a value; the rest are counted as skipped and the skip count is asserted.
- **175** named routes mutate (`POST`/`PUT`/`PATCH`/`DELETE`). Since 2026-09-18 every one of them is exercised as a **guest** and must refuse, and since the same date every one of them is proven to resolve to a real controller method; but only **59** are named by any test as an authorised user, leaving **116** whose behaviour is unverified.

That last figure is the honest state of coverage, and it is why **trap 8** matters: a feature spanning HTTP → service → ledger needs at least one test that enters through the route. Reproduce the figure by extracting route names from `route:list --json` and grepping `tests/` for each one.

### Debt that is systemic, not an outlier

Do not "fix" either of these by touching one file — they are the shape of the older half of the codebase, and a partial migration is worse than a consistent one.

- **18 controllers contain `DB::transaction`, and 7 of those write more than one kind of model** inside it (`JurnalController`, `MaterialRequestController`, `PemeriksaanConsiderController`, `PesananPembelianController`, `ReturPembelianController`, `StockOpnameController`, `TransferGudangController`). The layering rule says cross-model processes live in `app/Services`, and the newer domains obey it (sales, production, cross-dock, BOM, kitting, landed cost). Extracting the older ones is a deliberate project, not a drive-by.
- **Models are split 53 `$guarded` to 40 `$fillable`** out of 94. This split is the root of **trap 1**, which has bitten four times. Check which one a model uses before adding a column.

## Reconciliation invariants — the sell side is watched too (2026-09-18)

`AccountingReconciliationService::checks()` now returns **eight** invariants: `stock`, `journal`, `invoice`, `grni`, `ap`, **`ar`**, **`transit`**, `wip`. The `ar` check compares the sum of `sisa_tagihan` on POSTED/PARTIALLY_PAID sales invoices against the GL balance of `PIUTANG_USAHA`.

It was added because the set was **asymmetric**: the buy side had two watchdogs (`grni` for goods received not invoiced, `ap` for unpaid supplier invoices) and the sell side had none. The receivable figures tied perfectly when measured by hand, so this fixed no data — it closed the hole where a *future* drift would have gone unreported on the reconciliation page while the identical drift on payables raised an alarm. Treat a new balance-sheet subledger the same way: if a document type can accumulate a balance, give it an invariant when you build it, not after.

## What the 2026-09-18 A–Z audit checked, and what it found

Run against the live seeded database on Laravel 12.69 / PHP 8.3. Recorded so the next reader knows which questions are already answered and does not re-ask them.

### Architecture — verified clean

- **784 `$fillable`/`$casts` entries** across every model resolve to real columns; **no model points at a missing table**. Rename drift inside models is zero.
- **No `create($request->all())` anywhere** — every write goes through a Form Request's validated array, which is why the 11 models still on `$guarded = []` are not the hole they look like.
- **Zero orphan rows** across ten parent/child relationships that were spot-checked (deliveries, invoice lines, receipt lines, journal lines, layers, WIP cost rows, cross-dock).
- Three controllers (`Kit`, `GelombangPengambilan`, `PengeluaranBarang`) have **no Policy but are not unguarded** — they use `Gate::allows('operateWarehouse')` plus explicit `canAccessGudang()` scoping. A different idiom, not a hole.

### Architecture — fixed in the same pass

- **11 `hasMany`/`hasOne` relations named no foreign key.** All eleven happened to resolve correctly *today*, which is exactly what makes trap 3 dangerous. All now pass the FK explicitly, and `test_every_has_many_relation_names_its_foreign_key` blocks new ones.
- **Two phantom tables** — see trap 11.
- **Asset status was a magic string in nine places** (`'ACTIVE'`, `'SOLD'`, `'TRADED_IN'`, `'DISPOSED'`) while every other document model declares its statuses as constants. Now `Aset::ACTIVE` and friends. Checking each literal before replacing mattered: three of the matches were reservation statuses, one was a lot's `quality_status`, and one a picking order — replacing by pattern would have broken three unrelated models.

### Architecture — measured, deliberately not changed

- **`$guarded = []` on 11 models.** Tightening to `['id']` was tried and **reverted**: with a non-empty `$guarded` and an empty `$fillable`, Eloquent consults the schema on every `fill()`, which adds a DB round-trip to model hydration and breaks pure unit tests that construct models without booting Laravel. No code mass-assigns `id`, so the change bought nothing real.
- **16 services read `Auth::id()`/`Auth::user()` directly.** That is a hidden dependency which is null in a seeder, a queue worker, or any service-level call — it already bit `WarehouseExecutionService::reserve()` once. Pass the user explicitly in new code.
- **21 domain tables carry no `wms_` prefix** (`bahans`, `gudangs`, `suppliers`, `pelanggans`, `requests`, `stok_gudangs`, `mutasi_stoks`, `transfer_gudangs`, and others). The History section's claim that the sweep gave "every domain table" the prefix is **wrong, and is corrected there**. Renaming them now would cross trap 6 (Yajra column and table names reaching JS) for cosmetic gain; it needs a decision, not a drive-by.
- **Statuses on `ReservasiPersediaan`, lot `quality_status` and picking orders are still literals** (roughly 30 across ten files). The same cleanup as the asset one applies, but the literals overlap between models, so it must be done per model with the same file-by-file check, not by pattern.

### Business rules — verified against the seeded data

- **Chart of accounts**: 48 accounts, first digit matches category in every one (1 aset, 2 liabilitas, 3 ekuitas, 4 pendapatan, 5 beban). Three accounts carry a normal balance opposite to their category — `1591 Akumulasi Penyusutan`, `4102 Retur Penjualan`, `5201 Diskon Pembelian` — and all three are **correct contra accounts**, not defects. No journal line posts to a non-postable or inactive account. Every `AccountingSetting` mapping points at an account of the category its constant requires.
- **The ledger balances**: posted debits equal posted credits exactly, and assets equal liabilities + equity + current earnings exactly.
- **Tax rates match the regulations**: PPN 11%, PPh 23 2%, PPh 22 1.5%, PPh 4(2) final 10% for land/building rent.
- **FIFO is honoured**: zero cases of a newer layer being consumed while an older untouched layer of the same material and warehouse still had stock.
- **Warehouse types behave as documented, and are enforced in the service, not just by flags.** Gudang Rusak is terminal in *both* directions — stock cannot leave it, and cannot be transferred straight into it either, because it must pass through Consider first. Stock arriving in Consider is written as `QC_HOLD`, never `AVAILABLE`. `AturanJenisGudangTest` now pins all of this, plus the flag/meaning agreement and the rule that quarantined or damaged stock can never be issued. Before this pass **no test mentioned `RUSAK` or `CONSIDER` at all**.
- **LPB has no separate posting trace and does not need one**: `PenerimaanBarangService::terima()` creates the draft and posts it inside one transaction for one user, so `id_user` plus `created_at` is the trace. Do not "fix" this by adding `diposting_oleh`.

## Known repo quirks

- The historical `accouting` (not `accounting`) misspelling in `resources/views/accouting` and `AuthController` was fixed 2026-09-01 — the view directory is now `resources/views/accounting`. If you spot the old name anywhere else (a stray blade include, a bookmark, an old branch), fix it the same way.
- `.env.example` exists; production secrets must still be managed outside the repo.
- `DatabaseSeeder` mixes master data and demo data — avoid running the full seeder against production.

## History — how this codebase got here

Kept short on purpose. None of this is work to resume.

**The original 4-phase modernization plan (approved 2026-09-01) is fully done.** Phase 1 was Tailwind/daisyUI/Alpine replacing Bootstrap/jQuery/DataTables (2026-09-01); `composer.json` declared Laravel 12 then, but the installed `vendor/` stayed on Laravel 10 until the framework was actually upgraded and the skeleton converted on **2026-09-18** — see **Application skeleton** above. Indonesian `snake_case` DB naming (phase 2); file/class naming matching `make:model -a` (phase 3). Phase 4 — thin controllers, validation in Form Requests, authorization in Policies, reusable logic in Services — was never a discrete task; it is the standing convention now written into **Architecture rules** above.

**Phases 2 and 3 were done together in 11 clusters on branch `refactor/rename-baku-indonesia`** (finished 2026-09-04), going further than planned: jargon (LPB, BAP, NPK, PO) became full *baku* Indonesian and most domain tables got the `wms_` prefix. **"Every" overstated it** — measuring on 2026-09-18 found 21 domain tables that never got it, including `bahans`, `gudangs`, `suppliers`, `pelanggans` and `stok_gudangs`; see **Measured debt** for why renaming them now is a decision rather than a cleanup. The conventions it produced are in **Architecture rules**; the technical traps it exposed are in **Traps**. The branch was never merged or pushed — the user asked to keep it local. **If someone asks to "continue the rename", confirm what they mean: the sweep as scoped is finished.**

Historical names, for anyone tracing one: `Asset`→`Aset`, `Lpb`→`PenerimaanBarang`, `ServiceBap`→`PenerimaanJasa`, `ServicePurchase`→`PesananJasa`, `ServiceCategory`→`KategoriJasa`, `Pembelian`→`PesananPembelian`, `Npk`→`PemakaianBarang`, `InvoiceLpb`→`FakturPembelian`, `InvoicePayment`→`PembayaranFaktur`, `ChartOfAccount`→`BaganAkun`, `InventoryLayer`→`LayerPersediaan`, `WarehouseLocation`→`LokasiGudang`, `PickingOrder`→`PesananPengambilan`, `QualityInspection`→`PemeriksaanKualitas`, `LandedCost`→`BiayaTambahan`, plus ten more in the WMS-control cluster.

**Known latent debt, documented not fixed:** the `Auditable` trait writes the raw FQCN into `wms_log_audit.auditable_type`, so rows written before a class rename keep the old string. Nothing queries that column, so it sits with the other deliberate historical drift (kept `LPB-` journal prefixes, stored `NPK`/`LPB` `sumber_transaksi` values) rather than being backfilled.

**Four surveys drove most of the roadmap** and are now fully resolved — their findings live in the feature notes above and their remaining items in the Open-items index:

| Survey | Verdict now |
|---|---|
| WMS module backlog (2026-09-09) | All warehouse-execution gaps built by 2026-09-13 |
| Multi-warehouse assessment (2026-09-11) | All five gaps closed; the last, per-warehouse GL valuation, on 2026-09-16 |
| Financial reporting & tax (2026-09-11) | PSAK 1 complete; fiscal reconciliation, PPh Badan and deferred tax built; the sales half built 2026-09-16 |
| Production-readiness / platform (2026-09-12) | All eight items built by 2026-09-13 |

The multi-warehouse survey's positive verdict is worth keeping, because it explains why the operational core is trusted: per-warehouse stock is real rather than cosmetic (`stok_gudangs` keyed on (gudang, bahan), `LayerPersediaan` carries `gudang_id`, so **FIFO costing is per-warehouse, not one global pool**); transfers are genuinely two-phase with in-transit custody carrying the source's `unit_cost`, so FIFO cost travels with the goods instead of being re-averaged; under-receipt is modeled as a `TRANSFER_SHORTAGE` layer rather than swallowed; reservations actually lock stock; and reconciliation carries real invariants.

**"Lacak Pembelian" was the oldest outstanding user request** (2026-09-06 → built 2026-09-16). The framing note from the original ask is still the right one: PPN paid on a purchase is **not** part of the goods' cost basis — it posts to `PPN_MASUKAN`/`PPN_IMPOR` as a recoverable asset, so it is never a fourth disposition bucket.
