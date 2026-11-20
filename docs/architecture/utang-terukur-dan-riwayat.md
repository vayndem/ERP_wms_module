# Utang terukur, audit, dan riwayat

Dipindahkan apa adanya dari `CLAUDE.md` pada 2026-11-20: pengukuran utang dan cakupan test, audit A–Z 2026-09-18, invarian sisi jual, kekhasan repo, dan riwayat bagaimana kode ini sampai ke bentuknya. Ringkasan yang dibaca agen ada di [`CLAUDE.md`](../../CLAUDE.md) bagian 6.

Semua angka di bawah **bertanggal dan bisa usang** (kebanyakan 2026-09-18, Laravel 12.69 / PHP 8.3). Ukur ulang sebelum mengandalkan satu pun; pengukuran ulang 2026-11-20 dicatat di blok **Koreksi 2026-11-20**. Teks tetap berbahasa Inggris, bahasa aslinya (lihat [catatan fitur](catatan-fitur.md)).

## Measured debt and coverage

Numbers here are **snapshots taken 2026-09-18** on Laravel 12.69 / PHP 8.3. Re-measure before relying on one; the commands that produced them are given so you can.

> **Koreksi 2026-11-20 (diukur ulang dengan `grep`, bukan dengan menjalankan suite).**
> - Model: 103 file di `app/Models`; 51 memuat `protected $guarded`, 50 memuat `protected $fillable` (`grep -l`). Angka 53/40 dari 94 di bawah usang.
> - Controller yang memuat `DB::transaction`: 18 file (`grep -l`). Angka lama menyebut 18 controller juga; tujuh yang menulis lintas model di bawah belum diperiksa ulang satu per satu.
> - Service yang membaca `Auth::id()`/`Auth::user()`: 16 file, tetapi sejak `AtribusiAktorTest` (2026-11-20) bagian yang disebut di sana menerima aktor eksplisit dan `Auth` hanya fallback.
> - Test: 52 kelas di `tests/Feature` dan 12 di `tests/Unit`, 441 method `test_*` (`grep`, bukan hasil lari). Jumlah per kelas dijaga di [jaring pengaman test](jaring-pengaman-test.md).
> - **Tidak diukur ulang, tandai usang:** 148/52/175/59 route (celah cakupan), 21 tabel tanpa `wms_`, 47 tabel kosong, 245 test hijau, 174 gagal / 53 lulus tanpa manifest. Semuanya tertanggal 2026-09-18.
> - Pernyataan "CI di `.github/workflows/ci.yml`" di versi lama `CLAUDE.md` salah: folder `.github` tidak ada. Pernyataan bahwa `ExampleTest` memakai `RefreshDatabase` juga salah: barisnya dikomentari dan tidak ada test yang memakainya.

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
