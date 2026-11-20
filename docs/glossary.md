# Glossary

Istilah domain dan teknis yang dipakai di kode dan dokumen. Definisi singkat; rinciannya di dokumen yang ditautkan dan di [`CLAUDE.md`](../CLAUDE.md).

## Bisnis dan akuntansi

| Istilah | Arti |
|---|---|
| **PO (pesanan pembelian)** | Pesanan ke supplier. Status `OPEN → CLOSED`. Tabel `wms_pesanan_pembelian`, kunci bisnis `no_po` |
| **LPB (penerimaan barang)** | Laporan penerimaan barang dari PO. Memposting layer persediaan dan jurnal Dr Persediaan / Cr GRNI. Model `PenerimaanBarang`, kunci `id_lpb` |
| **BAP** | Berita acara penerimaan jasa (`PenerimaanJasa`). Tidak memposting jurnal; biaya jasa masuk GL saat faktur supplier diposting |
| **GRNI** | *Goods received not invoiced*: akun penampung antara LPB dan faktur supplier. Saldonya harus sama dengan nilai LPB yang belum ditagih (invarian `grni`) |
| **NPK (pemakaian barang)** | Dokumen pemakaian stok oleh produksi atau unit lain. Memakai layer FIFO, memposting Dr Beban / Cr Persediaan. Bila diberi perintah kerja, biayanya masuk WIP. Lifecycle `DRAFT → POSTED → REVERSED` |
| **Faktur pembelian** | Tagihan supplier (`FakturPembelian`, tabel `wms_faktur_pembelian`). Dicocokkan PO–LPB–faktur sebelum jurnal hutang diposting. Status `UNPAID → PARTIALLY_PAID → PAID` atau `VOID` |
| **Faktur penjualan** | Tagihan ke pelanggan (`FakturPenjualan`). Menimbulkan piutang dan PPN Keluaran. Faktur ber-PPN tidak bisa diposting tanpa NSFP |
| **Surat jalan** | Pengiriman ke pelanggan. Di sinilah stok keluar (FIFO) dan HPP dibukukan, terpisah dari faktur |
| **HPP** | Harga pokok penjualan: biaya layer yang keluar lewat surat jalan, dijurnal ke Beban Pokok Penjualan |
| **Three-way match** | Pencocokan PO, LPB, dan faktur sebelum hutang diposting (`ThreeWayMatchService`). Selisih memblokir posting |
| **Jurnal** | Header `wms_jurnal` dengan detail `wms_jurnal_detail`. Dibuat oleh service, tidak oleh controller. Lihat [akuntansi dan jurnal](architecture/akuntansi-dan-jurnal.md) |
| **`REVERSED`** | Status jurnal asal yang sudah dibalik oleh jurnal pembalik. Tetap dihitung di laporan (lewat `Jurnal::scopeBerlaku`) karena pembaliknya `POSTED`; dibuang salah satu, saldo salah |
| **Pembalikan terkendali** | Satu-satunya cara mengoreksi dokumen `POSTED`: jurnal pembalik dengan jejak audit. Dokumen posted tidak pernah diedit atau dihapus |
| **Invarian rekonsiliasi** | Pemeriksaan kesetaraan yang harus selalu benar (mis. saldo GRNI = LPB belum ditagih). Delapan jumlahnya, dijalankan `wms:periksa-invarian` |
| **Period lock** | Penguncian rentang tanggal (`accounting_period_locks`); posting di periode terkunci ditolak. Pembukaan kunci tidak termasuk maker-checker (diputuskan user) |
| **Maker-checker** | Pembuat dan penyetuju harus orang berbeda: jurnal manual, pembalikan terkendali, perubahan COA dan mapping. Self-approval ditolak di service |
| **COA / mapping akun** | Bagan akun (`wms_bagan_akun`) dan peta peran → akun (`accounting_settings`, `AccountingSetting`). Kunci baru harus ditambah di konstanta dan di whitelist `accountId()` |
| **Dimensi gudang** | Kolom `wms_jurnal_detail.gudang_id`: nilai persediaan per gudang dibaca dari GL, tanpa COA terpisah per gudang |
| **CALK** | Catatan atas laporan keuangan: angka dari GL, narasi disimpan per periode dan bisa disunting |
| **Revaluasi kurs** | Penilaian ulang item moneter valas (hutang, piutang, kas) pada kurs penutup per periode (PSAK 10). Persediaan tidak disentuh |
| **Uang muka pelanggan / supplier** | Pembayaran di muka yang menunggu dipakai ke faktur. Akun 2112 (pelanggan) dan mapping `UANG_MUKA_SUPPLIER` |
| **Piutang aging** | Umur piutang per tanggal acuan, direkonstruksi dari faktur, retur, dan pembayaran, bukan dibaca dari `sisa_tagihan` hari ini |
| **Perintah kerja (work order)** | Pesanan produksi untuk pelanggan (`wms_data_pesanan`). Biaya diserap ke WIP, dibekukan saat selesai, dilepas proporsional per pengiriman |
| **WIP (1303)** | Barang Dalam Proses Produksi. Saldo GL harus sama dengan jumlah WIP perintah kerja berjalan (invarian `wip`) |
| **BOM / routing** | BOM: standar bahan per hasil jadi, dipakai laporan varians (tidak memposting jurnal). Routing: urutan operasi dan pusat kerja; tarif per jam di pusat kerja dipakai serapan tenaga kerja dan overhead |
| **Serapan tenaga kerja / overhead** | Jam kerja × tarif pusat kerja diserap ke WIP lewat `SerapanProduksiService` (akun 5203 dan 5204) |
| **Subkontrak** | Pengiriman bahan ke pihak luar untuk diproses (`SubkontrakService`), lewat transfer ke gudang subkontrak sehingga layer FIFO tetap utuh |
| **Plafon kredit** | `pelanggans.plafon_kredit`, dihitung terhadap eksposur piutang, kiriman belum difaktur, dan pesanan belum kirim (`PlafonKreditService`). Menembusnya butuh gate `tembusPlafonKredit` |
| **Landed cost** | Biaya tambahan (ongkir, bea) yang dikapitalisasi ke layer aktif (`LandedCostService`). Lawan akun harus liabilitas atau aset berposisi kredit; 2111 Biaya Masih Harus Dibayar tersedia untuk itu |

