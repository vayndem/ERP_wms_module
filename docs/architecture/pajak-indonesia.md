# Pajak Indonesia

Apa yang sudah dibangun untuk pajak, apa yang belum, dan fakta regulasi yang dipakai beserta status verifikasinya.

Status 2026-11-20: bukti potong sudah dibangun, tetapi XML-nya belum terbukti di Coretax.

Aturan kerja: **tidak ada layout eksternal (XML Coretax, BPPU, SPT) yang ditebak.** Hanya fakta berlabel TERVERIFIKASI dari sumber resmi yang dipakai untuk membangun; sisanya ditulis sebagai belum terverifikasi. Riset sumbernya dikerjakan 2026-11-20 dan semua tanggal akses di bawah adalah 2026-11-20. Urutan pekerjaan berikutnya ada di [peta jalan pajak](../backlog/pjk-pajak.md); keputusan yang menunggu di [keputusan menunggu](../backlog/keputusan-menunggu.md).

## Yang sudah dibangun

| Area | Keadaan | Letak |
|---|---|---|
| PPN | dihitung 11% lewat `tarif_ppn` (snapshot per dokumen). Jurnal: PPN Masukan (aset) dan PPN Impor di sisi beli; PPN Keluaran (liabilitas 2109) di sisi jual | `FakturPenjualanService`, `WmsAccountingService::postInvoice/postFakturPenjualan` |
| PPh 23, 22, 4(2) | faktur pembelian menyimpan snapshot `jenis_pph`, `dasar_pph`, `tarif_pph`; **nominal nyata dipotong per pembayaran** (`wms_pembayaran_faktur.potongan_pph`), dan `faktur.pph` hanyalah agregat | `PembayaranFakturController`, `TaxRate::rateFor()` |
| Tarif | tabel `tax_rates` dengan periode efektif; seeder: PPh 23 2%, PPh 22 1,5%, PPh 4(2) 10% | `DatabaseSeeder` |
| PPh Badan | tarif 22%, fasilitas Pasal 31E, kompensasi rugi tertua dulu (jendela lima tahun), unik per tahun pajak | `PajakPenghasilanService` |
| Rekonsiliasi fiskal | beda tetap dan beda waktu; klasifikasi fiskal di COA dengan override per baris jurnal; penyusutan fiskal ganda dihitung otomatis dan tidak dijurnal | `RekonsiliasiFiskalService` |
| Pajak tangguhan | metode liabilitas neraca (PSAK 46); jumlah yang sudah diakui dibaca dari GL, bukan field tersimpan | `PajakPenghasilanService` |
| Identitas pajak (fondasi B1) | validator NPWP/NIK/NITKU/NSFP, kolom identitas pada supplier dan pelanggan, snapshot pembeli pada faktur penjualan, unik NSFP penjualan | `App\Support\IdentitasPajak`, `App\Rules\*` |
| Metadata faktur pajak | `tanggal_faktur_pajak` dan `ppnbm` (hanya kolom) pada kedua tabel faktur | migrasi `2026_11_20_000103` |
| Kode objek pajak | tiga kode: `24-104-29`, `24-100-02` (PPh 23), `28-403-02` (PPh 4(2)); tanpa tarif | `wms_kode_objek_pajak`, `KodeObjekPajakSeeder` |
| Preflight kepatuhan | temuan berkategori, bukan boolean; tampil sebagai bagian halaman Laporan Pajak DJP | `KepatuhanPajakService` |
| Bukti potong PPh 23 dan 4(2) (tahap B2) | satu bukti per pembayaran `POSTED` yang memotong PPh (bukan per faktur), tabel `wms_bukti_potong` dengan status `DRAFT → DITERBITKAN → DIBATALKAN \| DIGANTI`; sinkron idempoten dari pembayaran; snapshot identitas penerima beku saat terbit; pembetulan membuat versi baru | `BuktiPotongService`, `BuktiPotong`, `BuktiPotongController`, migrasi `2026_11_20_000201`, `tests/Feature/BuktiPotongTest.php` |
| Ekspor bukti potong | XML `BpuBulk` dari contoh resmi DJP (**belum pernah dicoba di Coretax**) dan Excel/CSV internal lewat `GenericTableExport`; diblokir bila preflight menemukan penghalang | `BuktiPotongExportService`, rute `bukti-potong.xml` dan `bukti-potong.excel` |
| Lampiran bukti potong | PDF dari Coretax disimpan di samping bukti (`bukti-potong` di `LampiranService::INDUK`, kategori `BUKTI_POTONG`) | `LampiranService` |
| Halaman bukti potong | menu "Bukti Potong PPh" di grup pajak, di belakang `BuktiPotongPolicy` | `resources/views/bukti_potong`, `routes/accounting.php` |
| Prototipe laporan DJP | halaman dan XML per bulan; **bukan ekspor resmi**. `buktiPotong()` di dalamnya kini membaca pembayaran `POSTED` per tanggal bayar, bukan agregat `faktur.pph` | `LaporanPajakDjpService`, view `laporan_pajak_djp` |

