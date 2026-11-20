# Menambah halaman

Checklist membuat view baru: token tema, responsif, tabel, gate di sidebar, dan test akses per role.

Aturan latarnya ada di [frontend dan tema](../architecture/frontend-dan-tema.md). Halaman ini urutan kerjanya.

## Langkah

### 1. Route, controller, otorisasi

- Route di file domain yang tepat (`routes/warehouse.php`, `sales.php`, dst.), bernama (`xxx.index`), dan bisa di-`route:cache`.
- Controller memanggil `$this->authorize(...)` atau middleware policy. Halaman baca tidak menulis apa pun: `HalamanTanpaEfekSampingTest` gagal bila GET memicu `insert`/`update`/`delete`. Jangan memanggil `StokGudangService::saldo()` dari jalur baca.
- Form Request untuk setiap form, dengan `authorize()` atau diotorisasi controller (`ArchitectureConventionTest` memeriksanya).
- Form buat: tampilkan nomor berikutnya dengan `DocumentNumberService::preview()`, bukan metode yang mengalokasikan ([membuat alur dokumen](creating-a-document-flow.md#2-penomoran)).

### 2. View

- Taruh di `resources/views/<domain>/` dengan nama snake_case, extends `layouts/app`. Halaman login dan reset memakai `layouts/guest`.
- **Warna hanya dari token daisyUI**: `base-100` (kartu), `base-200` (halaman), `base-300` (batas), `base-content` (teks), `primary` dan kawan-kawan. Tidak `bg-white`, `text-gray-*`, `bg-slate-*`, tidak `style="color:…"`, tidak `[#hex]`.
- Tidak memaksa warna isi pada komponen bertema (`badge text-white`).
- Tanpa jQuery dan Bootstrap. Interaksi dengan Alpine.
- Blok Blade `@php … @endphp` untuk lebih dari penugasan sepele; jangan `@php(…)` panjang.
- Tampilkan keadaan kosong secara eksplisit (daftar kosong harus berkata sesuatu; `KualitasTampilanTest` memeriksanya).
- Bungkus setiap tabel lebar dengan `overflow-x-auto`.

### 3. Tabel dan interaksi

| Kebutuhan | Pakai |
|---|---|
| Daftar server-side dengan cari/sort | endpoint Yajra `datatables()->of(...)` dan `wmsDataTable()` dengan baris `<template x-for>`; **jangan mengganti nama kolom Yajra** yang dibaca JS |
| Daftar sederhana | tabel Blade dengan paginasi (pola sisi penjualan) |
| Form dalam modal | `<dialog class="modal">` + `.modal-box`; `openAjaxModal(url)`, `closeAjaxModal()` |
| Simpan/hapus CRUD sederhana | `submitAjaxForm($event)`, `confirmAjaxDelete($event, pesan)`; jangan menulis handler `fetch` baru. Bila memang memakai `fetch`, panggil `handleSessionExpired(response)` |
| `<select>` yang bisa dicari | atribut `data-app-picker` |
| Grafik | Chart.js lewat pola `task-chart.js`, yang membaca token tema dan memasang ulang saat `inventory:theme-changed` |

### 4. Responsif

Wajib, bukan hiasan. Pakai `sm:`/`md:`/`lg:` pada grid dan flex, hindari satu tata letak multi-kolom tetap, dan **ubah ukuran browser** (atau mode responsif devtools) sebelum menyatakan selesai. `.modal-box` sudah menggulir; jangan menimpanya dengan tinggi tetap atau `overflow-hidden`.

### 5. Menu (sidebar)

Tambahkan di `resources/views/layouts/template/sidebar.blade.php` dengan `@can(...)` yang **sama** dengan yang dipakai controller. Jangan menaruh dalam seksi yang dibatasi role lain (menu penjualan pernah terkubur di seksi khusus Akuntansi) dan jangan menulis cek role mentah yang menduplikasi gate.

### 6. Test akses per role

Tambahkan nama route ke kontrak di `tests/Feature/AksesPerRoleTest.php` (`kontrakAkses()`): daftar yang **harus bisa** dibuka dan yang **harus ditolak** per role. Super Admin lolos semuanya lewat `Gate::before`, jadi smoke test tidak mendeteksi masalah akses; tes ini satu-satunya yang melihatnya.

Jaring otomatis yang akan menangkap kesalahan umum (tidak perlu menulis ulang): `SmokeSemuaHalamanTest` (5xx), `KualitasTampilanTest` (kompilasi Blade, warna, kosong, gulir), `KonsistensiTemaTest` (warna di sumber), `HalamanTanpaEfekSampingTest`, `TanpaNPlusSatuTest` (eager loading di jalur baca).

Bila halaman perlu nilai parameter yang tidak bisa diselesaikan smoke test, perluas pemetaan `nilaiParameter()` di test itu; ada batas jumlah route yang boleh dilewati.

### 7. Periksa

- `npm run build`, lalu `php artisan test --filter=KualitasTampilanTest`, `KonsistensiTemaTest`, `AksesPerRoleTest`, dan test fitur Anda ([testing](testing.md)).
- Buka di mode terang **dan** gelap, di lebar ponsel dan desktop.
- Perbarui [peta lapisan](../architecture/module-map.md) bila menambah domain, dan [backlog](../backlog/README.md) bila menutup tugas.
