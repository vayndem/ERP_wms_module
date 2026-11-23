# ALG: Audit algoritma backend (2026-11)

Hasil tinjauan algoritma backend (kebenaran dan efisiensi) pada 2026-11-20, dengan status per temuan.

Tinjauan itu baca-saja. Setiap temuan yang masih terbuka di bawah **diverifikasi ulang di kode pada 2026-11-20** sebelum ditulis; temuan yang sudah diperbaiki hari itu bertanda ✅. Kode asal temuan tetap dipertahankan di kolom Tugas (K = kritis, T = tinggi, S = sedang, R = rendah) supaya bisa dicari silang dengan ringkasan lama. Skenario yang melibatkan angka pembulatan dihitung tangan di PHP 8.3 oleh peninjau; hitung ulang sebelum menjadikannya test.

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| ALG-001 | K1: semua laporan membaca jurnal `POSTED` saja padahal pembalikan membuat jurnal asal `REVERSED`; kini `Jurnal::scopeBerlaku` | ✅ | `app/Models/Jurnal.php`, `tests/Feature/JurnalDibalikTest.php` | — |
| ALG-002 | K2: nomor mutasi stok memakai sekuens bulanan dengan batas 999; kini `MTS-YYYYMM-ULID` | ✅ | `app/Services/StokGudangService.php`, `tests/Feature/NomorMutasiStokTest.php` | — |
| ALG-003 | T1: jurnal penerimaan transfer bertanggal pengiriman dan periode terkunci menolak penerimaan; kini tanggal terima | ✅ | `WmsAccountingService::postPenerimaanTransfer`, `tests/Feature/PenerimaanTransferTanggalTest.php` | — |
| ALG-004 | T3: pembulatan biaya per unit meninggalkan sisa WIP permanen; kini pelepasan terakhir = sisa | ✅ | `app/Models/DataPesanan.php`, `tests/Feature/PelepasanWipSisaTest.php` | — |
| ALG-005 | T5: penyusutan bisa terposting ganda per periode; kini unik `(aset_id, period_label)` | ✅ | migrasi `2026_11_20_000001`, `tests/Feature/PenyusutanGandaTest.php` | — |
| ALG-006 | S1: `post()` memakai `updateOrCreate` sehingga posting ulang menimpa jurnal, termasuk yang `REVERSED` | ⬜ | `WmsAccountingService::post` (baris `updateOrCreate`), `AsetAccountingService` | — |
| ALG-007 | S2: toleransi 0,01 mengizinkan jurnal tak seimbang tersimpan; invarian `journal` membaca header, bukan detail | ⬜ | `WmsAccountingService::post`, `AccountingReconciliationService::checks` | — |
| ALG-008 | S3: GRNI meninggalkan residu per faktur karena pembulatan di dua tingkat | ⬜ | `WmsAccountingService::postLpb`, `postInvoice` | — |
| ALG-009 | S4: perakitan kit bisa gagal tak seimbang; nilai layer berbeda dari nilai jurnal; penguraian membagi nilai menurut kuantitas fisik lintas satuan | ⬜ | `app/Services/KittingService.php` | — |
| ALG-010 | S6: landed cost membulatkan `unit_cost` ke 4 desimal sehingga nilai hilang; layer `IN_TRANSIT` boleh dialokasi | ⬜ | `app/Services/LandedCostService.php` | — |
| ALG-011 | S7: jurnal penerimaan transfer dan pengiriman mengakui nilai berbeda karena pembulatan di dua titik | ⬜ | `WmsAccountingService::nilaiTransferPerKategori` | — |
| ALG-012 | S5: tidak ada invarian terjadwal nilai layer = saldo GL persediaan | ⬜ | `AccountingReconciliationService::checks`, `RekonsiliasiGudangService` | — |
| ALG-013 | S8: `consumeStock` menjumlah float tanpa toleransi dan menduplikasi FIFO `ambilLayer` | ⬜ | `WmsAccountingService::consumeStock` | — |
| ALG-014 | S9: tidak ada indeks `wms_jurnal(status, tanggal)`; `whereDate` tidak sargable; buku besar tanpa paginasi | ⬜ | migrasi jurnal, `FinancialStatementService`, `AccountingPeriodService` | — |
| ALG-015 | S10: query di dalam loop pada jalur laporan dan job bulanan (WIP, revaluasi kurs, aging piutang) | ⬜ | `AccountingReconciliationService`, `RevaluasiKursService`, `PiutangAgingService` | — |
| ALG-016 | S11: biaya perintah kerja bisa masuk setelah biaya per unit dibekukan | ⬜ | `WmsAccountingService::postNpk` | — |
| ALG-017 | T2: urutan penguncian tidak konsisten antar alur, berpotensi deadlock | ⬜ | `PemakaianBarangController`, `StokGudangService`, `TransferGudangService`, `KittingService` | — |
| ALG-018 | R1: jurnal pembalik jurnal manual membuang dimensi gudang | ⬜ | `JurnalController` (pembalikan) | — |
| ALG-019 | R2: three-way match mencocokkan per baris PO pertama dan per LPB, bukan kumulatif | ⬜ | `ThreeWayMatchService`, `PenerimaanBarangService` | — |
| ALG-020 | R3: retur pembelian mencari faktur lewat string `no_invoice` tanpa scope supplier | ⬜ | `WmsAccountingService::postReturPembelian` | — |
| ALG-021 | R4: `DocumentNumberService` mengunci baris sekuens sampai transaksi terluar commit | ⬜ | `DocumentNumberService` | — |
| ALG-022 | R5: `ambilLayer` bisa membuat alokasi mikro dari galat float | ⬜ | `StokGudangService::ambilLayer` | — |
| ALG-023 | R6: perbandingan float pada tampilan laporan dan pemotongan `take` di PHP | ⬜ | `FinancialStatementService`, `PengingatService` | — |
| ALG-024 | T4: selisih kurs belum terealisasi tidak dibalik saat pelunasan faktur valas | ⬜ | `RevaluasiKursService`, `WmsAccountingService::postPayment` | — |

