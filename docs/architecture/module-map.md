# Peta lapisan dan domain

Daftar lapisan, file route per domain, dan siapa boleh memanggil siapa. Aturan di halaman ini ditegakkan oleh `tests/Unit/ArchitectureConventionTest.php` ([jaring pengaman](jaring-pengaman-test.md)). Kalau aturannya berubah, tes dan halaman ini berubah di commit yang sama.

## Lapisan

| Lapisan | Letak | Boleh memanggil | Tidak boleh |
|---|---|---|---|
| Route | `routes/*.php` | controller, middleware | berisi logika |
| Controller | `app/Http/Controllers` | Form Request, Policy/Gate, Service, Model (baca) | `->validate()` langsung; proses lintas model di dalam controller (utang lama, lihat [roadmap](roadmap.md)) |
| Form Request | `app/Http/Requests` | model untuk aturan `exists`/`unique` | menulis data |
| Policy | `app/Policies` | model, helper `User` | cek Super Admin redundan (`Gate::before` sudah melolosinya) |
| Service | `app/Services` | model, service lain, `DB::transaction` | membaca `Auth::id()` bila ada pemanggil yang bisa memberi pengguna (null di seeder dan queue) |
| Model | `app/Models` | relasi dan helper domain | proses lintas model |
| View | `resources/views` | gate lewat `@can` | cek role mentah yang menduplikasi gate |

Proses lintas model (posting dokumen, pembalikan, hitung FIFO) hidup di `app/Services`. Gate hanya untuk kemampuan lintas model (`viewWmsControl`, `operateWarehouse`, …); selebihnya policy per model.

## File route per domain

`routes/web.php` hanya me-`require` file domain. Daftar yang wajib ada dicek `test_routes_are_split_by_domain_and_cacheable`; file route baru harus ditambahkan ke daftar itu juga.

| File | Domain | Controller utama |
|---|---|---|
| `auth.php` | login, reset password, dashboard, logout | `AuthController`, `ResetPasswordController` |
| `administration.php` | pengguna, profil, notifikasi, lampiran | `UserController`, `ProfilController`, `NotifikasiController`, `LampiranDokumenController` |
| `warehouse.php` | gudang, stok, mutasi, transfer, NPK, LPB, opname, QC, kitting, cross-dock, subkontrak, kontrol WMS | `GudangController`, `StokGudangController`, `TransferGudangController`, `PemakaianBarangController`, `PenerimaanBarangController`, `StockOpnameController`, `WmsControlController`, `CrossDockController`, `SubkontrakController` |
| `procurement.php` | bahan, kategori, supplier, permintaan, PO, analitik pembelian | `BahanController`, `SupplierController`, `MaterialRequestController`, `PesananPembelianController`, `LacakPembelianController`, `SupplierScorecardController` |
| `finance.php` | faktur pembelian dan pembayaran | `FakturPembelianController`, `PembayaranFakturController` |
| `accounting.php` | COA, jurnal, laporan keuangan, rekonsiliasi, period lock, persetujuan, pajak, revaluasi kurs | `BaganAkunController`, `JurnalController`, `FinancialStatementController`, `AccountingReconciliationController`, `LaporanPajakDjpController`, `RevaluasiKursController` |
| `assets-and-services.php` | aset, jasa, pengeluaran barang (gate pass) | `AsetController`, `PesananJasaController`, `PenerimaanJasaController`, `PengeluaranBarangController` |
| `sales.php` | pelanggan, penjualan, piutang, produksi | `PelangganController`, `PesananPenjualanController`, `SuratJalanController`, `FakturPenjualanController`, `DataPesananController`, `BomController`, `RoutingProduksiController`, `PiutangAgingController`, `KinerjaSalesController` |
| `api.php` | satu endpoint Sanctum | closure `GET /api/user` |

`channels.php` sengaja tidak dipasang di `withRouting()`: broadcasting dorman. Mengaktifkannya adalah keputusan, bukan pembersihan.

## Service per domain

Seluruhnya di `app/Services`. Dikelompokkan menurut tanggung jawab; daftar lengkap lewat `ls app/Services`.

