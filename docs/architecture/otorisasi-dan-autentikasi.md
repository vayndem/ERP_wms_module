# Otorisasi dan autentikasi

Siapa boleh melakukan apa, bagaimana login dijaga, dan mengapa beberapa pengaturan bootstrap tampak aneh tetapi wajib.

Referensi lengkap role matrix ada di [`feed.MD`](../../feed.MD) (bagian 3 dan 4). Dokumen ini menjelaskan mekanisme dan alasannya.

## Tujuh role

`users.type` adalah foreign key ke `user_roles`; konstanta di `App\Models\User`.

| Nilai | Role | Catatan |
|---|---|---|
| 0 | `super_admin` | melewati semua policy lewat `Gate::before`; satu-satunya yang mengelola pengguna |
| 1 | `purchasing` | |
| 2 | `finance` | |
| 3 | `warehouse` | operator gudang |
| 4 | `accounting` | |
| 5 | `production` (Produksi) | operator gudang terbatas pada gudang yang ditugaskan |
| 6 | `accounting_manager` | hanya menyetujui (termasuk faktur supplier); ditambah 2026-09-05 |

`User` menyediakan `isSuperAdmin()`, `isPurchasing()`, …, `isAccountingManager()`, `isWarehouseOperator()` (warehouse atau produksi), dan `hasAnyRole()`. Kunci seeder pengguna per **role**, bukan email: email adalah atribut yang bisa diubah.

## `Gate::before` dan policy

`AuthServiceProvider` mendefinisikan `Gate::before` yang memberi `true` pada Super Admin. Konsekuensinya:

- Jangan menulis cek Super Admin di dalam policy; ia redundan. Cek raw di Blade seperti `isWarehouseOperator() || isSuperAdmin()` juga sama dengan `@can('operateWarehouse')` dan akan meleset bila gate berubah.
- `UserPolicy` mengembalikan `false` di semua method kecuali `view` (diri sendiri). Itu bukan kode mati: itulah cara menyatakan "hanya Super Admin" di repo yang melarang cek Super Admin dalam policy.
- Aturan "pembuat tidak boleh menyetujui" harus di **service**, karena `Gate::before` akan meloloskan Super Admin di policy.

Gate lintas model yang ada: `viewWmsControl`, `operateWarehouse`, `controlInventoryFinance`, `matchSupplierInvoice`, `viewFinancialStatements`, `viewExecutiveDashboard`, `viewProcurementAnalytics`, `viewKinerjaSales`, `tembusPlafonKredit` (Super Admin), `viewPiutangAging`. Selebihnya policy per model (45 file di `app/Policies`, ditemukan otomatis lewat konvensi). Policy baru mengikuti pola: helper privat "siapa boleh" dan "akses gudang", method publik `view/create/update/...` di bawahnya.

Tiga controller (`Kit`, `GelombangPengambilan`, `PengeluaranBarang`) tidak punya policy dan tidak tanpa pagar: mereka memakai `Gate::allows('operateWarehouse')` plus `canAccessGudang()` eksplisit. Itu idiom lain, bukan lubang.

### Atribusi aktor

`Auth::id()` null di seeder, queue worker, dan pemanggilan service langsung. Service baru menerima pengguna sebagai argumen (contoh `WarehouseExecutionService::reserve()`); `AtribusiAktorTest` mengunci perilaku ini untuk reversal, uang muka, retur, tukar tambah, dan plafon kredit.

## Scoping gudang

Gudang adalah lokasi fisik terpisah dengan admin masing-masing, jadi scoping **ditegakkan**, bukan opsional (keputusan user 2026-09-13).

- `User::accessibleGudangIds($ability)` mengembalikan: semua gudang aktif untuk Super Admin; untuk `warehouse` dan `production` gudang yang tercantum di `pembagian_gudangs` (dengan kolom kemampuan `boleh_menerima`, `boleh_npk`, `boleh_transfer`, `boleh_opname`); array kosong untuk role lain.
- `canAccessGudang($id, $ability)` membungkusnya dan menolak `null`.
- Controller menyaring daftar lewat `accessibleGudangIds()`; policy dan service memeriksa `canAccessGudang()`.
- Migrasi `2026_09_13_000004` memberi setiap pengguna warehouse yang sudah ada semua gudang aktif supaya tidak ada yang kehilangan akses di hari pertama. **Pengguna warehouse baru mulai dengan nol gudang**; antrean kerja menyatakannya dengan jelas alih-alih halaman kosong.
- Cross-dock dijaga di kedua sisi: policy untuk lihat dan batalkan, dan `tandai()` menolak gudang yang tidak boleh diterima pengguna.

## Alur login

Sesi web berbasis cookie (guard `web`). Tidak ada login API.

1. `routes/auth.php`: `GET /` (form), `POST login` dengan `throttle:login`, reset password (`lupa-password`, `reset-password/{token}`) juga di bawah `throttle:login`, semuanya di grup `guest`.
2. `AuthController::login`:
   1. `UserAccountService::akunNonaktifDenganKredensialBenar()` dicek **sebelum** `Auth::attempt`. Jika akunnya nonaktif dan passwordnya benar, dicatat satu baris audit dan pesan "Akun Anda dinonaktifkan…" ditampilkan. Pesan ini hanya muncul bila password benar, jadi tidak membocorkan status akun kepada penebak. Memeriksa setelah attempt yang gagal akan menulis **dua** baris audit untuk satu percobaan dan melakukan hashing dua kali.
   2. `Auth::attempt($credentials + ['is_active' => true], $remember)`.
   3. `session()->regenerate()` lalu `redirect()->intended(route('dashboard'))`.