## Urutan yang disarankan

1. **ALG-017 bersama ALG-013**: satu urutan kunci baku `bahans` → `stok_gudangs` (urut id) → layer, gudang selalu urut `id`, dan satu implementasi FIFO. K2 sudah mengangkat titik panas sekuens; sisanya tinggal kunci.
2. **ALG-024**: realisasi selisih kurs saat pelunasan, plus mengeluarkan faktur lunas dari penjumlahan invarian `ap`.
3. **ALG-006, ALG-007, ALG-012**: jurnal ketat dan idempoten, invarian detail, invarian nilai layer. Menutup kelas drift pembulatan; ALG-008–ALG-011 menghilangkan sumber drift-nya.
4. **ALG-014, ALG-015** saat volume jurnal bertambah.
5. Rendah (ALG-018–ALG-023) bila menyentuh area yang sama.

## Rincian temuan yang masih terbuka

Semuanya diverifikasi di kode pada 2026-11-20. "Dampak" adalah dugaan peninjau bila tidak ditandai pasti.

### Tinggi

**ALG-017 (T2) deadlock.** Empat pasang urutan kunci berlawanan: (1) `PemakaianBarangController` mengunci `bahans` lalu `stok_gudangs`, sedangkan `StokGudangService::masuk` mengunci `stok_gudangs` lalu `Bahan::increment`; (2) kitting mengunci layer (`ambilLayer`) lalu saldo, transfer mengunci saldo lalu layer; (4) `TransferGudangService::konfirmasi` mengunci gudang asal lalu tujuan, jadi dua konfirmasi berlawanan (A→B dan B→A) saling tunggu. Poin 3 (sekuens mutasi) hilang bersama K2. InnoDB membatalkan satu transaksi (galat 1213): bukan data rusak, tetapi 500 acak di jam sibuk. Frekuensi nyata belum diukur.

