# Cara kerja

Halaman ini aturan harian untuk setiap perubahan di ERP - Modul WMS. Aturan kode untuk agen ada di [`CLAUDE.md`](CLAUDE.md) beserta [`rules/backend-api.md`](rules/backend-api.md) dan [`rules/frontend-ui.md`](rules/frontend-ui.md); indeks dokumen manusia ada di [`docs/README.md`](docs/README.md).

Repo ini dikerjakan satu pengembang yang mengurus git sendiri, dengan bantuan agen kode. Tidak ada CI: tidak ada folder `.github` dan tidak ada workflow di repo (diperiksa 2026-11-20). Gerbang lokal di bawah adalah satu-satunya gerbang.

## Sebelum mulai

1. Baca [README](README.md), [indeks dokumentasi](docs/README.md) dan [`CLAUDE.md`](CLAUDE.md), terutama bagian "Behavioural rules": jebakan di sana sudah memakan waktu debugging berkali-kali.
2. Cek [backlog](docs/backlog/README.md) untuk tugas yang menyangkut pekerjaanmu (ID seperti `ALG-017`), dan [keputusan menunggu](docs/backlog/keputusan-menunggu.md). **Jangan memutuskan sendiri keputusan produk yang masih terbuka**: pertanyaan akuntansi atau pajak yang jawabannya mengubah jurnal, tarif, atau perilaku posting adalah milik pemilik produk. Tulis di keputusan menunggu dan tanyakan.
3. Cek [keputusan yang sudah diambil](CLAUDE.md#decisions-the-user-has-already-made--do-not-re-litigate-these) dan daftar yang [dibatalkan](CLAUDE.md#dropped-from-the-plan). Mengubahnya adalah percakapan baru, bukan refactor.
4. Siapkan lingkungan ([development lokal](docs/guides/local-development.md)):
   - **PHP 8.2 ke atas.** Bila `php` di PATH lebih lama, panggil biner yang baru secara eksplisit untuk `artisan` dan `composer`.
   - `composer install`, `npm install`, `.env` dari `.env.example`, `php artisan key:generate`.
   - Database MySQL dengan skema dan seed (`php artisan migrate --seed`), **hanya di database development**.
   - `php artisan queue:work` dan `php artisan schedule:work` bila pekerjaanmu menyentuh notifikasi, pengingat, atau penyusutan.
5. Tentukan database untuk test sebelum menjalankan apa pun. `phpunit.xml` **tidak** menimpa `DB_DATABASE`, jadi suite berjalan terhadap database yang ditunjuk `.env`. Untuk memisahkannya lihat [testing](docs/guides/testing.md#memakai-database-terpisah-disarankan).
6. Kalau fitur menyentuh jurnal atau stok, baca [membuat alur dokumen](docs/guides/creating-a-document-flow.md); kalau menambah halaman, [menambah halaman](docs/guides/adding-a-page.md); kalau menyentuh tabel atau kolom, [database](docs/guides/database.md).

## Selama mengerjakan

- **Perubahan bedah.** Sentuh hanya yang diminta; setiap baris yang berubah harus bisa ditelusuri ke permintaan. Jangan merapikan kode di sebelahnya, dan jangan menghapus kode mati yang sudah ada sebelumnya; sebutkan saja.
- **Ikuti lapisan.** Validasi di Form Request, otorisasi di Policy atau Gate, proses lintas model di `app/Services`, controller hanya orkestrasi ([peta lapisan](docs/architecture/module-map.md)).
- **Posting wajib dalam transaksi.** Mutasi stok dan akuntansi dibungkus `DB::transaction()`, dan operasi yang rawan balapan memakai `lockForUpdate()`. Jalur tulis tidak dioptimalkan seperti jalur baca, dan jalur baca tidak pernah menulis.
- **Jangan mengedit dokumen terposting.** Koreksi lewat pembalikan atau void terkendali dengan jurnal pembalik.
- **Nomor dokumen dialokasikan saat simpan**, bukan saat form dirender.
- **Fitur lintas lapisan butuh test lewat route** (HTTP → service → ledger), bukan hanya test service. Test yang membuktikan perbaikan harus gagal bila perbaikannya dibalikkan.
- **Tanpa komentar penjelasan di dalam kode.** Alasan sebuah keputusan ditulis di `CLAUDE.md` atau `docs/`.
- **Tampilan:** tanpa warna hardcode (token daisyUI saja), responsif di lebar ponsel, dua tema ([frontend dan tema](docs/architecture/frontend-dan-tema.md)).
- **Jangan menyalin pola `RefreshDatabase` bawaan Laravel** ke test baru: trait itu menghapus isi database aktif. Gunakan `DatabaseTransactions`.
- Jangan pernah meng-commit rahasia (`.env`, token, kredensial) dan jangan menuliskannya ke dokumen.

## Sebelum commit

Gerbang lokal yang nyata (perintah diverifikasi dari `composer.json` dan `package.json`, 2026-11-20):

```sh
composer test:case-sensitive
npm run build
php artisan test
```

- `composer test:case-sensitive` menjalankan `composer dump-autoload --optimize --strict-psr --strict-ambiguous` lalu `ArchitectureConventionTest`. Bug huruf besar-kecil hanya muncul di Linux, jadi di Windows jalankan ini sebelum menyerahkan pekerjaan.
- `npm run build` wajib sebelum `php artisan test`. Tanpa `public/build/manifest.json` ratusan test gagal 500 tanpa cacat kode ([troubleshooting](docs/guides/troubleshooting.md)).
- `php artisan test` berjalan terhadap database `.env`. Arahkan `DB_DATABASE` ke database yang boleh disentuh dan sudah di-seed; jangan menjalankannya terhadap database yang isinya dibutuhkan seseorang. Satu kelas: `php artisan test --filter=NamaKelas`.
- Bila menyentuh view atau jalur baca: jalankan `TanpaNPlusSatuTest`, `KualitasTampilanTest` dan `KonsistensiTemaTest` secara eksplisit.
- Bila menambah halaman atau pembatasan role: baris di `AksesPerRoleTest`.
- Bila menyentuh jurnal atau stok: semua delapan invarian rekonsiliasi harus tetap valid.
- Bila menambah migrasi: coba dengan `php artisan migrate:fresh --seed` di database development.
- Periksa `git status` untuk file nyasar, dan pastikan file yang ditulis berakhiran baris LF.

Belum ada satu perintah yang menjalankan ketiganya, dan belum ada CI; keduanya hanya usulan di [PLT-007](docs/backlog/plt-platform-test.md), bukan praktik.

## Branch dan commit

Praktik nyata repo ini (dibaca dari `git log` dan `git branch`, 2026-11-20):

- 53 commit oleh satu pengembang, riwayat lurus tanpa merge commit, semuanya di `main` yang melacak `origin/main`. Tidak ada alur pull request.
- Subjek commit pendek dalam bahasa Inggris. Yang lama menjelaskan perubahan ("Add maker-checker approval gate for supplier invoices"); yang terbaru singkat ("Fix: All Again").
- Branch lokal selain `main`: `refactor/rename-baku-indonesia` (rename sweep; tidak pernah di-merge atau di-push, atas permintaan pengembang), `simpan/coa-transit`, dan `vayndem/*` (branch kerja yang dibuat Orca sebagai worktree).

**Aturan untuk agen kode:** agen **tidak menjalankan `git add`, `commit`, `push`, `stash`, `checkout` atau `reset`** kecuali pengembang memintanya pada giliran itu. Satu persetujuan hanya berlaku untuk satu tindakan. Pengembang yang mengurus git.

**Usulan, belum menjadi praktik** (pakai hanya setelah pengembang setuju):

- Branch per pekerjaan: `feature/<id-tugas>-ringkas`, `fix/…`, `chore/…`, dari `main`.
- Subjek commit yang menyebut apa dan kenapa, satu langkah per commit supaya mudah di-revert.
- Jangan force-push ke `main`.

## Definition of done

- [ ] Perilaku baru punya test: jalur sukses dan penolakan. Bug punya test yang gagal sebelum perbaikan dan lulus sesudahnya (dan gagal lagi bila perbaikan dibalikkan).
- [ ] Fitur HTTP → service → ledger punya minimal satu test lewat route; delapan invarian rekonsiliasi tetap valid.
- [ ] `composer test:case-sensitive`, `npm run build` dan `php artisan test` hijau terhadap database yang aman.
- [ ] Dokumentasi ikut berubah di perubahan yang sama sesuai [tabel dokumentasi ikut berubah](docs/architecture/development-governance.md#dokumentasi-ikut-berubah); jebakan atau keputusan baru dicatat di `CLAUDE.md`.
- [ ] Tugas di [backlog](docs/backlog/README.md) diperbarui statusnya, dan hitungan tabel Area dihitung ulang dengan `grep` ([cara](docs/backlog/README.md#memperbarui-hitungan)); [roadmap](docs/architecture/roadmap.md) ikut bila status besar bergeser.
- [ ] Migrasi baru sudah dicoba dengan `migrate:fresh --seed`; kolom baru dicek terhadap `$fillable`/`$guarded` modelnya; nama index dibuat pendek (batas 64 karakter).
- [ ] Variabel lingkungan baru ada di `.env.example` dan [konfigurasi](docs/guides/configuration.md).
- [ ] Tampilan diperiksa di lebar ponsel dan di kedua tema; tidak ada warna hardcode.
- [ ] Tidak ada komentar penjelasan di kode, tidak ada rahasia di file mana pun.
- [ ] Keputusan produk yang terbuka dicatat di [keputusan menunggu](docs/backlog/keputusan-menunggu.md), bukan diputuskan sendiri.
