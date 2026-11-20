# Database

Aturan skema, cara menulis migrasi, dan cara memastikan perubahan aman lewat `migrate:fresh --seed`.

MySQL, diakses lewat Eloquent. Sumber kebenaran bentuk data adalah **migrasi**, di atas policy, controller, service, model, dan test (lihat [governance](../architecture/development-governance.md#sumber-kebenaran)).

## Aturan skema (dijaga `ArchitectureConventionTest`)

| Aturan | Alasan |
|---|---|
| Tabel domain berawalan `wms_`; tabel framework tidak (`users`, `jobs`, `notifications`, `sessions`, `password_reset_tokens`, `personal_access_tokens`, `failed_jobs`, `migrations`); `user_roles` dibiarkan | mengganti nama tabel framework melawan konvensi paket tanpa nilai bisnis |
| Nama tabel jamak `snake_case`; foreign key baru `<model>_id` | |
| Status adalah kode mesin huruf besar (`PENDING`, `APPROVED`, `POSTED`, `VOID`) | label Indonesia hanya di lapisan tampilan |
| Tanpa `double` dan float: kuantitas `DECIMAL(18,6)`, biaya satuan `DECIMAL(18,4)`, uang `DECIMAL(18,2)`, tarif `DECIMAL(8,4)` | pembulatan biner merusak jumlah uang |
| Tanpa nama tabel lama (`admin_namagudang`, `invoicelpbs`, `invoicelpbdetails`, `lpbdetails`, `kategoribahan`) | |
| Kolom umumnya tidak diterjemahkan; ganti nama kolom hanya bila menyematkan singkatan entitas yang sudah diganti | menerjemahkan semua kolom menyentuh setiap akses Eloquent, echo Blade, kunci Yajra, seeder, dan `row.xxx` JS |
| **Jangan mengganti nama kolom yang dikirim endpoint Yajra ke JS** | UI rusak diam-diam, test tidak menangkapnya (trap 6) |

Satu fakta penting: 21 tabel domain lama tidak berawalan `wms_` (diukur 2026-09-18). Tabel baru tetap `wms_`; menamai ulang yang lama menunggu keputusan ([KEP-009](../backlog/keputusan-menunggu.md#kep-009-prefix-wms_-untuk-21-tabel-lama)).

## Menulis migrasi

1. Nama file: `database/migrations/YYYY_MM_DD_NNNNNN_<deskripsi>.php`, urut menurut tanggal.
2. Pakai tipe tetap untuk uang, kuantitas, dan tarif (tabel di atas).
3. **Nama index dan unique maksimal 64 karakter.** Awalan `wms_` plus nama otomatis mudah melewatinya (pernah terjadi dua kali). Bila `migrate:fresh` melapor "Identifier name … is too long", beri nama pendek eksplisit sebagai argumen kedua `unique()`/`index()`.
4. **`down()` yang memasang kembali sesuatu yang dibuang `up()` adalah bau, bukan simetri**: baca sebagai pertanyaan apakah pembuangan itu memang disengaja. Foreign key `wms_pembayaran_faktur.finance_user_id` yang dibuang diam-diam membiarkan seeder menulis user id yang tidak pernah ada (trap 12).
5. Migrasi yang sudah diterapkan di tempat lain tidak diedit; buat migrasi baru.
6. Akun COA baru lewat migrasi **menolak bila kode sudah dipakai nama lain** (contoh `2026_10_01_000001`), karena seeder `updateOrCreate(['kode_akun' => …])` akan mengganti nama akun hidup tanpa galat (trap 4).
7. Migrasi yang menormalisasi data lama harus aman: perbaiki hanya bila hasilnya valid, dan hentikan dengan daftar bila ada duplikat (contoh normalisasi NPWP dan unik NSFP).
8. Hindari kolom `*_id` tanpa foreign key tanpa alasan. Pemeriksaan yatim di audit hanya melihat kolom **yang punya** constraint, jadi yang tanpa constraint bersembunyi.

## Model: `$fillable` atau `$guarded`

Penugasan massal membuang field **secara diam-diam**: tidak ada galat, kolom saja tidak terisi dan default database yang menang. Sudah terjadi empat kali (`users.is_active`, `wms_stock_opname.jenis`, `wms_pembayaran_faktur.supplier_id`, `wms_jurnal_detail.gudang_id`).

- Model di repo ini terbagi: sebagian `$guarded = []`, sebagian `$fillable` sungguhan (53 vs 40 dari 94 pada 2026-09-18). **Cek yang mana sebelum menambah kolom** dan tambahkan kolom ke `$fillable` bila itu yang dipakai.
- Bila sebuah field tidak boleh datang dari input (flag status akun, waktu posting), sengaja **keluarkan** dari `$fillable` dan tulis dengan `forceFill()->save()` (pola `UserAccountService`).
- Pintu kecuali: factory (`Model::unguarded()`) dan `Model::where()->update([...])` melewati penjagaan. Itu sebabnya test bisa mengisi field yang tidak bisa diisi kode produksi.
- Jangan mengetatkan `$guarded = []` menjadi `['id']`: dengan `$fillable` kosong Eloquent membaca skema pada setiap `fill()`, menambah round-trip dan merusak unit test yang membuat model tanpa framework. Sudah dicoba dan dibatalkan.

Setiap `hasMany`/`hasOne` harus menyebut foreign key secara eksplisit; Eloquent menurunkannya dari **nama kelas pendeklarasi**, jadi mengganti nama kelas diam-diam mengarahkan ulang relasi (`ArchitectureConventionTest` menjaganya).

## Nama tabel dalam string

`DB::table('x')`, `join()`, `from()`, `$table`, dan aturan `exists:`/`unique:` menulis nama tabel sebagai string, sehingga rename tidak menyentuhnya. `ArchitectureConventionTest::test_every_table_name_written_as_a_string_actually_exists` kini memeriksa semuanya; baris yang salah baru ketahuan saat dijalankan (revisi PO tercetak melempar "Base table not found" selama ini).

## Memverifikasi dengan `migrate:fresh --seed`

Setelah mengubah migrasi atau seeder, jalankan terhadap database **buangan**, bukan database yang datanya masih kamu butuhkan:

```sh
php artisan migrate:fresh --seed
```

- Seeder memakai `updateOrCreate`/`firstOrCreate` dan membungkus tiap skenario demo sehingga skenario yang tidak bisa jalan hanya memperingatkan. Jangan melepas pembungkus itu.
- Urutan seeder penting (lihat `DatabaseSeeder`): `PenjualanDanProduksiDemoSeeder` berjalan sesudah `syncMultiWarehouseDemoData()` karena `StokGudang` baru terisi di sana; skenario baru ditaruh di akhir dengan kuantitas kecil karena data demo sengaja tipis (tiga bahan berstok).
- Seeder mencari pengguna lewat **role**, bukan email, dan tidak memakai id tetap.
- `KekayaanDataDemoTest` menjaga dataset demo tidak menipis dan invarian tetap valid; jalankan setelah mengubah seeder.

## Mereset data dev

Data di database `inventory` dev boleh dimigrasi dan direset bebas (bukan produksi). Mengosongkan tidak merugikan, tetapi pastikan `DB_DATABASE` menunjuk database dev yang benar sebelum `migrate:fresh`.

## Penomoran dokumen

Nomor dialokasikan saat dokumen **disimpan** (`DocumentNumberService::external/financial/internal`), bukan saat form dirender. Form menampilkan `preview()`. Rinciannya di [membuat alur dokumen](creating-a-document-flow.md#2-penomoran).
