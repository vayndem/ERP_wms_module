# Testing

Cara menjalankan dan menulis test dengan aman, apa yang wajib dites, dan bahaya terhadap database yang dipakai.

Apa yang ditangkap tiap jaring dijelaskan di [jaring pengaman test](../architecture/jaring-pengaman-test.md). Halaman ini tentang cara kerjanya.

## Bahaya database: baca dulu

`phpunit.xml` mengatur `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, cache/mail/session `array`, dan `QUEUE_CONNECTION=sync`, tetapi **tidak** mengatur `DB_DATABASE` (baris SQLite dikomentari). Artinya test berjalan terhadap database yang ditunjuk `.env`, yaitu database kerja (`inventory` menurut `.env.example`).

- Hampir semua feature test memakai `DatabaseTransactions`: perubahan di-rollback di akhir test. Aman selama tidak ada yang commit di luar transaksi.
- Beberapa test membaca **data seeder** (mis. `KekayaanDataDemoTest`, `TanpaNPlusSatuTest`), jadi database itu harus sudah `migrate --seed`.
- **`RefreshDatabase` menghapus isi database aktif.** Pada 2026-11-20 tidak ada test yang memakainya: `tests/Feature/ExampleTest.php` hanya memuat baris `use` yang dikomentari. Versi lama `CLAUDE.md` mengklaim `ExampleTest` memakainya; klaim itu salah dan sudah dikoreksi. Tetap jangan menyalin pola bawaan Laravel itu ke test baru. Periksa dulu:

```sh
grep -rn "RefreshDatabase\|DatabaseMigrations" tests
```

Hasilnya seharusnya hanya baris yang dikomentari. Jangan menjalankan suite penuh terhadap database yang berisi data yang kamu butuhkan sebelum yakin.

### Memakai database terpisah (disarankan)

Variabel lingkungan sungguhan menimpa `.env`, jadi database test bisa dipilih per perintah. Pola (belum dijalankan oleh penulis dokumen; sesuaikan nama dan akses):

```powershell
$env:DB_DATABASE = 'inventory_test'
php artisan migrate:fresh --seed --force
npm run build
php artisan test
```

`migrate:fresh` di atas hanya aman karena `DB_DATABASE` sudah dialihkan; periksa nilainya (`echo $env:DB_DATABASE`) sebelum menjalankannya. Tugas membuat ini permanen ada di [PLT-006](../backlog/plt-platform-test.md).

## Menjalankan

```sh
php artisan test                              # suite penuh (Unit + Feature)
php artisan test --filter=TestClassName       # satu kelas
php artisan test --filter=test_method_name    # satu method
composer test:case-sensitive                  # autoload ketat + ArchitectureConventionTest
```

`npm run build` wajib sebelum test ([troubleshooting](troubleshooting.md#lingkungan)).

### Sebelum push

`composer test:case-sensitive` menjalankan `composer dump-autoload --optimize --strict-psr --strict-ambiguous` lalu `php artisan test --filter=ArchitectureConventionTest`. Bug kasus huruf hanya muncul di Linux, jadi di Windows jalankan ini sebelum menyerahkan pekerjaan.

**Tidak ada CI** (tidak ada `.github` atau workflow, diperiksa 2026-11-20; keputusan membuatnya ada di [PLT-007](../backlog/plt-platform-test.md)). Gerbang yang nyata adalah yang lokal, urutannya di [CONTRIBUTING](../../CONTRIBUTING.md#sebelum-commit): `composer test:case-sensitive`, `npm run build`, `php artisan test`. Bila kelak ada workflow, urutan yang wajar adalah `composer test:case-sensitive`, `php artisan migrate:fresh --seed --force`, `php artisan test` dengan MySQL asli.

## Letak test

| Jenis | Letak | Contoh |
|---|---|---|
| Unit: kelas dan policy tanpa HTTP | `tests/Unit` (sebagian mewarisi `PHPUnit\Framework\TestCase` tanpa boot framework, sebagian `Tests\TestCase`) | `InventoryCostCalculatorTest`, `IdentitasPajakTest`, `*PolicyTest` |
| Aturan arsitektur | `tests/Unit/ArchitectureConventionTest.php` | |
| Feature: service, HTTP, ledger | `tests/Feature` | `JurnalDibalikTest`, `PlafonKreditTest` |
| Jaring lintas halaman | `tests/Feature` | `SmokeSemuaHalamanTest`, `KualitasTampilanTest`, `AksesPerRoleTest` |

Feature test mewarisi `Tests\TestCase` (`CreatesApplication`) dan memakai `DatabaseTransactions`. Pengguna dibuat lewat factory dengan role: `User::factory()->create(['type' => User::ROLE_PURCHASING, 'is_active' => true])`.

## Apa yang wajib dites

| Perubahan | Minimal |
|---|---|
| Dokumen yang memposting jurnal atau stok | satu test yang masuk **lewat route** (HTTP → service → ledger), ditambah test service untuk cabang aturan; pastikan semua invarian rekonsiliasi tetap valid |
| Perbaikan bug | test yang **gagal bila perbaikannya dibalikkan** |
| Halaman baru | otomatis masuk smoke, tampilan, tema, N+1; tambah baris di `AksesPerRoleTest` bila ada pembatasan role |
| Policy atau gate baru | test per role yang diizinkan dan yang ditolak |
| Route mutasi | test sebagai pengguna berwenang; tamu sudah dicakup smoke |

Contoh kerangka test route:

```php
class ContohTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_role_can_create_a_document_over_http(): void
    {
        $pengguna = User::factory()->create(['type' => User::ROLE_PURCHASING, 'is_active' => true]);

        $this->actingAs($pengguna)->post(route('pelanggan.store'), $data)->assertRedirect();
    }
}
```

## Jebakan

- **Nomor dokumen tidak bisa dipilih.** Store mengalokasikan nomor di server dan mengabaikan kiriman klien; baca nomor dari respons (`->json('data.no_po')`, `->json('data.id_lpb')`).
- **PDF mengalirkan biner.** Jangan `assertSee` ke endpoint PDF: tidak bermakna, dan kegagalan mencetak seluruh biner sampai kehabisan memori PHP. Uji koleksi ekspor Excel-nya.
- **`actingAs()` meninggalkan sesi.** `post(route('login.attempt'))` sesudahnya terkena middleware `guest` dan dialihkan tanpa menguji login.
- **Data seeder tipis menyembunyikan cacat.** Tabel kosong adalah jalur kode tanpa test; test yang berasumsi "hanya ada satu" biasanya akibat dataset tipis, bukan aturan bisnis.
- **Test hijau bukan bukti.** Test yang berhenti di batas service melewatkan guard controller, form Blade, jalur pembalikan, dan aritmetika lintas baris.
