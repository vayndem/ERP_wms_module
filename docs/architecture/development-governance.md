# Governance pengembangan

Dari mana aturan berasal, di mana keputusan dicatat, dan dokumen apa yang ikut berubah saat kode berubah.

## Sumber kebenaran

Kalau dua sumber bertentangan, yang lebih atas menang. Urutan teknis menurut [`feed.MD` bagian 15](../../feed.MD) dan [`CLAUDE.md`](../../CLAUDE.md):

1. **Migrasi**: bentuk data dan batasan yang benar-benar ada di database.
2. **Policy**: siapa boleh melakukan apa, dan di keadaan dokumen apa.
3. **Controller + Form Request**: apa yang diterima dari luar dan bagaimana dijawab.
4. **Service**: aturan proses (posting, FIFO, pembalikan).
5. **Model**: relasi, cast, helper.
6. **Test**: bukti perilaku; test yang bertentangan dengan 1–5 biasanya yang usang.
7. Dokumen: `CLAUDE.md`, `feed.MD`, README, dan `docs/`.

Di atas semuanya ada **keputusan produk yang sudah diambil user**, dicatat di `CLAUDE.md` bagian "Decisions the user has already made": mengubahnya adalah percakapan baru, bukan refactor.

Bila dokumen dan kode berbeda, kode yang benar dan dokumen diperbaiki di perubahan yang sama. Contoh nyata (2026-11-20): bagian "Open-items index" `CLAUDE.md` menyebut plafon kredit, uang muka pelanggan, akun landed cost, dan serapan tenaga kerja sebagai belum dibangun padahal migrasi dan test untuk semuanya ada; ia juga menyebut CI di `.github/workflows/ci.yml` yang tidak pernah ada, dan `ExampleTest` yang memakai `RefreshDatabase` padahal barisnya dikomentari. Ketiganya dikoreksi; lihat [backlog](../backlog/README.md#sudah-diputuskan-dan-dibangun).

## Pembagian peran dokumen

| Dokumen | Untuk siapa | Berisi |
|---|---|---|
| `CLAUDE.md` dan `rules/` | agen kode | aturan umum, aturan keras per lapisan (`rules/backend-api.md`, `rules/frontend-ui.md`), jebakan, keputusan user, ringkasan fitur yang tampak salah bila hanya membaca kode |
| `CONTRIBUTING.md` (root) | pengembang dan agen | cara kerja harian: sebelum mulai, selama mengerjakan, gerbang sebelum commit, branch dan commit, definition of done |
| `feed.MD` | pengembang | referensi teknis: role matrix, alur domain, tabel, utang teknis |
| `README.md` (root) | semua orang | gambaran produk, modul, role, menjalankan, deployment |
| `docs/` | manusia yang mengerjakan atau meninjau | panduan, arsitektur ringkas dengan tautan, backlog, template |

`docs/` tidak menyalin `CLAUDE.md`. Ia meringkas, menaut, dan menjelaskan mengapa. Satu fakta ditulis di satu tempat, yang lain menaut. Dua dokumen di `docs/architecture/` adalah arsip yang dipindahkan dari `CLAUDE.md` supaya file itu tetap bisa dibaca penuh oleh agen: alasan lengkap per fitur di [catatan fitur](catatan-fitur.md), pengukuran dan riwayat di [utang terukur dan riwayat](utang-terukur-dan-riwayat.md).

## Tempat keputusan dicatat

Proyek ini tidak memakai ADR. Keputusannya tinggal di dua tempat:

| Keputusan | Tempat |
|---|---|
| **Sudah diambil** user: kebijakan akuntansi dan produk (siklus opname, scoping gudang, biaya produksi, output langsung ke pelanggan, maker-checker, integrasi eksternal dibatalkan, …) | `CLAUDE.md` bagian "Decisions the user has already made" dan "Dropped from the plan", beserta alasannya di catatan fitur |
| **Menunggu** user: kebijakan yang tidak boleh diputuskan engineer | [`backlog/keputusan-menunggu.md`](../backlog/keputusan-menunggu.md), disalin dari [template](../templates/template-keputusan-menunggu.md) |

Aturan praktisnya:

- Pertanyaan akuntansi atau pajak yang jawabannya mengubah jurnal, tarif, atau perilaku posting adalah keputusan produk. Tulis di keputusan menunggu dan jangan putuskan sendiri.
- Begitu diputuskan: pindahkan ke "Decisions the user has already made" dengan tanggal, hapus dari keputusan menunggu, dan ubah status tugas terkait di [backlog](../backlog/README.md).
- Yang dibatalkan dicatat di "Dropped from the plan" agar tidak ditemukan ulang dan dibangun lagi.
- Keputusan teknis kecil yang mengikuti pola yang ada cukup dicatat di catatan fitur `CLAUDE.md` bila alasannya tidak terlihat dari kode.

## Mengubah aturan penjaga

Aturan arsitektur ada di `ArchitectureConventionTest` dan jaring lain ([jaring pengaman](jaring-pengaman-test.md)).

1. Jelaskan alasan perubahan aturan di catatan fitur `CLAUDE.md`.
2. Ubah tes, dan pastikan ada contoh yang **gagal** saat aturan dilanggar, bukan hanya yang lolos. Cara memeriksa tes tidak kosong: balikkan perbaikannya dan pastikan tes memerah.
3. Perbarui [peta lapisan](module-map.md) dan tabel di `CLAUDE.md`.
4. Jalankan `composer test:case-sensitive`.

Melemahkan aturan agar kode yang melanggar lolos tidak boleh dilakukan tanpa keputusan user.

## Dokumentasi ikut berubah

| Perubahan | Dokumen yang ikut diperbarui |
|---|---|
| Migrasi atau kolom baru | [database](../guides/database.md) bila caranya berubah; `feed.MD` bagian tabel; `$fillable`/`$guarded` modelnya |
| Variabel lingkungan baru | `.env.example`, [konfigurasi](../guides/configuration.md) |
| Dokumen yang memposting jurnal atau stok | [akuntansi dan jurnal](akuntansi-dan-jurnal.md) atau [persediaan dan FIFO](persediaan-dan-fifo.md); invarian baru bila ada subledger baru |
| Akun COA atau kunci mapping baru | konstanta dan whitelist `AccountingSetting::accountId()`, [akuntansi dan jurnal](akuntansi-dan-jurnal.md) |
| File route baru | daftar wajib di `ArchitectureConventionTest`, [peta lapisan](module-map.md) |
| Role atau gate baru | [otorisasi dan autentikasi](otorisasi-dan-autentikasi.md), `AksesPerRoleTest`, `feed.MD` role matrix |
| Halaman baru | [menambah halaman](../guides/adding-a-page.md) bila ada pola baru; sidebar; `AksesPerRoleTest` |
| Perubahan pajak | [pajak Indonesia](pajak-indonesia.md); label verifikasi fakta regulasi |
| Tes jaring baru atau diubah | [jaring pengaman test](jaring-pengaman-test.md) |
| Tugas selesai atau dimulai | status di file area [backlog](../backlog/README.md) dan hitungan di tabel Area (hitung dengan `grep`) |
| Keputusan produk diambil | `CLAUDE.md` "Decisions…", hapus dari [keputusan menunggu](../backlog/keputusan-menunggu.md) |
| Masalah lingkungan baru ditemukan | [troubleshooting](../guides/troubleshooting.md) |
| Status besar area berubah | [roadmap](roadmap.md) |

## Bahasa

- **Dokumen untuk manusia** (`docs/`, README root) dalam bahasa Indonesia; `feed.MD` dwibahasa Indonesia/Inggris; `CLAUDE.md` dan `rules/` bahasa Inggris karena ditulis untuk agen; `CONTRIBUTING.md` bahasa Indonesia. Pengecualian: [catatan fitur](catatan-fitur.md) dan [utang terukur dan riwayat](utang-terukur-dan-riwayat.md) dipindahkan dari `CLAUDE.md` apa adanya dan tetap berbahasa Inggris sampai bagian itu disentuh. Istilah teknis yang lazim dan semua nama kode (kelas, tabel, route, perintah) tidak diterjemahkan.
- **Identifier kode** mengikuti konvensi repo: sufiks kelas Inggris (`Controller`, `Service`, `Request`, `Policy`), kata benda domain Indonesia (`PenerimaanBarang`, `FakturPenjualan`, `BaganAkun`). Nama tabel `wms_` + Indonesia, kolom status uppercase.
- **Komentar penjelasan di dalam kode tidak ditulis.** Ini aturan user: kode saja, tanpa noise. Alasan sebuah keputusan ditulis di `CLAUDE.md` atau di dokumen ini, bukan di sebelah barisnya. Contoh kode dalam dokumen juga tanpa komentar sepele.
- **Teks untuk pengguna aplikasi** (label, pesan galat, flash) bahasa Indonesia, hanya di lapisan tampilan.
- **Pesan commit** mengikuti kebiasaan repo; user yang mengurus git (jangan `add`/`commit`/`push` tanpa diminta di giliran itu).
