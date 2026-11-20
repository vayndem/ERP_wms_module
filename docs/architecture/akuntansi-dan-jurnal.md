# Akuntansi dan jurnal

Bagaimana dokumen bisnis menjadi jurnal, mengapa laporan harus membaca status `REVERSED`, dan apa arti delapan invarian rekonsiliasi.

Persediaan perpetual: setiap pergerakan stok memposting jurnal saat itu juga. Itu ciri yang membuat sistem ini ERP, dan sekaligus alasan stok, layer FIFO, hutang, piutang, dan buku besar harus bergerak dalam satu transaksi database.

## Posting jurnal

- Jurnal dibuat oleh **service**, tidak oleh controller. Pintu utamanya `WmsAccountingService::post()`, dipanggil dari method per dokumen (`postLpb`, `postNpk`, `postInvoice`, `postPayment`, `postPengirimanTransfer`, `postPenerimaanTransfer`, `postFakturPenjualan`, …). Aset punya `AsetAccountingService`.
- Setiap posting berjalan di dalam `DB::transaction()` yang sama dengan dokumen sumbernya. Rollback dokumen ikut membatalkan jurnal dan nomor yang dialokasikan.
- `post()` menolak jurnal tak seimbang (selisih debit-kredit lebih dari 0,01) dan memeriksa period lock lewat `AccountingPeriodService::assertOpen()`.
- Kunci keunikan jurnal otomatis adalah `(sumber_transaksi, reff_id)`. `reff_id` biasanya id baris dokumen; satu pengecualian disengaja: jurnal revaluasi kurs memakai kunci periode `YYYYMM` karena satu jurnal mewakili banyak baris, dan unique index itulah yang menjamin satu periode hanya bisa diposting sekali.
- Batas yang diketahui: `post()` memakai `updateOrCreate` sehingga memposting ulang kunci yang sama menimpa jurnalnya, termasuk yang sudah `REVERSED`; toleransi 0,01 membolehkan selisih sen tersimpan. Keduanya temuan audit yang belum dikerjakan ([audit algoritma](../backlog/alg-audit-algoritma.md), ALG-006 dan ALG-007).

## Struktur data

| Tabel | Isi |
|---|---|
| `wms_jurnal` | header: `no_jurnal`, `tanggal`, `sumber_transaksi`, `reff_id`, `status`, `total_debit`, `total_kredit`, `reversal_of_id`, pembuat dan pemosting |
| `wms_jurnal_detail` | baris: `coa_id`, `debit`, `kredit`, `keterangan`, dan **`gudang_id`** |
| `wms_bagan_akun` | COA: `kode_akun`, kategori, posisi normal, `is_postable`, `is_cash_bank`, `klasifikasi_fiskal` |
| `accounting_settings` | peta peran → akun (kunci seperti `HUTANG_USAHA`, `PERSEDIAAN_DALAM_PERJALANAN`) |
| `accounting_period_locks` | rentang terkunci; status `LOCKED`/`UNLOCKED` |
| `wms_permintaan_persetujuan` | permintaan maker-checker |

Status jurnal: `DRAFT`, `PENDING_APPROVAL` (jurnal manual menunggu penyetuju), `POSTED`, `REVERSED`. Karena semua laporan hanya menghitung status tertentu, jurnal manual yang belum disetujui otomatis tidak muncul di laporan mana pun.

### Dimensi gudang

`wms_jurnal_detail.gudang_id` membawa nilai persediaan per gudang langsung di GL. Setiap titik posting yang mengenal gudang menandai **semua** baris dokumennya, bukan hanya baris persediaan. Ini keputusan user (2026-09-16): dimensi di baris jurnal, bukan akun persediaan terpisah per gudang. Konsekuensi: jurnal pembalik harus menyalin `gudang_id`; versi otomatis (`reverseAutomaticJournal`) melakukannya, versi jurnal manual (`JurnalController`) belum (ALG-018).

## `REVERSED` dan `Jurnal::scopeBerlaku`

