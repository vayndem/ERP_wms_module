# CLAUDE.md

Instructions for coding agents working in this repository. Merged with the user's device-level `CLAUDE.md`; a project rule here wins for this project only where it says so.

Layer-specific rules (loaded with this file; the traps in section 6 apply to both):
- Backend / Laravel: @rules/backend-api.md
- Frontend / UI: @rules/frontend-ui.md

Human-facing documents live in `docs/` (index: `docs/README.md`) and are in Indonesian; how to work day to day is in `CONTRIBUTING.md`. `docs/` summarises and links this file, it does not replace it. When this file and the code disagree, **the code wins and this file must be corrected** (precedence: migration > policy > controller + Form Request > service > model > tests; `feed.MD` section 15).

## 1. Language

Two scopes, do not apply one where the other belongs.

**Product content (what the application renders to its users):**
- **IMPORTANT: the application is Indonesian.** UI text, flash messages, validation messages, notifications, emails, exported documents and reports are Bahasa Indonesia, in the presentation layer only. The Landing AI rule that product text must be English does **not** apply here.
- Domain status codes in the database stay uppercase English-looking machine codes (`POSTED`, `VOID`); Indonesian labels are presentation.
- Repository documents (`README.md`, `CONTRIBUTING.md`, `docs/`) are Bahasa Indonesia. `feed.MD` is bilingual. This file and `rules/` are English because they are written for agents; the long notes moved to `docs/architecture/catatan-fitur.md` and `utang-terukur-dan-riwayat.md` stay in their original English.
- Class, table, route and command names are written exactly as in the code, never translated in prose.

