# Jaring pengaman test

Apa yang ditangkap dan tidak ditangkap tiap test jaring, supaya test baru tidak menduplikasi satu jaring sambil meninggalkan celah yang sebenarnya terbuka.

Pelajaran yang melahirkan jaring-jaring ini (trap 8 di `CLAUDE.md` bagian "Behavioural rules"): dari sebelas cacat yang ditemukan review di build jasa, sepuluh berada di luar lapisan service (guard controller, form Blade, jalur pembalikan, aritmetika lintas baris) dan semuanya lolos dari suite hijau karena test memanggil service langsung. Fitur yang menempuh HTTP → service → ledger butuh minimal satu test yang masuk lewat route.

> **Peringatan database.** Suite ini berjalan terhadap database yang ditunjuk `.env` (`DB_DATABASE`); `phpunit.xml` tidak menimpanya. Hampir semua feature test memakai `DatabaseTransactions` dan membutuhkan data seeder. Pada 2026-11-20 **tidak ada test yang memakai `RefreshDatabase`**: `tests/Feature/ExampleTest.php` hanya memuat baris `use` yang dikomentari (`grep -rn "RefreshDatabase\|DatabaseMigrations" tests`). Versi lama `CLAUDE.md` mengklaim `ExampleTest` memakai `RefreshDatabase`; klaim itu salah dan sudah dikoreksi, tetapi peringatannya tetap dipertahankan karena trait itu menghapus isi database aktif begitu ada satu test yang memakainya, dan tes baru gampang menyalinnya dari contoh bawaan Laravel. Sebelum menjalankan suite penuh jalankan grep tadi dan arahkan `DB_DATABASE` ke database khusus test ([testing](../guides/testing.md)).

## Jaring

Jumlah test per kelas diukur dengan `grep -c "function test_"` pada 2026-11-20; ukur ulang sebelum mengandalkannya.