## Persediaan dan gudang

| Istilah | Arti |
|---|---|
| **Layer persediaan** | Satu lapis nilai stok (`wms_layer_persediaan`): kuantitas awal, sisa, `unit_cost`, gudang, status. Dasar FIFO dan valuasi |
| **FIFO / FEFO** | Pemakaian layer terlama dulu; FEFO: yang paling cepat kedaluwarsa dulu, lalu FIFO. Per gudang, bukan satu kolam global |
| **Status layer** | `AVAILABLE`, `QC_HOLD`, `IN_TRANSIT`, `REVERSED`, `TRANSFER_SHORTAGE` |
| **Gudang Normal / Consider / Rusak** | Jenis gudang (`Gudang::NORMAL/CONSIDER/RUSAK`). Consider: karantina, stok masuk berstatus `QC_HOLD`. Rusak: terminal; stok tidak bisa keluar maupun ditransfer langsung ke sana tanpa lewat Consider |
| **Transfer gudang** | Pemindahan antar gudang dua fase: `DIKIRIM` (layer tujuan `IN_TRANSIT`) lalu `DITERIMA`. Nilai layer ikut berpindah |
| **Barang dalam perjalanan (1304)** | Akun Persediaan Dalam Perjalanan. Jurnal pengiriman Dr 1304 / Cr persediaan asal; penerimaan Dr persediaan tujuan / Cr 1304. Selisih kurang terima tetap di 1304 |
| **`TRANSFER_SHORTAGE`** | Layer penanda barang yang dikirim tetapi tidak sampai |
| **Stok onhand** | `bahans.stok_onhand`: total master. Harus sama dengan stok gudang + in-transit (invarian `stock`) |
| **Saldo gudang** | `stok_gudangs` per (gudang, bahan): tersedia, direservasi, dipesan. `StokGudangService::saldo()` adalah helper tulis |
| **Reservasi** | Penguncian stok untuk kebutuhan tertentu (`ReservasiPersediaan`). Approval Material Request mereservasi sebanyak yang bebas dan tidak pernah memblokir approval |
| **Cross-dock** | Janji, bukan pergerakan: menandai barang yang baru diterima untuk pesanan penjualan tertentu lewat reservasi |
| **Kitting** | Perakitan atau penguraian kit (mini-BOM tanpa WIP). Hasil perakitan memakai layer seperti biasa |
| **Opname / siklus ABC** | Penghitungan fisik stok. Siklus: kelas A bulanan, B triwulanan, C tahunan (`AbcAnalysisService::SIKLUS_BULAN`) |
| **QC hold / putaway / picking** | Tahan mutu, penempatan ke bin, dan pengambilan FEFO/FIFO. Lihat WMS Control Center |
| **Nomor mutasi** | `MTS-YYYYMM-ULID`, kunci unik non-sekuensial di `mutasi_stoks` |
| **Lot / serial / expiry** | Pelacakan per lot dan nomor seri; `bahans.wajib_lot` dan `wajib_expiry` memaksa pengisiannya |