| Domain | Service |
|---|---|
| Stok dan FIFO | `StokGudangService`, `InventoryCostCalculator`, `TransferGudangService`, `KittingService`, `WarehouseExecutionService`, `PemeriksaanConsiderService`, `StockOpnameService`, `CycleCountService`, `AbcAnalysisService`, `SlottingService`, `ReplenishmentService`, `GelombangPengambilanService`, `CrossDockService`, `SubkontrakService` |
| Akuntansi | `WmsAccountingService`, `AsetAccountingService`, `AccountingPeriodService`, `AccountingReconciliationService`, `RekonsiliasiGudangService`, `InventoryReversalService`, `PersetujuanOperasiService`, `RevaluasiKursService`, `LandedCostService` |
| Laporan | `FinancialStatementService`, `CalkService`, `ExecutiveDashboardService`, `DashboardService`, `PiutangAgingService`, `KinerjaSalesService`, `LacakPembelianService`, `SupplierScorecardService` |
| Pajak | `PajakPenghasilanService`, `RekonsiliasiFiskalService`, `LaporanPajakDjpService`, `KepatuhanPajakService` |
| Pembelian | `PenerimaanBarangService`, `ThreeWayMatchService`, `PaymentAllocationService`, `MaterialRequestFulfillmentService`, `KapitalisasiAsetService` |
| Penjualan dan produksi | `PenjualanService`, `FakturPenjualanService`, `ReturPenjualanService`, `PlafonKreditService`, `DataPesananService`, `BomService`, `RoutingProduksiService`, `SerapanProduksiService` |
| Platform | `DocumentNumberService`, `DocumentOperationGuard`, `UserAccountService`, `LampiranService`, `NotifikasiService`, `PengingatService`, `AntreanKerjaService`, `PengeluaranBarangService` |

Pasangan yang sering tertukar: `PenerimaanBarangService::terima()` membuat dan memposting LPB dalam satu transaksi (jadi `id_user` + `created_at` adalah jejaknya, tidak perlu kolom "diposting oleh"); `StokGudangService::saldo()` adalah helper **tulis** (insertOrIgnore + `lockForUpdate`), jalur baca harus meng-query `StokGudang` langsung ([persediaan dan FIFO](persediaan-dan-fifo.md#jalur-baca-tidak-boleh-menyentuh-saldo)).

## Aturan yang dijaga `ArchitectureConventionTest`

| Aturan | Tes |
|---|---|
| Nama kelas sama persis dengan nama file, juga menurut path yang dilacak Git (kasus huruf) | `test_php_class_names_match_their_file_names`, `test_git_paths_and_app_references_are_case_exact` |
| Validasi hanya di Form Request | `test_controllers_delegate_validation_to_form_requests` |
| Route dipecah per domain dan bisa di-cache | `test_routes_are_split_by_domain_and_cacheable` |
| Status uppercase; tanpa `double` di migrasi; tanpa nama tabel lama | `test_document_status_values_use_uppercase_machine_codes`, `test_schema_uses_fixed_point_numbers_and_no_legacy_intermediate_tables` |
| Jalur tampilan tidak mengalokasikan nomor dokumen | `test_display_paths_never_allocate_a_document_number` |
| Form Blade menuju route yang ada, verb cocok, ada `@csrf` | `test_every_blade_form_targets_an_existing_route_with_a_matching_verb` |
| Tidak ada rujukan `route()`/`view()` menggantung | `test_no_dangling_route_or_view_reference_in_app_code` |
| Setiap Form Request punya otorisasi di dirinya atau controller-nya | `test_every_form_request_is_authorized_somewhere` |
| Aksi destruktif tidak bisa lewat GET | `test_destructive_actions_are_never_reachable_by_get` |
| `hasMany`/`hasOne` menyebut foreign key | `test_every_has_many_relation_names_its_foreign_key` |
| Nama tabel dalam string benar-benar ada | `test_every_table_name_written_as_a_string_actually_exists` |
| Route menunjuk method controller yang ada | `test_every_route_points_at_a_controller_method_that_exists` |

Aturan penamaan yang tidak terjaga tes tetap berlaku: tabel domain berawalan `wms_`, sufiks kelas tetap Inggris (`Controller`, `Service`, …), nama kolom umumnya tidak diterjemahkan, dan kolom yang dikirim endpoint Yajra tidak boleh di-rename karena dibaca JS ([database](../guides/database.md)).

## Apa yang harus diperbarui bila…

| Perubahan | Perbarui |
|---|---|
| file route baru | `routes/web.php`, daftar wajib di `ArchitectureConventionTest`, tabel di atas |
| service baru | tabel service di atas dan `docs/` domainnya (salin [template modul](../templates/module-readme.md)) |
| command terjadwal baru | `bootstrap/app.php` → `withSchedule()`, [development lokal](../guides/local-development.md) |
| provider baru | `bootstrap/providers.php` |
| rate limiter baru | `AppServiceProvider::boot()` |