| Jaring | Masuk lewat | Menangkap | Tidak menangkap |
|---|---|---|---|
| `ArchitectureConventionTest` (Unit, 14 test) | analisis statis, `git ls-files`, tabel route | kasus huruf kelas/file; validasi inline di controller; pemecahan route dan `route:cache`; status huruf kecil; `double` di migrasi dan nama tabel lama; nomor dokumen dialokasikan di jalur tampilan; form Blade menuju route/verb yang salah atau tanpa `@csrf`; rujukan `route()`/`view()` menggantung; Form Request tanpa otorisasi; aksi destruktif lewat GET; `hasMany`/`hasOne` tanpa foreign key; nama tabel dalam string yang sudah tidak ada; route menunjuk method controller yang tidak ada | perilaku saat berjalan |
| `SmokeSemuaHalamanTest` (2) | GET sebagai Super Admin; mutasi sebagai tamu | 5xx di route GET bernama; batas jumlah route yang dilewati karena parameter tak terselesaikan; route mutasi yang bisa dijangkau tamu atau yang 5xx alih-alih menolak | halaman 200 yang isinya salah; akses per role, sebab `Gate::before` membuka semuanya untuk Super Admin |
| `KualitasTampilanTest` (6) | GET + HTML mentah | view tidak terkompilasi; direktif Blade atau `{{ }}` mentah di browser; daftar kosong tanpa pesan; sisa placeholder/debug; tabel lebar tanpa pembungkus gulir; kelas warna hardcode di halaman yang dirender | kebenaran angka di halaman |
| `KonsistensiTemaTest` (3) | sumber Blade/JS/CSS + `tailwind.config.js` | warna hardcode di sumber, termasuk file yang tak pernah dirender; grafik yang berhenti membaca token tema atau tidak memasang ulang saat `inventory:theme-changed`; tangga elevasi dan kontras kedua tema | tata letak jelek yang memakai token benar |
| `HalamanTanpaEfekSampingTest` (1) | GET + log query | halaman GET yang menjalankan `insert`/`update`/`delete` | tulis di route mutasi (sah) |
| `AksesPerRoleTest` (1) | GET per role | halaman yang harus bisa dan tidak boleh dibuka tiap role: satu-satunya kelas yang buta bagi smoke Super Admin | route mutasi |
| `TanpaNPlusSatuTest` (2, diperluas 2026-11-25) | GET semua halaman bernama (termasuk yang berparameter, dengan id baris terkaya, dan ekspor PDF/Excel/XML) + log query, sebagai Super Admin dan sebagai enam peran operasional | pola query berulang melewati batas (`BATAS_POLA_BERULANG = 5`, dapat diturunkan lewat `NPLUS1_AMBANG`; `NPLUS1_DETAIL=<file>` menulis semua pola berulang) dan lazy loading, termasuk policy per baris yang hanya jalan untuk peran non-Super-Admin; eager load `in (?)` tidak dihitung | N+1 di jalur tulis, yang sengaja tidak diubah; halaman yang datanya demo hanya satu baris (dijaga `TanpaNPlusSatuPenskalaanTest`) |
| `TanpaNPlusSatuPenskalaanTest` (15, 2026-11-25) | GET sebelum dan sesudah menggandakan baris demo (4 baris) | jumlah query yang tumbuh per baris pada daftar/halaman yang pernah N+1 (pelanggan, bagan akun, faktur pembelian, kategori bahan/aset, transfer untuk produksi, rincian permintaan, pesanan penjualan, cross dock, revaluasi kurs, uang muka, retur pembelian), biaya perintah kerja dihitung sekali, akses gudang per permintaan, agregat pengisian ulang | halaman yang belum punya kasus penggandaan |
| `PengingatDanWatchdogTest` | service, command artisan, HTTP | pengingat yang berhenti menyala atau menyala di kedua sisi ambangnya; ringkasan harian kehilangan seksi; panel dashboard menyimpang dari ringkasan; watchdog invarian tidak gagal pada buku besar rusak; notifikasi merender pelanggaran sebagai tenggat | apakah notifikasi benar-benar terkirim, butuh `queue:work` |
| `KekayaanDataDemoTest` | database hasil seeder | dataset demo berhenti mengerjakan satu jenis dokumen; invarian rusak oleh dataset kaya; transfer kehilangan salah satu dari tiga keadaannya | apakah angka seeder masuk akal secara bisnis |
| `CrudMasterDataTest` (3) | bolak-balik HTTP | create → update → delete master data, policy per role per verb, duplikat dan field wajib | domain lain, belum punya CRUD test sendiri |
| `AturanJenisGudangTest` (5) | service + policy | Gudang Rusak terminal dua arah; stok Consider berstatus tertahan; flag gudang sesuai jenis; keluar dari karantina/rusak tertutup | aturan gudang yang hanya ada di controller |
| `RevisiPesananPembelianTest` (2) | bolak-balik HTTP | revisi PO tercetak diarsip ke tabel riwayat asli; yang belum tercetak tidak | jalur revisi dokumen lain |
| `JurnalDibalikTest` (3) | service dan laporan | pembalikan LPB atau jurnal manual mengembalikan semua laporan ke nilai awal; `scopeBerlaku` mengecualikan draft dan menunggu persetujuan | |
| `NomorMutasiStokTest`, `PelepasanWipSisaTest`, `PenerimaanTransferTanggalTest`, `PenyusutanGandaTest` | service | pengunci perbaikan 2026-11-20: nomor mutasi lewat batas seribu; sisa WIP nol di pengiriman terakhir; tanggal jurnal terima; unik penyusutan per periode | |
| `PlatformHardeningTest`, `AuthKontrakTest`, `AuthCelahTest`, `AtribusiAktorTest` | HTTP + service | lihat [otorisasi dan autentikasi](otorisasi-dan-autentikasi.md#pengujian) | |
| tes tax: `IdentitasPajakTest` (Unit), `IdentitasPajakMasterTest`, `FakturPajakMetadataTest`, `KepatuhanPajakTest`, `LaporanPajakDjpTest`, `BuktiPotongTest` | validator, service, HTTP | format identitas, snapshot pembeli, unik NSFP, preflight; demo lolos preflight; bukti potong per pembayaran, pembatalan, pembetulan, tie-out, penghalang ekspor | kecocokan XML dengan Coretax atau XSD DJP (tidak pernah dicoba, [PJK-014](../backlog/pjk-pajak.md)) |
| tes domain lain (`PaymentAndInvoiceTest`, `ProduksiWipTest`, `PenjualanDanPiutangTest`, `RevaluasiKursTest`, …) | service, HTTP bila perlu | aturan bisnis, invarian ledger, FIFO, WIP, pajak | lihat celah cakupan route di bawah |

## Aturan memakai jaring

- Test yang membuktikan sebuah perbaikan harus **gagal bila perbaikannya dibalikkan**. Itulah cara memastikan test tidak kosong.
- Test yang memposting ke route store tidak bisa memilih nomor dokumen: nomor dialokasikan server dan dibaca dari respons (`->json('data.no_po')`, `->json('data.id_lpb')`).
- Endpoint PDF mengalirkan biner: `assertSee` tidak berarti, dan kegagalan mencetak seluruh biner sampai kehabisan memori PHP. Uji koleksi ekspor Excel-nya.
- `actingAs()` meninggalkan sesi terautentikasi; `post(route('login.attempt'))` sesudahnya terkena middleware `guest` dan mengalihkan tanpa menguji login.
- Data seeder yang tipis menyembunyikan seluruh kelas cacat: tabel kosong adalah jalur kode tanpa test. `KekayaanDataDemoTest` menjaga dataset demo tidak menipis.
- Sebelum menyalahkan kode karena ratusan test gagal 500, cek `public/build/manifest.json`.

## Celah cakupan route

Pengukuran 2026-09-18 (di [utang terukur](utang-terukur-dan-riwayat.md#route-coverage-gap)): 148 route GET bernama tanpa parameter, 52 dengan parameter, 175 route mutasi; semuanya sudah diuji sebagai tamu dan terbukti menunjuk method yang ada, tetapi hanya 59 route mutasi disebut test sebagai pengguna berwenang. Angka itu belum diukur ulang setelah ada test baru. Reproduksi dengan `php artisan route:list --json` lalu cari nama route di `tests/`.

## Yang memengaruhi cara menjalankan

- `composer test:case-sensitive` menjalankan `composer dump-autoload --optimize --strict-psr --strict-ambiguous` lalu `ArchitectureConventionTest`; jalankan sebelum push di Windows karena bug kasus huruf hanya muncul di Linux.
- **Tidak ada CI.** Pada 2026-11-20 folder `.github` tidak ada di working tree maupun di `git ls-files`, dan tidak ada workflow di mana pun di repo. Versi lama `CLAUDE.md` yang menyebut `.github/workflows/ci.yml` sudah dikoreksi. Gerbang lokal yang nyata ada di [CONTRIBUTING](../../CONTRIBUTING.md#sebelum-commit): `composer test:case-sensitive`, `npm run build`, `php artisan test`. Urutan itu, dengan `migrate:fresh --seed --force` di database terpisah, juga yang akan dipakai bila workflow dibuat ([PLT-007](../backlog/plt-platform-test.md)).
