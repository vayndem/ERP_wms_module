# Konfigurasi

Variabel lingkungan yang dibaca aplikasi, di mana dibaca, dan cara menambah yang baru.

Sumber nama variabel adalah `.env.example` dan `config/*.php`. **Nilai tidak ditulis di sini**, apalagi rahasia: `.env` tidak di-commit, dan rahasia produksi dikelola di luar repo. Dokumen ini tidak pernah memuat isi `.env`.

## Sumber nilai

| Lingkungan | Sumber |
|---|---|
| Lokal | `.env` di root (salin dari `.env.example`) |
| Test | `.env` ditimpa `phpunit.xml` untuk `APP_ENV`, `BCRYPT_ROUNDS`, cache, mail, queue, session; **`DB_*` tidak ditimpa** ([testing](testing.md#bahaya-database-baca-dulu)) |
| Produksi | variabel lingkungan server; rahasia di luar repo |

Variabel lingkungan sungguhan menimpa `.env`.

## Variabel di `.env.example`

| Variabel | Dibaca di | Fungsi |
|---|---|---|
| `APP_NAME` | `config/app.php` | nama aplikasi ("ERP - Modul WMS"); dipakai judul halaman dan `MAIL_FROM_NAME` |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | `config/app.php` | lingkungan, mode debug (matikan di produksi), URL dasar |
| `APP_KEY` | `config/app.php` | kunci enkripsi; dibuat `php artisan key:generate`; rahasia |
| `TRUSTED_PROXIES` | `config('app.trusted_proxies')`, `AppServiceProvider::percayaiProxy()` | daftar IP proxy tepercaya dipisah koma, atau `*`; kosong berarti tidak ada. Penting di belakang reverse proxy agar IP klien benar untuk throttle login. Dijaga `ProxyTepercayaTest` |
| `NPWP_PERUSAHAAN`, `NITKU_PERUSAHAAN` | `config('app.npwp_perusahaan')`, `config('app.nitku_perusahaan')` | identitas pajak perusahaan (penjual); kosong membuat preflight melapor `NPWP_PERUSAHAAN_TIDAK_VALID`. Keputusan lanjutan di [KEP-007](../backlog/keputusan-menunggu.md#kep-007-identitas-perusahaan-di-konfigurasi) |
| `LOG_CHANNEL`, `LOG_LEVEL` | `config/logging.php` | |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `config/database.php` | koneksi MySQL; `DB_PASSWORD` rahasia. Nama database contoh `inventory` |
| `BROADCAST_DRIVER` | `config/broadcasting.php` | broadcasting dorman (`routes/channels.php` tidak dipasang) |
| `CACHE_DRIVER` | `config/cache.php` | |
| `FILESYSTEM_DISK` | `config/filesystems.php` | disk bawaan |
| `LAMPIRAN_DISK` | `config('filesystems.lampiran_disk')`, `LampiranService::disk()` | disk privat lampiran dokumen (default `lampiran`); bisa diganti ke penyimpanan lain tanpa mengubah kode |
| `QUEUE_CONNECTION` | `config/queue.php` | contoh `database`; dengan driver non-sync, `queue:work` wajib berjalan |
| `SESSION_DRIVER`, `SESSION_LIFETIME` | `config/session.php` | sesi web; lama dalam menit |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `config/mail.php` | surat; contoh memakai mailer `log` |

Konfigurasi penyimpanan S3 (`AWS_*`) ada di `config/filesystems.php` tetapi tidak ada di `.env.example`; isi hanya bila `LAMPIRAN_DISK` diarahkan ke disk S3.

`config/` sengaja dibiarkan dalam bentuk Laravel 10 yang lebih penuh, bukan dipangkas ke default Laravel 12: file itu membawa pengaturan nyata (termasuk `konstanta_ppn`) dan memangkasnya hanya menambah risiko.

## Menambah variabel baru

1. Baca variabel hanya lewat `config()`. Panggilan `env()` di luar file `config/` tidak bekerja setelah `config:cache`.
2. Tambah kunci di file `config/` yang sesuai (dengan default aman).
3. Tambah nama variabel ke `.env.example` (**tanpa nilai rahasia**).
4. Perbarui tabel di dokumen ini.
5. Bila tes membutuhkan nilai lain, atur di `phpunit.xml` atau di test, bukan di `.env`.
6. Rahasia baru tidak pernah di-commit; catat di dokumen deployment bahwa ia harus diatur di server.

## Hal yang berkaitan

- Throttle login dan limiter `api` ada di `AppServiceProvider::boot()`, bukan di konfigurasi.
- Jadwal command ada di `bootstrap/app.php` → `withSchedule()` ([development lokal](local-development.md#jadwal-dari-bootstrapappphp)).
- Provider terdaftar di `bootstrap/providers.php`, bukan `config/app.php`.
