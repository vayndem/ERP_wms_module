# Indeks dokumentasi

Mulai dari [README root](../README.md) untuk gambaran produk dan cara menjalankan. Halaman ini mendaftar semua dokumen di `docs/` beserta kapan dokumen itu dibutuhkan.

`docs/` untuk manusia. [`CLAUDE.md`](../CLAUDE.md) (beserta [`rules/backend-api.md`](../rules/backend-api.md) dan [`rules/frontend-ui.md`](../rules/frontend-ui.md)) adalah instruksi untuk agen dan tetap sumber utama aturan kode; [`CONTRIBUTING.md`](../CONTRIBUTING.md) ("Cara kerja") adalah aturan harian untuk setiap perubahan; [`feed.MD`](../feed.MD) adalah referensi teknis (role matrix, alur, tabel). Dokumen di sini meringkas dan menautkan keduanya, dan menjelaskan **mengapa**, bukan menyalin. Kalau dokumen dan kode berbeda, kode yang benar: urutannya ada di [governance](architecture/development-governance.md#sumber-kebenaran).

ERP - Modul WMS adalah modular monolith Laravel 12 (PHP 8.2+, MySQL) yang menyambungkan gudang, pembelian, penjualan, keuangan, dan akuntansi dalam satu alur terkendali: beli (permintaan → PO → LPB → stok multi-gudang → NPK → faktur supplier → pembayaran → jurnal) dan jual (pelanggan → pesanan → surat jalan → faktur penjualan → penerimaan pembayaran).

## Panduan (cara mengerjakan sesuatu)

| Panduan | Baca ketika kamu… |
|---|---|
| [Development lokal](guides/local-development.md) | menyiapkan komputer, menjalankan aplikasi, queue worker, scheduler, dan mematikan proses lama di Windows |
| [Testing](guides/testing.md) | menjalankan atau menulis test: letak tiap jenis test, database yang dipakai, apa yang wajib dites, `composer test:case-sensitive` |
| [Database](guides/database.md) | menambah tabel atau kolom, menulis migrasi, memilih `$fillable` atau `$guarded`, mereset data dev |
| [Konfigurasi](guides/configuration.md) | menambah atau mengubah variabel lingkungan (`.env`) |
| [Membuat alur dokumen](guides/creating-a-document-flow.md) | menambah dokumen yang memposting jurnal atau menggerakkan stok: service, transaksi, penomoran, dimensi gudang, invarian, test lewat route |
| [Menambah halaman](guides/adding-a-page.md) | membuat view baru: token tema, responsif, tabel Yajra atau paginasi, gate di sidebar, test akses per role |
| [Troubleshooting](guides/troubleshooting.md) | ada yang gagal: PHP lama menolak boot, manifest Vite hilang, test 500 massal, tabel tidak ada, index terlalu panjang, CRLF |

## Arsitektur (bagaimana dibangun dan kenapa)

| Dokumen | Isi |
|---|---|
| [Gambaran umum](architecture/overview.md) | komponen, alur beli dan jual, apa disimpan di mana, apa yang terjadi saat ada yang gagal |
| [Peta lapisan dan domain](architecture/module-map.md) | file route per domain, controller, service, model, siapa boleh memanggil siapa, dijaga `ArchitectureConventionTest` |
| [Governance](architecture/development-governance.md) | sumber kebenaran, tempat keputusan dicatat, dokumentasi yang ikut berubah, bahasa |
| [Roadmap](architecture/roadmap.md) | status besar per area dengan tanda status |
| [Akuntansi dan jurnal](architecture/akuntansi-dan-jurnal.md) | posting jurnal, `REVERSED`, dimensi gudang, mapping akun, delapan invarian, period lock, maker-checker |
| [Persediaan dan FIFO](architecture/persediaan-dan-fifo.md) | layer, FIFO/FEFO per gudang, `StokGudangService`, transfer dua fase dan akun 1304, reservasi, nomor mutasi |
| [Otorisasi dan autentikasi](architecture/otorisasi-dan-autentikasi.md) | tujuh role, `Gate::before`, policy, scoping gudang, alur login, Sanctum, sesi habis |
| [Pajak Indonesia](architecture/pajak-indonesia.md) | yang sudah dibangun, yang belum, dan fakta regulasi dengan status verifikasinya |
| [Frontend dan tema](architecture/frontend-dan-tema.md) | Tailwind + daisyUI + Alpine, dua tema, aturan tanpa warna hardcode, layout guest |
| [Jaring pengaman test](architecture/jaring-pengaman-test.md) | tiap test jaring menangkap apa dan tidak menangkap apa |
| [Catatan fitur](architecture/catatan-fitur.md) | alasan di balik keputusan yang tampak salah bila hanya membaca kode, dipindahkan dari `CLAUDE.md` (bahasa Inggris, dengan koreksi 2026-11-20) |
| [Utang terukur dan riwayat](architecture/utang-terukur-dan-riwayat.md) | pengukuran utang dan cakupan, audit A–Z, riwayat rename dan upgrade, dipindahkan dari `CLAUDE.md` |

## Backlog (yang belum dikerjakan dan keputusan yang menunggu)

| Dokumen | Isi |
|---|---|
| [Backlog](backlog/README.md) | legenda status, tabel area dengan hitungan, cara membaca |
| [Keputusan menunggu](backlog/keputusan-menunggu.md) | keputusan produk yang belum diambil: konteks, pilihan, dampak |
| [Audit algoritma 2026-11](backlog/alg-audit-algoritma.md) | temuan audit backend yang sudah diperbaiki dan yang belum, diverifikasi ulang di kode |
| [Peta jalan pajak](backlog/pjk-pajak.md) | urutan pekerjaan pajak berikutnya dan prasyaratnya |

## Referensi

| Dokumen | Isi |
|---|---|
| [Glossary](glossary.md) | istilah bisnis, akuntansi, gudang, pajak Indonesia, dan teknis |
| [Template modul](templates/module-readme.md) | salin untuk mendokumentasikan satu domain di `app/Services` |
| [Template fitur](templates/template-fitur.md) | salin sebelum membangun fitur baru |
| [Template bug](templates/template-bug.md) | salin saat mencatat bug dan perbaikannya |
| [Template keputusan menunggu](templates/template-keputusan-menunggu.md) | salin saat ada keputusan produk yang harus ditanyakan ke pemilik |
| [Template panduan](templates/template-guide.md) | salin untuk panduan baru |
| [`CONTRIBUTING.md`](../CONTRIBUTING.md) | cara kerja: sebelum mulai, selama mengerjakan, gerbang sebelum commit, branch dan commit, definition of done |
| [`CLAUDE.md`](../CLAUDE.md) | aturan umum untuk agen, jebakan yang sudah terjadi, keputusan user, ringkasan fitur, open-items |
| [`rules/backend-api.md`](../rules/backend-api.md), [`rules/frontend-ui.md`](../rules/frontend-ui.md) | aturan keras per lapisan: Laravel dan frontend (Tailwind, daisyUI, tema, responsif) |
| [`feed.MD`](../feed.MD) | referensi teknis; bagian 15 menentukan sumber kebenaran |
| [README root](../README.md) | gambaran produk, modul aktif, role, akun development, deployment |

## Menulis dokumentasi

- Bahasa dokumen: Indonesia. Nama kelas, tabel, route, dan perintah ditulis persis seperti di kode. Lihat [governance](architecture/development-governance.md#bahasa).
- Tulis **mengapa**. Yang dijawab `grep` lebih baik tidak ditulis.
- Angka dan hasil ukur ditulis bersama tanggal pengukuran dan perintahnya, bukan sebagai klaim tetap.
- Tabel hanya untuk perbandingan dan daftar yang memang tabular; selebihnya paragraf pendek dan daftar.
- Perbarui dokumen di perubahan yang sama dengan kodenya: tabel "dokumentasi ikut berubah" ada di [governance](architecture/development-governance.md#dokumentasi-ikut-berubah).
- Jangan menulis rahasia (password, token, isi `.env`) dan jangan menulis data pribadi.
