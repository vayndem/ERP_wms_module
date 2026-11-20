# Persediaan dan FIFO

Bagaimana stok dicatat per gudang, bagaimana layer FIFO dikonsumsi, bagaimana transfer dua fase bekerja, dan apa yang tidak boleh disentuh di jalur baca.

Stok punya tiga lapis yang harus tetap sama: master (`bahans.stok_onhand`), saldo per gudang (`stok_gudangs`), dan layer (`wms_layer_persediaan`). Nilainya dijurnal ke GL (lihat [akuntansi dan jurnal](akuntansi-dan-jurnal.md)). Invarian `stock` memeriksa master vs layer; halaman rekonsiliasi gudang memeriksa saldo vs layer dan nilai vs GL.

## Layer dan statusnya

Satu layer = satu lapis nilai: gudang, bahan, `unit_cost`, `initial_quantity`, `remaining_quantity`, `transaction_date`, status, lot, dan asal (`source_type`, `source_id`, unik sebagai pasangan).

| Status | Arti |
|---|---|
| `AVAILABLE` | boleh dipakai NPK, surat jalan, transfer |
| `QC_HOLD` | tertahan inspeksi, atau stok masuk gudang Consider |
| `IN_TRANSIT` | tujuan transfer yang masih di jalan; nilainya ada di akun 1304, bukan di persediaan gudang |
| `REVERSED` | layer dari dokumen yang dibalik |
| `TRANSFER_SHORTAGE` | penanda barang yang dikirim tapi tidak sampai; kuantitas dan nilainya dinolkan |

**FIFO per gudang, bukan satu kolam global.** `LayerPersediaan` membawa `gudang_id`, jadi biaya mengikuti gudang. Pemakaian untuk picking memakai FEFO dulu lalu FIFO.

### Cara layer dikonsumsi

`StokGudangService::ambilLayer($gudang, $bahan, $jumlah, $tanggal, $statuses = ['AVAILABLE'])` adalah satu-satunya implementasi FIFO yang dipakai transfer, kitting, surat jalan, dan alur baru. Ia:

- mengunci layer (`lockForUpdate`), diurut `transaction_date` lalu `id`;
- hanya layer bertanggal tidak lebih baru dari tanggal dokumen, dengan `remaining_quantity > 0`;
- melewati layer yang lotnya diblokir atau sudah kedaluwarsa, dan menjelaskan hal itu di pesan galat ("N sudah kedaluwarsa dan M diblokir");
- melempar `RuntimeException` bila total layer kurang dari kebutuhan (toleransi 0,000001).

Pengecualian: `WmsAccountingService::consumeStock()` (jalur NPK) masih punya salinan algoritma sendiri dengan perbandingan float tanpa toleransi; menyatukannya masuk [audit](../backlog/alg-audit-algoritma.md) (ALG-013).

Satuan: `Bahan` boleh punya satuan kecil. NPK menyimpan `jumlah` (satuan input), `jumlah_stok` (sudah dikonversi), dan `satuan_transaksi`; konversi terjadi **sebelum** konsumsi FIFO.

## `StokGudangService`

| Method | Peran |
|---|---|
| `saldo($gudang, $bahan)` | membuat baris saldo bila belum ada (`insertOrIgnore`) lalu mengunci baris itu. **Helper tulis**, bukan getter |
| `masuk()` / `keluar()` | mengubah `stok_tersedia`, opsional `Bahan::stok_onhand` (`$affectGlobal`), dan mencatat `MutasiStok`. `keluar` menolak bila tersedia dikurangi direservasi kurang dari jumlah |
| `tambahPesanan()` / `kurangiPesanan()` | mengatur `stok_dipesan` |
| `ambilLayer()` | konsumsi FIFO di atas |

`affectGlobal` bernilai `false` untuk transfer: barang pindah gudang, total perusahaan tidak berubah. Parameter `$userId` eksplisit tersedia; pakai bila pemanggil bisa memberinya, karena `Auth::id()` bernilai null di seeder dan queue.

### Jalur baca tidak boleh menyentuh saldo

`saldo()` melakukan `insertOrIgnore` dan `lockForUpdate`. Memanggilnya dari halaman GET membuat baris `stok_gudangs` baru dan mengunci baris saat orang hanya melihat layar. Ini sudah terjadi: `CrossDockService::saran()` memanggilnya, dan `HalamanTanpaEfekSampingTest` yang menemukannya. Jalur baca meng-query `StokGudang` langsung.

### Nomor mutasi

`mutasi_stoks.nomor_mutasi` berbentuk `MTS-YYYYMM-<ULID>`. Dulu memakai sekuens internal bulanan dengan batas 999 untuk seluruh perusahaan: mutasi ke-1000 dalam sebulan menggagalkan semua transaksi stok. Ia juga mengunci satu baris sekuens di tiap pergerakan sehingga semua gudang saling serial. Nomor mutasi bukan nomor dokumen audit; kunci unik non-sekuensial cukup. `NomorMutasiStokTest` mengunci perilakunya.

