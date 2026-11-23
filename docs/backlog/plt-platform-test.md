# PLT: Platform dan test

Pekerjaan platform, jaring test, dan utang teknis sistemik. Konteks di [jaring pengaman test](../architecture/jaring-pengaman-test.md).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| PLT-001 | Test N+1 permanen di semua halaman bernama dan eager loading jalur baca | ✅ | `tests/Feature/TanpaNPlusSatuTest.php`, `ExecutiveDashboardService` | — |
| PLT-002 | `SubkontrakPersediaanTest` dibetulkan memakai assertion selisih karena seeder meninggalkan satu unit di gudang subkontrak | ✅ | `tests/Feature/SubkontrakPersediaanTest.php` | — |
| PLT-003 | Jaring smoke, tampilan, tema, tanpa efek samping, dan akses per role | ✅ | `tests/Feature/SmokeSemuaHalamanTest.php`, `KualitasTampilanTest.php`, `KonsistensiTemaTest.php`, `HalamanTanpaEfekSampingTest.php`, `AksesPerRoleTest.php` | — |
| PLT-004 | Pengingat harian dan watchdog invarian terjadwal | ✅ | `app/Services/PengingatService.php`, `app/Console/Commands/PeriksaInvarianAkuntansi.php`, `tests/Feature/PengingatDanWatchdogTest.php` | — |
| PLT-005 | Ukur ulang cakupan route mutasi oleh test (angka terakhir 2026-09-18: 175 route mutasi, 59 disebut test sebagai pengguna berwenang) | ⬜ | [jaring pengaman test](../architecture/jaring-pengaman-test.md#celah-cakupan-route) | — |
| PLT-006 | Database khusus test: `phpunit.xml` tidak menimpa `DB_DATABASE`, jadi suite berjalan terhadap database di `.env`; sediakan `.env.testing` atau nilai di `phpunit.xml` | ⬜ | `phpunit.xml`, [testing](../guides/testing.md) | — |
| PLT-007 | Putuskan dan buat gerbang otomatis: tidak ada CI (tidak ada `.github`, tidak ada workflow, diperiksa 2026-11-20; `CLAUDE.md` yang menyebutnya sudah dikoreksi). Pilihan: workflow GitHub Actions dengan MySQL (`composer test:case-sensitive`, `migrate:fresh --seed --force`, `php artisan test`) atau skrip gerbang lokal satu perintah | ⬜ | `composer.json`, [CONTRIBUTING](../../CONTRIBUTING.md) | — |
| PLT-008 | Pindahkan proses lintas model dari tujuh controller ke `app/Services` (`JurnalController`, `MaterialRequestController`, `PemeriksaanConsiderController`, `PesananPembelianController`, `ReturPembelianController`, `StockOpnameController`, `TransferGudangController`); proyek tersendiri | ⬜ | [utang terukur](../architecture/utang-terukur-dan-riwayat.md) | — |
| PLT-009 | Ganti literal status `ReservasiPersediaan`, lot `quality_status`, dan picking dengan konstanta, per model dengan pemeriksaan file demi file | ⬜ | [audit A–Z](../architecture/utang-terukur-dan-riwayat.md#what-the-2026-09-18-az-audit-checked-and-what-it-found) | — |
| PLT-010 | Service yang membaca `Auth::id()` langsung menerima pengguna sebagai argumen (16 file masih memuatnya, `grep` 2026-11-20; sebagian sudah menerima aktor eksplisit dengan fallback, [AUT-006](aut-autentikasi.md); `TransferGudangService::terima()` dan `AsetAccountingService::depreciate()` menyusul 2026-11-23, diuji di `AtribusiAktorTest`) | ⬜ | `CLAUDE.md` trap 18, `tests/Feature/AtribusiAktorTest.php` | — |
| PLT-011 | Pastikan file hasil kerja berakhiran baris LF sebelum commit (`.gitattributes` memakai `eol=lf`, tetapi file hasil worker pernah CRLF) | ⬜ | `.gitattributes` | — |
| PLT-012 | Menamai ulang 21 tabel tanpa prefix `wms_` | ⛔ | [KEP-009](keputusan-menunggu.md#kep-009-prefix-wms_-untuk-21-tabel-lama) | — |
