# Membuat alur dokumen

Menambah dokumen yang memposting jurnal atau menggerakkan stok: urutan langkah, dan jebakan yang sudah pernah memakan waktu debugging.

Contoh acuan yang bisa dibaca: `FakturPenjualanService::posting()` (dokumen → jurnal → status) dan `TransferGudangService` (stok dua fase). Jebakan lengkap ada di bagian "Behavioural rules" `CLAUDE.md`; halaman ini menaruhnya di tempat kamu akan membutuhkannya.

## Ringkasan: apa ditaruh di mana

| Yang dibuat | Letak |
|---|---|
| Tabel dan kolom | `database/migrations` ([database](database.md)) |
| Model, konstanta status, relasi | `app/Models` |
| Validasi | `app/Http/Requests` (Form Request) |
| Otorisasi | `app/Policies` (+ gate di `AuthServiceProvider` bila lintas model) |
| Proses lintas model, transaksi, stok, jurnal | `app/Services` |
| Method posting jurnal | `WmsAccountingService::postXxx` |
| Controller tipis | `app/Http/Controllers` |
| Route | file domain di `routes/` |
| View | `resources/views` ([menambah halaman](adding-a-page.md)) |
| Test | `tests/Feature` |

## Langkah

### 1. Skema dan model

- Tabel `wms_<nama>` jamak; status uppercase (`DRAFT`, `POSTED`, `VOID` atau `REVERSED`); uang `DECIMAL(18,2)`, kuantitas `DECIMAL(18,6)`.
- Konstanta status di model, bukan literal di mana-mana.
- Cek `$fillable` atau `$guarded` modelnya sebelum mengandalkan `create()` ([database](database.md#model-fillable-atau-guarded)).
- Setiap `hasMany`/`hasOne` menyebut foreign key.
- Lifecycle dokumen yang diposting mengikuti pola `DRAFT → POSTED → REVERSED` (atau `CANCELLED`). **Dokumen posted tidak pernah diedit atau dihapus**: koreksi lewat pembalikan terkendali dengan jejak audit dan jurnal pembalik.

### 2. Penomoran

Nomor dokumen dialokasikan **saat dokumen disimpan, di dalam transaksi yang sama**, bukan saat form dirender. `DocumentNumberService` punya dua mode yang tidak boleh tertukar:

| Mode | Method | Menulis? | Dipakai di |
|---|---|---|---|
| Alokasi | `external()`, `financial()`, `internal()` | ya, menaikkan `document_sequences.last_number` | `store()`/service, di dalam transaksi |
| Pratinjau | `preview(DocumentNumberService::EXTERNAL\|FINANCIAL\|INTERNAL, …)` | tidak | form buat, JSON "nomor berikutnya" |

Akibat bila salah: membuka form lalu pergi membakar nomor selamanya (celah di urutan yang dirancang bisa diaudit, dan menggerus batas 999 per periode), dan bila form mengirim nomornya kembali sebagai input, tulang punggung audit dokumen itu bisa disunting klien. Aturan yang berlaku:

- `store()` mengalokasikan nomornya sendiri dan **mengabaikan** nomor dari klien; aturan `required`/`regex`/`unique` untuk nomor tidak ada di Form Request.
- Input nomor di form adalah field tampilan yang `disabled`, bukan data form.
- Nomor mutasi stok bukan nomor dokumen audit: `MTS-YYYYMM-<ULID>`.
- `ArchitectureConventionTest::test_display_paths_never_allocate_a_document_number` menjaga jalur tampilan; `HalamanTanpaEfekSampingTest` menangkap halaman GET yang menulis.

### 3. Service dan transaksi

Skeleton (mengikuti `FakturPenjualanService::posting`):

```php
public function posting(Dokumen $dokumen, User $user): Dokumen
{
    return DB::transaction(function () use ($dokumen, $user) {
        $dokumen = Dokumen::with('details')->lockForUpdate()->findOrFail($dokumen->id);

        if (!$dokumen->isDraft()) {
            throw new RuntimeException('Hanya dokumen draft yang dapat diposting.');
        }

        $jurnal = $this->akuntansi->postDokumen($dokumen);

        $dokumen->update([
            'status' => Dokumen::POSTED,
            'journal_id' => $jurnal->id,
            'diposting_oleh' => $user->id,
            'diposting_pada' => now(),
        ]);

        return $dokumen->fresh('details');
    });
}
```

Aturan:

- **Satu transaksi** membungkus dokumen, stok, jurnal, dan subledger; rollback membatalkan semuanya, termasuk nomor yang dialokasikan.
- **Kunci baris lalu baca ulang.** Muat dokumen dengan `lockForUpdate()` di dalam transaksi dan periksa statusnya di situ. Dua klik bersamaan lalu aman.
- **Jangan menghitung dari atribut yang dimuat sebelum loop.** Bila beberapa baris dokumen menyentuh baris yang sama, baca ulang di bawah `lockForUpdate()` atau pakai `increment()` / `DB::raw('kolom + n')`. Pernah terjadi dua kali (kapasitas bin; kapitalisasi jasa pada dua baris ke satu aset sehingga GL bergerak A+B sementara aset hanya B).
- **Aktor eksplisit.** Terima `User` atau id sebagai argumen. `Auth::id()` null di seeder, queue, dan pemanggilan service langsung (kolom `created_by` NOT NULL gagal). `StokGudangService` menerima `$userId`.
- **Idempotensi.** Sandarkan pada batasan database: unik `(sumber_transaksi, reff_id)` untuk jurnal otomatis, `DocumentOperationGuard::claim()` untuk operasi yang tidak boleh ganda (transfer kirim dan terima), unik pada kolom referensi dokumen.
- **Galat klien adalah galat klien.** Lempar `RuntimeException` untuk pelanggaran aturan dan biarkan controller menjadikannya 422/pesan flash; bukan `abort(500)`. Catatan: `InvalidArgumentException` bukan `RuntimeException` dan lolos sebagai 500.

### 4. Stok

- Gerakkan stok hanya lewat `StokGudangService` (`masuk`, `keluar`, `ambilLayer`) agar saldo, master, layer, dan `MutasiStok` bergerak bersama ([persediaan dan FIFO](../architecture/persediaan-dan-fifo.md)).
- Pemakaian layer memakai `ambilLayer()`; jangan menulis implementasi FIFO kedua.
- `$affectGlobal = false` untuk perpindahan antar gudang (total perusahaan tidak berubah).
- **Jalur baca tidak memanggil `saldo()`**; ia menulis dan mengunci.
- Satuan: konversi ke satuan stok **sebelum** FIFO.

### 5. Jurnal

- Tambah method `postXxx` di `WmsAccountingService`, panggil `$this->periods->assertOpen($tanggal, 'label')` di awal, kumpulkan baris dengan helper `line()`, dan akhiri dengan `post($marker, $tanggal, $sumber, $reffId, $keterangan, $lines)`.
- **Tandai gudang pada semua baris** dokumen yang mengenal gudang (argumen `gudang_id` helper `line()`), bukan hanya baris persediaan.
- Akun lewat `AccountingSetting::accountId(KUNCI)`. Kunci baru: konstanta **dan** whitelist `$expected`; kode COA baru dicek benar-benar kosong (migrasi menolak bila terpakai nama lain).
- Jurnal harus seimbang; hati-hati pembulatan per baris dan per kategori (lihat temuan [ALG-006 sampai ALG-011](../backlog/alg-audit-algoritma.md)).
- Pembalikan: jurnal asal menjadi `REVERSED`, pembalik `POSTED` dengan debit-kredit tertukar dan **`gudang_id` disalin**. Pembalikan dokumen lewat maker-checker (`PersetujuanOperasiService`); penyetuju bukan pembuat, dicek di service.
- Laporan yang membaca jurnal memakai `Jurnal::scopeBerlaku()`.

### 6. Invarian

Bila dokumen menumpuk saldo neraca (piutang, hutang, in-transit, WIP, uang muka, …), beri ia invarian di `AccountingReconciliationService::checks()` **saat membangunnya**, bukan sesudahnya, dan tambahkan pasangannya di [akuntansi dan jurnal](../architecture/akuntansi-dan-jurnal.md#delapan-invarian-rekonsiliasi). Invarian yang ada harus tetap valid setelah dokumen baru dijalankan lewat seeder demo.

### 7. Controller, route, otorisasi

- Controller hanya orkestrasi: Form Request → service → respons. Tanpa `->validate()`.
- Route di file domain yang tepat, bernama, mutasi **tidak lewat GET**. File route baru wajib masuk daftar `ArchitectureConventionTest`.
- Policy dengan helper privat "siapa boleh" dan "akses gudang"; cek `canAccessGudang()` untuk dokumen bergudang. Jangan menulis cek Super Admin di policy.
- Bila URL segment berbeda dari nama binding lama, pertahankan nama binding dengan `->parameters(['segment-baru' => 'namaLama'])`.

### 8. Seeder demo

Skenario baru di akhir `DatabaseSeeder`, dibungkus agar bila datanya tidak ada hanya memperingatkan, dengan kuantitas kecil (data demo tipis). Jalankan `KekayaanDataDemoTest` sesudahnya.

### 9. Test

Wajib: **satu test yang masuk lewat route**, plus test service untuk cabang aturan.

```php
$response = $this->actingAs($pengguna)
    ->post(route('dokumen.store'), $data)
    ->assertOk();

$nomor = $response->json('data.nomor');
```

- Baca nomor dari respons; test tidak bisa memilihnya.
- Periksa efek ke ledger: jurnal seimbang, stok dan layer sesuai, dan **invarian tetap valid**.
- Bila ada pembalikan, uji bahwa semua laporan kembali ke nilai awal (pola `JurnalDibalikTest`).
- Uji penolakan: periode terkunci, status salah, role salah, dan permintaan ganda.
- Bila perbaikan bug: pastikan test **gagal saat perbaikannya dibalik**.
- Jalankan `composer test:case-sensitive` sebelum push di Windows.

### 10. Dokumentasi

Perbarui dokumen yang ikut berubah menurut [governance](../architecture/development-governance.md#dokumentasi-ikut-berubah): akuntansi/persediaan, peta lapisan, [backlog](../backlog/README.md), dan `CLAUDE.md` bila ada keputusan atau jebakan baru.