Rincian bukti potong: fakta pemotongan adalah **pembayaran**, bukan faktur. `wms_pembayaran_faktur.potongan_pph` dan `tanggal_pembayaran` menentukan kapan dan berapa yang dipotong; `faktur.pph` hanyalah agregat yang ditulis ulang tiap pembayaran atau void, sehingga satu faktur yang dibayar di dua bulan adalah dua pemotongan di dua masa. Unik `(pembayaran_id, urutan, versi)`: `urutan` menyisakan ruang bila satu pembayaran kelak memuat beberapa objek pajak, `versi` memungkinkan pembetulan hidup berdampingan dengan bukti terbit yang digantikannya. Dasar pemotongan dihitung `potongan ÷ (tarif/100)` sehingga mencerminkan dasar pembayaran itu. Bukti terbit tidak pernah diubah diam-diam: pembayaran yang di-void menandainya `perlu_pembatalan` dan memunculkan temuan sampai orang membatalkan atau menggantinya. Tidak ada cek kunci periode karena bukti tidak memposting apa pun ke buku besar. `tieOut()` membandingkan bukti per jenis per masa dengan kredit bersih akun Hutang PPh dari jurnal `PELUNASAN_HUTANG` termasuk jurnal pembaliknya, dan menampilkan baris merah bila berbeda. Nomor bukti resmi bukan milik kita: `nomor_bukti_djp` unik, nullable, diketik pengguna saat penerbitan, tidak pernah dibuat sistem. Keputusan terbuka: [KEP-005](../backlog/keputusan-menunggu.md#kep-005-tarif-100-lebih-tinggi-tanpa-npwp) dan [KEP-006](../backlog/keputusan-menunggu.md#kep-006-penomoran-bukti-potong).

Rincian perilaku identitas: validator hanya memeriksa panjang dan digit (NPWP 16, NIK 16, NITKU 22) setelah memangkas pemisah (`.`, `-`, spasi); NPWP 15 digit lama disimpan dengan angka 0 di depan; NSFP diterima dalam bentuk lama `000.000-00.00000000` dan 17 digit polos dan **tidak pernah ditafsirkan**. `wms_faktur_pembelian` sengaja tanpa unik NSFP karena tiap penerbit menomori sendiri; duplikat hanya dideteksi per supplier di preflight. Migrasi menormalisasi NPWP yang sudah ada **hanya bila hasilnya valid**; yang tidak valid dibiarkan dan muncul di preflight.

Kategori temuan preflight: `IDENTITAS_KOSONG`, `IDENTITAS_FORMAT_SALAH`, `NSFP_FORMAT_SALAH`, `NSFP_DUPLIKAT`, `NPWP_PERUSAHAAN_TIDAK_VALID`, `FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK`, `PPH_TANPA_KODE_OBJEK`, ditambah lima untuk bukti potong: `PEMBAYARAN_PPH_TANPA_BUKTI_POTONG`, `BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP`, `BUKTI_POTONG_TANPA_KODE_OBJEK`, `PENERIMA_TANPA_NPWP_VALID` (hanya peringatan) dan `BUKTI_POTONG_PEMBAYARAN_VOID`. Semua kecuali peringatan itu, bersama NPWP perusahaan yang tidak valid, menghalangi ekspor XML bukti potong (`KepatuhanPajakService::PENGHALANG_EKSPOR_BUPOT`). PPh 22 tidak pernah ditandai kekurangan kode objek karena belum ada kode terverifikasi untuknya. Kode objek di database produksi tidak terisi oleh `migrate`; jalankan `php artisan db:seed --class=KodeObjekPajakSeeder` sekali (idempoten).

## Yang belum dibangun

| Item | Mengapa belum | Prasyarat |
|---|---|---|
| Ekspor XML faktur keluaran Coretax | XSD resmi `TaxInvoice.xsd` tidak berhasil diperoleh; hanya sampel dan template Excel; urutan elemen mengikat tidak terjamin | XSD atau spesifikasi resmi yang terbaca |
| Pembuktian XML BPPU di Coretax | XSD resmi tidak pernah didapat; XML dibuat hanya dari contoh `bppu.xml` dan kamus kolom v.3. Asumsi kami sendiri, bukan DJP: `Document` = `CommercialInvoice` dengan nomor dan tanggal faktur supplier, `TaxCertificate` = `N/A`, `TaxBase` tidak dibulatkan ke rupiah penuh, `Rate` dari snapshot tarif faktur | mencoba impor di Coretax dan mencocokkan hasilnya ([PJK-014](../backlog/pjk-pajak.md)) |
| Rekap SPT Masa PPN | sumber koreksi belum lengkap (retur pembelian tanpa DPP/PPN), struktur lampiran belum terverifikasi | koreksi PPN retur, bukti potong, snapshot masa |
| Retensi dan legal hold | tidak ada kelas retensi, `retention_until`, atau status arsip; `LampiranService::hapus()` menghapus file dan baris | kebijakan titik awal retensi |
| Lampiran faktur penjualan | `FakturPenjualan` memakai trait `HasLampiran` tetapi tidak terdaftar di `LampiranService::INDUK` (yang terdaftar: faktur pembelian, pesanan pembelian, penerimaan barang, pembayaran faktur, retur pembelian, pemeriksaan kualitas, aset, bukti potong) | menambah ke whitelist ([PJK-009](../backlog/pjk-pajak.md)) |
| Snapshot identitas penjual | `NPWP_PERUSAHAAN` dan `NITKU_PERUSAHAAN` di `.env` (kosong di `.env.example`); tidak ada master identitas perusahaan teraudit | keputusan konfigurasi |
| PPnBM dalam hitungan | hanya kolom dan field | spesifikasi |

## Fakta regulasi

Legenda: **[T]** terverifikasi: isi berkas resmi dibaca langsung. **[B]** belum terverifikasi: sekunder, ingatan, atau inferensi. Jangan dikodekan sebagai kebenaran sebelum diperiksa ulang.

### Faktur pajak dan NSFP

| Fakta | Status | Sumber |
|---|---|---|
| Format impor Coretax adalah XML, bukan CSV lama; struktur `TaxInvoiceBulk > TaxInvoice > GoodService` pada sampel resmi | [T] | halaman DJP "Template XML dan Converter Excel ke XML" dan sampel `Sample Faktur PK Template v.1.4.xml` |
| Kode + NSFP = 17 digit: 2 kode transaksi, 2 kode status, 13 digit NSFP yang diberikan DJP (Pasal 37 ayat 1) | [T] | PER-11/PJ/2025 |
| NSFP diberikan otomatis saat e-Faktur diunggah dan disetujui (Pasal 43); batas unggah dan persetujuan tanggal 20 bulan berikutnya, e-Faktur tanpa persetujuan bukan Faktur Pajak (Pasal 44) | [T] | PER-11/PJ/2025 |
| Impor XML tidak memuat NSFP | [T] | halaman DJP, template Excel resmi |
| Pemetaan satu-satu kolom Excel ke elemen XML | [B] | inferensi dari urutan dan nilai sampel |
| Format tanggal XML `yyyy-mm-dd`; pembulatan dua desimal (template) vs rupiah penuh (slide PER-11) | [B] | konflik antar sumber |
| Kode transaksi untuk penjualan biasa dengan DPP nilai lain 11/12: `01` atau `04` | [B] | template memberi contoh `04`; lampiran PER-11 belum terbaca |
| Kode status faktur normal (diduga `00`), cara impor faktur pengganti | [B] | |
| Isi PER-12/PJ/2026 terhadap ketentuan faktur; konflik penanda "Dicabut" vs "Aktif" untuk PER-11 | [B] | teks resmi tidak terunduh |

Sumber resmi (akses 2026-11-20): `pajak.go.id/id/reformdjp/coretax/template-xml-dan-converter-excel-ke-xml`; paket `ConverterEfakturCoretax__v1.6.zip` (template Excel v1.6.1); `pajak.go.id/sites/default/files/lampiran/PER-11_PJ_2025_0.pdf`.

### Identitas

| Fakta | Status | Sumber |
|---|---|---|
| WP OP penduduk memakai NIK; badan, OP bukan penduduk, dan instansi pemerintah memakai NPWP format 16 digit (Pasal 2 ayat 1) | [T] | PMK 112/PMK.03/2022, `jdih.kemenkeu.go.id` |
| NPWP 16 digit = angka 0 + NPWP 15 digit (Pasal 7 ayat 2) | [T] | PMK 112/PMK.03/2022 |
| Sejak 1 Januari 2024 NIK dan NPWP 16 digit dipakai dalam layanan DJP; NITKU adalah identitas tempat kegiatan usaha (Pasal 1 angka 6, Pasal 11) | [T] | PMK 112/PMK.03/2022 |
| Template: ID TKU penjual wajib 22 digit; pembeli bukan TIN diisi `000000` pada ID TKU dan 16 nol pada NPWP/NIK | [T] | sheet `Keterangan` template DJP |
| Struktur internal 22 digit NITKU (diduga NPWP 16 digit + 6 digit tempat usaha) | [B] | inferensi dari sampel; PMK 112 tidak menyebut panjang 22 |
| Checksum NPWP atau NIK resmi | [B] | tidak ditemukan di sumber resmi mana pun; tidak ditemukan bukan bukti tidak ada. Karena itu tidak ada checksum di validator |
| NIK tepat 16 digit dalam konteks DJP | [B] | dari contoh dan sumber sekunder |

### PPN dan DPP

| Fakta | Status | Sumber |
|---|---|---|
| Barang mewah dan sejenisnya: 12% atas harga jual (Pasal 2); selain itu 12% atas DPP nilai lain = 11/12 harga jual, efektif 11% (Pasal 3), berlaku 1 Januari 2025 | [T] | PMK 131 Tahun 2024, `jdih.kemenkeu.go.id` |
| Template Excel v1.6.1 (Januari 2026) masih memuat tarif PPN 12 dengan DPP nilai lain 11/12 | [T] | paket converter v1.6 |
| Tarif dan DPP tidak berubah sampai 2026-11-20 | [B] | tidak menemukan peraturan perubah, tetapi pencarian JDIH tidak menyeluruh |
| Isi pasal PMK 11 Tahun 2025 (DPP nilai lain dan besaran tertentu) | [B] | hanya dibaca dari siaran pers DJP 18 Februari 2025 |

### PPh pemotongan dan kode objek pajak

| Fakta | Status | Sumber |
|---|---|---|
| Format BPPU (PPh 23, 4(2)): elemen `BpuBulk > Bpu` dan kamus kolom; contoh `bppu.xml` | [T] | `BPPU Excel to XML v.3.xlsx` dan `bppu.zip`, `pajak.go.id` |
| KOP `24-104-29` jasa perawatan/perbaikan mesin, peralatan, listrik, AC (2%); `24-100-02` sewa harta selain tanah/bangunan (2%); `28-403-02` sewa tanah/bangunan (10%, PPh 4(2) final) | [T] | sheet `REF` BPPU v.3 |
| `24-103-01` adalah **Royalti** (15%), bukan sewa; sumber sekunder yang menyebutnya sebagai sewa keliru | [T] | sheet `REF` BPPU v.3 |
| KOP lain yang terverifikasi tetapi belum di-seed: `24-104-28` instalasi (2%), `24-104-30` perawatan kendaraan (2%), `24-104-24` jasa komputer (2%) | [T] | sheet `REF` BPPU v.3 |
| PPh 23 2% atas sewa harta dan jasa tertentu (Pasal 23 ayat 1 huruf c) | [T] | UU PPh versi konsolidasi, `pajak.go.id` (belum dibandingkan dengan UU HPP) |
| Tarif lebih tinggi 100% bila penerima tanpa NPWP (Pasal 23 ayat 1a) | [T] naskah UU; [B] perlakuan Coretax saat impor | UU PPh versi konsolidasi |
| Dasar hukum 10% sewa tanah/bangunan (PP 34/2016) | [B] | tidak dibaca |
| Setor paling lambat tanggal 15 bulan berikutnya (PPh 4(2), 23); lapor paling lama 20 hari setelah masa pajak (Pasal 94 ayat 2, Pasal 171 ayat 1) | [T] naskah asli; [B] versi terkini | PMK 81 Tahun 2024; PMK 54/2025 dan PMK 1/2026 dilaporkan mengubahnya, tidak diperiksa |
| Pembulatan DPP dan PPh BPPU ke rupiah penuh (Pasal 129 PER-11/PJ/2025) | [B] | dilaporkan DDTC |

### SPT Masa PPN

| Fakta | Status | Sumber |
|---|---|---|
| Lapor dan setor PPN terutang paling lambat akhir bulan berikutnya setelah masa pajak (PMK 81/2024 Pasal 171 ayat 13, Pasal 94 ayat 3 huruf e); wajib lapor walau tanpa transaksi | [T] | PMK 81/2024; halaman DJP SPT Masa PPN |
| Pajak Masukan dikreditkan pada masa yang sama (Pasal 375); masa berikutnya paling lama 3 masa pajak selama belum dibebankan sebagai biaya (KT-08/2025) | [T] | PMK 81/2024; Keterangan Tertulis DJP KT-08/2025 (20 Februari 2025) |
| Struktur induk dan lampiran SPT Masa PPN di Coretax (A1, A2, B1, B2, B3, C) dan fungsinya | [B] | hanya A2 dan B3 tercantum di halaman DJP yang masih mereferensikan sistem lama |
| Rumus induk dan perlakuan kelebihan (kompensasi atau restitusi) | [B] | UU PPN Pasal 9 tidak dibaca |
| Format impor XML SPT PPN dan lampiran | [B] | berkas contoh di halaman template DJP belum dibuka |

### Retensi dokumen

| Fakta | Status | Sumber |
|---|---|---|
| Buku, catatan, dan dokumen dasar pembukuan (termasuk hasil pengolahan data elektronik) disimpan 10 tahun di Indonesia (UU KUP Pasal 28 ayat 11) | [T] | UU KUP versi konsolidasi, `pajak.go.id` |
| Catatan, bukti pembukuan, dan data pendukung administrasi keuangan disimpan 10 tahun sejak akhir tahun buku (UU 8/1997 Pasal 11 ayat 1); dokumen boleh dialihkan ke media lain tetapi asli otentik tertentu tetap disimpan | [T] | UU 8/1997, `peraturan.go.id` |
| Titik awal 10 tahun menurut KUP | [B] | tidak eksplisit di ayat; rekomendasi aman: hitung dari akhir tahun buku |
| PP 87/1999, PP 88/1999, sanksi Pasal 39 KUP | [B] | tidak dibaca |

## Memakai dokumen ini

- Sebelum mengodekan fakta berlabel [B], baca sumber resminya dan ubah labelnya di sini di perubahan yang sama.
- Nilai tarif tidak di-hardcode di exporter; rujuk `tax_rates`.
- Jangan membuat halaman pajak kedua; perluas halaman Laporan Pajak DJP dan preflight yang ada.
- Setiap kolom pajak baru: cek `$fillable` versus `$guarded` modelnya, beri nama index pendek (batas 64 karakter), status uppercase, uang `DECIMAL(18,2)`, tarif `DECIMAL(8,4)` ([database](../guides/database.md)).
