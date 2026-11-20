# AUT: Autentikasi dan akses

Pekerjaan login, sesi, audit akun, dan atribusi aktor. Konteks di [otorisasi dan autentikasi](../architecture/otorisasi-dan-autentikasi.md).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| AUT-001 | Pengguna login dialihkan ke `route('dashboard')`, tamu ke `route('login')` | ✅ | `bootstrap/app.php`, `tests/Feature/AuthKontrakTest.php` | — |
| AUT-002 | Audit reset password lewat event, tepat satu baris per reset | ✅ | `CatatAktivitasAutentikasi::handlePasswordReset`, `AuthKontrakTest` | — |
| AUT-003 | Middleware `PastikanAkunAktif` mengeluarkan akun yang dinonaktifkan saat sedang masuk | ✅ | `app/Http/Middleware/PastikanAkunAktif.php`, `AuthKontrakTest` | — |
| AUT-004 | Penanganan sesi habis 401/419 di JS (modal, tabel, lonceng) | ✅ | `resources/js/ajax-modal.js`, `data-table.js`, `notification-bell.js` | — |
| AUT-005 | Layout guest untuk login, lupa password, dan reset password | ✅ | `resources/views/layouts/guest.blade.php`, `resources/views/auth/` | — |
| AUT-006 | Aktor eksplisit di service (reversal, uang muka, retur, tukar tambah) tanpa bergantung pada `Auth::id()` | ✅ | `tests/Feature/AtribusiAktorTest.php` | — |
| AUT-007 | Plafon kredit ditembus lewat gate `tembusPlafonKredit`, bukan cek role mentah | ✅ | `app/Providers/AuthServiceProvider.php`, `AtribusiAktorTest` | — |
| AUT-008 | Token Sanctum dicabut saat akun dinonaktifkan | ✅ | `UserAccountService::nonaktifkan`, `tests/Feature/AuthCelahTest.php` | — |
| AUT-009 | Lihat layout guest di Chrome dan Safari nyata, termasuk tautan "Lupa password?" di mode gelap | ⬜ | `resources/views/layouts/guest.blade.php` | — |
| AUT-010 | Pastikan perilaku sesi habis non-AJAX (halaman biasa, 419 pada POST form) mengarah ke login dengan pesan yang jelas; belum diverifikasi, `withExceptions()` tidak punya handler khusus | ⬜ | `bootstrap/app.php` | — |
