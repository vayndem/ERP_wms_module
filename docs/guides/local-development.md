# Development lokal

Menyiapkan komputer, menjalankan aplikasi beserta queue worker dan scheduler, dan mematikan proses lama di Windows.

Perintah dasarnya berasal dari bagian Commands `CLAUDE.md` dan diperiksa terhadap `composer.json` dan `package.json`. Akun login development ada di [README root](../../README.md) (bagian "Akun Development"); jangan menyalinnya ke dokumen lain.

## Prasyarat

| Alat | Versi | Catatan |
|---|---|---|
| PHP | 8.2 atau lebih baru (`composer.json`: `^8.2`) | terpasang di proyek ini 8.3 menurut `CLAUDE.md` |
| Composer | 2 | jalankan dengan PHP 8.2+, lihat peringatan di bawah |
| Node dan npm | versi yang menjalankan Vite 8 | `package.json` tidak menyatakan `engines`; versi minimum belum diverifikasi |
| MySQL | server lokal | database default `inventory` (`.env.example`) |

**PHP lama menolak boot.** Sejak Laravel 12 (2026-09-18), `artisan` berhenti bila PHP di PATH di bawah 8.2 karena `vendor/composer/platform_check.php`. Panggil binari yang benar secara eksplisit. Hal yang sama berlaku untuk `composer`: menjalankannya dengan PHP lama membangun ulang autoloader terhadap platform yang salah (`composer dump-autoload` pun terpengaruh). Gejalanya di [troubleshooting](troubleshooting.md).

## Instalasi

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

- `.env` tidak di-commit. `post-root-package-install` hanya menyalin `.env.example` saat proyek dibuat dengan `composer create-project`; pada clone biasa salin sendiri.
- `migrate --seed` menjalankan seluruh `DatabaseSeeder`, yang mencampur data master dan data demo. **Hanya untuk development dan test**; jangan menjalankannya terhadap produksi. Produksi tidak terisi kode objek pajak oleh `migrate`: jalankan `php artisan db:seed --class=KodeObjekPajakSeeder` sekali.
- Data dev boleh direset bebas ([database](database.md#mereset-data-dev)).
- `npm run build` wajib sebelum `php artisan test`. Aset tidak di-commit; tanpa `public/build/manifest.json` hampir semua halaman 500.

## Menjalankan

| Perintah | Fungsi | Wajib? |
|---|---|---|
| `php artisan serve` | server aplikasi | ya |
| `npm run dev` | Vite dev server dengan hot reload | untuk mengubah front-end; bila tidak, cukup `npm run build` |
| `php artisan queue:work` | memproses notifikasi yang diantrikan (`QUEUE_CONNECTION=database`) | ya sejak 2026-09-12 |
| `php artisan schedule:work` | pengingat, replenishment, ABC, penyusutan, watchdog invarian | ya sejak 2026-09-12 |

Tanpa worker, notifikasi menumpuk di tabel `jobs` dan `notifications` kosong. Itu perilaku yang benar: command menulis "diantrikan", bukan "terkirim". Tanpa scheduler sistem kembali ke "hanya tahu bila orang mengklik".

### Jadwal (dari `bootstrap/app.php`)

| Command | Waktu | Fungsi |
|---|---|---|
| `wms:hitung-replenishment` | harian 05:00 | saran pengisian ulang |
| `wms:hitung-abc` | Senin 04:00 | klasifikasi ABC |
| `wms:periksa-invarian` | harian 06:30 | alarm bila invarian rekonsiliasi merah (exit non-nol) |
| `wms:pengingat-harian` | hari kerja 07:00 | ringkasan pengingat per peran |
| `wms:penyusutan-bulanan` | tanggal 1, 02:00 | penyusutan; default bulan **lalu** |
| `queue:prune-failed --hours=720` | Minggu 03:00 | |
| `auth:clear-resets` | harian | |

Semuanya `withoutOverlapping()->onOneServer()` (kecuali dua baris terakhir). Menjalankan satu command manual: `php artisan wms:periksa-invarian`.

## Mematikan proses lama di Windows

`pkill` dari Git Bash sering gagal mematikan proses di komputer ini, dan proses lama yang menumpuk membuat hasil uji menyesatkan. Pakai PowerShell. Contoh untuk `artisan serve`/`queue:work`/`schedule:work` (sesuaikan filternya dengan yang berjalan):

```powershell
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
  Where-Object { $_.CommandLine -like '*artisan*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```

Lalu pastikan port benar-benar kosong sebelum menjalankan ulang:

```powershell
netstat -ano | findstr :8000
```

Untuk Vite ganti `Name = 'node.exe'` dan filter `*vite*`. Perintah ini adalah pola yang disarankan; penulis dokumen belum menjalankannya ulang di komputer ini.

## Sebelum push (Windows)

Windows tidak peka huruf besar-kecil, Linux peka (production di Linux; tidak ada CI). Jalankan `composer test:case-sensitive` sebelum push ([testing](testing.md#sebelum-push)). User yang mengurus `git add`/`commit`/`push`; agen tidak melakukannya tanpa diminta.