Membalik dokumen tidak menghapus apa pun: jurnal asal diubah menjadi `REVERSED` dan dibuat jurnal pembalik berstatus `POSTED` dengan debit dan kredit ditukar. Maka saldo yang benar adalah **jumlah keduanya**. Laporan yang hanya membaca `POSTED` melihat pembaliknya saja, dan saldo salah sebesar nilai dokumen: persediaan negatif, GRNI positif, sementara neraca saldo tetap seimbang sehingga tidak ada alarm.

Cacat ini ada di semua laporan keuangan sampai 2026-11-20. Perbaikannya satu tempat: `Jurnal::scopeBerlaku()` (`whereIn status ['POSTED','REVERSED']`, konstanta `Jurnal::STATUS_BERLAKU`), dipakai oleh laporan keuangan, rekonsiliasi fiskal, PPh Badan, pajak tangguhan, dashboard eksekutif, dan tie-out aging piutang. Aturannya:

- Query laporan dan saldo atas `wms_jurnal` memakai `berlaku()`, bukan `where('status','POSTED')`.
- Raw query `DB::table('wms_jurnal')` memakai `whereIn('status', ['POSTED','REVERSED'])` seperti di `AccountingReconciliationService`.
- Pengujiannya `JurnalDibalikTest`: posting, balik, lalu setiap laporan kembali ke nilai awal.

## Mapping akun

`AccountingSetting::accountId($key)` mengembalikan id akun atau melempar `RuntimeException` bila mapping hilang, akun tidak aktif, atau kategori/posisi normalnya tidak cocok. Kecocokan dicek terhadap whitelist `$expected` di dalam method itu. **Kunci baru harus ditambahkan di dua tempat**: konstanta dan whitelist `$expected`; kunci yang hanya ada di konstanta ditolak walau barisnya sah.

Dua jebakan COA (trap 4 di `CLAUDE.md`):

- `DatabaseSeeder` memakai `updateOrCreate(['kode_akun' => …])`; kode yang sudah terpakai akan **mengganti nama akun hidup** tanpa galat. Cek kode benar-benar kosong sebelum menambah akun. Migrasi akun baru (contoh `2026_10_01_000001`) menolak bila kode sudah dipakai nama lain.
- Akun kontra punya posisi normal berlawanan dengan kategorinya (`1591 Akumulasi Penyusutan`, `4102 Retur Penjualan`, `5201 Diskon Pembelian`) dan itu benar.

Akun penting yang dirujuk di dokumen lain: 1303 Barang Dalam Proses Produksi, 1304 Persediaan Dalam Perjalanan, 2109 Hutang PPN Keluaran, 2111 Biaya Masih Harus Dibayar, 2112 Uang Muka Pelanggan, 5203/5204 serapan tenaga kerja dan overhead, 5308 Kerugian Pembatalan Produksi, 4204 dan 5303 laba/rugi selisih kurs. Rinciannya lewat `AccountingSetting` dan seeder; jangan menyalin kode akun dari dokumen ini tanpa memeriksa.

## Delapan invarian rekonsiliasi

`AccountingReconciliationService::checks()` mengembalikan delapan pemeriksaan (diverifikasi di kode 2026-11-20). Satu invarian merah berarti dua pandangan atas fakta yang sama sudah berbeda; ia tidak memperbaiki apa pun, hanya membuka mata.