## Pajak Indonesia

| Istilah | Arti |
|---|---|
| **PKP** | Pengusaha Kena Pajak. Kolom `status_pkp` pada supplier dan pelanggan, kosong berarti tidak diketahui |
| **NPWP / NIK / NITKU** | NPWP 16 digit (15 digit lama diberi 0 di depan), NIK 16 digit, NITKU 22 digit. Validator hanya memeriksa panjang dan digit; tidak ada checksum resmi. [`IdentitasPajak`](../app/Support/IdentitasPajak.php) |
| **NSFP** | Nomor Seri Faktur Pajak. Menurut PER-11/PJ/2025 diberikan DJP saat e-Faktur diunggah dan disetujui. Sistem menerima bentuk lama `000.000-00.00000000` dan 17 digit polos; tidak pernah menafsirkannya |
| **DPP** | Dasar Pengenaan Pajak. Di sistem: `total_dpp` faktur penjualan, `dpp_ppn` faktur pembelian |
| **PPN Masukan / Keluaran** | PPN dari pembelian (aset, mapping `PPN_MASUKAN`/`PPN_IMPOR`) dan dari penjualan (liabilitas 2109, `PPN_KELUARAN`) |
| **PPh 23 / 22 / 4(2)** | Pajak yang dipotong saat pembayaran supplier. Snapshot jenis dan tarif di faktur pembelian; nominal nyata per pembayaran (`wms_pembayaran_faktur.potongan_pph`) |
| **KOP (kode objek pajak)** | Kode objek pemotongan, mis. `24-104-29`. Tabel `wms_kode_objek_pajak` hanya memuat tiga kode yang terverifikasi |
| **Bukti potong (BPPU)** | Bukti pemotongan PPh unifikasi (PPh 23 dan 4(2)). Sudah dibangun sebagai dokumen `wms_bukti_potong`, satu per pembayaran `POSTED` yang memotong PPh; nomor resminya dari DJP/Coretax dan diketik pengguna saat penerbitan. XML ekspornya belum pernah dicoba di Coretax |
| **Coretax** | Sistem inti administrasi perpajakan DJP; impor lewat XML. Ekspor XML resmi belum dibangun karena XSD resmi belum didapat |
| **SPT Masa PPN (1111)** | Laporan PPN bulanan. Belum dibangun |
| **PPnBM** | Pajak penjualan atas barang mewah. Hanya kolom dan field; tidak masuk hitungan maupun jurnal |
| **PSAK 1 / 10 / 16 / 46** | Penyajian laporan keuangan; pengaruh kurs valas; aset tetap; pajak penghasilan (pajak tangguhan). Dipakai sebagai acuan perilaku, bukan klaim kepatuhan audit |

## Teknis

| Istilah | Arti |
|---|---|
| **Modular monolith** | Satu aplikasi Laravel dengan domain dipisah per file route dan service, bukan layanan terpisah |
| **Form Request** | Kelas validasi di `app/Http/Requests`; controller tidak boleh memanggil `->validate()` |
| **Policy / Gate** | Otorisasi per model (policy) dan lintas model (gate di `AuthServiceProvider`). Super Admin lolos lewat `Gate::before` |
| **Yajra / `wmsDataTable()`** | Endpoint tabel server-side (Yajra) dan factory Alpine yang membacanya. Nama kolom yang dikirim Yajra dibaca JS, jadi tidak boleh diganti sembarangan |
| **daisyUI token** | Warna lewat token tema (`base-100`, `primary`, …), bukan literal seperti `bg-white` |
| **Prefix `wms_`** | Tabel domain berawalan `wms_`; tabel framework tidak. Ada 21 tabel domain lama tanpa prefix (diukur 2026-09-18) |
| **Status uppercase** | Kolom status berisi kode mesin huruf besar (`POSTED`, `VOID`); label Indonesia hanya di tampilan |
| **Jaring pengaman (net)** | Test yang masuk lewat satu pintu dan menangkap satu kelas cacat. Lihat [jaring pengaman test](architecture/jaring-pengaman-test.md) |
| **Trap** | Jebakan yang sudah pernah terjadi, dicatat di bagian "Behavioural rules" `CLAUDE.md` |
| **Scheduler / queue worker** | `php artisan schedule:work` dan `php artisan queue:work`; keduanya wajib agar pengingat dan notifikasi jalan |