**ALG-024 (T4) selisih kurs.** `RevaluasiKursService` hanya merevaluasi faktur ber-`sisa_tagihan > 0`, dan `postPayment` tidak menyebut kurs sama sekali. Faktur USD yang direvaluasi rugi Rp 5.000.000 di Maret lalu dilunasi April meninggalkan kredit Rp 5.000.000 di Hutang Usaha tanpa faktur pendukung. Invarian `ap` malah menjumlahkan revaluasi semua faktur termasuk yang lunas, jadi alarm tidak berbunyi. PSAK 10 (selisih terealisasi saat penyelesaian) tidak dijalankan. Dampak magnitude tergantung ada tidaknya faktur valas di produksi.

### Sedang

- **ALG-006 (S1).** Pemanggilan kedua `post()` dengan kunci yang sama (retry, klik ganda yang lolos guard) menulis ulang isi jurnal; jurnal `REVERSED` bisa hidup lagi tanpa pembalik. Hapus-lalu-buat detail juga tidak satu transaksi di dalam `post()`, aman hanya karena pemanggil membungkusnya. Saran: tolak atau kembalikan yang ada, kecuali jalur edit DRAFT yang disengaja.
- **ALG-007 (S2).** Selisih ≤ 0,01 diterima; invarian `journal` membandingkan `total_debit` dan `total_kredit` header, tidak jumlah detail. Sumber ketidakseimbangan nyata: `postInvoice` menjumlah mentah per kategori lalu membulatkan dan mengkredit `grand_total` yang dibulatkan DB. Neraca saldo bisa melenceng beberapa sen per faktur tanpa tanda.
- **ALG-008 (S3).** Dua LPB masing-masing 100,004: kredit GRNI 100,00 + 100,00, debit penutup `round(200,008)` = 200,01. Residu 0,01 per faktur menggeser invarian `grni` begitu akumulasi melewati 0,01.
- **ALG-009 (S4).** Empat kategori komponen masing-masing 10,0049: kredit 4 × 10,00 = 40,00, debit `round(40,0196)` = 40,02, selisih 0,02 > 0,01 sehingga `post()` melempar dan perakitan di-rollback. `unit_cost` kit dibulatkan 4 desimal sehingga nilai layer ≠ nilai jurnal. Penguraian membagi nilai menurut jumlah fisik lintas satuan (10 m kain dan 10 pcs kancing dapat porsi sama); kelayakan bisnisnya belum dinilai.
- **ALG-010 (S6).** Alokasi Rp 123,45 ke layer 1.000.000 unit: kenaikan 0,00012345 dibulatkan 0,0001 sehingga nilai layer naik Rp 100 sementara jurnal mencatat 123,45. `allocate()` hanya menyaring `remaining_quantity > 0`, jadi layer `IN_TRANSIT` bisa menerima landed cost padahal nilainya di 1304.
- **ALG-011 (S7).** Dua alokasi 33,3333: pengiriman 33,33 + 33,33 = 66,66, penerimaan `round(66,6666)` = 66,67. Sisa 0,01 tergantung di 1304 dan invarian `transit` memerah setelah beberapa transfer.
- **ALG-012 (S5).** `checks()` menaruh nilai layer sebagai `amount` tanpa `expected`; pembanding nilai hanya di halaman rekonsiliasi gudang, tanpa alarm.
- **ALG-013 (S8).** `0,1 + 0,7 < 0,8` bernilai benar di float (`0.7999999999999999`), jadi NPK yang menghabiskan tepat seluruh stok ditolak. `ambilLayer` memakai toleransi 0,000001; `consumeStock` tidak.
- **ALG-014 (S9).** `wms_jurnal` hanya punya PK, `no_jurnal` unik, dan `(sumber_transaksi, reff_id)` unik; tidak ada indeks `tanggal` atau `(status, tanggal)`. Semua laporan memfilter keduanya lewat `whereHas('jurnal', …)` dengan `whereDate`, yang tidak memakai indeks. Buku besar memuat semua baris satu akun ke memori tanpa paginasi.
- **ALG-015 (S10).** *(sebagian, 2026-11-23: jalur WIP kini batch lewat `DataPesanan::saldoWipBatch()`, dua query tetap; sisanya masih terbuka.)* Dahulu `DataPesanan::berjalan()->get()->sum(saldoWip())` melakukan query per perintah kerja; `akumulasiSelisih()` satu query per faktur valas; `PiutangAgingService` memuat semua faktur non-draft lalu `whereIn`. `TanpaNPlusSatuTest` sudah menutup pola ini untuk halaman, bukan untuk jalur-jalur ini.
- **ALG-016 (S11).** `postNpk` memuat `DataPesanan::find()` tanpa kunci; `selesaikan()` mengunci dan membekukan biaya per unit. NPK bersamaan dapat menambah biaya setelah pembekuan.