3. `redirectUsersTo(route('dashboard'))` dan `redirectGuestsTo(route('login'))` di `bootstrap/app.php`.

### Throttle dua tingkat

`AppServiceProvider::boot()` mendefinisikan limiter `login`: **5 per menit per `email|ip`** dan **20 per menit per IP**. Tingkat kedua mencegah satu penyerang mengunci satu kantor dengan menghajar satu alamat; tingkat pertama memperlambat tebakan pada satu akun. Limiter `api` 60 per menit per pengguna atau IP.

### Middleware `PastikanAkunAktif`

Ditambahkan ke grup `web` di `bootstrap/app.php`. Akun yang dinonaktifkan **saat sedang masuk** dikeluarkan di request berikutnya: sesi di-invalidate, token CSRF diregenerasi, JSON mendapat 401, selain itu redirect ke login dengan pesan. Tanpanya sesi yang sudah terbuka tetap hidup setelah akun dimatikan.

### Audit lewat event

Audit login tidak ditulis di controller. `CatatAktivitasAutentikasi` mendengarkan `Login`, `Logout`, `PasswordReset`, `Failed` dan memanggil `UserAccountService::catatLogin/…`, jadi **setiap** jalur autentikasi tercakup (termasuk reset password), bukan hanya satu form. `AuthKontrakTest` menegaskan satu reset password menulis tepat satu baris audit.

Setelah password diganti lewat `ResetPasswordController::reset`, semua token Sanctum pengguna dicabut (`$user->tokens()->delete()`) dan, bila `SESSION_DRIVER=database`, baris sesi pengguna itu di tabel sesi dihapus sehingga sesi lain ikut keluar. Dengan driver `file` atau `cookie` sesi lain tidak bisa dicabut dari server; `remember_token` sudah diganti sehingga cookie ingat-saya lama tidak berlaku.

### `withEvents(discover: false)`

Laravel 12 mendaftarkan `EventServiceProvider`-nya sendiri yang menemukan listener di `app/Listeners` secara otomatis. Dibiarkan menyala, tiap listener `CatatAktivitasAutentikasi` terikat **dua kali**: setiap login gagal menulis dua baris audit dan tes throttle gagal di separuh jumlah percobaan. Aplikasi memakai peta `$listen` eksplisit di `App\Providers\EventServiceProvider`, jadi penemuan otomatis harus mati (`bootstrap/app.php` dan `shouldDiscoverEvents()` mengembalikan `false`).

### Nonaktif dan aktif kembali

`UserAccountService::nonaktifkan()` menulis `is_active`, `deactivated_at`, `deactivated_by` lewat `forceFill()->save()`: kolom status akun **tidak** ada di `$fillable` karena tidak boleh datang dari input request. Ia juga mencabut token Sanctum pengguna (`$user->tokens()->delete()`). Admin tidak bisa menonaktifkan dirinya sendiri, dan Super Admin aktif terakhir tidak bisa menurunkan dirinya sendiri.

## Sanctum

Sanctum hanya dipasang pada satu endpoint: `GET /api/user` (`routes/api.php`, middleware `auth:sanctum`) yang mengembalikan pengguna. Tidak ada API bisnis; integrasi eksternal dibatalkan user (2026-09-16). Token milik akun yang dinonaktifkan dicabut (`AuthCelahTest`).

## Sesi habis

Halaman memakai fetch/Yajra/modal, sehingga sesi habis muncul sebagai respons 401 (JSON dari `PastikanAkunAktif`) atau 419 (token CSRF kedaluwarsa), bukan redirect yang terlihat.

- `handleSessionExpired(response)` di `resources/js/ajax-modal.js` mendeteksi 401 dan 419, menampilkan peringatan "Sesi Anda telah berakhir", lalu mengarahkan ke URL dari `<meta name="login-url">` (diisi di `layouts/app.blade.php`). Ia hanya menampilkan sekali per halaman.
- Dipakai oleh `openAjaxModal`, `submitAjaxForm`, `wmsDataTable()` (`data-table.js`), dan lonceng notifikasi (`notification-bell.js`). Pemanggil baru yang memakai `fetch` harus memanggilnya juga atau pengguna melihat galat umum.
- Tidak ada handler server khusus untuk 401/419 di `bootstrap/app.php` (`withExceptions()` hanya mengatur `dontFlash`). Pengalihan murni di sisi klien; belum diverifikasi untuk skenario non-AJAX.

## Pengujian

| Tes | Mengunci |
|---|---|
| `PlatformHardeningTest` | audit login/gagal, throttle, nonaktif, manajemen pengguna hanya Super Admin, ganti password, scheduler terdaftar |
| `AuthKontrakTest` | redirect pengguna login, satu baris audit reset, reset password mencabut semua token Sanctum pengguna itu, sesi akun nonaktif ditolak (401 JSON) |
| `AuthCelahTest` | token Sanctum valid/tidak valid/dicabut, sesi setelah logout/nonaktif, policy user, limit 20 per IP, remember me |
| `AksesPerRoleTest` | halaman yang harus bisa dan tidak boleh dibuka per role |
| `AtribusiAktorTest` | aktor eksplisit tanpa sesi |

Tampilan halaman auth (layout guest) belum dilihat di Chrome atau Safari nyata, dan tautan "Lupa password?" di mode gelap perlu dilihat mata ([backlog](../backlog/aut-autentikasi.md)).
