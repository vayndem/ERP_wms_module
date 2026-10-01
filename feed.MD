# ERP - Modul WMS Technical Reference

Dokumen ini adalah referensi teknis untuk developer, QA, DBA, dan maintainer. **Refresh terakhir: 1 Oktober 2026** — mencakup rename sweep baku Indonesia (tabel `wms_*`, class model/controller Indonesia), migrasi Tailwind CSS + daisyUI + Alpine.js, role `accounting_manager` (maker-checker approval invoice), Material Request yang dibuka untuk semua role, dashboard "all tasks" + chart per role, Dashboard Eksekutif, export Excel di seluruh laporan, depresiasi aset otomatis, PPh 22/4(2) Final, dan field multi-currency ringan pada faktur pembelian. Pembaruan 1 Oktober 2026: akun lawan landed cost, penegakan plafon kredit dengan penembusan Super Admin, uang muka pelanggan, pengecualian mapping kategori jasa, serapan tenaga kerja dan overhead ke WIP berbasis jam, ember “hilang di jalan” pada Lacak Pembelian, pengiriman persediaan untuk subkontrak, serta data pelaporan DJP (Coretax hanya menerima XML sejak 1 Januari 2025). Jika ada detail yang meleset, `CLAUDE.md` di root repo adalah sumber kebenaran paling mutakhir.

Untuk gambaran produk dan alur bisnis yang lebih singkat, lihat [README.md](README.md).

## 1. Technical baseline

| Komponen | Implementasi |
|---|---|
| Backend | PHP 8.2+ (dikembangkan di 8.3.26), Laravel 12.69 |
| Database | MySQL |
| Web auth | Laravel session auth |
| API auth | Laravel Sanctum |
| UI | Blade, Tailwind CSS, daisyUI, Alpine.js |
| Build | Vite |
| Chart | Chart.js (via `taskChart` Alpine component) |
| Report | DomPDF, TCPDF, Laravel Excel (Maatwebsite) |
| Test | PHPUnit 11 |

Arsitektur aplikasi berbentuk modular monolith Laravel.

### 1.1 Skeleton Laravel 12 (sejak 2026-09-18)