### Rendah

- **ALG-018 (R1)** `JurnalController` menyalin hanya `coa_id`, `debit`, `kredit`, `keterangan` saat membalik; versi otomatis menyalin `gudang_id`. Pembalikan ganda bersamaan hanya ditolak unique `(REVERSAL, reff_id)` dengan galat duplicate key.
- **ALG-019 (R2)** `firstWhere('bahan_id', …)` pada PO dengan dua baris bahan sama; kelebihan kuantitas dibandingkan per LPB terhadap jumlah PO penuh (60 + 60 untuk PO 100 tidak terdeteksi).
- **ALG-020 (R3)** `FakturPembelian::where('no_invoice', …)` tanpa scope supplier; apakah nomor invoice bisa sama antar supplier belum dikonfirmasi (tidak ada unik yang ditemukan). Retur juga tidak mengoreksi PPN Masukan, itu keputusan terpisah ([KEP-003](keputusan-menunggu.md)).
- **ALG-021 (R4)** kunci sekuens `FIN_JR` per hari dan `EXT_*` per hari bertahan sampai transaksi dokumen selesai; throughput, bukan data.
- **ALG-022 (R5)** sisa float 1e-17 > 0 membuat satu layer tambahan menerima `take` mikro; toleransi kecukupan 1e-6 mengizinkan kekurangan yang tidak dialokasikan.
- **ALG-023 (R6)** `$row['amount'] != 0` pada float dapat menampilkan baris Rp 0,00; `lotSegeraKedaluwarsa` memuat semua lalu `take($limit)` di PHP.

## Sudah diperiksa dan dinyatakan benar

Agar tidak diulang: presisi kuantitas stok (DECIMAL(18,6), galat float jauh di bawah 1e-6); penomoran dokumen tidak membakar nomor saat form dibuka dan rollback ikut membatalkan sekuens; PPh Badan (tarif 22%, Pasal 31E, batas Rp 50 M, pembulatan PKP ke ribuan, kompensasi rugi lima tahun, unik tahun pajak); penyusutan garis lurus dan saldo menurun dibatasi residu; jurnal sisi penjualan seimbang secara konstruksi; idempotensi dokumen (transfer lewat `DocumentOperationGuard`, jurnal lewat unik `(sumber_transaksi, reff_id)`, revaluasi lewat kunci periode, PPh lewat unik tahun); `stok_gudangs` unik `(gudang_id, bahan_id)` dan indeks layer `(bahan_id, gudang_id, remaining_quantity)`.

Hal yang sudah dicatat `CLAUDE.md` sebagai keputusan sengaja tidak dilaporkan sebagai temuan: `saldo()` sebagai helper tulis, residual Lacak Pembelian, selisih transfer yang tinggal di 1304, tie-out aging piutang ditiadakan untuk satu pelanggan.
