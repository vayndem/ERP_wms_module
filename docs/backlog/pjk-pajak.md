# PJK: Pajak

Tugas pajak beserta urutan dan prasyaratnya (peta jalan). Fakta regulasi dan status verifikasinya ada di [pajak Indonesia](../architecture/pajak-indonesia.md); keputusan yang menunggu di [keputusan menunggu](keputusan-menunggu.md).

Aturan yang tidak berubah: tidak ada layout eksternal yang ditebak, tidak ada halaman pajak kedua (perluas halaman Laporan Pajak DJP dan preflight), dan nilai tarif tidak di-hardcode di exporter.

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| PJK-001 | Validator identitas pajak bersama (NPWP 16, NIK 16, NITKU 22, NSFP 17) dan rule pembungkusnya | ✅ | `app/Support/IdentitasPajak.php`, `app/Rules/`, `tests/Unit/IdentitasPajakTest.php` | — |
| PJK-002 | Kolom `nik`, `nitku`, `tipe_identitas`, `status_pkp` pada supplier dan pelanggan, normalisasi NPWP yang valid | ✅ | migrasi `2026_11_20_000101`, `tests/Feature/IdentitasPajakMasterTest.php` | — |
| PJK-003 | `tanggal_faktur_pajak` dan `ppnbm` pada kedua faktur, snapshot pembeli saat posting, unik NSFP faktur penjualan | ✅ | migrasi `2026_11_20_000103`, `tests/Feature/FakturPajakMetadataTest.php` | — |
| PJK-004 | Tabel kode objek pajak dengan tiga kode terverifikasi dan foreign key dari faktur pembelian | ✅ | migrasi `2026_11_20_000102`, `database/seeders/KodeObjekPajakSeeder.php` | — |
| PJK-005 | Preflight kepatuhan berkategori di halaman Laporan Pajak DJP | ✅ | `app/Services/KepatuhanPajakService.php`, `tests/Feature/KepatuhanPajakTest.php` | — |
| PJK-006 | Bukti potong PPh 23 dan 4(2) dibangun dari pembayaran `POSTED` per objek: tabel `wms_bukti_potong`, sinkron idempoten, penerbitan dengan nomor DJP yang diketik, pembatalan dan pembetulan, tie-out ke buku besar, preflight lima kategori baru, lampiran PDF, halaman di grup pajak, ekspor Excel dan XML BPPU; `LaporanPajakDjpService::buktiPotong()` kini membaca pembayaran per tanggal bayar | ✅ | `app/Services/BuktiPotongService.php`, `BuktiPotongExportService.php`, `app/Models/BuktiPotong.php`, migrasi `2026_11_20_000201`, `tests/Feature/BuktiPotongTest.php`; asumsi penomoran di [KEP-006](keputusan-menunggu.md#kep-006-penomoran-bukti-potong) | — |
| PJK-007 | Koreksi PPN Masukan pada retur pembelian | ⛔ | [KEP-003](keputusan-menunggu.md#kep-003-ppn-masukan-pada-retur-pembelian) | — |
| PJK-008 | Ekspor XML faktur keluaran Coretax, diblokir bila preflight gagal, menyertakan retur dan tukar tambah | ⛔ | XSD resmi belum didapat; [KEP-001](keputusan-menunggu.md#kep-001-nsfp-sebelum-posting-atau-sesudah-unggah-coretax), [KEP-002](keputusan-menunggu.md#kep-002-ppn-12-dengan-dpp-nilai-lain-atau-11-efektif) | — |
| PJK-009 | Lampiran faktur penjualan: tambahkan `FakturPenjualan` ke whitelist `LampiranService::INDUK` (model sudah memakai `HasLampiran`) | ⬜ | `app/Services/LampiranService.php` | — |
| PJK-010 | Snapshot identitas penjual (master identitas perusahaan) dan pemakaian NITKU di keluaran | ⛔ | [KEP-007](keputusan-menunggu.md#kep-007-identitas-perusahaan-di-konfigurasi) | — |
| PJK-011 | Retensi dan legal hold: kelas retensi, tanggal boleh dimusnahkan, status arsip; `LampiranService::hapus()` menghapus langsung | ⬜ | `app/Services/LampiranService.php`, [pajak Indonesia](../architecture/pajak-indonesia.md#retensi-dokumen) | — |
| PJK-012 | Tambah kode objek pajak terverifikasi lain: `24-104-28`, `24-104-30`, `24-104-24` (semua PPh 23 2%) | ⬜ | `app/Models/KodeObjekPajak.php` (`DAFTAR`) | — |
| PJK-014 | Coba XML BPPU di Coretax dan perbaiki asumsi yang ternyata salah (`Document` = `CommercialInvoice`, `TaxCertificate` = `N/A`, `TaxBase` tidak dibulatkan, `Rate` dari snapshot); XSD resmi tidak pernah didapat, jadi XML ini belum terbukti | ⛔ | akses Coretax atau XSD resmi; `app/Services/BuktiPotongExportService.php` | — |
| PJK-013 | Rekap SPT Masa PPN: snapshot masa, rekonsiliasi ke COA 2109 dan PPN Masukan, kompensasi, pembetulan, hash artefak | ⬜ | bergantung PJK-007 dan PJK-011 (PJK-006 selesai) | — |

## Urutan yang disarankan

1. **Fondasi (selesai).** PJK-001 sampai PJK-005.
2. **Peristiwa pajak yang benar.** PJK-006 (bukti potong per pembayaran) selesai; PJK-007 (retur pembelian) menunggu keputusan KEP-003. Bukti potong baru benar-benar selesai setelah PJK-014 membuktikan XML-nya di Coretax. PJK-012 dan PJK-009 tidak bergantung keputusan dan bisa dikerjakan kapan saja.
3. **Retensi dan legal hold (PJK-011)** sebelum produksi menghasilkan banyak artefak pajak.
4. **Ekspor faktur keluaran (PJK-008).** Prasyarat keras: dapatkan `TaxInvoice.xsd` resmi atau spesifikasi yang terbaca, putuskan KEP-001 dan KEP-002, dan konfirmasi kode transaksi. Prototipe XML yang ada tidak boleh dipakai melapor.
5. **Rekap SPT Masa PPN (PJK-013) paling akhir.** Paling mudah salah bila dibangun sebelum koreksi retur dan identitas selesai; struktur lampiran induk belum terverifikasi.

## Prasyarat per tugas

| Tugas | Prasyarat |
|---|---|
| untuk PJK-006 (terpenuhi) | satu bukti per pembayaran dan objek, bukan per faktur; snapshot identitas supplier saat terbit; sinkron idempoten. Dua keputusan masih terbuka dan tidak menghalangi: penomoran (KEP-006, dikerjakan dengan asumsi nomor DJP diketik) dan tarif per KOP (KEP-004) |
| untuk PJK-014 | akses ke Coretax atau XSD/spesifikasi resmi; supplier uji dengan NITKU 22 digit |
| untuk PJK-007 | keputusan KEP-003; kolom DPP/PPN pada retur; jurnal pengurang PPN Masukan; test lewat route |
| untuk PJK-008 | XSD resmi; KEP-001 dan KEP-002; identitas penjual (PJK-010); artefak ekspor per masa dengan versi dan hash agar reproduksibel |
| untuk PJK-009 | tidak ada |
| untuk PJK-010 | KEP-007 |
| untuk PJK-011 | kebijakan titik awal retensi (diusulkan: akhir tahun buku dokumen, 10 tahun; KUP Pasal 28 ayat 11 tidak eksplisit soal titik awal) |
| untuk PJK-012 | tidak ada; satu kategori jasa tidak selalu memetakan ke satu kode objek, jadi pilihan per jenis jasa tetap perlu di UI |
| untuk PJK-013 | PJK-006 (selesai), PJK-007, PJK-011; klasifikasi dapat/tidak dikreditkan untuk PPN Masukan; struktur lampiran Coretax terverifikasi |

Kode di `LaporanPajakDjpService` (prototipe) memakai `tanggal` faktur biasa, tidak membawa NITKU, nama atau alamat penjual, PPnBM, retur, maupun tukar tambah, dan tidak memblokir faktur yang belum siap lapor. Perlakukan sebagai bahan dataset dan preflight, bukan keluaran.