**Code:**
- Identifiers follow the repo convention: English class suffixes (`Controller`, `Service`, `Request`, `Policy`, `Factory`, `Seeder`), Indonesian domain nouns (`PenerimaanBarang`, `FakturPenjualan`, `BaganAkun`), tables `wms_` + Indonesian.
- **YOU MUST NOT write explanatory comments inside code** (the user's rule): code only, no noise. The reason for a decision goes in this file or in `docs/`, not next to the line. Example code in documents is also free of trivial comments.

**Your replies to the developer** follow the language of the prompt (Bahasa Indonesia or English).

## 2. Error Handling Contract (cross-layer)

**IMPORTANT: internal implementation details must never cross a trust boundary.**
- **YOU MUST NOT let raw exceptions, `SQLSTATE` text, stack traces or driver/ORM error strings reach an end user.**
- What the code actually does (verified 2026-11-20): services throw `RuntimeException` with a user-safe Indonesian message and the controller turns it into a validation error (`back()->withErrors`) or a 422 JSON `{success:false, message}`; plain guards use `abort(422, ...)`. Any other exception goes to Laravel's default handler (`withExceptions()` has no custom handler), so **`APP_DEBUG` must be `false` outside local development**; `.env.example` ships `true`. Mechanism and rules: `rules/backend-api.md` section 2.
- The frontend renders a safe fallback whatever the server returned: `rules/frontend-ui.md` section 2.

## 3. Secrets & Credentials

- **YOU MUST NOT hardcode passwords, tokens, API keys or connection strings in committed code.** Use `.env` (never committed; `.env.example` has no real values) and `config/`.
- Do not write secrets, `.env` contents or personal data into documents, notes or test fixtures. Demo identities (NPWP/NIK/NITKU) are fake but format-valid; company identity comes from `NPWP_PERUSAHAAN`/`NITKU_PERUSAHAAN` in `.env`, empty in `.env.example`.
- Attachments live on the private `lampiran` disk; production secrets are managed outside the repo.

## 4. Repo Etiquette

- **YOU MUST NOT run `git add`, `git commit` or `git push` unless the user asks for it in that turn.** The user handles git. Do not run `stash`, `checkout`, `reset` or any history-changing command on your own either. One approval covers that action only.
- Actual practice, read from `git log` and `git branch` on 2026-11-20: 53 commits by one author, linear history with no merge commits, all on `main` (tracking `origin/main`). Subjects are short English; older ones describe the change ("Add maker-checker approval gate for supplier invoices"), recent ones are terse ("Fix: All Again"). Local side branches exist: `refactor/rename-baku-indonesia` (the rename sweep, never merged or pushed by the user's choice), `simpan/coa-transit`, and `vayndem/*` working branches created by the Orca multi-agent IDE as worktrees. There is no pull-request flow, no `.github` folder and no CI.
- **Proposal, not yet practice:** branch per piece of work (`feature/…`, `fix/…`, `chore/…`) and a subject that says what changed and why. Adopt it only when the user says so; `CONTRIBUTING.md` labels it the same way.
- Never commit `.env`, `public/build`, `vendor` or `node_modules`; check `git status` for stray files before the user commits.
- Line endings are LF (`.gitattributes`); a file written by a worker may arrive CRLF, normalise it (PLT-011).

## 5. AI Coding Workflow

### Think Before Coding
Don't assume. Don't hide confusion. Surface tradeoffs.
- **Correct the user when an instruction, premise or plan is wrong or against best practice.** Say so plainly before acting and propose the better option; if they still want it their way, follow it unless it is unsafe or breaks a hard rule. Correct your own earlier recommendations as soon as you find them wrong.
- State assumptions. If uncertain, ask. If several interpretations exist, present them.
- **Product, accounting and tax questions are not engineering decisions.** Anything whose answer changes a journal, a tax rate or posting behaviour goes to `docs/backlog/keputusan-menunggu.md`; do not decide it yourself, and do not re-litigate a decision already in the table in section 6.
- Do not trust a note that names a file, function or number without checking it; notes age (this file was stale about CI and `RefreshDatabase` until 2026-11-20).

### Simplicity First
Minimum code that solves the problem. Nothing speculative.
- No features beyond what was asked; no abstraction for single-use code; no configurability nobody requested; no handling for impossible scenarios.
- Prefer the mechanism that already exists. A cross-dock reuses `ReservasiPersediaan`, a work order reuses `ambilLayer()`, a sales return reuses the layer mechanism: none of them added a parallel state or a second costing path. Check for an existing COA account before inventing one.

### Surgical Changes
Touch only what you must. Clean up only your own mess.
- Don't improve adjacent code or formatting. Mention unrelated dead code; don't delete it.
- Remove imports/variables your own change made unused.
- **A write path is not a read path.** A path that takes `lockForUpdate()` or calls a write helper (`StokGudangService::saldo()`) must not be "optimised" the way a read is, and a read path must never write. Several defects here came from treating them alike.
- **A bulk replace is not surgical.** A word-boundary replace of an entity name once corrupted COA labels and flash messages. Eyeball every hit.
- Every changed line traces to the request.

### Goal-Driven Execution
Define success criteria, loop until verified.
- "Fix the bug" → write a test that fails because of it, then make it pass. A test for a fix must fail when the fix is reverted.
- "Add a posting document" → a test through the route (HTTP → service → ledger) plus all reconciliation invariants still valid.
- For multi-step work state a short plan, each step with its check:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
```
- Do not run migrations, seeders or the full suite against a database whose content someone needs; see Commands.

## 6. Project: ERP - Modul WMS

### Overview

**ERP - Modul WMS** (`APP_NAME`; rebranded 2026-09-06, formerly "WMS FullSpec") is an ERP module, not a standalone WMS: a Laravel 12 / PHP 8.2+ / MySQL modular monolith connecting warehouse, procurement, sales, finance and accounting into one controlled flow.

- Buy side: request → PO → LPB (receipt) → multi-warehouse stock → NPK (usage) → supplier invoice → payment → GL journal.
- Sell side (added 2026-09-16; it was procure-to-pay only before that, correct any older note saying so): customer → sales order → delivery (surat jalan: FIFO + HPP) → sales invoice (receivable + output VAT) → payment receipt, plus sales returns.
- Stock movements and financial postings drive inventory value, FIFO layers, payables and the ledger together. Perpetual inventory: every movement posts a journal immediately.
- The rebrand is naming only. `wms_` table prefix, `Wms*` class names (`WmsAccountingService`), the `wmsDataTable()` JS helper and the "WMS Control Center" feature name stay as they are.

Business documentation (Indonesian/English): `README.md`, `feed.MD` (role matrix, flows, tables, WMS control framework, technical debt; kept in step with this file). Developer documents: `docs/README.md`.

| If you are about to… | Read |
|---|---|
| write any code | `rules/backend-api.md` or `rules/frontend-ui.md`, and **Behavioural rules** below: the traps already cost debugging time, several more than once |
| change something a user chose | **Decisions the user has already made**: changing one is a new conversation, not a refactor |
| pick up work | **Open-items index** and `docs/backlog/README.md` |
| touch a feature | its line in **Feature digest**, then `docs/architecture/catatan-fitur.md` for the full reasoning |
| add tests | `docs/architecture/jaring-pengaman-test.md` (which net catches which class of defect) |
| trace a historical name or a measurement | `docs/architecture/utang-terukur-dan-riwayat.md` |

**Detail is wanted, but only durable detail**: why a decision was made, what invariant protects it, what breaks if undone, and measurements with their date. Not what the next `grep` answers better, not build logs, not "N tests passing" as a standing claim (put the number in a dated measurement). Every figure carries the date it was taken; re-measure before trusting one and update it in place.

### Commands

PHP 8.2+ is required (Laravel 12). If `php` on PATH is older, call the newer binary explicitly for `artisan` and `composer`.

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
npm run dev
php artisan queue:work
php artisan schedule:work
```

`migrate --seed` is for a development database only (the seeder mixes master and demo data). `npm run build` is required before the tests (trap 10). `queue:work` and `schedule:work` are deployment requirements: notifications are queued, and reminders, replenishment, depreciation and the invariant watchdog are scheduled.

```bash
php artisan test
php artisan test --filter=TestClassName
php artisan test --filter=test_method_name
composer test:case-sensitive
```

`composer test:case-sensitive` runs `composer dump-autoload --optimize --strict-psr --strict-ambiguous`, then `php artisan test --filter=ArchitectureConventionTest`. Case-sensitivity bugs only show on Linux; run it before handing work over on Windows.

**Test database (verified 2026-11-20):** `phpunit.xml` sets `APP_ENV=testing`, array cache/mail/session and `QUEUE_CONNECTION=sync`, but **does not override `DB_DATABASE`** (the SQLite lines are commented out). The suite therefore runs against the database `.env` points at (`inventory` in `.env.example`) and needs it already seeded. Feature tests use `DatabaseTransactions`; **no test uses `RefreshDatabase`** (the only mention is a commented `use` line in `tests/Feature/ExampleTest.php`). An earlier version of this file said otherwise and was wrong. Do not add `RefreshDatabase` or `DatabaseMigrations`: they would wipe the working database. To isolate, override `DB_DATABASE` per command (see `docs/guides/testing.md`).

**There is no CI.** No `.github` folder and no `ci.yml` exist in the working tree or in `git ls-files` (checked 2026-11-20); an earlier version of this file described a workflow that was never in the repository. The local gate in `CONTRIBUTING.md` is the only gate. Whether to add a workflow is PLT-007.

### Invariants (enforced by tests)

Hard constraints. The build fails if they are violated. Details of the rules are in `rules/backend-api.md` section 4 and `docs/architecture/module-map.md`.

`tests/Unit/ArchitectureConventionTest.php` checks: class/file casing against reflection and `git ls-files`; no inline `->validate()` in controllers; route files split per domain and `route:cache`-able; uppercase status codes; no `double` in migrations; no legacy table names; no document number allocated on a display path; every Blade form points at an existing route and verb with `@csrf`; no dangling `route()`/`view()` reference; every Form Request authorized by itself or its controller; no destructive action reachable by GET; every `hasMany`/`hasOne` names its foreign key; every table name written as a string exists in the schema; every route resolves to an existing controller method.

Other nets (what each catches and cannot catch: `docs/architecture/jaring-pengaman-test.md`):
- `SmokeSemuaHalamanTest`: no 5xx on any named GET route as Super Admin; every mutating route refuses a guest.
- `KualitasTampilanTest`: every view compiles, no raw Blade reaches the browser, no silently empty list, tables have scroll wrappers, no hardcoded colour on a rendered page. `KonsistensiTemaTest`: no hardcoded colour in sources, charts read theme tokens, both themes' elevation and contrast.
- `HalamanTanpaEfekSampingTest`: no parameterless GET page issues `insert`/`update`/`delete`.
- `AksesPerRoleTest`: which pages each role must reach and be refused (Super Admin smoke is blind to this because `Gate::before` opens everything).
- `TanpaNPlusSatuTest`: no repeated query pattern above the threshold on any named GET page. `Model::preventLazyLoading` is turned on **only inside this test**, never in production code.
- `AccountingReconciliationService::checks()`: eight ledger invariants, watched by `php artisan wms:periksa-invarian` (see Feature digest).
- `KekayaanDataDemoTest`: the demo dataset keeps every table in its `tabelYangHarusTerisi()` list non-empty (30 entries on 2026-11-20) and keeps all invariants valid.

### Behavioural rules (traps this codebase has already sprung)

Every one cost real debugging time at least once. Read before writing code, not after.

#### 1. Mass assignment drops fields silently — it never errors

Hit **four times**: `users.is_active`/`deactivated_at`/`deactivated_by`, `wms_stock_opname.jenis`, `wms_pembayaran_faktur.supplier_id`, and `wms_jurnal_detail.gudang_id` (2026-09-16 — this one would have dropped an entire new reporting dimension while every test stayed green). Each time a field was written, accepted without complaint, and simply absent from the row — the DB default won instead.

Models here are inconsistent: some use `$guarded = []`, some declare a real `$fillable` list. **Check which one before adding a column**, and add the column to `$fillable` if that is what the model uses. Where a field must *never* come from request input (an account-state flag, a posting timestamp), deliberately keep it **out** of `$fillable` and write it with `forceFill()->save()` — that is the pattern on `UserAccountService`, and it is intentional.

Note the escape hatches: Eloquent **factories** bypass guarding (`Factory::make()` wraps in `Model::unguarded()`), and a **query-builder** `Model::where(...)->update([...])` bypasses it too. Only *model* `update()`/`fill()` is guarded — which is why a test can set a guarded field but production code cannot.

#### 2. Read a value, write it after a sibling already changed it

Hit **twice**, in the same shape both times. Bin capacity (2026-09-09) kept a running accumulator *and* re-read the bin's fill, double-counting two lines into one bin. Service capitalization (2026-09-14) gave each receipt line its own hydrated `Aset`, so two lines onto one asset both read the same stale `acquisition_cost` and the second write clobbered the first — the GL moved by A+B while the asset moved by only B.

**When several lines of one document touch the same row, re-read it under `lockForUpdate()` inside the transaction, or use `increment()`.** Never compute from an attribute loaded before the loop started.

#### 3. Renaming a model silently re-points its `hasMany`/`hasOne`

Eloquent derives the FK for these from **the declaring class name**, not from the related model and not from the column that exists. A relation that omitted its FK argument because the old class name happened to match the real column starts guessing a different, wrong column the moment the class is renamed — with no error until something queries through it. This bit four relations during the rename sweep, two of which only surfaced a cluster later, during a seeder run.

`belongsTo()` is immune (its FK comes from the *method* name). **Always pass the FK explicitly.**

#### 4. A COA code collision hijacks a live account instead of failing

`DatabaseSeeder` uses `updateOrCreate(['kode_akun' => …])`. Adding an account with a code already in use **renames the existing account** — no error, no duplicate. This silently renamed "Peralatan dan Inventaris" (carrying a 4,000,000 balance and referenced by `KategoriAset.akun_aset_id`) into a deferred-tax account. **Check the code is genuinely unused before adding any COA.**

Related: `AccountingSetting::accountId()` validates against an `$expected` whitelist keyed by constant. **A key missing from that array is rejected even when its mapping row exists and is perfectly valid** — a new setting must be added in both places.

#### 5. MySQL's 64-character identifier limit

`wms_`-prefixed table names plus auto-generated index names cross it easily (hit twice). If `migrate:fresh` reports "Identifier name … is too long", pass an explicit short name as the second argument to `unique()`/`index()`.

#### 6. Column names the JS layer reads are load-bearing

Yajra `datatables()->of(...)` endpoints hand column names straight to the `wmsDataTable()` Alpine layer. Renaming one breaks the UI **silently**, and `php artisan test` never catches it because PHPUnit does not touch the Blade `x-for` row templates. This is why `id_lpb`, `lpb_id` and `no_po` were deliberately left un-renamed through the whole rename sweep.

#### 7. A bulk word-boundary replace will hit display text

A `\bPembelian\b` replace over `.php` files corrupted three COA seed labels and four user-facing flash messages, because the entity name is also an ordinary Indonesian word. The test suite does **not** catch corrupted display strings. **Eyeball every hit after any bulk replace** — and note that raw-string table joins with aliases (`DB::table('x as d')`) escape a naive `['"]x['"]` grep.

#### 8. Tests that stop at the service boundary miss most defects

Of 11 defects a review found in the jasa build, **ten lived outside the service layer** — controller guards, Blade forms, the void/reversal path, cross-line arithmetic inside one document — and every one of them survived a green test suite because the tests called services directly. **A feature spanning HTTP → service → ledger needs at least one test that enters through the route.**

Two smaller test traps: a PDF endpoint streams binary, so `assertSee` against it is meaningless and a *failing* one dumps the whole binary and exhausts PHP's memory limit (assert on the Excel export's collection instead); and `actingAs()` leaves the session authenticated, so a later `post(route('login.attempt'))` hits the `guest` middleware and redirects to `/home` without ever exercising login.

#### 9. `@php(...)` with a long expression silently stops compiling the rest of the file

`@php($x = ...)` is matched by a balanced-parenthesis regex. Feed it a long enough expression and the match **fails silently**: Blade emits a bare `<?php` with no closing tag, so everything after it stays in PHP mode and the page dies with a parse error pointing at some unrelated `@endforeach` far below. The reconciliation page was broken this way from commit `a6f8694` and no test caught it, because no test opened the page.

**Use the block form `@php ... @endphp` for anything beyond a trivial assignment.** And note the failure mode: a Blade parse error naming a directive you did not touch usually means an *earlier* directive failed to compile. This bit twice in one session — the second time on a freshly written `@php($presisi = abs($value - round($value)) > 0.000001 ? 6 : 0)`, minutes after the trap was documented. `KualitasTampilanTest::test_every_blade_view_compiles_to_valid_php` now compiles every view and lints the output, so it cannot reach a browser again.

#### 10. A missing Vite manifest fails ~170 tests with no code defect in sight

Without `public/build/manifest.json`, every page that renders the layout throws `ViteManifestNotFoundException` and returns **500**, so the feature suite collapses into a wall of "Expected 200, got 500" plus a `SmokeSemuaHalamanTest` listing most of the application. Nothing is wrong with the code. **Run `npm install && npm run build` before `php artisan test`** — the assets are not committed, so a fresh clone or a cleaned `public/build` reproduces it every time. Seen on 2026-09-18: 174 failed, 53 passed before the build; 226 passed, 1 failed after, and that last one was a genuine half-finished edit.

#### 11. A renamed table leaves raw name strings behind, and nothing says so until that line runs

Found 2026-09-18, a year after the rename sweep: `PesananPembelianController::archiveHistoryIfNeeded()` still wrote to `pembelian_histories` and `pembelian_detail_histories`. Those tables became `wms_riwayat_pesanan_pembelian` and `wms_riwayat_pesanan_pembelian_detail` in 2026-09-04. **Every revision of an already-printed purchase order threw `SQLSTATE[42S02] Base table not found`** — for as long as the rename had existed.

Three things let it survive: the string sits inside `DB::table('…')`, so no class, model or IDE rename touches it; the code path only fires when `cetak >= 1`, which no seeder or test produced; and `pembelian.update` was one of the mutating routes no test named. Trap 7 already warned that raw-string table references escape a naive grep — this is that warning coming true.

`ArchitectureConventionTest::test_every_table_name_written_as_a_string_actually_exists` now resolves every table named in `DB::table()`, `join()`, `from()`, `$table`, and the `exists:`/`unique:` validation rules against the live schema, so a stale name fails the build instead of a user's save. `RevisiPesananPembelianTest` covers the revision path itself; reverting the fix makes it fail, which is how I checked the test is not vacuous.

#### 12. A foreign key dropped in a migration and never re-added lets bad ids accumulate for months

Found 2026-09-18. `2026_07_29_000001_harden_wms_accounting_schema` drops `wms_pembayaran_faktur.finance_user_id`'s foreign key in `up()` and re-adds it only in `down()` — so a rollback restored a constraint the upgrade had silently removed for good. With nothing enforcing it, **three seeders wrote `finance_user_id => 13` at four call sites, a user id that has never existed** (the role seeder creates seven users), and every demo payment's "diproses oleh" resolved to null. No test read that relation, so the suite stayed green.

The seeders now resolve the user by **role** (`User::where('type', User::ROLE_FINANCE)`), not by email — an email is an editable attribute and a seeder must not key on one. `2026_09_18_000006_restore_finance_user_foreign_key` repoints any surviving orphan and restores the constraint, and `PaymentAndInvoiceTest::test_every_payment_names_a_finance_user_that_actually_exists` fails if it is dropped again.

Two lessons worth generalising: **`down()` re-adding something `up()` removed is a smell, not symmetry** — read it as a question about whether the removal was intended; and a hardcoded id in a seeder is a dangling reference waiting for the constraint that was supposed to catch it. Note that the orphan sweep in the audit only inspects columns that *have* a constraint, so a dropped one hides from it too — the `*_id`-without-a-foreign-key listing is the check that finds these.

#### 13. A route can point at a controller method that does not exist, and only 404-adjacent luck hides it

`pembelian.update-so-term` and `pembelian.update-financials` named `PesananPembelianController::updateSoTerm()` and `::updateFinancials()`, neither of which has ever existed in this repo's history. Both were `PATCH`, so `SmokeSemuaHalamanTest` (GET only) could not reach them, and no Blade form, JS call or test referenced the names — they were routes to nowhere that would have thrown on resolution. They were deleted rather than implemented, on the same reasoning that retired the `debits`/`kredits` stubs.

`ArchitectureConventionTest::test_every_route_points_at_a_controller_method_that_exists` now walks the route table and resolves every `Controller@method` action. Re-adding one of the dead routes makes it fail, which is how the check was verified as non-vacuous.

#### 14. Thin demo data hides whole classes of defect, and every one of them is in a code path no seeder ever ran

Measured 2026-09-18: **47 domain tables were empty** after `migrate:fresh --seed`, including transfers, kitting, QC, picking waves, returns, period locks and approvals. Enriching the seeders to fill them surfaced **five defects in a single pass**, none of which any test had ever reached:

1. **A transfer shortage never left `bahans.stok_onhand`.** `keluar()`/`masuk()` pass `affectGlobal = false` for transfers, which is right for goods that arrive — but on an under-receipt the missing units are genuinely gone and nothing decremented the master. `terima()` now decrements by `jumlah_selisih`.
2. **The `transit` invariant contradicted its own feature note.** It compared `1304` strictly against `IN_TRANSIT` layers, while **Goods in transit** documents that a shortage deliberately leaves its value parked in 1304. Any under-receipt therefore turned the invariant permanently red. Expected is now in-transit layers **plus** the unresolved shortage value, derived from `alokasi_transfer_gudangs` because a `TRANSFER_SHORTAGE` layer is zeroed and no longer carries it.
3. **`RekonsiliasiGudangService::summary()` still counted in-transit layers in its value total** while the GL had moved that value to 1304 — a leftover from before the two-phase transfer. `perGudang()` already excluded them; `summary()` did not.
4. **Disassembling a kit with more than one component always threw.** `hasilkanKomponen()` wrote one layer per component, all keyed `('PERAKITAN_KIT', perakitan_id)`, against a unique index on that pair. Single-component kits worked, so nothing noticed. Component layers now key on the `wms_perakitan_kit_detail` row (`PERAKITAN_KIT_DETAIL`), following the `TRANSFER_ALLOCATION` precedent.
5. **A controlled reversal permanently skewed per-warehouse inventory value.** `RekonsiliasiGudangService` filtered `status = 'POSTED'`, so the original journal (now `REVERSED`) dropped out while the reversing entry stayed in — the report overstated by the reversed amount forever. `AccountingReconciliationService` had always used `whereIn(['POSTED','REVERSED'])`; both now do.

The lesson generalises past this list: **an empty table is a code path with no test, and the defects hide in exactly the branches a thin dataset never reaches** — the shortage branch, the multi-component branch, the reversed-journal branch. `KekayaanDataDemoTest` now asserts that its named tables are non-empty (24 when written, 30 entries on 2026-11-20) and that the enriched dataset keeps all eight invariants valid, so the dataset cannot quietly thin out again.

It also broke six tests whose premises were accidents of thin data, and each fix made the test stronger rather than looser: the FX tests assumed exactly one foreign-currency invoice and are now data-driven across every open one; the CALK tests assumed no narrative existed anywhere (the seeder now writes **last** year's, leaving the current period showing its template badge); the supplier scorecard asserted "reject ratio is null" when what it meant was "null only without inspection data"; and the traceability test asserted zero untraced value when the documented design says kit assembly records no link back. That last one is now an **if-and-only-if**: untraced value may exist exactly when kitting or a transfer shortage is present, which is strictly stronger than the zero it replaced.

#### 15. A report that reads only `POSTED` skews by every reversal (found 2026-11-20)

A controlled reversal flips the **original** journal to `REVERSED` and leaves the reversing entry `POSTED`. Every report that filtered `status = 'POSTED'` therefore dropped the original and kept the reversal, so a reversed LPB or manual journal pushed the ledger, the financial statements and the per-warehouse inventory value off by the reversed amount, permanently. (`RekonsiliasiGudangService` hit it first, see trap 14 item 5; the audit found it was everywhere.)

**Reports read journals through `Jurnal::scopeBerlaku` (`POSTED` + `REVERSED`).** `JurnalDibalikTest` checks that reversing returns every report to its starting value, and that drafts and pending-approval journals stay excluded. A new report that filters `POSTED` by hand is a defect.

#### 16. A running sequence with a ceiling is a production incident waiting for volume (found 2026-11-20)

`StokGudangService` numbered stock movements from a monthly sequence capped at 999; the 1,000th movement of a month would have failed the stock write (audit ALG-002). Movement numbers are now `MTS-YYYYMM-ULID`, unique with no ceiling (`NomorMutasiStokTest::test_mutasi_setelah_batas_seribu_tetap_berhasil_dengan_nomor_unik`). **`DocumentNumberService` still enforces 999 per period for the documents that use it**; that ceiling is real for them and was not touched.

#### 17. Rounding a per-unit cost leaves a permanent residue (found 2026-11-20)

WIP released to the customer as `quantity × biaya_per_unit` (rounded to 4 places) left a permanent residue in 1303 after the last shipment (audit ALG-004). `DataPesanan::saldoWip()` is now total cost minus the cost already released (`SuratJalanDetail.hpp` of draft and posted deliveries), and the **last release takes whatever remains**. Whenever a total is split into rounded parts, the final part must be the remainder, not another rounded product.

#### 18. A service that reads `Auth::id()` is null outside a request (found 2026-11-20)

Services read `Auth::id()` for the actor; in a seeder, a queue worker or a service-level call it is null, and `wms_reservasi_persediaan.created_by` is NOT NULL. The services covered by `AtribusiAktorTest` (reversal, customer advance, returns, trade-in, credit-limit check) now take an explicit actor and fall back to the authenticated user only when none is given. The credit-limit override uses the gate `tembusPlafonKredit`, not a raw role check. Sixteen services still read `Auth` directly (counted 2026-11-20): pass the actor explicitly in new code.

### Decisions the user has already made — do not re-litigate these

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
| Production costing | Material enters WIP at NPK issue (not backflush); WIP absorbs material + subcontract services only (labour and overhead were added later, 2026-10-01, see Feature digest) | 2026-09-16 |
| Production output | Goes **straight to the customer** — never becomes finished-goods stock | 2026-09-16 |
| Unshipped output | Stays in WIP; released proportionally per shipment; order closes when fully shipped | 2026-09-16 |
| Production scope | **Always for a customer order**, so a work order always has a shipment path | 2026-09-16 |
| API/EDI integration | **Dropped from the plan** — see Dropped from the plan | 2026-09-16 |

### Domain concepts to know before editing warehouse/inventory code

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

### Conventions

- **Language, naming, status codes, decimals, routes, tests:** `rules/backend-api.md` and `rules/frontend-ui.md`. The accounting vocabulary is fixed: LPB (receipt), BAP (service acceptance), NPK (usage), PO, GRNI, HPP, PPN Masukan/Keluaran.
- **Document lifecycles:** Material Request `PENDING → APPROVED/REJECTED`; Purchase Order `OPEN → CLOSED`; LPB/BAP and NPK `DRAFT → POSTED → REVERSED` (or `CANCELLED`); supplier invoice `UNPAID → PARTIALLY_PAID → PAID` (or `VOID`); payment `POSTED → VOID`; transfer `DRAFT → DIAJUKAN → DIKIRIM → DITERIMA` (or `DIBATALKAN`); stock opname `DRAFT → SUBMITTED → APPROVED → POSTED` (or `REJECTED`); manual journal `DRAFT → PENDING_APPROVAL → POSTED`. **Posted documents are immutable.**
- **Unknown or externally specified rules are not guessed.** Tax layouts (Coretax XML, BPPU, SPT) are built only from facts labelled verified in `docs/architecture/pajak-indonesia.md`; the rest is written as "belum diverifikasi".
- Never invent a retained-earnings COA (the balance sheet derives it and would double-count), never add a rate column to routing rows, never add `diposting_oleh` to LPB (`terima()` creates and posts in one transaction for one user, so `id_user` + `created_at` is the trace).
- Three controllers (`Kit`, `GelombangPengambilan`, `PengeluaranBarang`) have no Policy and are not unguarded: they use `Gate::allows('operateWarehouse')` plus `canAccessGudang()`. A different idiom, not a hole.
- Contra accounts with a normal balance opposite to their category are correct (`1591`, `4102`, `5201`); `4102 Retur Penjualan` is `PENDAPATAN/DEBIT` on purpose.

### Workflow

- **Before handing work over:** `composer test:case-sensitive`, `npm run build`, `php artisan test` against a database you can afford to touch, as listed in `CONTRIBUTING.md`. Run `TanpaNPlusSatuTest`, `KualitasTampilanTest` and `KonsistensiTemaTest` whenever a view or a read path changed.
- **A feature spanning HTTP → service → ledger gets a test through the route**, a test that fails when the fix is reverted, and all eight invariants still valid.
- **A migration is tried with `migrate:fresh --seed`** on a development database, because the seeders are the only thing that exercises half the schema (trap 14).
- **Documentation changes in the same change** as the code; the table of what changes with what is in `docs/architecture/development-governance.md`. Status of work lives in `docs/backlog/` (counts recomputed with `grep`, never by hand).
- **Changing an architecture rule** means explaining it here, changing the test so it fails on a violation, updating `docs/architecture/module-map.md`, and running `composer test:case-sensitive`. Weakening a rule so violating code passes needs the user's decision.
- Reconciliation, period locks and maker-checker are controls, not obstacles: do not bypass them in a seeder or a test helper except through the documented service path.

### Feature digest: the decisions that look wrong if you only read the code

Full reasoning, per area, is in `docs/architecture/catatan-fitur.md`; this is the part that must not be undone. Items marked *(updated 2026-11-20)* replace what the old notes said.

- **Document numbering (since 2026-09-18).** Allocate at save, never at render. Thirteen display sites used to burn a number on merely opening a form, and the number was client-supplied, so the audit backbone was editable. Both halves are fixed (`rules/backend-api.md` section 7).
- **FX revaluation (PSAK 10).** Monetary items only; inventory is non-monetary and stays at the historical rate. The closing rate is typed per currency per period (`wms_kurs_penutup`), a period posts once, revaluation never touches `sisa_tagihan` (the `ap` invariant adds cumulative revaluation instead). The FX journal's `reff_id` is a period key `YYYYMM`; the unique `(sumber_transaksi, reff_id)` is the database guard, so do not "fix" it into a row id. **Open:** realising the difference on settlement (ALG-024).
- **Routing is descriptive.** Rates live on the work centre (`wms_pusat_kerja`), never on routing rows; a work centre claims at most 1,440 minutes a day; saving a routing replaces the whole list, so an empty submission is refused in `RoutingProduksiService`.
- **Goods in transit.** `1304 Persediaan Dalam Perjalanan` makes the transfer journal two-phase; both 1304 legs are tagged with the destination warehouse; an under-receipt leaves the difference parked in 1304 (a `TRANSFER_SHORTAGE` layer marks it); `RekonsiliasiGudangService` must not add in-transit value back to the source. Invariant `transit` = in-transit layers + unresolved shortage value derived from `alokasi_transfer_gudangs`. The receipt journal is dated at receipt.
- **Cancelled work orders.** `5308 Kerugian Pembatalan Produksi` writes the WIP off and a negative `wms_data_pesanan_biaya` row so the subledger reaches zero; a cancelled order leaves `DataPesanan::berjalan()`.
- **Receivables ageing.** Reconstructs a past date (a sales return mutates `grand_total` in place); VOID invoices excluded; the GL tie-out is suppressed for a single customer (no customer dimension on `wms_jurnal_detail`). Buckets identical to `apAgingBuckets()`.
- **Sales.** Delivery is separate from invoice; the delivery is what moves stock and the ledger (through `ambilLayer()`, with the warehouse dimension); output VAT reuses `2109` / `PPN_KELUARAN`; a PPN invoice cannot post without an NSFP (KEP-001); the salesperson is snapshotted on the invoice (`sales_user_id`, defaults to the creator, no eighth role); a sales return creates a **new** layer at the delivery's cost; `4102` is contra-revenue. *(updated 2026-11-20)* Over-payment is no longer refused: it is parked as a customer advance (`2112 Uang Muka Pelanggan`, `UangMukaPelanggan`), and the credit limit **is enforced** by `PlafonKreditService` (exposure = receivables + undelivered orders + delivered-unbilled; only Super Admin may break it, through gate `tembusPlafonKredit`, with a written reason). The sales lists are plain paginated Blade tables, a deliberate deviation from Yajra.
- **Cross-dock** is a promise, not a movement: it reuses `ReservasiPersediaan`; `postingSuratJalan()` releases the marks of its order lines before consuming stock and splits a partial mark; it is warehouse-scoped on both create and cancel. `WarehouseExecutionService::reserve()` takes an explicit `$userId`.
- **BOM variance is reporting only** (no journal: production costs on actual consumption and material already hit WIP at NPK issue). One active BOM per `bahan_hasil`; unplanned usage is shown as `DI LUAR BOM`; variance is `null`, never zero, when no cost exists.
- **Production work orders and WIP.** Job costing on actual consumption; WIP balance is derived from `wms_data_pesanan_biaya`, never a stored running field; a completed order accepts no further cost (unit cost frozen, later cost goes on a new order); the cost row is written inside `postNpk()`; an NPK without a work order is completely unchanged; `1303` and not `1302` (1302 already carried a balance from a different journal and would have made the `wip` invariant unverifiable). *(updated 2026-11-20)* Labour and overhead are absorbed into WIP from recorded hours × work-centre rates (`SerapanProduksiService`, accounts `5203`/`5204`); the last release takes the remainder (trap 17).
- **Analytics.** Lacak Pembelian walks a layer graph and anchors on the LPB detail, not `initial_quantity` (QC inspection mutates it); PPN is never a cost bucket. *(updated 2026-11-20)* A `hilang_transit` bucket exists; kit assembly remains the one consumption path with no link back, so `tidak_terlacak` can be non-zero exactly then. The supplier scorecard has no promised-date (the schema has none), and the QC reject ratio is `null`, not `0`, without inspection data.
- **CALK** is hybrid: figures from the GL, four narrative sections per period (`kebijakan_akuntansi` falls back to a badged template).
- **Maker-checker.** Manual journals use the status column; reversals and COA changes use `wms_permintaan_persetujuan` + `PersetujuanOperasiService`. The payload is data, never code (a `match` over five constants). **Self-approval is refused in the service.** COA edits are gated only when the account is mapped or carries journal lines. Period unlock is deliberately not gated.
- **Warehouse dimension** (`wms_jurnal_detail.gudang_id`): all lines of a document are tagged; migration `2026_09_16_000004` backfilled history.
- **Services (jasa).** Capitalization hooks `postInvoice()` per line, double capitalization is impossible by a unique on `(sumber_type, sumber_id)`; a gate pass and a vendor loaner post no journal; trade-in is an asset disposal measured against DPP only (nilai wajar); `postInvoice()` partitions service lines; a gate pass is cancellable only while `DRAFT`. Sending inventory out for subcontract goes through a transfer to the subcontract warehouse (`SubkontrakService`, built since the old notes said it was excluded), never through a custody document.
- **Warehouse execution.** ABC classifies on the cumulative share *before* adding the item; cycle counting reuses the opname lifecycle; slotting is read-only advice; a newly created Warehouse user has zero warehouses; QC re-inspection checks both `LPB_DETAIL` and `QC_REJECT_*` layers (`putaway()`'s own guard still checks only the first, STK-004); kitting is a mini-BOM, not WIP; Material Request approval auto-reserves partially and never blocks.
- **Lot control.** `wajib_expiry` without `wajib_lot` is rejected; putaway is per line and bin capacity is enforced (null = unlimited, either capacity check skipped without data); a completed pick becomes a `DRAFT` NPK so the posting path is not duplicated; serials are capped at what the lot received.
- **Platform hardening.** Account-state flags are not fillable; deactivated accounts are checked before `Auth::attempt` (one audit row per attempt); auth auditing hangs off Laravel events; `throttle:login` has two tiers (5/min per `email|ip`, 20/min per IP); attachment delete is restricted to the uploader, evidence is not document content; `LampiranService::INDUK` is the whitelist; `PenerimaanBarangService` throws `RuntimeException` for the over-receive race so it is a 422, not a 500; the dashboard reconciliation count is cached five minutes and the reconciliation page itself is deliberately live.
- **Accounting and tax.** Declining balance is 2× straight-line on running book value; Arus Kas classifies by the dominant non-cash counterpart (borrowings land in Operasi, a known limitation); equity change uses gross credits and debits and treats retained earnings as a pseudo-component; PSAK 1 statements and CALK carry a self tie-out row; `BEDA_WAKTU` corrections are always the difference, never the book amount; fiscal depreciation ignores residual and is not journalled; deferred tax (PSAK 46) reads the GL, never a stored field; `posting()` of PPh Badan does not refuse on a loss year; loss carry-forward is oldest-first. Service tax: repair 2% PPh 23 (capitalise if it extends life), rent splits PPh 23 2% vs PPh 4(2) final, trade-in is not a service.
- **Reminders and watchdog.** `PengingatService` serves both dashboard panels and the daily digest (a new reminder must go in both); it reuses `PengeluaranBarangService::terlambat()`; cross-dock stale after 7 days, work order stalled after 14 days measured from the last cost row; `wms:periksa-invarian` runs 06:30, before the 07:00 digest, and exits non-zero on a red invariant; `InvarianMenyimpang` is its own notification class.
- **Demo dataset.** Ten demo seeders run in a fixed order after the role, user and tax-code seeders, each leaning on the previous (`DatabaseSeeder`); every scenario warns and continues; QC needs an LPB whose layers are whole; the reversal scenario picks an NPK with no work order; CALK is written for last year so the template badge stays testable; the data is small on purpose, add scenarios last with low quantities. *(updated 2026-11-20)* Landed cost is now seeded (`2111` exists).
- **Eight reconciliation invariants** in `AccountingReconciliationService::checks()`: `stock`, `journal`, `invoice`, `grni`, `ap`, `ar`, `transit`, `wip`. A new balance-sheet subledger gets its invariant when it is built, not after (the sell side went without one until 2026-09-18).

### Algorithm audit fixes (2026-11-20)

A backend algorithm review fixed five defects the same day; the open findings are ALG-006 to ALG-024 in `docs/backlog/alg-audit-algoritma.md`.

- Reports read `Jurnal::scopeBerlaku` (trap 15). Stock-movement numbers are `MTS-YYYYMM-ULID` (trap 16).
- The transfer-receipt journal is dated at receipt (`WmsAccountingService::postPenerimaanTransfer`); a locked period used to reject a receipt because the journal took the shipping date. `PenerimaanTransferTanggalTest`.
- WIP release: the last shipment releases the remainder (trap 17). `PelepasanWipSisaTest`.
- Depreciation cannot post twice for the same asset and period: unique `(aset_id, period_label)` (`penyusutan_aset_periode_unique`, migration `2026_11_20_000001`). `PenyusutanGandaTest`. A double click on "Susutkan" now fails on the unique and shows a 500 until AKT-003 catches it.
- Services take an explicit actor (trap 18). `AtribusiAktorTest`.
- The `PastikanAkunAktif` middleware (appended to the `web` group in `bootstrap/app.php`) logs out an account deactivated mid-session; Sanctum tokens are revoked on deactivation (`AuthKontrakTest`, `AuthCelahTest`).
- `TanpaNPlusSatuTest` guards the read paths against N+1 using `preventLazyLoading` inside the test only; write paths are deliberately left alone.
- `SubkontrakPersediaanTest` now asserts a delta against the subcontract warehouse balance, because the seeder leaves one unit there (`PLT-002`).

### Tax identity and metadata foundation (2026-11-20)

Groundwork so that a Coretax export can later be built on unambiguous data. Only facts labelled verified in the research reports were used. **No XML generator was written and no XSD layout was guessed** — the official `TaxInvoice.xsd` was not obtainable, and the prototype XML on the existing `laporan-pajak-djp` page is untouched.

- **One shared validator** (`App\Support\IdentitasPajak`, wrapped by `App\Rules\NpwpValid/NikValid/NitkuValid/NsfpValid`, used through the `MengaturIdentitasPajak` Form Request trait): NPWP 16 digits (an old 15-digit one is stored with a leading `0`, PMK 112/2022), NIK 16, NITKU 22 — **length and digits only**; the internal structure of NITKU is unverified and no checksum exists, so none is invented. Separators (`.`, `-`, space) are stripped before validation. NSFP accepts the old `000.000-00.00000000` form and 17 plain digits, and is never interpreted.
- **Master data**: `suppliers` and `pelanggans` gained `nik`, `nitku`, `tipe_identitas` (`NPWP`|`NIK`, default `NPWP`) and `status_pkp` (nullable: null means unknown). The migration normalises existing NPWP values **only when the result is valid**; invalid ones are left in place and surface in the preflight rather than being deleted.
- **Invoices**: both invoice tables gained `tanggal_faktur_pajak` and `ppnbm` (a column and a field only — **PPnBM is not in any calculation or journal**). `wms_faktur_penjualan` has a unique index on `no_faktur_pajak` (the migration aborts, listing them, if duplicates exist) and a buyer snapshot (`pembeli_*`) written **at posting** from the customer master, so later master edits cannot rewrite an issued invoice; existing non-draft invoices were backfilled. `wms_faktur_pembelian` deliberately has **no** unique NSFP, because each issuer numbers independently — duplicates are only detected, per supplier, in the preflight.
- **`wms_kode_objek_pajak`** holds only the codes the research verified: `24-104-29` (maintenance of machines/electrical/AC, PPh 23), `24-100-02` (rent of non-land/building assets, PPh 23), `28-403-02` (land/building rent, PPh 4(2) final). **No rate is stored** — rates stay in `tax_rates`. Royalty (`24-103-01`, 15%) was left out on purpose: the system has a single PPh 23 rate of 2%, so selecting it would withhold the wrong amount. `wms_faktur_pembelian.kode_objek_pajak` is a nullable foreign key onto `kode`; the Form Request only accepts a code whose `jenis_pph` matches the invoice's. Production databases are not seeded by `migrate`, so run `php artisan db:seed --class=KodeObjekPajakSeeder` (idempotent) once.
- **`KepatuhanPajakService`** is a read-only preflight returning categorised findings, not a boolean: `IDENTITAS_KOSONG`, `IDENTITAS_FORMAT_SALAH`, `NSFP_FORMAT_SALAH`, `NSFP_DUPLIKAT`, `NPWP_PERUSAHAAN_TIDAK_VALID`, `FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK`, `PPH_TANPA_KODE_OBJEK`. It is shown as a section of the existing Laporan Pajak DJP page — **there is deliberately no second tax page**. PPh 22 is never flagged for a missing object code because no verified code exists for it.
- **Demo data**: identities are fake but format-valid, and `MetadataPajakDemoSeeder` (runs last) fills NSFP/date/object code so every demo period passes the preflight (asserted by `KepatuhanPajakTest`).

**Product decisions still open (KEP-001 to KEP-003 in `docs/backlog/keputusan-menunggu.md`) — nothing here changes behaviour until the user decides:**

- **(a) NSFP before posting.** A sales invoice with PPN still cannot be posted without an NSFP. The research says Coretax assigns the NSFP itself after the XML is uploaded and approved, so the official flow may be *issue → upload → fill the NSFP back*. The old rule was kept as-is.
- **(b) PPN rate representation.** The system still computes 11% via `tarif_ppn`. The official scheme is 12% on a "nilai lain" DPP of 11/12 (effective 11%, PMK 131/2024); whether to represent it that way, and which transaction code applies (`01` vs `04`), is unresolved. Calculation and journals are untouched.
- **(c) Input-VAT correction on purchase returns.** `ReturPembelian` stores no DPP/PPN and its journal does not reduce PPN Masukan. Fixing it changes journals, so it was not done.

### Withholding slips — PPh 23 and PPh 4(2) (2026-11-20, tahap B2)

- **The fact is the payment, not the invoice.** `wms_pembayaran_faktur.potongan_pph` + `tanggal_pembayaran` (status POSTED) is when and how much was withheld. `wms_faktur_pembelian.pph` is an aggregate cache that `PembayaranFakturController` rewrites on every payment/void, so one invoice paid across two months is two withholdings in two periods, and a voided payment silently changes the aggregate. `LaporanPajakDjpService::buktiPotong()` used to select by invoice date and print one row per invoice from that cache; it now reads POSTED payments by payment date (joined to the slip row when one exists). **Never read `faktur.pph` as an event.**
- **`wms_bukti_potong` / `BuktiPotong`** — one slip per payment, unique on `(pembayaran_id, urutan, versi)`: `urutan` leaves room for a payment that later carries several tax objects (today one invoice has one `kode_objek_pajak`, so always 1), `versi` is how a correction coexists with the issued slip it replaces. Statuses `DRAFT → DITERBITKAN → DIBATALKAN | DIGANTI`. Supported types are `PPH23` and `PPH4A2` (`BuktiPotong::JENIS_DIDUKUNG`); the schema is generic enough for `PPH22`. The base is derived as `potongan ÷ (tarif/100)` so it is the base *of that payment*, not the invoice's whole base.
- **`BuktiPotongService`**: `sinkron($masa)` is idempotent and is the only thing that creates slips (a page render must not write — `HalamanTanpaEfekSampingTest`). A **DRAFT** follows the supplier master (identity snapshot is refreshed on sync); once **DITERBITKAN** the recipient snapshot is frozen. A voided payment cancels its DRAFT; an issued slip is **never changed silently** — it is flagged `perlu_pembatalan` and surfaced in the preflight until a person cancels it (`batalkan`) or replaces it (`buatPembetulan`: old → DIGANTI, new DRAFT `versi+1`). Issuing refuses an incomplete recipient identity (`IdentitasPajak`), a missing/inactive/mismatched object code, a voided payment and a reused number. No period-lock check on purpose: a slip posts nothing to the ledger.
- **Numbering is not ours.** The official number comes from DJP/Coretax; `nomor_bukti_djp` is a nullable unique column typed by the user at issuance (required then, free-form, never generated, never from `DocumentNumberService`). The internal reference is the row id.
- **Ledger tie-out** (`tieOut()`): slips (DRAFT+DITERBITKAN) per type per period vs the net credit on the Hutang PPh account from `PELUNASAN_HUTANG` journals dated in the period **plus the reversing entries of those same journals** (the reversal is dated when it was made, so it must be attributed to the original's period, otherwise a void in a later month looks like a difference). Rendered as a red row when it differs; reports "tidak dapat dihitung" if the account mapping is missing.
- **Preflight** adds `PEMBAYARAN_PPH_TANPA_BUKTI_POTONG`, `BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP`, `BUKTI_POTONG_TANPA_KODE_OBJEK`, `PENERIMA_TANPA_NPWP_VALID` (warning only) and `BUKTI_POTONG_PEMBAYARAN_VOID`; since 2026-11-23 also `BUKTI_POTONG_TANPA_NITKU` (recipient without NPWP and without a 22-digit NITKU) and `BUKTI_POTONG_KODE_OBJEK_TIDAK_VALID` (object code inactive or of another PPh type), so the XML button no longer lights up and then errors. All except the warning block the XML export (`KepatuhanPajakService::PENGHALANG_EKSPOR_BUPOT`, together with a bad company NPWP). The preflight runs once per request: pages call `periksa($masa, $pembayaranBerpph)` and derive the blockers and withholding findings from that one result (`penghalang($masa, $hasil)`, `temuanBuktiPotongDari($hasil)`), instead of recomputing it. A purchase invoice with `is_ppn = false` stores no `no_faktur_pajak` and no `tanggal_faktur_pajak` (the Form Requests null them), whatever the client sent. Read-path indexes for the tax pages are in migration `2026_11_23_000001_add_indeks_baca_pajak` (explicit short names; the unused `bp_masa_jenis_index` was left in place), and the date filters on those paths are sargable (`where`/`whereBetween` on the `date` column, not `whereDate`/`whereYear`/`whereMonth`), proven identical by `QueryTanggalSargableTest`. Carbon 3 `diffInDays` is signed: `now()->diffInDays($past)` is negative, write `$past->diffInDays(now())` (fixed in `CycleCountService` and `AbcAnalysisService`).
- **XML export is built, from the official DJP example only.** `BuktiPotongExportService` emits `BpuBulk/TIN/ListOfBpu/Bpu/{TaxPeriodMonth, TaxPeriodYear, CounterpartTin, IDPlaceOfBusinessActivityOfIncomeRecipient, TaxCertificate, TaxObjectCode, TaxBase, Rate, Document, DocumentNumber, DocumentDate, IDPlaceOfBusinessActivity, GovTreasurerOpt, SP2DNumber(xsi:nil), WithholdingDate}` — every name quoted verbatim from the official `bppu.xml` and the converter v.3 dictionary (research `hasil-B.md` §1.2–1.3, labelled verified). **The XSD was never found**, so the UI and the file say it must be tried in Coretax before use. Assumptions that are *ours*, not DJP's: `Document` = `CommercialInvoice` (it appears in the official example) with the supplier invoice number/date, `TaxCertificate` = `N/A`, `TaxBase` is not rounded to whole rupiah (rounding per PER-11/PJ/2025 is only a secondary-source claim), and `Rate` is the invoice's snapshot rate. A slip whose recipient has no 22-digit NITKU blocks the export. Excel/CSV (`GenericTableExport`) is the internal alternative.
- **Attachments**: `bukti-potong` is in `LampiranService::INDUK`, category `BUKTI_POTONG`, so the Coretax PDF is stored beside the slip; delete stays uploader-only.
- **Demo data**: `BuktiPotongDemoSeeder` (runs after `MetadataPajakDemoSeeder`) adds one PPh 23 invoice paid in two different months, one PPh 4(2) rent, one withholding payment that is then voided, and one issued slip numbered `DEMO-BP-0001`. It also corrected `AssetAndServiceDemoSeeder`, whose partial payment withheld 200,000 on an 8,000,000 base at 2% (cap 160,000) — a demo row the controller would have refused.

**Product decisions still open — nothing here changes behaviour until the user decides:**

- **(d) Recipient without NPWP (KEP-005).** Pasal 23 ayat (1a) UU PPh (verified in the research) sets a rate 100% higher for a recipient with no NPWP. `PembayaranFakturController` still withholds at the invoice's snapshot rate; the preflight only warns (`PENERIMA_TANPA_NPWP_VALID`). How Coretax treats a non-NPWP/invalid NIK on import was not found. A NIK-only recipient is accepted for issuance.
- **(e) Whether issuing here should wait for Coretax (part of KEP-006).** Today a slip is DITERBITKAN only once the Coretax number is typed in (export → import in Coretax → record the number). If the user wants to mark "issued" before the number exists, `nomor_bukti_djp` stops being required at that step.

### Dropped from the plan

Things that were once on the backlog and are **no longer wanted**. They are recorded here rather than deleted, because a gap that is simply removed gets rediscovered by the next read-through of the code and quietly re-added. **Do not put these back without the user asking.**

- **The `debits` and `kredits` master tables and their CRUD API.** Removed on 2026-09-18 after an audit found them unreachable: two `(id, kode, nama)` lookup tables scaffolded on 2026-07-21, never linked from any menu, empty in every environment, referenced by no domain code, and — being domain tables without the `wms_` prefix — in breach of the naming rule as well. They nevertheless exposed ten routed CRUD endpoints that accepted writes. Deleted with their models, policies, form requests, controllers, routes and migration. **If debit/credit notes are wanted later, build them as a real document type with a journal path, not by restoring these stubs.**

- **External API/EDI integration** (to suppliers, marketplaces, or other ERPs), beyond the bare Sanctum auth base that already exists. **Dropped by the user on 2026-09-16.** The absence of an integration layer is therefore a deliberate product decision, not an unfinished item — if a future survey of the code notices there is no public API, that is the expected state. Sanctum stays where it is; nothing needs removing.

### Open-items index (verified against the code 2026-11-20)

The authoritative list of **tasks** is `docs/backlog/README.md` (IDs, statuses, evidence, counts computed with `grep`); the authoritative list of **product decisions waiting for the user** is `docs/backlog/keputusan-menunggu.md`. This index is the short version for an agent that must not build something that is deliberately absent. Things that look missing are often deliberate: check **Dropped from the plan** and the Feature digest first.

**Built, removed from this index on 2026-11-20 because the old list was stale** (each checked in code and tests; reasoning moved into the Feature digest and `docs/architecture/catatan-fitur.md`):

| Old open item | Now | Evidence |
|---|---|---|
| Customer credit limit not enforced | enforced | `PlafonKreditService`, `PlafonKreditTest` |
| Customer advances | built (`2112`) | `UangMukaPelanggan`, `UangMukaPelangganTest` |
| Counter-account for landed cost | `2111` + `BIAYA_MASIH_HARUS_DIBAYAR` | migration `2026_10_01_000001`, seeded by `OperasiGudangDemoSeeder` |
| Service categories vs mapping rules | service categories judged separately | `UpdateAccountingMappingRequest` |
| Labour and overhead absorption into WIP | built (`5203`/`5204`) | `SerapanProduksiService`, `SerapanTenagaKerjaTest` |
| Lacak Pembelian bucket for goods lost in transit | `hilang_transit` | `LacakPembelianService` |
| Sending inventory out for subcontract | via the subcontract warehouse | `SubkontrakService`, `SubkontrakPersediaanTest` |

**Waiting for a decision from the user** (details: `keputusan-menunggu.md`): (a) NSFP before posting vs after the Coretax upload (KEP-001); (b) PPN as 11% effective vs 12% on a DPP of 11/12, and transaction code `01` vs `04` (KEP-002); (c) input-VAT correction on purchase returns (KEP-003); tax rate per object code, royalty is 15% (KEP-004); (d) a recipient without NPWP, rate 100% higher (KEP-005); (e) withholding-slip numbering and whether issuing waits for the Coretax number (KEP-006); company identity in config (KEP-007); barcode/QR, per kit not per item (KEP-008, deferred 2026-09-13); renaming 21 tables without `wms_` (KEP-009); NSFP validator format 17 vs 13 digits, raw-string uniqueness, NIK/NITKU hard rejection vs warning (KEP-010).

**Externally specified, verify the spec first:** the Coretax XML for output tax invoices and the SPT Masa PPN (`PJK-008`, `PJK-013`) need the official XSD or a readable specification, which was never obtained; the existing `LaporanPajakDjpService` XML is a prototype and **must not be reported with**. The BPPU XML for withholding slips is built from the official DJP example only and has never been tried in Coretax. Never infer a layout.

**Ordinary unbuilt work:** the open audit findings ALG-006 to ALG-024 (lock order, FX realisation on settlement, strict idempotent journals, rounding drift, journal index, loops), PLT-005 to PLT-011 (route coverage re-measure, test database, CI, controllers writing across models, status constants, `Auth::id()`), AUT-009/010, AKT-003/004, STK-004, PJK-009, PJK-011 to PJK-013.

**Nothing open:** platform production-readiness (built 2026-09-12/13), the five PSAK 1 statements, maker-checker in the three chosen areas, per-warehouse inventory valuation, the sales, production and services domains as scoped, and the receivables/salesperson/cross-dock/BOM cluster.

### Measured debt and coverage (digest; full text in `docs/architecture/utang-terukur-dan-riwayat.md`)

Figures are snapshots and age; the dated source and re-measure commands are in that document.

- **Systemic, not an outlier; do not fix by touching one file.** 18 controllers contain `DB::transaction` and seven of them write more than one model inside it (`JurnalController`, `MaterialRequestController`, `PemeriksaanConsiderController`, `PesananPembelianController`, `ReturPembelianController`, `StockOpnameController`, `TransferGudangController`): that is the older half of the codebase (PLT-008). Models are split almost evenly between `$guarded` and `$fillable` (51 vs 50 of 104 files under `app/Models`, `grep -rl` and `find`, re-measured 2026-11-23; the old 103 predates a new file), which is the root of trap 1.
- **Route coverage (2026-09-18, not re-measured):** 148 parameterless named GET routes, 52 with parameters, 175 mutating routes; every mutating route is exercised as a guest and proven to resolve, but only 59 are named by a test as an authorised user. That is why trap 8 matters (PLT-005 re-measures).
- Tests that exist and what they guard: `docs/architecture/jaring-pengaman-test.md`. Do not state a test count as a standing claim; on 2026-11-20 `grep` found 52 feature and 12 unit test classes.
- 21 domain tables carry no `wms_` prefix (counted 2026-09-18). Renaming crosses trap 6 for cosmetic gain; it is a decision (KEP-009).
- A–Z audit, what is already answered (do not re-ask): 784 `$fillable`/`$casts` entries resolved to real columns; no `create($request->all())`; zero orphan rows across ten relationships; 48-account chart of accounts consistent; the ledger balances; tax rates match the regulations; FIFO is honoured per material and warehouse; Gudang Rusak is terminal in both directions and stock arriving in Consider is `QC_HOLD`. `$guarded = []` was **not** tightened to `['id']` (tried and reverted).

### Known repo quirks

- The old `accouting` misspelling was fixed 2026-09-01 (`resources/views/accounting`); fix the same way if it reappears.
- `.env.example` exists; production secrets are managed outside the repo.
- `DatabaseSeeder` mixes master and demo data: never run it against production.
- `Auditable` writes the raw class name into `wms_log_audit.auditable_type`, so rows written before a class rename keep the old string; nothing queries it. It sits with the other deliberate historical drift (`LPB-` journal prefixes, stored `NPK`/`LPB` `sumber_transaksi` values).

### History (digest)

The 4-phase modernisation plan (approved 2026-09-01) is done: Tailwind/daisyUI/Alpine (phase 1), Indonesian `snake_case` naming (2, 3), and thin controllers with validation in Form Requests (4, the standing convention). The rename ran in 11 clusters on the local branch `refactor/rename-baku-indonesia` (finished 2026-09-04, **never merged or pushed by the user's choice**); "continue the rename" needs confirming what is meant, because the sweep as scoped is finished and "every table got the prefix" was an overstatement (21 did not). Laravel was upgraded from 10 to 12.69 on 2026-09-18. Historical names, the four surveys that drove the roadmap and why the multi-warehouse core is trusted: `docs/architecture/utang-terukur-dan-riwayat.md`.
