# Frontend dan tema

Bagaimana antarmuka dibangun (Tailwind, daisyUI, Alpine), mengapa warna tidak pernah ditulis di view, dan pola yang dipakai ulang.

Antarmuka dipindahkan penuh dari Bootstrap 5, jQuery, dan DataTables.js ke Tailwind + daisyUI + Alpine (2026-09-01). Tidak ada jQuery atau Bootstrap JS di kodebase, dan tidak boleh kembali. Panduan langkah per langkah untuk halaman baru ada di [menambah halaman](../guides/adding-a-page.md).

## Tumpukan

| Lapisan | Dipakai | Letak |
|---|---|---|
| CSS | Tailwind 3 + daisyUI 4, dibundel Vite | `resources/css/app.css`, `tailwind.config.js` |
| Interaktivitas | Alpine.js 3 | `resources/js/app.js` dan modul di sebelahnya |
| Grafik | Chart.js 4 | `resources/js/task-chart.js` |
| Pop-up konfirmasi | SweetAlert2 | `resources/js/alert.js`, kulit ulang di `app.css` |
| Build | Vite 8 dengan `laravel-vite-plugin` | `vite.config.js`; hasil di `public/build` (tidak di-commit) |

Versi mengikuti `package.json`; bukan klaim tetap.

`npm run build` wajib sebelum menjalankan test: tanpa `public/build/manifest.json`, semua halaman yang memakai layout melempar `ViteManifestNotFoundException` dan ratusan test gagal tanpa cacat kode ([troubleshooting](../guides/troubleshooting.md)).

## Dua tema, tanpa warna hardcode

`tailwind.config.js` mendefinisikan tepat dua tema daisyUI: `wms` (terang) dan `wms-dark`. Tema aktif ditulis sebagai `data-theme` di `<html>` oleh skrip inline di `<head>` (`layouts/app.blade.php` dan `layouts/guest.blade.php`), **sebelum CSS dimuat**, sehingga pengguna mode gelap tidak melihat kilatan putih. `app-shell.js` hanya menjaga tombol dan `localStorage` (kunci `inventory-theme`) tetap sinkron dan memicu `inventory:theme-changed`.

Aturannya:

- **Jangan menulis warna literal di view.** Tidak `bg-white`, `text-gray-500`, `bg-slate-800`, tidak `style="color:#…"`. Pakai token daisyUI: `base-100` (permukaan), `base-200` (halaman), `base-300` (batas), `base-content` (teks), `primary`/`primary-content` dan kawan-kawan.
- **Jangan memaksa warna isi pada komponen bertema.** `badge text-white` terlihat benar di terang dan tak terbaca di gelap saat `badge-warning` memakai kuning terang dengan isi gelap. daisyUI sudah memilih warna isi per varian.
- **Tangga elevasi harus terbaca sama di kedua tema.** Terang: halaman `base-200`, kartu `base-100` di atasnya. Gelap: halaman lebih gelap dari kartu, sidebar (`neutral`) berbeda dari keduanya. Dulu gelap terbalik dan `base-100` identik dengan `neutral`, sehingga batas sidebar dan kartu hilang. `KonsistensiTemaTest` menjaga tangga dan kontras (WCAG).
- **Canvas tidak mewarisi kelas CSS.** `task-chart.js` membaca properti HSL daisyUI (`--bc`, `--n`, `--nc`, `--b1`) lewat `getComputedStyle` dan memasangnya ulang saat `inventory:theme-changed`. Tanpa itu grafik memakai abu-abu bawaan Chart.js yang tak terlihat di gelap.
- **SweetAlert2 membawa stylesheet terang sendiri**, jadi pop-up mengabaikan tema. Override di dasar `app.css` mengulitinya dari token; tombolnya tombol daisyUI sungguhan (`buttonsStyling: false` + `customClass`). Aturan itu **di luar `@layer components` dengan sengaja**: Tailwind membuang kelas khusus di dalam layer bila tidak menemukannya di konten yang dipindai, dan `swal2-*` hanya muncul di bundel JS vendor. Tandanya: keluaran `app.css` tidak membesar.
- **Pengecualian**: `resources/views/reports/**` dan `stock_opname/pdf` dicetak di kertas dan memang memakai warna literal; tes tidak membukanya karena melewati route `.pdf`/`.excel`/`report`.

