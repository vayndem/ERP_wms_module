# Backend / Laravel Rules

Loaded when working on backend code (routes, controllers, Form Requests, policies, services, models, migrations, seeders). Inherits language, error-handling, secrets and workflow rules from the root `CLAUDE.md`. The traps this codebase has already sprung are in `CLAUDE.md` section 6; read them before writing code, they are not repeated here.

Where a note here conflicts with the code, the code wins and the note must be corrected. The precedence order is migration > policy > controller + Form Request > service > model > tests (`feed.MD` section 15, [governance](../docs/architecture/development-governance.md#sumber-kebenaran)).

## 1. Layers

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

- **YOU MUST keep cross-model processes (posting a document, reversing it, computing FIFO cost) in `app/Services`, not controllers.** The newer domains obey this (sales, production, cross-dock, BOM, kitting, landed cost, withholding slips); the older half does not, and that is systemic debt, not a licence to copy it. Do not extract an old controller as a drive-by.
- Stock and accounting mutations are wrapped in `DB::transaction()`; operations prone to races use `lockForUpdate()`.
- **Posted documents are immutable.** Corrections always go through a controlled reversal/void with audit metadata and a reversing journal entry. Never edit or delete a posted document.
- A write path is not a read path. A write path that locks (`lockForUpdate()`, `saldo()`) must not be "optimised" like a read; a read path must never call a write helper (see trap about `StokGudangService::saldo()`).
- Services must not depend on `Auth::id()`/`Auth::user()`; it is null in a seeder, a queue worker or any service-level call. Accept the acting user (or its id) as an argument and fall back to the authenticated user only when none is given. Sixteen services still read `Auth` as a fallback (counted 2026-11-20); that is debt, do not add to it.
- Database queries: no query inside a loop on a read path, eager-load relations, and prefer sargable predicates. `TanpaNPlusSatuTest` fails the build on repeated query patterns for every named GET page.

## 2. Error handling at the source

**IMPORTANT: this is the mechanism behind the ban stated in `CLAUDE.md` section 2.**

- A service signals a business rule violation by throwing `RuntimeException` with a message written for the end user (Indonesian, no SQL, no class names). The controller catches it and returns it: `back()->withErrors([...])` for page forms, `response()->json(['success' => false, 'message' => ...], 422)` for AJAX. A client-data problem is a client error (422), never a 500.
- Guards that need no service use `abort(422, '...')` / `abort_if` / `abort_unless` with the same kind of message.
- **There is no custom exception handler.** `bootstrap/app.php` `withExceptions()` only sets `dontFlash`. Every exception that is not a `RuntimeException` reaches Laravel's default handler, so the only thing keeping a stack trace or `SQLSTATE` text away from users is **`APP_DEBUG=false`** outside local development. `.env.example` ships `APP_DEBUG=true`; production must override it.
- **Never put `$e->getMessage()` of a non-`RuntimeException` (a `QueryException`, a `PDOException`) into a response.** Catch it, log it, and answer with a message of your own.

## 3. Validation and authorization

- **No inline validation in controllers**: controllers must not call `->validate(...)`; all validation lives in `app/Http/Requests` Form Request classes. Every Form Request must authorize, or its controller must (`ArchitectureConventionTest`).
- Never write `create($request->all())`; write the validated array.
- Authorization uses per-model Policies. Gates (`AuthServiceProvider`) are only for capabilities that span several models (`viewWmsControl`, `operateWarehouse`, `tembusPlafonKredit`, `viewPiutangAging`). Policies follow the private "who is allowed" / "warehouse access" helper pattern, with public `view/create/update/...` below (`PemakaianBarangPolicy`, `TransferGudangPolicy`, `StockOpnamePolicy` are the model to copy).
- `Gate::before` gives Super Admin every policy. **Do not add redundant Super Admin checks inside policies.** An all-false policy is precisely how "Super Admin only" is expressed (`UserPolicy`).
- In Blade use the gate (`@can('operateWarehouse')`), not a raw role check such as `isWarehouseOperator() || isSuperAdmin()`.
- A self-approval rule (maker-checker) is enforced in the service, not only the policy, because `Gate::before` defeats a policy-only check.

### Roles

`users.type` is a foreign key into `user_roles`. Seven roles: `super_admin`(0), `purchasing`(1), `finance`(2), `warehouse`(3), `accounting`(4), `production`(5), `accounting_manager`(6, approves supplier invoices only). `User` exposes `isSuperAdmin()`, `isPurchasing()`, `isFinance()`, `isWarehouse()`, `isAccounting()`, `isProduction()`, `isAccountingManager()`, `isWarehouseOperator()`, plus `accessibleGudangIds($ability)` and `canAccessGudang($gudangId, $ability)`.

The Production role is scoped to specific warehouses through `pembagian_gudangs` (`boleh_menerima`, `boleh_npk`, `boleh_transfer`, `boleh_opname`); Warehouse has every active warehouse. Controllers filter warehouse-scoped lists through `accessibleGudangIds()`. A newly created Warehouse user starts with zero warehouses.

Deactivated accounts: `is_active`, `deactivated_at`, `deactivated_by` are deliberately not in `$fillable` and are written with `forceFill()->save()` by `UserAccountService`. The `PastikanAkunAktif` middleware (appended to the `web` group) logs a deactivated user out mid-session.

## 4. Naming and structure (enforced by `tests/Unit/ArchitectureConventionTest.php`)

- **Class/file casing**: the class name must exactly match its filename, checked against PHP reflection and the Git-tracked paths. Windows is case-insensitive, so a mismatch only shows on Linux; run `composer test:case-sensitive` before handing work over.
- **Routes are split by domain** and required from `routes/web.php`: `auth.php`, `administration.php`, `warehouse.php`, `procurement.php`, `finance.php`, `accounting.php`, `assets-and-services.php`, `sales.php`. They must stay `route:cache`-able. A new route file is created, required from `routes/web.php`, **and** added to the required-file list in `ArchitectureConventionTest`. Every route must point at a controller method that exists.
- **Status columns are uppercase machine codes** (`PENDING`, `APPROVED`, `POSTED`, `VOID`); Indonesian labels belong in the presentation layer only. Declare statuses as model constants (`Aset::ACTIVE`), not scattered literals.
- **No floating point for business data**: `$table->double(...)` is banned. Quantities `DECIMAL(18,6)`, unit cost `DECIMAL(18,4)`, money `DECIMAL(18,2)`, rates `DECIMAL(8,4)`.
- **No legacy table names** (`admin_namagudang`, `invoicelpbs`, `invoicelpbdetails`, `lpbdetails`, `kategoribahan`) may reappear.
- **Domain tables carry the `wms_` prefix**; Laravel framework tables (`users`, `jobs`, `notifications`, `sessions`, `password_reset_tokens`, `personal_access_tokens`, `failed_jobs`, `migrations`) and `user_roles` do not. 21 older domain tables never got the prefix (counted 2026-09-18); renaming them is an open product decision (KEP-009), not a cleanup.
- Tables are plural `snake_case`; new foreign keys are `<model>_id`.
- **Class suffixes stay English** (`Controller`, `Policy`, `Request`, `Service`, `Factory`, `Seeder`); only the domain noun is Indonesian (`AsetController`, not `AsetPengendali`).
- **Column names are generally not translated** (generic English columns such as `name`, `status`, `amount`, `book_value` stay; translating them costs every Eloquent access, Blade echo, Yajra key, seeder key and JS `row.xxx`); rename only a column that embeds a renamed entity's old abbreviation. **Never rename a column a Yajra endpoint exposes to JS** (`id_lpb`, `lpb_id`, `no_po` stay).
- When a resource's URL segment changes, keep the internal binding name with `->parameters(['new-segment' => 'oldName'])`.
- Every `hasMany`/`hasOne` names its foreign key explicitly. Renaming a model otherwise re-points it silently.
- No `Controller@method` route to a method that does not exist; no table name written as a string (`DB::table`, `join`, `exists:`, `unique:`) that is not in the schema.
- A destructive action is never reachable by GET; every Blade form carries `@csrf` and points at an existing route and verb.

## 5. Models and mass assignment

- Models are split between `$guarded = []` and a real `$fillable`. **Check which one a model uses before adding a column**, and add the column to `$fillable` when that is what it uses. A field that is not fillable is dropped silently, never an error.
- A field that must never come from request input (account state, posting timestamp) stays out of `$fillable` and is written with `forceFill()->save()`.
- Factories and query-builder `->update()` bypass guarding; only model `update()`/`fill()` is guarded, so a test can set a field production code cannot.
- Do not tighten `$guarded = []` to `['id']` as a cleanup: it was tried and reverted (extra schema round-trip on every `fill()`, breaks pure unit tests).
- When several lines of one document touch the same row, re-read the row under `lockForUpdate()` inside the transaction, or use `increment()`. Never compute from an attribute loaded before the loop.

## 6. Migrations and seeders

- MySQL identifiers are limited to 64 characters. `wms_`-prefixed tables plus generated index names cross it; pass an explicit short name as the second argument of `unique()`/`index()`.
- A foreign key dropped in `up()` and re-added only in `down()` is a smell, not symmetry. Check whether the removal was intended. A column named `*_id` with no foreign key is the thing to list when auditing for this.
- A seeder resolves users by **role**, never by email or a hardcoded id.
- `DatabaseSeeder` uses `updateOrCreate(['kode_akun' => …])`: adding an account with a code already in use renames the existing account. **Check the code is unused first.** A new `AccountingSetting` key must be added both as a constant and to the `$expected` whitelist in `accountId()`.
- Check for an existing account before inventing one (`4204`, `5303` already existed, unmapped).
- Every demo scenario is wrapped so it warns and continues; a scenario that cannot run must never break `migrate:fresh --seed`. Add new scenarios at the end of the seeder order and keep quantities low: the demo data is small on purpose.
- Never run the full `DatabaseSeeder` against production (it mixes master and demo data). Production does not get `KodeObjekPajakSeeder` from `migrate`; run it once by hand (idempotent).

## 7. Document numbering: allocate at save, never at render

`DocumentNumberService` has two modes and they are not interchangeable.

- `external()` / `financial()` / `internal()` **allocate**: they increment `document_sequences.last_number` and persist it. Call them only at the moment a document is written, in the same transaction.
- `preview(DocumentNumberService::EXTERNAL|FINANCIAL|INTERNAL, …)` **reads** and writes nothing. A create form or a "next number" JSON field uses this.
- Every `store()` allocates its own number server-side and ignores anything the client sent. Number inputs on forms are `disabled` display fields, not form data, and the `Store*Request` classes carry no number rules.
- **A GET page never writes.** `HalamanTanpaEfekSampingTest` fails on any `insert`/`update`/`delete` from a parameterless GET page. A read path queries `StokGudang` directly; `StokGudangService::saldo()` is a write helper (`insertOrIgnore` + `lockForUpdate()`).
- Stock-movement numbers are `MTS-YYYYMM-ULID` (`StokGudangService`), so the old 999-per-period ceiling does not apply to them.
- A test that posts to a store route reads the number back from the response (`->json('data.no_po')`, `->json('data.id_lpb')`).

## 8. Reports and ledger

- Reports read journals through `Jurnal::scopeBerlaku` (`POSTED` and `REVERSED`), not `status = 'POSTED'`. A controlled reversal turns the original into `REVERSED` while the reversing entry stays `POSTED`; filtering `POSTED` alone drops the original and keeps the reversal, skewing every report by the reversed amount, permanently.
- A statement that can tie out to the GL says so on screen, and renders a red "Selisih" row when it diverges.
- New balance-sheet subledger means a new reconciliation invariant in `AccountingReconciliationService::checks()`, built together with the feature, not after.
- Dates posted on a journal are the date the event happened (a transfer receipt is dated at receipt, not at shipment); a locked period refuses the posting.
- Warehouse dimension: every posting site that knows a warehouse tags **all** lines of that document with `gudang_id`, not only the inventory line.

## 9. Application skeleton (Laravel 12, PHP 8.2+)

The app runs on Laravel 12 with the 11/12 skeleton. There is no `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php` or `RouteServiceProvider`. `app/Http/Middleware/` holds only app middleware written since (`PastikanAkunAktif`).

| To do this | Edit |
|---|---|
| add or alias a middleware | `bootstrap/app.php` → `withMiddleware()` (`$middleware->alias([...])`, `->web(append: …)`, `->api(prepend: …)`) |
| register a service provider | `bootstrap/providers.php` |
| add a scheduled command | `bootstrap/app.php` → `withSchedule()` |
| change exception handling | `bootstrap/app.php` → `withExceptions()` |
| add a rate limiter | `AppServiceProvider::boot()` |
| add an artisan command | drop it in `app/Console/Commands` (auto-discovered) |

- **`->withEvents(discover: false)` is load-bearing.** With discovery on, every listener in `App\Listeners\CatatAktivitasAutentikasi` is bound twice: each failed login writes two audit rows and the throttle trips at half the attempts. Listeners are in the explicit `$listen` map of `App\Providers\EventServiceProvider`.
- Middleware groups/aliases, guest/auth redirects, trusted proxies, string trimming, `dontFlash`, the schedule and route loading all live in `bootstrap/app.php`; providers in `bootstrap/providers.php` (`config/app.php` `providers` is just `ServiceProvider::defaultProviders()`; Yajra registers through package discovery). The `login`/`api` rate limiters live in `AppServiceProvider::boot()`. `public/index.php` uses the slim `$app->handleRequest(...)` form. A command dropped in `app/Console/Commands` needs no `commands()` list.
- Broadcasting stays dormant (the old `BroadcastServiceProvider` and `RouteServiceProvider` are deleted): `routes/channels.php` is deliberately not passed to `withRouting()`.
- `config/` was deliberately left in its fuller Laravel 10 shape; do not trim it.
- `php artisan` does not boot on PHP below 8.2 (`vendor/composer/platform_check.php`), and `composer` run with an older PHP rebuilds the autoloader against the wrong platform. Call the 8.2+ binary explicitly if `php` on PATH is older.
- Notifications are queued and reminders/replenishment/depreciation/watchdog are scheduled: a working system needs `php artisan queue:work` and `php artisan schedule:work` running. Without a worker, notifications sit in `jobs` (that is correct behaviour).

## 10. Tests

- Feature tests extend `Tests\TestCase`, use `DatabaseTransactions` (49 test classes at 2026-11-20) and read seeder data, so the database named by `.env` must already be `migrate --seed`ed. **`phpunit.xml` does not override `DB_DATABASE`**, so the suite runs against that database. No test uses `RefreshDatabase` (the only mention is a commented `use` line in `tests/Feature/ExampleTest.php`); do not add one, it would wipe the working database.
- A feature spanning HTTP → service → ledger needs at least one test that enters through the route. Service-only tests miss controller guards, Blade forms, reversal paths and cross-line arithmetic.
- A test that proves a fix must fail when the fix is reverted.
- A PDF endpoint streams binary: do not `assertSee` against it (a failure dumps the binary and exhausts PHP memory); assert on the Excel export's collection.
- `actingAs()` leaves the session authenticated, so a later `post(route('login.attempt'))` hits the `guest` middleware.
- `public/build/manifest.json` must exist (`npm run build`) or about 170 tests fail with 500 and no code defect.
- `preventLazyLoading` is switched on only inside `TanpaNPlusSatuTest`, never in production code.
- Thin demo data hides defects in the branches it never reaches; `KekayaanDataDemoTest` guards that the dataset keeps exercising each document type and keeps the invariants valid.
