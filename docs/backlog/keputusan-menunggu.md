# Keputusan menunggu

Keputusan produk dan akuntansi yang belum diambil pemilik produk. Engineer tidak memutuskannya sendiri: jawabannya mengubah jurnal, tarif, atau perilaku posting.

Daftar ini diverifikasi terhadap kode pada 2026-11-20 dan sejalan dengan "Open-items index" `CLAUDE.md`, yang sejak hari itu hanya meringkasnya. Butir lama yang sudah dibangun tidak ada di sini (lihat [backlog](README.md#sudah-diputuskan-dan-dibangun)). Begitu sebuah keputusan diambil: pindahkan ke "Decisions the user has already made" di `CLAUDE.md`, hapus dari sini, dan ubah status tugas terkait. Kerangka tiap entri ada di [template](../templates/template-keputusan-menunggu.md).

| ID | Keputusan | Status | Rujukan | Commit |
|---|---|---|---|---|
| KEP-001 | Aturan wajib NSFP saat posting faktur penjualan vs alur Coretax | ⛔ | `FakturPenjualanService` (posting menolak faktur ber-PPN tanpa NSFP) | — |
| KEP-002 | Representasi PPN: 11% efektif vs 12% atas DPP nilai lain 11/12; kode transaksi `01` vs `04` | ⛔ | `tarif_ppn`, [pajak Indonesia](../architecture/pajak-indonesia.md) | — |
| KEP-003 | Koreksi PPN Masukan pada retur pembelian | ⛔ | `ReturPembelian`, `WmsAccountingService::postReturPembelian` | — |
| KEP-004 | Tarif per kode objek pajak (royalti `24-103-01` 15%, sistem hanya punya satu tarif PPh 23) | ⛔ | `KodeObjekPajak::DAFTAR`, `tax_rates` | — |
| KEP-005 | Tarif lebih tinggi 100% bila penerima penghasilan tanpa NPWP | ⛔ | [pajak Indonesia](../architecture/pajak-indonesia.md) | — |
| KEP-006 | Penomoran bukti potong dan apakah penerbitan menunggu nomor Coretax | ⛔ | `wms_bukti_potong`, `BuktiPotongService::terbitkan` | — |
| KEP-007 | `NPWP_PERUSAHAAN` dan `NITKU_PERUSAHAAN` di konfigurasi | ⛔ | `config/app.php`, `.env.example` | — |
| KEP-008 | Barcode / QR untuk putaway dan picking | ⛔ | keputusan user 2026-09-13: ditunda; bila dikerjakan, per bundling/kit, bukan per item | — |
| KEP-009 | Menamai ulang 21 tabel domain yang belum berawalan `wms_` | ⛔ | [utang terukur](../architecture/utang-terukur-dan-riwayat.md) | — |

## KEP-001: NSFP sebelum posting atau sesudah unggah Coretax

- **Konteks.** Faktur penjualan ber-PPN tidak bisa diposting tanpa `no_faktur_pajak`. Menurut PER-11/PJ/2025 Pasal 43, NSFP diberikan DJP otomatis saat e-Faktur diunggah dan disetujui; impor XML tidak memuat NSFP. Jadi alur resminya mungkin: terbitkan → unggah → NSFP diisi balik.
- **Pilihan.** (a) Pertahankan aturan sekarang: NSFP diisi manual sebelum posting (cocok bila faktur dibuat di tempat lain lebih dulu). (b) Izinkan posting tanpa NSFP dengan status "menunggu NSFP", lalu isi balik setelah persetujuan DJP dan blokir pelaporan sampai terisi.
- **Dampak.** (b) mengubah status dokumen, laporan piutang, dan preflight; NSFP bukan lagi syarat posting tetapi syarat pelaporan. Tidak mengubah jurnal.
- **Pemilik keputusan.** pemilik produk (akuntansi pajak).

## KEP-002: PPN 12% dengan DPP nilai lain atau 11% efektif

- **Konteks.** Sistem menghitung 11% lewat `tarif_ppn`. Skema resmi (PMK 131/2024 Pasal 3) adalah tarif 12% atas DPP nilai lain 11/12, efektif 11%; barang mewah 12% atas harga jual. Template Excel Coretax memakai kolom DPP, DPP Nilai Lain, Tarif PPN. Kode transaksi untuk penjualan biasa dengan DPP nilai lain belum pasti (`01` atau `04`).
- **Pilihan.** (a) Tetap 11% efektif secara internal dan memetakan ke format resmi saat ekspor. (b) Menyimpan DPP nilai lain dan tarif 12% di data faktur.
- **Dampak.** (b) mengubah struktur faktur, hitungan, dan jurnal; (a) menunda keputusan ke pembuatan ekspor. Kode transaksi harus dipastikan dari sumber resmi lebih dulu.
- **Pemilik keputusan.** pemilik produk.

## KEP-003: PPN Masukan pada retur pembelian

- **Konteks.** `ReturPembelian` tidak menyimpan DPP atau PPN, dan jurnalnya mengurangi hutang, GRNI, dan persediaan tanpa mengurangi PPN Masukan. Untuk SPT Masa PPN ini celah material.
- **Pilihan.** (a) Tambah DPP/PPN pada retur dan jurnal pengurang PPN Masukan. (b) Biarkan dan koreksi di luar sistem.
- **Dampak.** (a) mengubah jurnal retur dan menambah kolom; wajib diuji lewat route. Blokir pekerjaan rekap SPT Masa PPN.
- **Pemilik keputusan.** pemilik produk dan akuntansi.

## KEP-004: Tarif per kode objek pajak

- **Konteks.** `wms_kode_objek_pajak` hanya berisi tiga kode (`24-104-29`, `24-100-02`, `28-403-02`) tanpa tarif; tarif ada di `tax_rates`. Royalti `24-103-01` berarti 15% menurut sheet REF BPPU, padahal sistem hanya punya satu tarif PPh 23 (2%): memilihnya akan memotong nilai yang salah. Kode terverifikasi lain belum di-seed: `24-104-28`, `24-104-30`, `24-104-24` (semuanya 2%).
- **Pilihan.** (a) Tetap satu tarif PPh 23 dan menolak kode bertarif lain. (b) Memindahkan tarif ke per kode objek.
- **Dampak.** (b) mengubah perhitungan potongan di `PembayaranFakturController` dan snapshot di faktur.
- **Pemilik keputusan.** pemilik produk (pajak).

## KEP-005: Tarif 100% lebih tinggi tanpa NPWP

- **Konteks.** UU PPh Pasal 23 ayat 1a menaikkan tarif 100% bila penerima tanpa NPWP (terverifikasi dari naskah konsolidasi). Perlakuan Coretax saat impor BPPU untuk penerima tanpa NPWP/NIK valid tidak ditemukan di sumber resmi. Sistem tidak menerapkannya sekarang: `PembayaranFakturController` memotong dengan tarif snapshot faktur, preflight hanya memperingatkan (`PENERIMA_TANPA_NPWP_VALID`), dan penerima ber-NIK tanpa NPWP tetap diterima untuk penerbitan.
- **Pilihan.** (a) Hitung otomatis saat identitas kosong. (b) Peringatan di preflight saja.
- **Dampak.** (a) mengubah nominal potongan dan jurnal.
- **Pemilik keputusan.** pemilik produk (pajak).

## KEP-006: Penomoran bukti potong

- **Konteks.** Bukti potong sudah dibangun (2026-11-20, [PJK-006](pjk-pajak.md)) dengan **asumsi kerja**: nomor resmi berasal dari DJP/Coretax, diketik pengguna saat penerbitan (`nomor_bukti_djp`, wajib saat itu, bebas format, unik, tidak pernah dibuat sistem atau `DocumentNumberService`); referensi internal adalah id baris. Status `DRAFT → DITERBITKAN → DIBATALKAN | DIGANTI`. Penerbitan resmi lewat e-Bupot Unifikasi; belum ada konfirmasi pemilik atas asumsi ini.
- **Pilihan.** (a) Pertahankan: DITERBITKAN hanya setelah nomor Coretax diketik. (b) Izinkan status terbit sebelum nomor ada, sehingga `nomor_bukti_djp` tidak lagi wajib di langkah itu. (c) Tambah nomor internal dari `DocumentNumberService` di samping nomor DJP.
- **Dampak.** (b) dan (c) mengubah skema atau validasi penerbitan dan preflight; tidak mengubah jurnal (slip tidak memposting apa pun).
- **Pemilik keputusan.** pemilik produk.

## KEP-007: Identitas perusahaan di konfigurasi

- **Konteks.** `config('app.npwp_perusahaan')` dan `config('app.nitku_perusahaan')` dibaca dari `NPWP_PERUSAHAAN` dan `NITKU_PERUSAHAAN`; keduanya kosong di `.env.example`, dan preflight melaporkan `NPWP_PERUSAHAAN_TIDAK_VALID` selama tidak terisi. Tidak ada master identitas perusahaan teraudit (nama legal, alamat, NITKU per cabang).
- **Pilihan.** (a) Cukup variabel lingkungan. (b) Master identitas perusahaan di database dengan riwayat.
- **Dampak.** (b) menambah tabel dan snapshot identitas penjual pada faktur terbit.
- **Pemilik keputusan.** pemilik produk; nilai sebenarnya diisi pemilik di `.env` (jangan ditulis di repo).

## KEP-008: Barcode / QR

- **Konteks.** Ditunda user 2026-09-13. Catatan desain yang dipertahankan: per bundling/kit, bukan per item.
- **Pilihan.** tetap ditunda, atau dikerjakan untuk putaway dan picking per kit.
- **Dampak.** menyentuh putaway, picking, dan label; tidak menyentuh jurnal.
- **Pemilik keputusan.** pemilik produk.

## KEP-009: Prefix `wms_` untuk 21 tabel lama

- **Konteks.** Diukur 2026-09-18: 21 tabel domain tidak berawalan `wms_` (mis. `bahans`, `gudangs`, `suppliers`, `pelanggans`, `stok_gudangs`, `transfer_gudangs`). Menamainya ulang melewati trap 6 (nama kolom dan tabel dibaca JS) demi keuntungan kosmetik.
- **Pilihan.** biarkan, atau satu proyek rename terkoordinasi.
- **Dampak.** menyentuh setiap model, string `DB::table`, seeder, endpoint Yajra, dan JS; ukur ulang jumlah tabelnya dengan `SHOW TABLES` sebelum merencanakan.
- **Pemilik keputusan.** pemilik produk dan teknis.