Framework benar-benar dinaikkan dari Laravel 10 ke Laravel 12 pada 2026-09-18, sekaligus memakai skeleton Laravel 11/12. Berkas berikut **sudah tidak ada lagi** dan itu disengaja: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`, `app/Providers/RouteServiceProvider.php`, serta seluruh stub di `app/Http/Middleware/`.

- Middleware group dan alias, redirect tamu/pengguna, trusted proxy, trim string, `dontFlash`, penjadwalan, dan pemuatan route semuanya dikonfigurasi di **`bootstrap/app.php`**.
- Daftar provider aplikasi pindah ke **`bootstrap/providers.php`**; `config/app.php` hanya menyisakan `ServiceProvider::defaultProviders()`.
- Rate limiter `login` dan `api` pindah ke `AppServiceProvider::boot()`; perilakunya tidak berubah.
- **`->withEvents(discover: false)` wajib dipertahankan.** Laravel 12 mendaftarkan `EventServiceProvider` bawaan yang melakukan penemuan listener otomatis di `app/Listeners`. Bila dibiarkan menyala, listener `CatatAktivitasAutentikasi` terpasang **dua kali**, sehingga satu percobaan login gagal menulis dua baris audit dan throttle tercapai di separuh jumlah percobaan.
- Broadcasting tetap nonaktif: `routes/channels.php` sengaja tidak dioper ke `withRouting()`.
- `config/` sengaja dibiarkan dalam bentuk Laravel 10 yang lebih lengkap, karena berisi setelan nyata termasuk `konstanta_ppn`.

## 2. Lapisan aplikasi

| Lapisan | Lokasi | Tanggung jawab |
|---|---|---|
| Routes | `routes/*.php` (dipisah per domain: `auth.php`, `warehouse.php`, `procurement.php`, `finance.php`, `accounting.php`, `assets-and-services.php`, di-require dari `routes/web.php`) | endpoint dan middleware |
| Controllers | `app/Http/Controllers` | orkestrasi use case, HTTP-only |
| Form Requests | `app/Http/Requests` | validasi dan sebagian authorization |
| Policies | `app/Policies` | role dan state guard, auto-discovered |
| Services | `app/Services` | FIFO, accounting, numbering, opname, asset, reconciliation, transfer, three-way match, landed cost, reversal |
| Models | `app/Models` | relasi, cast, helper domain |
| Migrations | `database/migrations` | schema dan constraint |
| Seeders | `database/seeders` | role, user, master data, demo data |
| Views | `resources/views` | Blade + Tailwind CSS + daisyUI + Alpine.js |

Perubahan stok dan akuntansi penting dibungkus `DB::transaction()`. Operasi yang rawan race condition memakai `lockForUpdate()`. Controller tidak boleh memanggil `->validate()` langsung — semua validasi wajib lewat Form Request (diperiksa `tests/Unit/ArchitectureConventionTest.php`).

## 3. Authentication

### 3.1 Web login

Flow login:

1. `GET /` menampilkan form login
2. `POST /login` memvalidasi email dan password
3. `Auth::attempt()` memeriksa credential ke tabel `users`
4. session diregenerasi setelah login sukses
5. user diarahkan ke `dashboard`
6. logout menjalankan invalidate session dan regenerate CSRF token

### 3.2 User dan role

Role tidak lagi berupa angka liar langsung tanpa referensi. Struktur sekarang:

- `users.type` menyimpan foreign key ke `user_roles.id`
- `user_roles` menjadi kamus role

Role aktif:

| ID | Name | Keterangan |
|---:|---|---|
| 0 | `super_admin` | bypass semua policy |
| 1 | `purchasing` | operasional procurement |
| 2 | `finance` | pembayaran supplier |
| 3 | `warehouse` | operasional gudang umum |
| 4 | `accounting` | akuntansi dan kontrol |
| 5 | `production` | operasional gudang produksi |
| 6 | `accounting_manager` | approve faktur pembelian saja (maker-checker), ditambahkan 2026-09-05 |

Helper penting pada `User`:

- `isSuperAdmin()`
- `isPurchasing()`
- `isFinance()`
- `isWarehouse()`
- `isAccounting()`
- `isProduction()`
- `isAccountingManager()`
- `isWarehouseOperator()`
- `accessibleGudangIds($ability)`
- `canAccessGudang($gudangId, $ability)`

### 3.3 SuperAdmin

`SuperAdmin` memakai `type = 0` dan mendapat bypass policy melalui `Gate::before` pada `AuthServiceProvider`.

Artinya:

- dapat mengakses seluruh modul
- tidak dibatasi state policy biasa
- cocok untuk maintenance dan audit

### 3.4 Development accounts

`UserSeeder` membuat akun:

| Email | Password | Type | Role |
|---|---|---:|---|
| `superadmin@wms.local` | `Wms12345!` | 0 | SuperAdmin |
| `purchasing@wms.local` | `Wms12345!` | 1 | Purchasing |
| `finance@wms.local` | `Wms12345!` | 2 | Finance |
| `warehouse@wms.local` | `Wms12345!` | 3 | Warehouse |
| `accounting@wms.local` | `Wms12345!` | 4 | Accounting |
| `produksi@wms.local` | `Wms12345!` | 5 | Produksi |
| `accounting-manager@wms.local` | `Wms12345!` | 6 | Accounting Manager |

## 4. Authorization

### 4.1 Gambaran role

| Capability | 0 | 1 | 2 | 3 | 4 | 5 | 6 |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Bypass policy | yes | no | no | no | no | no | no |
| Supplier | yes | yes | no | no | no | no | no |
| Request (semua role kecuali Purchasing bisa buat) | yes | approve | yes | yes | approve | yes | yes |
| LPB | yes | yes | no | yes | no | no | no |
| NPK | yes | yes | no | yes | yes | yes | no |
| Transfer Gudang | yes | no | no | yes | no | yes | no |
| Stock Opname physical | yes | no | no | yes | no | yes | no |
| Stock Opname valuation/posting | yes | no | no | no | yes | no | no |
| Faktur Pembelian — create | yes | yes | no | no | no | no | no |
| Faktur Pembelian — approve (maker-checker) | yes | no | no | no | no | no | yes |
| Payment | yes | no | yes | no | no | no | no |
| COA/Jurnal/Tax/Period Lock | yes | no | no | no | yes | no | no |
| Dashboard Eksekutif | yes | no | no | no | yes | no | yes |

### 4.2 Produksi

Role `Produksi` adalah role operasional baru untuk tahap 1 `Gudang Produksi`.

Karakteristiknya:

- bisa masuk dashboard produksi
- bisa melihat saldo stok dan mutasi gudang yang di-assign
- bisa membuat dan melihat `NPK` (Pemakaian Barang) dari gudang yang di-assign
- bisa membuat dan melihat `Transfer Gudang` yang melibatkan gudang yang di-assign
- bisa membuat dan submit `Stock Opname` pada gudang yang di-assign
- tidak punya visibilitas finansial
- tidak mengelola master gudang atau pembagian gudang

Pembatasan gudangnya memakai `pembagian_gudangs`.

### 4.3 Pattern policy

Policy sekarang diarahkan mengikuti pola helper private di atas, misalnya:

- private helper siapa yang boleh
- private helper warehouse access
- public method `view/create/update/...` di bawah

Contoh file yang sudah mengikuti pola ini:

- `PemakaianBarangPolicy`
- `TransferGudangPolicy`
- `StockOpnamePolicy`
- beberapa policy accounting/procurement lain

### 4.4 Maker-checker faktur pembelian (2026-09-05)

Supplier invoice sekarang tidak langsung diposting saat dibuat:

- `FakturPembelianController::store()`/`update()` menyimpan invoice dengan `status = PENDING_APPROVAL`, tanpa memanggil `postInvoice()`.
- Hanya role `accounting_manager` yang bisa approve, lewat `POST faktur-pembelian/{id}/approve` (`FakturPembelianController::approve()`, gated `FakturPembelianPolicy::approve()`). Approve inilah yang memicu posting jurnal ke GL.
- Invoice berstatus `PENDING_APPROVAL` tidak bisa dibayar (`FakturPembelianPolicy::pay()`) atau diretur (`ReturPembelianController` menolak LPB dari invoice yang masih pending).
- Cek rekonsiliasi (`AccountingReconciliationService`) dan dashboard Finance mengecualikan invoice `PENDING_APPROVAL` supaya tidak muncul sebagai temuan/aksi palsu.
- Gate lain (COA/jurnal manual, period lock, controlled reversal) **belum** ikut pola maker-checker ini — masih dipegang penuh oleh `accounting`.

## 5. Gudang dan multi-warehouse

### 5.1 Master gudang aktif

Seeder default membuat:

- `GDG-UTAMA` / Gudang Utama
- `GDG-PRODUKSI` / Gudang Produksi
- `GDG-CONSIDER` / Gudang Consider
- `GDG-RUSAK` / Gudang Rusak

### 5.2 Jenis gudang

`Gudang` memakai constant:

- `NORMAL`
- `CONSIDER`
- `RUSAK`

Capability gudang:

- `boleh_penerimaan`
- `boleh_npk`
- `boleh_transfer`
- `boleh_opname`

### 5.3 Pembagian gudang

Tabel `pembagian_gudangs` dipakai untuk pembatasan akses gudang, sejak 2026-09-13 untuk role `Warehouse` **dan** `Produksi`.

Field penting:

- `user_id`
- `gudang_id`
- `boleh_menerima`
- `boleh_npk`
- `boleh_transfer`
- `boleh_opname`

Perilaku saat ini (berubah 2026-09-13):

- `SuperAdmin` mendapat seluruh gudang aktif
- `Warehouse` **dan** `Produksi` hanya mendapat gudang yang di-assign
- operator tanpa assignment tidak mendapat gudang sama sekali
- filter controller memakai `accessibleGudangIds()`

Konteks perubahan: gudang perusahaan tersebar di beberapa lokasi dan tiap lokasi punya adminnya sendiri, sehingga operator lokasi A tidak boleh menerima/mengeluarkan/menghitung stok di lokasi B. Migrasi `2026_09_13_000004` memberi seluruh user `Warehouse` yang sudah ada akses ke semua gudang aktif supaya tidak ada yang kehilangan akses saat rilis; penyempitan dilakukan per orang lewat menu Pembagian Gudang.

### 5.4 Gudang Produksi tahap 1

Implementasi tahap 1 saat ini:

- transfer `Gudang Utama -> Gudang Produksi` dilakukan melalui dokumen transfer biasa
- tidak ada jurnal beban saat transfer internal
- NPK dari gudang produksi menjadi titik mulai konsumsi bahan
- opname tetap per gudang

Belum ada WIP manufacturing khusus. Sistem masih memakai pola pemakaian bahan langsung saat NPK keluar.

## 6. Domain flow penting

### 6.1 LPB (Penerimaan Barang)

- LPB mengacu ke PO
- posting LPB menambah `stok_gudangs`
- posting LPB membuat `wms_layer_persediaan`
- jurnal LPB: debit persediaan, kredit GRNI
- form penerimaan menangkap `lot_number` **dan** `expires_at` (sejak 2026-09-09); lot dibuat/dipakai ulang per `(bahan_id, lot_number)`, dan tanggal kedaluwarsa diisikan ke lot lama yang masih kosong. Ini yang mengaktifkan FEFO picking dan seluruh guard stok kedaluwarsa — sebelumnya lot dari penerimaan selalu ber-`expires_at` NULL sehingga FEFO turun jadi FIFO biasa.

### 6.2 NPK (Pemakaian Barang)

- input bisa memakai satuan kecil bila bahan punya konversi
- sistem menyimpan:
  - `jumlah` sebagai jumlah transaksi
  - `jumlah_stok` sebagai jumlah dalam satuan stok utama
  - `satuan_transaksi` sebagai snapshot unit input
- FIFO layer dikonsumsi dari gudang asal (hanya layer `AVAILABLE`, tidak blocked, tidak expired)
- jurnal NPK: debit beban, kredit persediaan

Catatan untuk produksi:

- role `Produksi` hanya dapat membuat NPK dari gudang yang di-assign
- dashboard produksi menampilkan NPK terbaru dari gudang produksinya

### 6.3 Transfer gudang

- transfer memakai `transfer_gudangs`, detail, dan alokasi
- nilai layer dipertahankan
- transfer tidak membentuk jurnal selama perpindahan internal
- role `Produksi` dapat melihat transfer bila gudang asal atau tujuan termasuk assignment-nya

### 6.4 Stock opname

State:

- `DRAFT`
- `SUBMITTED`
- `APPROVED`
- `POSTED`
- `REJECTED`

Role:

- `Warehouse` dan `Produksi`: hitung fisik, update, submit
- `Accounting`: approve, reject, post

### 6.5 Retur Pembelian (termasuk pasca-invoice, 2026-09-05)

- Retur bisa dibuat terhadap LPB yang belum di-invoice (mengurangi GRNI) **atau** yang sudah di-invoice/dibayar.
- Jika invoice masih punya `sisa_tagihan`, nilai retur langsung mengurangi `grand_total`/`sisa_tagihan` invoice tersebut (debit Hutang Usaha, bukan GRNI).
- Jika nilai retur melebihi sisa tagihan (termasuk kasus sudah lunas penuh), kelebihannya dicatat sebagai uang muka supplier (`jenis_selisih=UANG_MUKA_SUPPLIER`, tanpa kas keluar) yang bisa dipakai di pembayaran berikutnya lewat `available-advances/{supplier}`.
- Reversal retur (`InventoryReversalService::reverseReturPembelian()`) memulihkan saldo invoice dan membatalkan uang muka terkait (ditolak bila uang muka sudah terpakai).

### 6.6 Faktur Pembelian — approval, PPh, dan multi-currency ringan

- Alur approval: lihat [4.4](#44-maker-checker-faktur-pembelian-2026-09-05).
- `jenis_pph` (`PPH23`/`PPH22`/`PPH4A2`, nullable) dipilih saat input invoice; masing-masing punya akun hutang sendiri (`HUTANG_PPH23`/`HUTANG_PPH22`/`HUTANG_PPH4A2`). Pembayaran menolak potongan PPh bila invoice tidak punya `jenis_pph`.
- `mata_uang_asing`/`kurs`/`nilai_asing` menyimpan referensi kurs asal invoice supplier luar negeri — **bukan** ledger multi-currency sungguhan; `grand_total` tetap IDR, dihitung dari nilai yang sudah dikonversi saat LPB. Selisih kurs saat pembayaran memakai mekanisme umum `jenis_selisih`/`coa_selisih_id` (akun baru: `4204` Laba Selisih Kurs, `5303` Rugi Selisih Kurs).
- `ppn_impor` diposting sebagai baris nyata ke `AccountingSetting::PPN_IMPOR` (akun aset, `1104`), dikreditkan ke Hutang Usaha bersama `grand_total`.

### 6.7a Jasa: kapitalisasi, gate pass, barang titipan, tukar tambah (2026-09-14)

Empat hal yang sebelumnya tidak bisa dicatat sistem:

- **Kapitalisasi jasa ke aset.** `wms_kategori_jasa.perlakuan` = `BEBAN` (default) atau `KAPITALISASI`. Kategori kapitalisasi mewajibkan `wms_pesanan_jasa_detail.aset_id`; saat invoice diposting, nilainya didebit ke akun aset (`KategoriAset.akun_aset_id`), bukan ke `expense_coa_id`, lalu menambah `acquisition_cost`/`book_value` dan boleh memperpanjang `useful_life_months` lewat `tambahan_umur_bulan`. Jejaknya di `wms_kapitalisasi_aset` dengan unique `(sumber_type, sumber_id)` sehingga posting ulang invoice tidak menggelembungkan aset.
- **Gate pass** (`wms_pengeluaran_barang`): barang kita keluar ke vendor lalu kembali. Alur `DRAFT -> DI_VENDOR -> SEBAGIAN_KEMBALI -> SELESAI`. **Tidak membuat jurnal** — kepemilikan tidak berpindah, aset tetap `ACTIVE` dan tetap disusutkan. Mengeluarkan *persediaan* (bukan aset) untuk subkontrak sengaja tidak didukung di sini karena akan menyentuh layer FIFO; pakai Transfer Gudang ke gudang khusus.
- **Barang titipan vendor** (`wms_barang_titipan`): unit pinjaman selama barang kita diperbaiki. Nol jurnal, nol layer persediaan, nol aset — barang ini milik vendor. `nilai_taksiran` hanya memo.
- **Tukar tambah** (`disposal_type = TRADE_IN`): pelepasan aset via barter. DPP = nilai wajar barang yang diserahkan (UU PPN Pasal 1A), PPN Keluaran ke COA `2109`, dan nilai DPP+PPN menjadi **uang muka supplier** lewat mekanisme yang sudah ada sehingga bisa mengurangi tagihan pembelian penggantinya. Laba/rugi diukur terhadap DPP saja, bukan DPP+PPN. Belum menghasilkan e-Faktur — bagian itu memang butuh sisi penjualan.

### 6.7 Depresiasi aset otomatis (2026-09-05)

- `AsetAccountingService::runAutomaticDepreciation()` (`POST aset/penyusutan-otomatis`) menjalankan depresiasi garis lurus untuk semua aset aktif pada satu periode, melewati aset yang sudah didepresiasi di periode itu atau tidak punya umur manfaat.
- Form manual (`StorePenyusutanAsetRequest`) tetap ada untuk penyesuaian, default ke nilai garis lurus yang disarankan bila `amount` dikosongkan.
- Metode declining-balance belum diimplementasikan.

### 6.8 Material Request (dibuka untuk semua role, 2026-09-05)

- Semua role kecuali Purchasing sekarang bisa membuat Material Request (`MaterialRequestPolicy::create()`); Purchasing dan Accounting tetap yang approve.
- `requests.requested_by` mencatat pembuat; non-reviewer hanya melihat request miliknya sendiri di `request.index`.
- Status baru `FULFILLED` otomatis aktif begitu setiap baris `realisasi` mencapai `jumlah_acc` — dihitung terpusat di `MaterialRequestFulfillmentService::syncRealisasi()`, dipanggil dari setiap tempat yang dulu meng-update `realisasi` secara manual (`PesananPembelianController`/`PesananPembelianDetailController`). Status kembali ke `APPROVED` bila baris PO terkait diedit/dihapus di bawah kuota penuh.

### 6.9 Penjualan dan piutang (sejak 2026-09-16)

- `PesananPenjualan` menampung pesanan pelanggan beserta DPP/PPN; statusnya `OPEN` sampai seluruh baris terkirim.
- `SuratJalan` adalah tempat stok dan GL benar-benar bergerak: posting mengonsumsi layer lewat `StokGudangService::ambilLayer()` (jalur FIFO yang sama dengan NPK dan transfer), mencatat tiap tarikan di `wms_surat_jalan_alokasi`, lalu memposting **debit Beban Pokok Penjualan / kredit Persediaan dengan dimensi gudang**. Surat jalan draft tidak menyentuh apa pun.
- `FakturPenjualan` memposting **debit Piutang Usaha / kredit Penjualan + PPN Keluaran**, dan menolak posting bila ber-PPN tanpa NSFP.
- `PenerimaanPembayaran` mengurangi piutang. Sejak 2026-10-01 kelebihan bayar **tidak ditolak**: bagian yang menutup faktur masuk kolom `jumlah`, sisanya ke `jumlah_uang_muka` dan terbentuk baris `wms_uang_muka_pelanggan` (akun `2112`). Faktur karena itu tidak pernah bisa terbayar lebih dari sisa tagihannya.
- `ReturPenjualan` mengembalikan stok sebagai **layer baru** (`source_type='RETUR_PENJUALAN'`), bukan membatalkan konsumsi layer lama — layer lama bisa sudah terpakai dokumen lain dan menghidupkannya kembali akan merusak urutan FIFO.

### 6.10 Produksi dan barang dalam proses (sejak 2026-09-16)

- `DataPesanan` (perintah kerja) selalu lahir dari satu baris `PesananPenjualan`; produk yang dibuat mengikuti baris itu.
- NPK yang ditandai ke perintah kerja **mendebit Barang Dalam Proses (`1303`)**, bukan akun beban. NPK tanpa tanda perilakunya tidak berubah.
- Saldo WIP **diturunkan dari baris `wms_data_pesanan_biaya`**, bukan disimpan sebagai kolom berjalan.
- Saat dilaporkan selesai, harga pokok per unit dikunci dan perintah kerja **berhenti menerima biaya**. Konsekuensinya: NPK yang perintah kerjanya sudah disegel tidak bisa dibalik.
- Keluaran tidak dibukukan sebagai stok barang jadi. Tiap pengiriman melepas WIP secara proporsional ke Beban Pokok Penjualan; perintah kerja tutup saat terkirim penuh.

### 6.11 Umur piutang dan sales (sejak 2026-09-18)

- `PiutangAgingService` mengelompokkan sisa tagihan ke lima ember yang **persis sama** dengan `apAgingBuckets()` di sisi hutang, supaya kedua sisi terbaca dalam skala yang sama.
- Laporan ini punya **tanggal acuan**. Karena retur penjualan memotong `grand_total` faktur di tempat, nilai retur yang tanggalnya **sesudah** tanggal acuan ditambahkan kembali; tanpa itu piutang periode lalu terbaca terlalu kecil. Pembayaran hanya dihitung sampai tanggal acuan.
- Faktur `VOID` dikeluarkan sepenuhnya, sama seperti sisi hutang. Faktur yang di-void **setelah** tanggal acuan karenanya tidak muncul pada laporan mundur — batas yang disengaja.
- Laporan menampilkan **tie-out** terhadap saldo buku besar Piutang Usaha dan menandai selisihnya merah. Tie-out **dimatikan saat difilter per pelanggan**, karena `wms_jurnal_detail` tidak punya dimensi pelanggan.
- `wms_pesanan_penjualan.sales_user_id` mencatat sales pemegang pelanggan dan **disalin ke `wms_faktur_penjualan.sales_user_id`** saat faktur dibuat, sehingga pengalihan pesanan tidak memindahkan penjualan yang sudah tertagih. Standarnya adalah pembuat dokumen; **tidak ada role baru** — siapa pun pengguna aktif bisa ditunjuk.
- `KinerjaSalesService` merangkum pesanan, penjualan DPP, retur, penjualan bersih, dan piutang beredar per sales dalam satu rentang tanggal.

### 6.12 Cross dock (sejak 2026-09-18)

- Cross dock adalah **janji, bukan perpindahan**: ia memakai `ReservasiPersediaan` yang sudah ada, bukan status layer baru, sehingga invariant "saldo gudang = jumlah layer" tidak tersentuh.
- Usulannya mempertemukan baris penerimaan barang 14 hari terakhir dengan baris pesanan penjualan terbuka di **gudang yang sama** dan bahan yang sama, dibatasi oleh sisa pesanan, sisa baris LPB, dan stok bebas.
- Karena `StokGudangService::keluar()` menuntut *tersedia − direservasi ≥ jumlah*, reservasi cross dock justru akan menghalangi pengirimannya sendiri. Maka `postingSuratJalan()` **melepas tanda cross dock milik baris pesanan itu sebelum mengonsumsi stok**.
- Pengiriman **sebagian** memecah tanda: bagian terkirim menjadi `DIKIRIM`, sisanya direservasi ulang sebagai baris baru.

### 6.13 BOM dan varians pemakaian (sejak 2026-09-18)

- `wms_bom` menyimpan komponen standar per bahan hasil; hanya **satu BOM aktif per bahan**, dijaga di service.
- Varians membandingkan standar (jumlah BOM × basis perintah kerja) dengan pemakaian NPK aktual pada perintah kerja itu. Basisnya `jumlah_selesai` bila sudah selesai, selain itu `jumlah_rencana`.
- **Varians tidak menjurnal apa pun.** Costing produksi berbasis pemakaian aktual dan materialnya sudah masuk WIP saat NPK diposting; menjurnal varians di atasnya akan menghitung ganda.
- Bahan yang terpakai tapi tidak ada di BOM tampil sebagai **DI LUAR BOM**, bukan disembunyikan. Penilaian memakai harga rata-rata aktual, mundur ke harga layer terbaru, dan **null bila keduanya tidak ada** — bukan nol, yang akan terbaca sebagai "tidak ada selisih".

### 6.14 Penomoran dokumen (diperbaiki 2026-09-18)

- `DocumentNumberService` punya dua mode. `external()`/`financial()`/`internal()` **mengalokasikan** (menaikkan `document_sequences.last_number`) dan hanya boleh dipanggil saat dokumen benar-benar disimpan. `preview(...)` **membaca** dan tidak menulis apa pun — itu yang dipakai form dan field "nomor berikutnya".
- Sebelum 2026-09-18, tiga belas titik tampilan memanggil metode yang mengalokasikan, sehingga **membuka form lalu batal membakar satu nomor dokumen secara permanen** dan meninggalkan lubang pada urutan yang justru ada untuk keperluan audit.
- Form lalu mengirim balik nomor itu sebagai input readonly dan controller memercayainya — artinya nomor dokumen, tulang punggung audit, **ditentukan klien**. Sekarang setiap `store()` mengalokasikan sendiri di sisi server dan mengabaikan kiriman klien; aturan `required`/`regex`/`unique` untuk field nomor dihapus dari `Store*Request` karena hanya ada untuk mengawasi input yang kini tidak dipakai.
- Konsekuensi untuk tes: tes yang memanggil route store **tidak boleh memilih nomornya sendiri**, melainkan membacanya dari respons (`->json('data.no_po')`, `->json('data.id_lpb')`).
- `StokGudangService::saldo()` juga bukan pembacaan murni — ia melakukan `insertOrIgnore` dan `lockForUpdate()`. Jalur baca harus query `StokGudang` langsung.

### 6.15 Tema terang/gelap (dirapikan 2026-09-18)

- Hanya ada dua tema daisyUI: `wms` (terang) dan `wms-dark`. Tema aktif dipasang sebagai `data-theme` **inline di `<head>`** sebelum CSS dimuat, jadi pengguna mode gelap tidak melihat kilatan putih.
- **Warna tidak pernah ditulis mati di view.** Pakai token: `base-100` untuk permukaan, `base-200` untuk halaman, `base-300` untuk garis, `base-content` untuk teks. Dijaga `KualitasTampilanTest::test_no_page_hardcodes_colors_outside_the_theme`. Pengecualian sah hanya `resources/views/reports/**` dan `stock_opname/pdf`, karena dicetak di kertas.
- Jangan memaksakan warna teks ke komponen bertema. `badge text-white` terbaca di mode terang lalu menjadi putih-di-atas-kuning saat `badge-warning` tampil di `wms-dark`.
- Tangga elevasi kini sama arah di kedua tema. Sebelumnya di mode gelap kartu **lebih gelap** daripada halaman, dan `base-100` sama persis dengan `neutral` sehingga sidebar menyatu dengan kartu. Sekarang: halaman `#0b1220` → kartu `#17233d` → garis `#2a3a56`, sidebar `#0f172a`. Kontras teks 13,3:1 (gelap) dan 14,6:1 (terang).
- **Grafik harus membaca token sendiri** karena canvas tidak mewarisi kelas CSS. `task-chart.js` mengambil variabel HSL daisyUI lewat `getComputedStyle` dan memasang ulang saat `inventory:theme-changed`. Tanpa itu sumbu grafik memakai abu-abu bawaan Chart.js yang tak terlihat di latar gelap.
- **SweetAlert2 menyuntik CSS terangnya sendiri.** Override-nya ada di akhir `app.css` dan sengaja **di luar `@layer components`** — Tailwind memangkas kelas kustom di dalam layer bila tidak ditemukan di konten yang dipindai, dan `swal2-*` hanya muncul di bundel JS vendor. Tandanya bila salah: ukuran `app.css` hasil build tidak bertambah.

### 6.15b Persediaan dalam perjalanan dan pembatalan perintah kerja (2026-09-18)

- **`1304 Persediaan Dalam Perjalanan`** membuat jurnal transfer jadi **dua tahap**. Pengiriman: debit 1304 / kredit persediaan gudang asal. Penerimaan: debit persediaan gudang tujuan / kredit 1304. Sebelumnya hanya ada satu jurnal saat penerimaan, sehingga gudang yang sudah mengirim barang masih memikul nilainya di buku besar.
- Kedua baris 1304 **ditandai gudang tujuan** di kedua tahap. Menandai tahap pengiriman dengan gudang asal justru berarti "gudang A masih memegang nilai ini" — hal yang sedang diperbaiki. Dengan gudang tujuan, saldonya menjawab pertanyaan berguna: *apa yang sedang menuju gudang B*, dan menjadi nol saat diterima.
- **Penerimaan kurang menyisakan selisihnya di 1304**, disengaja: barang yang berangkat tapi tak sampai tetap harus terlihat, bukan lenyap di antara dua gudang.
- `RekonsiliasiGudangService` **berhenti mengatribusikan nilai in-transit kembali ke gudang asal**. Kompensasi itu dulu ada justru karena buku besar menahannya di sana; setelah buku besar benar, membiarkannya akan membuat setiap transfer berjalan tampak merah.
- Invarian baru **`transit`**: saldo 1304 harus sama dengan nilai seluruh layer `IN_TRANSIT`.
- **`5308 Kerugian Pembatalan Produksi`** memungkinkan perintah kerja dibatalkan setelah menyerap biaya: debit 5308 / kredit `1303`, disertai baris biaya **negatif** di `wms_data_pesanan_biaya` agar subledger ikut nol. Akun terpisah dipilih agar kerugian pembatalan tampak sebagai barisnya sendiri di Laba Rugi, bukan tercampur harga pokok penjualan.
- Perintah kerja yang dibatalkan keluar dari `DataPesanan::berjalan()`, sehingga invarian `wip` tetap berlaku tanpa penyesuaian tambahan.

### 6.15c Revaluasi kurs dan routing produksi (2026-09-18)

- **PSAK 10 hanya merevaluasi pos moneter.** Persediaan adalah pos **non-moneter** dan tetap pada kurs historis — catatan lama yang bilang ini berarti "layer FIFO berdenominasi valas" keliru. Hanya hutang, piutang, dan kas yang disajikan ulang; tidak ada layer, HPP, atau dasar biaya yang tersentuh.
- `4204 Laba Selisih Kurs` dan `5303 Rugi Selisih Kurs` **sudah ada sejak lama**, hanya belum dipetakan. Periksa akun yang sudah ada sebelum membuat yang baru.
- Kurs penutup **diketik per mata uang per periode** di `wms_kurs_penutup`; tidak ada umpan kurs otomatis. Periode tidak bisa diposting selama masih ada faktur valas terbuka tanpa kurs, dan hanya bisa diposting **sekali**.
- Revaluasi **tidak mengubah `sisa_tagihan`**; pergerakannya dicatat di `wms_revaluasi_kurs`. Karena itu **invarian `ap` diperlebar** dengan akumulasi revaluasi — tanpa itu ia langsung merah begitu revaluasi pertama diposting.
- **Routing tetap deskriptif; tarifnya ada di pusat kerja, bukan di baris routing.** `wms_pusat_kerja` + `wms_bom_operasi` memberi BOM urutan operasi dengan waktu standar. Sejak 2026-10-01 `wms_pusat_kerja` punya `tarif_tenaga_kerja_per_jam` dan `tarif_overhead_per_jam`, dipakai saat jam kerja dicatat di `wms_jam_kerja_produksi`. **`wms_bom_operasi` tetap tanpa kolom tarif** dan ada tes yang menjaganya: satu operasi dinilai dari tarif pusat kerjanya, bukan dari baris routing-nya.
- Satu pusat kerja tidak boleh mengaku kapasitas lebih dari **1.440 menit per hari**, dan yang masih dipakai routing tidak bisa dinonaktifkan.

### 6.16 Invarian rekonsiliasi (sisi jual ikut diawasi, 2026-09-18)

`AccountingReconciliationService::checks()` mengembalikan **delapan** invarian: `stock`, `journal`, `invoice`, `grni`, `ap`, **`ar`**, **`transit`**, `wip`.

- `ar` membandingkan jumlah `sisa_tagihan` faktur penjualan berstatus POSTED/PARTIALLY_PAID dengan saldo buku besar `PIUTANG_USAHA`.
- Ditambahkan karena himpunannya **timpang**: sisi beli punya dua penjaga (`grni` dan `ap`), sisi jual nol. Angkanya sudah cocok saat diukur manual, jadi ini tidak memperbaiki data — ia menutup lubang di mana penyimpangan **di masa depan** akan lewat tanpa laporan, padahal penyimpangan serupa di hutang langsung berbunyi.
- Aturan turunannya: setiap jenis dokumen yang bisa menumpuk saldo di neraca wajib diberi invarian saat dibangun, bukan belakangan.

### 6.17 Fitur yang dihapus: `debits` dan `kredits` (2026-09-18)

Dua tabel master `(id, kode, nama)` dari 21 Juli 2026 dihapus beserta model, policy, form request, controller, route, dan migrationnya. Alasannya: tidak pernah ditaut dari menu mana pun, kosong di semua environment, tidak dirujuk kode domain mana pun, dan sebagai tabel domain tanpa awalan `wms_` juga melanggar aturan penamaan — sementara tetap membuka **sepuluh endpoint CRUD** yang menerima tulisan. Bila nota debit/kredit dibutuhkan nanti, bangun sebagai jenis dokumen sungguhan dengan jalur jurnal, jangan menghidupkan kembali stub ini.

## 7. Dashboard

Dashboard aktif:

- `dashboard` umum untuk SuperAdmin/fallback
- `purchasing.dashboard`
- `finance.payment-dashboard`
- `gudang.dashboard`
- `accounting.dashboard`
- `produksi.dashboard`
- `executive-dashboard.index` — khusus Accounting dan Accounting Manager

Dashboard produksi menampilkan:

- jumlah gudang tugas
- NPK hari ini
- transfer aktif
- opname aktif
- transfer terbaru
- NPK terbaru

### 7.1 "All tasks" grid + chart per role (2026-09-05)

Setiap dashboard role (lewat `AuthController::dashboard()`) sekarang juga menampilkan koleksi `tasks` — setiap item outstanding yang relevan untuk role tersebut sebagai kartu klik (`task-grid.blade.php`) plus chart bar/doughnut (`task-chart.blade.php` + `resources/js/task-chart.js`, Alpine component `taskChart`). Ini murni tambahan — stat-card dan daftar top-5/top-8 yang sudah ada sebelumnya tidak dihapus. Setiap kartu tugas mengarah ke halaman index terkait (kebanyakan tidak otomatis ter-filter ke status spesifik itu).

### 7.2 Dashboard Eksekutif (2026-09-06)

`App\Http\Controllers\ExecutiveDashboardController` + `App\Services\ExecutiveDashboardService`, gate `viewExecutiveDashboard` (Accounting + Accounting Manager). Menyajikan 4 chart langsung dari data GL yang sudah posted, tanpa tabel baru:

1. Tren nilai persediaan — saldo GL akhir bulan (6 bulan terakhir) pada setiap akun `KategoriBahan.coa_persediaan_id`.
2. Aging hutang (AP aging) — `sisa_tagihan` dikelompokkan per bucket hari terlambat dari `tgl_deadline_pembayaran`, mengecualikan invoice `VOID`/`PENDING_APPROVAL`.
3. Top 5 supplier berdasarkan total invoice sepanjang waktu.
4. Biaya per kategori bahan tahun berjalan — debit GL pada `KategoriBahan.coa_beban_id`.

Chart memakai komponen `taskChart` yang sama (diperluas dengan tipe `line` dan opsi `beginAtZero`) lewat partial baru `metric-chart.blade.php` (label/data-driven, bukan task-count-driven).

## 8. Database aktif

### 8.1 Tabel utama

| Kelompok | Tabel |
|---|---|
| Auth | `users`, `user_roles`, `personal_access_tokens`, `password_reset_tokens` |
| Master | `suppliers`, `gudangs`, `bahan`, `kategoribahan`, `tipe_pembebanans` |
| Gudang | `stok_gudangs`, `pembagian_gudangs`, `pengaturan_bahan_gudangs`, `mutasi_stoks` |
| Transfer | `transfer_gudangs`, `detail_transfer_gudangs`, `alokasi_transfer_gudangs` |
| Consider | `pemeriksaan_considers`, `detail_pemeriksaan_considers` |
| Procurement | `requests`, `request_details`, `wms_pesanan_pembelian`, `wms_pesanan_pembelian_detail` |
| Receipt | `wms_penerimaan_barang`, `wms_penerimaan_barang_detail` |
| Issue | `wms_pemakaian_barang`, `wms_pemakaian_barang_alokasi_stok`, `wms_layer_persediaan` |
| Retur | `wms_retur_pembelian`, `wms_retur_pembelian_detail` |
| AP | `wms_faktur_pembelian`, `wms_faktur_pembelian_penerimaan`, `wms_pembayaran_faktur` |
| Ledger | `wms_bagan_akun`, `accounting_settings`, `wms_jurnal`, `wms_jurnal_detail` |
| Controls | `accounting_period_locks`, `tax_rates`, `document_sequences` |
| Opname | `wms_stock_opname`, `wms_stock_opname_detail`, `wms_stock_opname_alokasi` |
| Asset | `wms_kategori_asets`, `wms_asets`, `wms_penyusutan_asets`, `wms_pelepasan_asets` |
| Service | `wms_kategori_jasa`, `wms_pesanan_jasa_detail`, `wms_penerimaan_jasa_detail`, `wms_penerimaan_jasa_alokasi` |
| Sales | `pelanggans`, `wms_pesanan_penjualan`, `wms_pesanan_penjualan_detail`, `wms_surat_jalan`, `wms_surat_jalan_detail`, `wms_surat_jalan_alokasi` |
| AR | `wms_faktur_penjualan`, `wms_faktur_penjualan_detail`, `wms_penerimaan_pembayaran`, `wms_retur_penjualan`, `wms_retur_penjualan_detail` |
| Produksi | `wms_data_pesanan`, `wms_data_pesanan_biaya` |
| Pelaporan | `wms_catatan_laporan_keuangan`, `wms_permintaan_persetujuan` |
| Pajak | `wms_perhitungan_pajak_penghasilan`, `wms_kompensasi_kerugian_fiskal` |
| Kitting | `wms_kit`, `wms_kit_komponen`, `wms_perakitan_kit`, `wms_perakitan_kit_detail` |
| Gelombang | `wms_gelombang_pengambilan` |
| Jasa lanjutan | `wms_kapitalisasi_aset`, `wms_pengeluaran_barang`, `wms_pengeluaran_barang_detail`, `wms_barang_titipan` |
| Lampiran | `wms_lampiran_dokumen` |
| WMS control | `wms_log_audit`, `wms_kunci_operasi_dokumen`, `wms_pembalikan_dokumen`, `wms_lokasi_gudang`, `wms_lot_persediaan`, `wms_serial_persediaan`, `wms_reservasi_persediaan`, `wms_pesanan_pengambilan`, `wms_pesanan_pengambilan_detail`, `wms_pemeriksaan_kualitas`, `wms_pemeriksaan_kualitas_detail`, `wms_biaya_tambahan`, `wms_biaya_tambahan_alokasi`, `wms_saran_pengisian_ulang` |

Penambahan kolom penting sejak refresh sebelumnya (semua di tabel yang sudah ada, bukan tabel baru): `wms_faktur_pembelian.jenis_pph`/`mata_uang_asing`/`kurs`/`nilai_asing`/`ppn_impor`/`approved_by`/`approved_at`, `wms_pembayaran_faktur.potongan_pph` (rename dari `potongan_pph23`)/`uang_muka_sumber_payment_id`/`uang_muka_dipakai`, `wms_retur_pembelian.invoice_id`/`hutang_reduction`/`advance_payment_id`, `requests.requested_by`.

Kolom lintas-domain yang ditambahkan 2026-09-16 dan gampang terlewat: **`wms_jurnal_detail.gudang_id`** (dimensi gudang; sudah di-backfill ke jurnal lama lewat migration `2026_09_16_000004`), `wms_pemakaian_barang.data_pesanan_id`, `wms_surat_jalan_detail.data_pesanan_id`, dan `wms_penerimaan_jasa_alokasi.data_pesanan_id`. Ketiga kolom `data_pesanan_id` itulah yang mengubah `kode_datapesanan` yang dulu sekadar teks bebas menjadi kaitan nyata ke perintah kerja.

### 8.2 Migration yang relevan dengan role

- `2026_08_07_000001_add_type_to_users_table.php`
- `2026_08_10_000001_create_user_roles_table.php`
- `2026_08_10_000002_add_production_role_to_user_roles.php`
- `2026_09_05_000003_add_accounting_manager_role_to_user_roles.php`

## 9. Seeder

Urutan utama `DatabaseSeeder`:

1. `UserRoleSeeder`
2. `UserSeeder`
3. master tax / COA / accounting setting
4. kategori bahan dan service category
5. warehouse master termasuk `Gudang Produksi`
6. supplier dan bahan demo
7. seeder demo transaksi
8. sinkronisasi multi-warehouse demo data
9. `PenjualanDanProduksiDemoSeeder`

Pada sinkronisasi multi-warehouse:

- user `Warehouse` mendapat akses ke semua gudang
- user `Produksi` otomatis di-assign ke `Gudang Produksi`

`UserRoleSeeder`/`UserSeeder` sekarang juga membuat role dan akun `accounting_manager` (lihat [3.4](#34-development-accounts)).

`PenjualanDanProduksiDemoSeeder` (2026-09-18) sengaja berjalan **setelah** sinkronisasi multi-warehouse, karena baru di sanalah `StokGudang` terisi dan alur penjualan butuh saldo yang bisa dijual. Ia membuat dua pelanggan, satu penjualan produksi lengkap (perintah kerja + NPK + surat jalan + faktur + pembayaran sebagian), satu penjualan dagang yang sudah lewat jatuh tempo, satu BOM beserta varians boros/hemat, dan satu tanda cross dock. Tanggal penjualan mundurnya **diambil dari `transaction_date` layer yang benar-benar ada**, sebab `ambilLayer()` menolak layer yang lebih baru dari tanggal dokumen. Tiap skenario dibungkus agar dataset demo yang tipis hanya memunculkan peringatan, bukan menggagalkan `migrate:fresh --seed`.

## 10. Installation

Development:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Frontend hot reload:

```bash
npm run dev
```

Catatan:

- `.env.example` sudah tersedia di root repo
- `migrate --seed` dipakai untuk development/test
- production sebaiknya memisahkan master seeder dan demo seeder

## 11. Testing

Perintah:

```bash
npm install && npm run build                  # WAJIB dulu: tanpa manifest Vite semua halaman 500
php artisan test                              # full suite
php artisan test --filter=TestClassName       # satu class
composer test:case-sensitive                  # strict PSR-4 + architecture test, ala runner Linux
```

### 11.1 Jaring pengaman: apa yang ditangkap masing-masing

Setiap jaring menutup kelas kegagalan yang berbeda. Tahu mana menangkap apa mencegah orang menulis tes yang menduplikasi jaring lain sementara celah aslinya tetap menganga.

| Jaring | Masuk lewat | Menangkap | Tidak bisa menangkap |
|---|---|---|---|
| `ArchitectureConventionTest` (14 tes) | analisis statis + `git ls-files` + tabel route | casing class/berkas, validasi inline di controller, pemisahan route per domain dan `route:cache`, status huruf kecil, floating point di migration, nama tabel legacy, **alokasi nomor dokumen di jalur tampilan**, **form Blade yang salah route/verb/tanpa `@csrf`**, **`route()`/`view()` menggantung**, **Form Request yang tidak diotorisasi di mana pun**, **aksi destruktif yang bisa dipicu GET**, **relasi `hasMany`/`hasOne` tanpa foreign key eksplisit**, **nama tabel string yang sudah tidak ada**, **route yang menunjuk method controller yang tidak ada** | perilaku saat berjalan |
| `SmokeSemuaHalamanTest` (2 tes) | HTTP GET sebagai Super Admin, dan HTTP mutasi sebagai tamu | 5xx pada route GET bernama; plafon jumlah route yang boleh dilewati karena parameternya tak teresolusi; **route mutasi yang tembus tanpa login atau meledak** | halaman yang balas 200 tapi isinya salah; akses per role, karena `Gate::before` membuka segalanya untuk Super Admin |
| `KualitasTampilanTest` (6 tes) | HTTP GET + HTML mentah | view yang tidak terkompilasi, directive Blade mentah atau `{{ $x }}` bocor ke browser, listing kosong tanpa pesan, tabel lebar tanpa pembungkus scroll, **kelas warna mati pada halaman yang dirender** | kebenaran angka di halaman |
| `KonsistensiTemaTest` (3 tes) | berkas sumber Blade/JS/CSS + `tailwind.config.js` | **warna mati di seluruh sumber** termasuk berkas yang tidak dirender tes mana pun, `theme('colors.*')`, nilai arbitrer `[#hex]`, gaya inline, warna mentah di CSS; grafik yang tidak membaca token atau tidak memasang ulang saat tema berganti; tangga elevasi dan kontras WCAG kedua tema | tampilan yang jelek tapi memakai token yang benar |
| `HalamanTanpaEfekSampingTest` (1 tes) | HTTP GET + query log | halaman GET yang menjalankan `insert`/`update`/`delete` | penulisan pada route mutasi, yang memang sah |
| `AksesPerRoleTest` | HTTP GET per role | halaman yang wajib terbuka dan wajib ditolak per role; sejak 2026-09-23 termasuk BOM, routing produksi, varians pemakaian, kinerja sales, umur piutang, revaluasi kurs, dan cross dock | route mutasi |
| `JadwalDanKontrolPeriodeTest` (4 tes) | perintah artisan, service, dan HTTP | perintah terjadwal yang hanya jalan kalau ada user login, perintah yang keluar sukses padahal gagal, posting yang lolos ke periode terkunci, dan form yang 500 karena input tidak divalidasi | pengiriman notifikasi yang diantrikan |
| `ProxyTepercayaTest` (3 tes) | HTTP GET dengan header proxy palsu | `X-Forwarded-For` yang bisa mengubah `request()->ip()` padahal tidak ada proxy tepercaya — celah yang melumpuhkan throttle login per IP dan audit login | penyalahgunaan IP setelah proxy sah memang dipercaya |
| `CrudMasterDataTest` (3 tes) | HTTP penuh | siklus buat–ubah–hapus, penegakan policy per role, dan validasi duplikat/wajib pada master data | domain lain yang belum punya tes CRUD sendiri |
| tes domain | service, dan HTTP bila penting | aturan bisnis, invarian buku besar, FIFO, WIP, pajak | lihat celah cakupan di bawah |

### 11.2 Celah cakupan yang diketahui

Diukur 18 September 2026 dengan `php artisan route:list --json` dibandingkan nama route yang disebut di `tests/`:

- **146** route GET tanpa parameter — tertutup jaring GET di atas.
- **51** route GET berparameter — hanya tertutup bila `SmokeSemuaHalamanTest::nilaiParameter()` bisa meresolusi nilainya; sisanya dihitung sebagai terlewat dan jumlahnya diassert.
- **172** route mutasi. Sejak 2026-09-18 semuanya diuji **menolak tamu**, tapi hanya **57** yang disebut tes mana pun sebagai pengguna berwenang; **115** belum diverifikasi perilakunya. Reproduksi angkanya dengan mengambil nama route dari `route:list --json` lalu mencarinya di `tests/`.

Itulah alasan **trap 8** di `CLAUDE.md`: fitur yang membentang dari HTTP sampai buku besar butuh minimal satu tes yang masuk lewat route.

### 11.3 Cakupan domain

- laporan keuangan PSAK 1 lengkap (Neraca Saldo, Buku Besar, Laba Rugi, Neraca, Arus Kas, Perubahan Ekuitas, CALK) — Arus Kas dan Perubahan Ekuitas punya tie-out sendiri
- maker-checker jurnal manual, pembalikan dokumen, perubahan COA/mapping; pemohon tidak bisa menyetujui permintaannya sendiri
- dimensi gudang pada baris jurnal, transfer internal yang netral secara total
- lacak pembelian per penerimaan (termasuk ember **terjual**) dan kartu skor supplier
- penjualan end-to-end: pesanan, surat jalan (FIFO + HPP), faktur (piutang + PPN Keluaran), pembayaran, retur
- **umur piutang**: penempatan ember, tanggal acuan mundur, tie-out subledger vs buku besar, sales yang tersalin ke faktur
- **cross dock**: reservasi saat ditandai, pelepasan saat surat jalan diposting, pemecahan tanda saat kirim sebagian, penegakan akses gudang
- **BOM dan varians pemakaian**, termasuk perintah kerja tanpa BOM
- produksi: WIP dari NPK, penguncian harga pokok, pelepasan proporsional, invarian WIP vs buku besar
- aset dan jasa, depresiasi otomatis, kapitalisasi
- multi-warehouse, transfer in-transit, reservasi/picking, three-way match, landed cost, reversal, rekonsiliasi
- payment allocation, PPh 23/22/4(2) Final, uang muka supplier
- stock opname, retur pembelian pasca-invoice
- Material Request lintas role + auto-`FULFILLED`, dashboard per role, Dashboard Eksekutif, export Excel

### 11.4 Hasil audit A–Z 18 September 2026

Dijalankan terhadap database ter-seed. Dicatat supaya pertanyaan yang sama tidak diulang.

**Bersih (arsitektur):** 784 entri `$fillable`/`$casts` semuanya menunjuk kolom nyata; tidak ada model dengan tabel hilang; tidak ada `create($request->all())`; nol baris yatim pada sepuluh relasi induk-anak yang diperiksa.

**Diperbaiki:** 11 relasi `hasMany`/`hasOne` tanpa foreign key eksplisit (semuanya kebetulan benar hari ini — itu justru yang membuat jebakan #3 berbahaya); **dua tabel hantu** `pembelian_histories` dan `pembelian_detail_histories` yang membuat setiap revisi PO tercetak gagal sejak rename 2026-09-04; status aset yang ditulis sebagai string ajaib di sembilan tempat.

**Diukur, sengaja tidak diubah:** `$guarded = []` pada 11 model (dicoba diperketat lalu dikembalikan — Eloquent jadi membaca skema tiap `fill()` dan memecah tes unit murni, sementara tidak ada kode yang mengisi `id`); 16 service membaca `Auth::` langsung; **21 tabel domain tanpa awalan `wms_`** — klaim lama bahwa "semua tabel domain" mendapat awalan itu **keliru**; status `ReservasiPersediaan`, `quality_status` lot, dan pesanan pengambilan masih literal.

**Aturan bisnis terverifikasi:** 48 akun COA, digit pertama cocok kategori semuanya; tiga akun berposisi normal terbalik dan ketiganya **akun kontra yang benar**; tidak ada jurnal ke akun non-postable; seluruh mapping menunjuk kategori akun yang tepat; debit = kredit dan aset = liabilitas + ekuitas + laba, keduanya selisih nol; tarif PPN 11%, PPh 23 2%, PPh 22 1,5%, PPh 4(2) 10% sesuai ketentuan; **nol pelanggaran urutan FIFO**; aturan jenis gudang ditegakkan di service dan kini dijaga `AturanJenisGudangTest` — sebelumnya tidak ada satu pun tes yang menyebut `RUSAK` atau `CONSIDER`.

## 12. Technical debt

Daftar yang masih relevan per 2026-09-18:

1. `DatabaseSeeder` masih mencampur master dan demo — jangan dijalankan penuh ke produksi. Sejak 2026-09-18 ia menjalankan tujuh seeder demo berurutan; lihat CLAUDE.md bagian "Demo dataset" untuk siapa bergantung pada siapa
2. biaya tambahan (landed cost) belum bisa diseed maupun dipakai realistis: aturannya menuntut akun lawan `LIABILITAS/KREDIT` atau `ASET/KREDIT`, sehingga ongkos angkut tidak bisa dikreditkan ke Kas, sedangkan satu-satunya liabilitas yang cocok (`2101`) justru yang diukur invarian `ap` — butuh COA "Biaya Masih Harus Dibayar" atau pelonggaran aturan
3. ~~Lacak Pembelian tanpa ember barang hilang di jalan~~ — **ditutup 2026-10-01**: selisih transfer kini punya kolom "Hilang di Jalan" sendiri, sehingga residu `tidak_terlacak` tinggal disebabkan perakitan kit
4. belum ada token management UI/API untuk Sanctum — catatan: **layer integrasi API/EDI sendiri sudah dicoret dari perencanaan**, jadi ini hanya soal mengelola token internal
5. beberapa file lama masih punya encoding teks yang jelek
6. daftar modul penjualan memakai tabel Blade berpaginasi, bukan Yajra + `wmsDataTable()` seperti modul lain — penyimpangan yang disengaja dan dicatat; konversinya mekanis
7. ~~Form mapping tidak bisa disimpan karena kategori jasa~~ — **ditutup 2026-10-01**: kategori yang ditunjuk `wms_kategori_jasa.kategori_bahan_id` memakai aturan sendiri (`coa_persediaan_id` boleh ASET/DEBIT atau BEBAN/DEBIT); kategori barang tetap ketat
8. ~~`plafon_kredit` tidak ditegakkan~~ — **ditutup 2026-10-01**: pesanan ditolak bila eksposur (piutang + terkirim belum difakturkan + dipesan belum dikirim) melewati plafon; plafon 0/kosong berarti tanpa batas, dan Super Admin bisa menembus dengan alasan tertulis yang tersimpan di dokumennya
9. suite tes berjalan dekat plafon memori PHP; `phpunit.xml` menyetel `memory_limit=512M` agar deterministik. Kalau export terus bertambah besar, alihkan tes export ke koleksi `Excel::fake()` daripada mengunduh sungguhan
10. 16 kolom `*_id` di tabel `wms_` tidak punya constraint foreign key (diukur 2026-09-18). Sebagian besar memang benar demikian — kolom polimorfik (`reff_id`, `sumber_id`, `document_id`, `auditable_id`, `source_id`, `referensi_id`, `reference_id`, `lampiran_id`) tidak bisa dibatasi ke satu tabel, dan dua tabel riwayat pesanan pembelian sengaja bebas FK supaya snapshot tetap hidup ketika sumbernya dihapus. Yang perlu diingat: pemeriksaan baris yatim hanya menjangkau kolom yang **punya** constraint, jadi kolom di daftar ini tidak dijaga siapa pun
11. seluruh laporan PDF kini punya saudara Excel setelah rincian stock opname per dokumen menyusul (diukur 2026-09-18: 28 PDF, 28 Excel) — kalau menambah laporan PDF baru, tambahkan sekaligus sibling Excel-nya lewat `GenericTableExport`

**Data demo diperkaya 2026-09-18**: dari 47 tabel domain kosong menjadi 20. Pengayaan itu sendiri membongkar lima cacat yang tidak pernah dijangkau test mana pun — selisih transfer tidak mengurangi stok master, invarian `transit` bertabrakan dengan residu selisih yang disengaja, `RekonsiliasiGudangService` masih menghitung nilai in-transit dan membuang jurnal berstatus REVERSED, serta urai kit dengan lebih dari satu komponen selalu melanggar unique index. Rinciannya di CLAUDE.md jebakan 14.

**Sudah ditutup sejak daftar sebelumnya**: stok dalam perjalanan kini punya akun sendiri (`1304`, jurnal transfer dua tahap plus invariant `transit`); perintah kerja yang dibatalkan setelah menyerap biaya punya jalur hapus buku (`5308`); pengingat terjadwal yang dulu hanya menjangkau sisi beli kini mencakup piutang jatuh tempo, barang keluar belum kembali, cross dock yang menahan stok, dan perintah kerja mandek; delapan invarian rekonsiliasi dijaga `wms:periksa-invarian` yang berjalan 06.30 dan mengembalikan exit code bukan nol saat ada yang menyimpang; dan route yang menunjuk method controller tidak ada — dulu `request.*`, terakhir `pembelian.update-so-term` dan `pembelian.update-financials` — kini dijaga `ArchitectureConventionTest::test_every_route_points_at_a_controller_method_that_exists` alih-alih ditemukan satu per satu.

**Bukan lagi utang teknis** (dipertahankan sebagai catatan historis): UI manajemen user + reset password + audit login, `.env.example`, NSFP pada faktur pembelian, lima laporan PSAK 1 lengkap termasuk Arus Kas, Perubahan Ekuitas dan CALK, PPh 22/4(2) Final, depresiasi otomatis garis lurus & saldo menurun, rekonsiliasi fiskal + PPh Badan + pajak tangguhan + kompensasi kerugian, field multi-currency ringan + PPN Impor, retur pembelian pasca-invoice, rename sweep baku Indonesia, migrasi Tailwind/daisyUI/Alpine, Material Request lintas role, dashboard task grid + chart + pengingat + progres, Dashboard Eksekutif, export Excel di seluruh laporan, scheduler + notifikasi terantre, lampiran dokumen, antrean kerja operator, cycle count ABC, wave picking, kitting, seluruh celah jasa (kapitalisasi, gate pass, barang titipan, tukar tambah), Lacak Pembelian, Kartu Skor Supplier, **maker-checker yang kini mencakup jurnal manual/pembalikan dokumen/perubahan COA**, **penilaian persediaan per gudang lewat dimensi gudang pada baris jurnal**, **sisi AR/penjualan lengkap**, dan **perintah kerja produksi dengan barang dalam proses**.

CI GitHub Actions sudah berjalan untuk build, fresh migration+seed, dan test — itu infrastruktur yang ada, bukan utang.

## 13. WMS control standard (12 Agustus 2026)

Framework kontrol mencakup:

- `wms_log_audit` untuk histori perubahan dokumen kritis
- `wms_kunci_operasi_dokumen` untuk idempotency
- `wms_pembalikan_dokumen` untuk pembalikan LPB/NPK
- `wms_lokasi_gudang`, `wms_lot_persediaan`, dan `wms_serial_persediaan`
- status layer `AVAILABLE`, `QC_HOLD`, `IN_TRANSIT`, `REVERSED`, dan `TRANSFER_SHORTAGE`
- `wms_pemeriksaan_kualitas` dan putaway
- `wms_biaya_tambahan` beserta alokasi ke layer
- `wms_reservasi_persediaan` dan `wms_pesanan_pengambilan`
- `wms_saran_pengisian_ulang`, lengkap dengan tombol "Buat Request" yang mengubah saran jadi Material Request `PENDING` dan menandai saran itu `REQUESTED` (kolom `material_request_id`)
- three-way matching tersimpan pada faktur pembelian
- peringatan stok kedaluwarsa/mendekati kedaluwarsa muncul sebagai kartu tugas di dashboard gudang dan produksi
- `wms_gelombang_pengambilan` untuk wave/batch picking, dengan `gelombang_id` + `urutan_dalam_gelombang` pada `wms_pesanan_pengambilan`
- `wms_kit`, `wms_kit_komponen`, `wms_perakitan_kit` + detail untuk kitting/bundling
- klasifikasi ABC pada `pengaturan_bahan_gudangs` (`kelas_abc`, `nilai_pemakaian`, `kontribusi_kumulatif`) sebagai dasar cycle count dan slotting
- dimensi dan kapasitas volume pada `wms_lokasi_gudang`, plus `volume_cm3` pada `bahans`
- `wms_stock_opname.jenis` (`PENUH`/`SIKLUS`) + `kelas_abc` untuk cycle count parsial
- `wms_pengeluaran_barang` + detail (gate pass barang keluar ke vendor) dan `wms_barang_titipan` (unit pinjaman vendor) — keduanya catatan kustodian tanpa jurnal
- `wms_kapitalisasi_aset` sebagai jejak audit jasa yang dikapitalisasi ke aset, unique per sumber agar tidak dobel
- `wms_pengiriman_subkontrak` + detail untuk barang milik sendiri yang dikerjakan vendor; perpindahannya memakai Transfer Gudang biasa ke `GDG-SUBKONTRAK`, sehingga layer dan saldo gudang tetap cocok
- `wms_jam_kerja_produksi` sebagai pencatatan jam per perintah kerja; tiap baris menulis jurnal **dan** baris `wms_data_pesanan_biaya` sekaligus karena invarian `wip` membandingkan tepat keduanya
- `wms_uang_muka_pelanggan` untuk kelebihan bayar yang diparkir; pemakaiannya tercatat sebagai `wms_penerimaan_pembayaran` ber-`uang_muka_id` dengan `coa_kas_bank_id` kosong, supaya kas tidak terhitung dua kali
- jejak penembusan plafon kredit pada `wms_pesanan_penjualan` (`plafon_dilampaui`, `alasan_plafon`, `plafon_disetujui_oleh`, `eksposur_saat_dibuat`)

Urutan transfer standar adalah `DRAFT -> DIAJUKAN -> DIKIRIM -> DITERIMA`. Urutan receiving adalah `RECEIVED -> QC_COMPLETED/QC_COMPLETED_WITH_HOLD -> PUTAWAY`. Dokumen posted tidak boleh diedit; gunakan workflow reversal atau void sumber transaksi.

Sejak 2026-09-13, QC boleh dijalankan **dua kali**: pemeriksaan awal dari `RECEIVED`, dan pemeriksaan lanjutan dari `QC_COMPLETED_WITH_HOLD` yang wajib menyebutkan baris mana yang diperiksa ulang. Baris yang stoknya masih QC hold ditolak dan diarahkan ke Pemeriksaan Consider, bukan ke QC ulang.

Gelombang pengambilan memakai urutan `DIRENCANAKAN -> DIRILIS -> SELESAI` (atau `DIBATALKAN`, yang melepas kembali perintah pengambilannya). Perakitan kit memakai `DRAFT -> POSTED`, dengan jenis `RAKIT` atau `URAI`.

## 14. Next development yang masuk akal

Berdasarkan "Open-items index" di `CLAUDE.md` (paling relevan per 1 Oktober 2026). **Tujuh baris kebijakan yang menggantung sejak September sudah dikerjakan semua pada 2026-10-01**: akun lawan landed cost, plafon kredit, uang muka pelanggan, pengecualian mapping kategori jasa, serapan tenaga kerja dan overhead, ember "hilang di jalan", dan pengiriman subkontrak.

1. **Pemetaan elemen XML DJP.** Datanya, halamannya, dan ekspornya sudah ada, tapi **nama elemen XML-nya milik repo ini, bukan DJP**. Cocokkan dengan `Sample Faktur PK Template v.1.4.xml` dan `ConverterEfakturCoretax v1.6` dari laman "Template XML dan Converter Excel ke XML" DJP, baru cabut banner peringatan di halamannya. **Jangan dipakai lapor sebelum itu.** e-Bupot Unifikasi (BPPU/BPNR) dan lampiran SPT ada di laman yang sama dan belum diekspor. Catatan penting: sejak **1 Januari 2025 Coretax hanya menerima XML**; jalur CSV e-Faktur lama sudah tidak berlaku, jadi rencana lama yang menyebut "e-Faktur CSV" memang sudah usang.
2. **NITKU.** Coretax memvalidasi NITKU berdampingan dengan NPWP. Kunci config `app.nitku_perusahaan` sudah ada dan masih kosong; belum ditulis ke ekspor karena nama elemennya belum terverifikasi.
3. **Biaya subkontrak belum mengalir ke WIP.** `SubkontrakService` memindahkan barang dan mencatat kustodi; tagihan jasa vendornya masih lewat jalur pesanan jasa biasa. Menautkan pengiriman subkontrak ke perintah kerjanya akan membuat biaya itu mendarat di job yang benar.
4. **Selisih tenaga kerja.** Jam diserap dengan tarif standar, routing menyimpan waktu standar per operasi, tapi belum ada yang membandingkan keduanya — tahap lanjutan yang sama seperti BOM bagi material.
5. **Halaman saldo uang muka pelanggan.** `UangMukaPelanggan` tampil pada faktur yang bisa dilunasinya dan di buku besar; daftar per pelanggan dengan umur saldo akan memudahkan penagihan sisa yang belum terpakai.
6. **Multi-currency penuh** — revaluasi monetary item sudah ada sejak 2026-09-18; yang belum adalah penyajian laba/rugi selisih kurs terealisasi per transaksi.
7. **Barcode/QR** untuk putaway/picking — **ditunda pemilik produk pada 2026-09-13**. Catatan desain yang harus diingat: QR-nya **per bundling/kit, bukan per barang**.

Sudah selesai dan tidak perlu direncanakan lagi — **2026-09-13**: antrean kerja operator, re-inspeksi QC, cycle count berbasis ABC, auto-reservasi saat approval Material Request, dimensi/volume bin + slotting ABC, pembatasan gudang per operator, wave/batch picking, kitting/bundling. **2026-09-14**: kapitalisasi jasa ke aset (PSAK 16), gate pass barang keluar ke vendor, barang titipan vendor, tukar tambah aset, dan Laporan Perubahan Ekuitas. **2026-09-16**: Lacak Pembelian, Kartu Skor Supplier, CALK, maker-checker diperluas, dimensi gudang pada baris jurnal, seluruh sisi penjualan, dan perintah kerja produksi dengan Barang Dalam Proses. **2026-09-18**: persediaan dalam perjalanan (`1304`) dengan transfer dua fase, hapus buku perintah kerja yang dibatalkan (`5308`), umur piutang, kinerja sales, cross-docking, BOM dan varians pemakaian, routing produksi, serta revaluasi kurs PSAK 10. **2026-10-01**: akun lawan landed cost (`2111`), penegakan plafon kredit dengan penembusan Super Admin yang terekam, uang muka pelanggan (`2112`), pengecualian aturan mapping untuk kategori jasa, serapan tenaga kerja (`5203`) dan overhead (`5204`) ke WIP berbasis jam pusat kerja, ember "hilang di jalan" pada Lacak Pembelian, dan pengiriman persediaan untuk subkontrak lewat Gudang Subkontrak.

### Dicoret dari perencanaan

**Layer integrasi eksternal (API/EDI ke supplier, marketplace, atau ERP lain) dicoret pemilik produk pada 2026-09-16.** Dicatat di sini, bukan sekadar dihapus, supaya tidak ditemukan ulang sebagai celah oleh pembacaan kode berikutnya lalu dimasukkan lagi. Tidak adanya API publik adalah keadaan yang memang dikehendaki. Base Sanctum yang sudah ada tetap dibiarkan.

### Catatan terbuka yang ditemukan 2026-09-16

`UpdateAccountingMappingRequest` memvalidasi **semua** `KategoriBahan` dengan aturan kategori barang, padahal dua kategori jasa bawaan seeder tidak memenuhinya: "Jasa Operasional" memetakan `coa_persediaan_id` ke akun BEBAN, dan "Jasa Produksi" memetakan `coa_beban_id` ke akun ASET. Akibatnya form mapping tidak bisa disimpan untuk daftar kategori lengkap. Ini bukan bug baru — sudah ada sebelum pekerjaan maker-checker — dan perlu keputusan: kategori jasa dikecualikan dari aturan mapping barang, atau diberi akun yang sesuai.

## 15. Source of truth

Jika dokumentasi dan kode berbeda, gunakan urutan berikut:

1. migration untuk schema
2. policy untuk otorisasi
3. controller + form request untuk workflow
4. service untuk aturan stok dan akuntansi
5. model untuk helper dan relation
6. test untuk behavior guarantee