## Transfer dua fase

```text
 DRAFT → DIAJUKAN → konfirmasi (DIKIRIM) → terima (DITERIMA)      atau DIBATALKAN
                      │                        │
 layer asal dikonsumsi│  layer tujuan dibuat   │  layer tujuan dilepas sebesar yang diterima,
 (ambilLayer)         │  IN_TRANSIT            │  status AVAILABLE (QC_HOLD bila tujuan Consider)
 jurnal: Dr 1304 /    │  dengan unit_cost asal │  jurnal: Dr persediaan tujuan / Cr 1304
 Cr persediaan asal   │                        │  bertanggal tanggal TERIMA
```

- **Nilai ikut barangnya.** Layer tujuan membawa `unit_cost` layer asal, jadi FIFO tidak di-rata-rata ulang.
- **Idempoten.** Konfirmasi dan terima memanggil `DocumentOperationGuard::claim()` (insert unik); permintaan ganda ditolak.
- **Kurang terima** (`jumlah_diterima` < `jumlah_dikirim`): layer yang tidak sampai menjadi `TRANSFER_SHORTAGE`, `stok_onhand` master dikurangi sebesar selisih (barang itu benar-benar hilang, dan `affectGlobal=false` tidak menurunkannya), dan nilainya **tetap di 1304**. Itu disengaja: barang yang berangkat dan tak tiba masih jadi masalah seseorang, dan akun bernama menjaganya terlihat. Invarian `transit` memperhitungkan nilai itu.
- **Penandaan gudang pada 1304.** Kedua sisi jurnal 1304 dilabeli gudang **tujuan** supaya saldonya menjawab "apa yang sedang menuju gudang B" dan nol di sana saat tiba.
- **Aturan jenis gudang** ditegakkan di service, bukan hanya flag: dari Rusak tidak bisa keluar; ke Rusak tidak bisa langsung (harus lewat Consider); dari Consider hanya lewat pemeriksaan Consider. `AturanJenisGudangTest` mengunci ini.

## QC hold, putaway, picking, reservasi

- **QC.** Penolakan sebagian menaruh stok tertahan di layer `QC_REJECT_{id}`, bukan `LPB_DETAIL`; pemeriksaan "masih ada yang tertahan" memeriksa kedua jenis sumber. Penjaga tertahan di `putaway()` masih hanya memeriksa `LPB_DETAIL`.
- **Putaway per baris**, dan `wms_lokasi_gudang.capacity` ditegakkan (kapasitas null berarti tak terbatas; kapasitas volumetrik dilewati bila datanya tidak ada).
- **Picking selesai menjadi NPK `DRAFT`**, bukan yang sudah diposting, supaya jalur jurnal/FIFO/period-lock yang sudah teruji tidak diduplikasi. `picking_order_id` mencegah duplikat.
- **Reservasi** (`ReservasiPersediaan`) mengunci stok bebas. Approval Material Request mereservasi sebanyak yang bebas, sebagian dan tidak fatal (keputusan user). Cross-dock memakai reservasi yang sama; `postingSuratJalan()` melepas tanda-tandanya sebelum mengonsumsi stok.
- **Kitting** adalah mini-BOM tanpa WIP; `postPerakitanKit()` mengembalikan null bila semua baris bernilai nol.
- **Lot dan expiry.** `bahans.wajib_lot` dan `wajib_expiry` memaksa pengisian; `wajib_expiry` tanpa `wajib_lot` ditolak karena kedaluwarsa menempel pada lot. Tanpanya FEFO dan semua penjaga kedaluwarsa diam.

## Retur penjualan dan layer

Retur penjualan membuat layer **baru** di biaya surat jalan (`source_type='RETUR_PENJUALAN'`), bukan membangkitkan layer asal yang mungkin sudah sebagian terpakai. Membangkitkannya akan merusak urutan FIFO.

## Hal yang sudah terjadi, supaya tidak berulang

| Kejadian | Pelajaran |
|---|---|
| Dua baris dokumen menyentuh baris yang sama dan yang kedua menimpa yang pertama | Baca ulang di bawah `lockForUpdate()` di dalam transaksi atau pakai `increment()`; jangan menghitung dari atribut yang dimuat sebelum loop (trap 2) |
| Penguraian kit multi-komponen selalu gagal | layer komponen kini diberi kunci `PERAKITAN_KIT_DETAIL`, bukan `PERAKITAN_KIT`, mengikuti preseden `TRANSFER_ALLOCATION` |
| Selisih transfer tidak pernah keluar dari master | `terima()` mengurangi master sebesar `jumlah_selisih` |
| Ringkasan rekonsiliasi gudang menghitung layer in-transit | `summary()` kini mengecualikannya seperti `perGudang()` |

Temuan audit yang masih terbuka di area ini: urutan kunci antar alur (ALG-017), pembulatan perakitan kit, landed cost dan nilai transfer (ALG-009, ALG-010, ALG-011), dan perbandingan float di konsumsi NPK (ALG-013).
