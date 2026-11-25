# Troubleshooting

Masalah nyata yang pernah terjadi di repo ini, penyebabnya, dan cara keluarnya.

Semua baris berasal dari jebakan yang tercatat di `CLAUDE.md` atau dari perilaku yang bisa dilihat di kode. Bila menemukan masalah lingkungan baru, tambahkan di sini di perubahan yang sama.

## Lingkungan

| Gejala | Penyebab | Solusi |
|---|---|---|
| `php artisan` berhenti dengan pesan dari `vendor/composer/platform_check.php` | PHP di PATH di bawah 8.2 (Laravel 12 butuh 8.2+) | panggil binari PHP 8.2+ secara eksplisit; periksa `php -v` |
| Autoloader tiba-tiba dibangun ulang dan boot gagal setelah `composer dump-autoload` atau `composer install` | `composer` dijalankan dengan PHP lama, jadi autoloader dihitung terhadap platform yang salah | jalankan `composer` memakai PHP 8.2+ yang sama dengan `artisan`, lalu ulangi |
| Ratusan test gagal dengan 500 dan `SmokeSemuaHalamanTest` mendaftar hampir seluruh aplikasi | `public/build/manifest.json` tidak ada: `ViteManifestNotFoundException` di setiap halaman ber-layout. Tidak ada cacat kode. Terlihat 2026-09-18: 174 gagal dan 53 lulus sebelum build, 226 lulus dan 1 gagal sesudahnya | `npm install && npm run build`, ulangi test. Aset tidak di-commit, jadi clone baru atau `public/build` yang dibersihkan memicunya |
| Proses `artisan serve`/Vite lama masih memakai port dan hasil uji menyesatkan | `pkill` dari Git Bash sering gagal mematikan proses di komputer ini | matikan lewat PowerShell dan verifikasi dengan `netstat` ([development lokal](local-development.md#mematikan-proses-lama-di-windows)) |
| Isi database kerja hilang setelah menjalankan suite test | satu test memakai `RefreshDatabase` | pada 2026-11-20 tidak ada yang memakainya (baris di `ExampleTest` dikomentari); periksa `grep -rn "RefreshDatabase\|DatabaseMigrations" tests`, jangan menjalankan suite terhadap database yang berisi data yang kamu butuhkan, dan pakai `DB_DATABASE` terpisah ([testing](testing.md#bahaya-database-baca-dulu)) |
| Diff penuh perubahan akhir baris (CRLF vs LF) pada file hasil alat atau worker | `.gitattributes` memakai `* text=auto eol=lf`, tetapi file yang ditulis alat tertentu berakhiran CRLF | normalisasi ke LF sebelum commit; periksa dengan `git diff --stat` bahwa hanya baris yang berubah yang tampak |
| `composer test:case-sensitive` gagal, padahal lulus di Windows | nama kelas dan nama file berbeda huruf; Windows tidak peka huruf besar-kecil, Linux dan Git peka | samakan persis nama kelas dengan nama file dan dengan path yang dilacak Git (`git mv` untuk huruf saja), lalu ulangi |
| Notifikasi tidak pernah terkirim; tabel `notifications` kosong | `queue:work` tidak berjalan; pekerjaan menumpuk di `jobs` | jalankan `php artisan queue:work`. Ini perilaku benar, bukan bug |
| Tidak ada pengingat harian, penyusutan, atau saran replenishment | `schedule:work` tidak berjalan | jalankan `php artisan schedule:work` |

## Database dan migrasi

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Identifier name '…' is too long` saat `migrate:fresh` | batas 64 karakter MySQL; awalan `wms_` plus nama index otomatis | beri nama pendek eksplisit sebagai argumen kedua `unique()`/`index()` |
| `SQLSTATE[42S02] Base table or view not found` dari satu tindakan tertentu (misal merevisi PO tercetak) | nama tabel lama tertinggal sebagai string (`DB::table('…')`, `join()`, aturan `exists:`) setelah rename | perbaiki namanya; `ArchitectureConventionTest::test_every_table_name_written_as_a_string_actually_exists` menangkapnya sekarang |
| Kolom yang dikirim tersimpan kosong atau berisi default database, tanpa galat | penugasan massal membuang field yang tidak ada di `$fillable` | tambahkan ke `$fillable` bila modelnya memakainya; field yang tidak boleh dari input ditulis `forceFill()` ([database](database.md#model-fillable-atau-guarded)) |
| Relasi `hasMany`/`hasOne` mengembalikan kosong setelah mengganti nama model | Eloquent menurunkan foreign key dari nama kelas pendeklarasi | sebutkan foreign key secara eksplisit |
| Kode akun COA terpakai berganti nama sendiri setelah seed | `updateOrCreate(['kode_akun' => …])` mengganti akun yang kodenya sama | cek kode benar-benar kosong sebelum menambah akun |
| Satu key mapping ada barisnya tetapi `accountId()` menolak | kunci tidak ada di whitelist `$expected` | tambahkan di konstanta **dan** whitelist `AccountingSetting` |
| Seeder menulis user id yang tidak ada | id tetap di seeder | cari pengguna lewat role (`User::where('type', …)`), bukan id atau email |

## Akuntansi dan stok

| Gejala | Penyebab | Solusi |
|---|---|---|
| Neraca saldo seimbang tetapi persediaan negatif dan GRNI positif setelah pembalikan | laporan yang membaca hanya `POSTED` mengabaikan jurnal `REVERSED` | pakai `Jurnal::scopeBerlaku()` / `whereIn status ['POSTED','REVERSED']` |
| Salah satu invarian rekonsiliasi merah | dua pandangan atas fakta yang sama berbeda | jalankan `php artisan wms:periksa-invarian`, lihat [akuntansi dan jurnal](../architecture/akuntansi-dan-jurnal.md#delapan-invarian-rekonsiliasi) untuk arti tiap kunci |
| Membuka halaman membuat baris `stok_gudangs` baru atau mengunci baris | halaman GET memanggil `StokGudangService::saldo()`, helper tulis | query `StokGudang` langsung di jalur baca; `HalamanTanpaEfekSampingTest` menangkapnya |
| Nomor dokumen melompat atau habis | form memakai metode yang mengalokasikan, atau klien mengirim nomor | pakai `preview()` untuk tampilan; alokasi hanya di `store()` ([membuat alur dokumen](creating-a-document-flow.md#2-penomoran)) |
| Posting transaksi bertanggal lalu ditolak "periode … sudah dikunci" | period lock | itu perilaku benar; pakai tanggal kejadian sebenarnya |
| NPK menolak "Stok layer … tidak mencukupi" padahal stok persis sama | perbandingan float di `consumeStock` tanpa toleransi (temuan audit [ALG-013](../backlog/alg-audit-algoritma.md)) | belum diperbaiki; kurangi atau ubah jumlah sedikit, atau kerjakan ALG-013 |
| Pesan "sudah disusutkan untuk periode …" setelah klik ganda "Susutkan" | penolakan yang disengaja: kunci baris aset dan unik periode mencegah data ganda, dan controller menampilkannya sebagai galat form ([AKT-003](../backlog/akt-akuntansi.md)) | tidak ada yang perlu diperbaiki; cek riwayat penyusutan aset |
| Satu login gagal menghasilkan dua baris audit; tes throttle gagal di separuh jumlah percobaan | penemuan otomatis event Laravel 12 mengikat listener dua kali | pastikan `->withEvents(discover: false)` tetap ada di `bootstrap/app.php` |

## Tampilan

| Gejala | Penyebab | Solusi |
|---|---|---|
| Halaman mati dengan galat parse yang menunjuk `@endforeach` jauh di bawah direktif yang tidak kamu ubah | `@php($x = …)` dengan ekspresi panjang gagal dikompilasi diam-diam sehingga Blade menulis `<?php` tanpa penutup | ganti dengan blok `@php … @endphp`; cek direktif sebelumnya |
| Tabel Yajra kosong atau kolom hilang setelah mengganti nama kolom | JS (`wmsDataTable`) membaca nama kolom yang dikirim Yajra | kembalikan nama kolom; jangan rename kolom yang diekspos Yajra |
| Pop-up SweetAlert tampil terang di mode gelap | aturan override-nya di dalam `@layer` dibuang Tailwind | taruh di luar `@layer components`; tandanya keluaran `app.css` tidak membesar |
| `badge text-white` tak terbaca di mode gelap | warna isi dipaksa pada komponen bertema | hapus kelas warna; daisyUI memilih warna isi per varian |
| Modal tinggi memotong tombol footer | `overflow-hidden` atau tinggi tetap menimpa aturan global `.modal-box` | hapus override; modal harus menggulir, bukan memotong |
| Peringatan sesi berakhir muncul lalu pengguna kembali ke login di tengah pekerjaan | respons 401/419 pada `fetch` | perilaku yang dirancang (`handleSessionExpired`); sebaliknya, pemanggil `fetch` baru harus memanggilnya |