| Kunci | Membandingkan | Arti bila merah |
|---|---|---|
| `stock` | `bahans.stok_onhand` vs jumlah sisa layer per bahan | master dan layer berbeda; kemungkinan pergerakan stok yang tidak lewat `StokGudangService` |
| `journal` | `total_debit` vs `total_kredit` pada header jurnal `POSTED`/`REVERSED` | jurnal tak seimbang. Membaca kolom header, bukan jumlah detail (ALG-007) |
| `invoice` | `sisa_tagihan` faktur pembelian vs `grand_total` dikurangi pembayaran | pembayaran tidak tersinkron |
| `grni` | nilai LPB yang belum ditagih vs saldo akun clearing GRNI | LPB atau faktur diposting tanpa jurnal pasangannya, atau pembulatan menumpuk (ALG-008) |
| `ap` | sisa tagihan + akumulasi revaluasi kurs vs saldo Hutang Usaha | hutang di GL berbeda dari subledger faktur |
| `ar` | `sisa_tagihan` faktur penjualan `POSTED`/`PARTIALLY_PAID` vs saldo Piutang Usaha | sisi jual diawasi sama seperti sisi beli; ditambah 2026-09-18 karena set sebelumnya asimetris |
| `transit` | saldo 1304 vs nilai layer `IN_TRANSIT` plus nilai selisih transfer yang belum diselesaikan | transfer dua fase tidak seimbang. Selisih kurang terima sengaja tetap di 1304 |
| `wip` | saldo GL 1303 vs jumlah WIP perintah kerja berjalan | biaya diserap tanpa baris subledger, atau pembulatan melepas WIP kurang |

Aturan untuk subledger baru: bila sebuah dokumen bisa menumpuk saldo neraca, beri ia invarian **saat membangunnya**, bukan sesudahnya. Satu hal yang belum ada: pembanding nilai layer vs saldo GL persediaan sebagai invarian terjadwal; padanannya hanya di halaman rekonsiliasi gudang (ALG-012).

`php artisan wms:periksa-invarian` berjalan 06:30 dan mengembalikan kode keluar non-nol saat ada yang merah, sehingga bisa dipakai di CI atau monitor.

## Period lock

Periode terkunci menolak posting bertanggal di dalamnya (`assertOpen()`). Ini penguncian sumber jurnal, bukan "lock pelaporan pajak": ia tidak menyimpan masa pajak, hash file, atau status lapor. Pembukaan kunci sengaja tidak melewati maker-checker (ditawarkan, ditolak user 2026-09-16).

Akibat yang pernah menggigit: tanggal yang diperiksa harus tanggal kejadian sebenarnya. Jurnal penerimaan transfer yang dulu memakai tanggal pengiriman menolak seluruh penerimaan bila periode pengiriman sudah dikunci; kini memakai tanggal terima.

## Maker-checker

Cakupan (keputusan user): jurnal manual, pembalikan terkendali, perubahan COA dan mapping.

- Jurnal manual memakai kolom status (`DRAFT → PENDING_APPROVAL → POSTED`).
- Pembalikan LPB/NPK/retur dan perubahan COA/mapping memakai `wms_permintaan_persetujuan` lewat `PersetujuanOperasiService`. Jenis aksi adalah konstanta tetap (`REVERSE_LPB`, `REVERSE_NPK`, `REVERSE_RETUR`, `COA_UBAH`, `COA_MAPPING`); payload adalah data, tidak pernah kode atau callable terserialisasi.
- Penyetuju tidak boleh pembuat. Pemeriksaan ini ada di **service**, bukan hanya policy, karena `Gate::before` melolosi Super Admin dan pemeriksaan di policy saja akan menggugurkan tujuannya.
- Perubahan COA hanya digerbang bila akun "load-bearing" (termasuk mapping atau punya baris jurnal); akun baru yang belum dipakai tersimpan langsung. `updateMapping()` selalu butuh persetujuan.
- Role `accounting_manager` hanya menyetujui (termasuk faktur supplier); detail role ada di [otorisasi dan autentikasi](otorisasi-dan-autentikasi.md).

## Yang bukan jurnal

- Transfer internal: sejak 2026-09-16 memposting jurnal (dua fase lewat 1304 sejak 2026-09-18).
- Penyusutan fiskal: perhitungan pajak, tidak dijurnal.
- Gate pass: kustodi berpindah, kepemilikan tidak; tanpa jurnal.
- Pinjaman vendor (loaner): tanpa jurnal, layer, atau aset.
- BAP jasa: jurnal baru di faktur, bukan di penerimaan.