## Pola yang dipakai ulang

| Kebutuhan | Pola | Letak |
|---|---|---|
| Tabel server-side | endpoint Yajra (`datatables()->of(...)`) dibaca factory Alpine `wmsDataTable(config)`; baris berupa `<template x-for>` Blade, bukan HTML dari server | `resources/js/data-table.js` |
| Tabel penjualan | tabel Blade biasa dengan paginasi (sengaja tidak Yajra); aturan tanpa jQuery tetap berlaku | `resources/views/pesanan_penjualan`, dll. |
| Modal | `<dialog class="modal">` + `.modal-box`; dibuka `openAjaxModal(url)`, ditutup `closeAjaxModal()` | `resources/js/ajax-modal.js` |
| Form CRUD sederhana | `submitAjaxForm($event)` dan `confirmAjaxDelete($event, pesan)`; tidak menulis handler AJAX baru per view | `ajax-modal.js` |
| `<select>` yang bisa dicari | atribut `data-app-picker`; ditingkatkan otomatis | `resources/js/smart-picker.js` |
| Notifikasi | lonceng dengan polling, memanggil `handleSessionExpired` | `notification-bell.js` |
| Autosave formulir | | `form-autosave.js` |
| Format angka | | `format.js`, `number-format.js` |

Kolom yang dikirim Yajra dibaca JS (`row.xxx`). Mengganti namanya merusak UI secara diam-diam dan `php artisan test` tidak akan menangkapnya karena PHPUnit tidak menjalankan template Alpine. Itu sebabnya `id_lpb`, `lpb_id`, dan `no_po` tidak pernah di-rename.

`.modal-box` memiliki `max-height` dan `overflow-y: auto` global di `app.css` supaya tombol footer form tinggi tetap terjangkau. Jangan menimpanya dengan tinggi tetap atau `overflow-hidden` per view.

## Layout

| Layout | Dipakai | Isi |
|---|---|---|
| `layouts/app.blade.php` | semua halaman setelah login | skrip tema, `<meta name="login-url">`, `@vite`, sidebar, navbar |
| `layouts/guest.blade.php` | login, lupa password, reset password | skrip tema yang sama, `@vite`, kartu tengah responsif; tanpa sidebar |
| `layouts/template/*` | potongan: sidebar, navbar, footer, lampiran, grid tugas, grafik metrik, daftar pengingat | di-include layout |
| `resources/views/partials` | komponen kecil bersama (mis. `identitas-pajak`) | |

Layout guest belum dilihat di Chrome atau Safari nyata dan tautan "Lupa password?" di mode gelap perlu dilihat mata ([backlog](../backlog/aut-autentikasi.md)).

## Responsif adalah syarat

Setiap view baru atau yang diubah harus diperiksa di lebar ponsel, bukan hanya desktop: awalan `sm:`/`md:`/`lg:` pada grid dan flex (bukan satu tata letak kolom tetap), tabel lebar di dalam `overflow-x-auto`, dan benar-benar mengubah ukuran browser atau memakai mode responsif devtools. Empat tampilan pernah punya tabel tak bisa digulir dan empat grid tak pernah menumpuk di ponsel; `KualitasTampilanTest` kini menangkap tabel tanpa pembungkus gulir.

## Jebakan Blade

- `@php($x = …)` dengan ekspresi panjang gagal dikompilasi **secara diam-diam**: Blade menulis `<?php` tanpa penutup dan halaman mati dengan galat parse di `@endforeach` jauh di bawah. Pakai blok `@php … @endphp` untuk apa pun yang lebih dari penugasan sepele. Galat parse yang menyebut direktif yang tidak kamu sentuh biasanya berarti direktif **sebelumnya** gagal. `KualitasTampilanTest::test_every_blade_view_compiles_to_valid_php` mengompilasi semua view.
- Pengganti massal berbasis kata (`\bPembelian\b`) merusak teks tampilan karena nama entitas juga kata biasa; periksa setiap hasil. Tes tidak menangkap string tampilan yang rusak.
- Cek role mentah di Blade menduplikasi gate. Pakai `@can('operateWarehouse')`, bukan `isWarehouseOperator() || isSuperAdmin()`.
