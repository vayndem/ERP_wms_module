# Roadmap

Status besar per area. Rincian per tugas (ID, rujukan) ada di [backlog](../backlog/README.md); halaman ini hanya gambaran.

✅ selesai dan ada di kode · 🔄 sedang jalan atau sebagian · ⬜ belum · ⛔ tertahan menunggu keputusan atau pihak luar · ➖ tidak dikerjakan

Status diambil dari kode dan test (diperiksa 2026-11-20), bukan dari catatan: bila ada beda dengan `CLAUDE.md`, kodenya yang benar.

## Alur bisnis

| Area | Status | Catatan |
|---|---|---|
| Beli: permintaan → PO → LPB → stok → NPK → faktur → pembayaran → jurnal | ✅ | revisi PO tercetak diarsipkan; retur pembelian belum mengoreksi PPN Masukan (⛔, [keputusan](../backlog/keputusan-menunggu.md)) |
| Jual: pelanggan → pesanan → surat jalan → faktur → penerimaan, retur | ✅ | plafon kredit ditegakkan lewat `PlafonKreditService`; uang muka pelanggan ada |
| Piutang aging, kinerja sales, cross-dock, BOM, routing | ✅ | |
| Produksi: perintah kerja, WIP, serapan tenaga kerja dan overhead, pembatalan | ✅ | output langsung ke pelanggan; tidak ada stok barang jadi (keputusan user) |
| Subkontrak bahan lewat transfer ke gudang subkontrak | ✅ | `SubkontrakService` |
| Aset tetap, jasa, kapitalisasi, gate pass, loaner, tukar tambah | ✅ | |

## Gudang dan persediaan

| Area | Status | Catatan |
|---|---|---|
| FIFO/FEFO per gudang, layer, reservasi, QC, putaway, picking, serial, lot | ✅ | |
| Transfer dua fase dan 1304 | ✅ | tanggal jurnal penerimaan memakai tanggal terima (diperbaiki 2026-11-20) |
| Opname dan siklus ABC, slotting, replenishment | ✅ | |
| Nomor mutasi stok tanpa batas 999 | ✅ | `MTS-YYYYMM-ULID` (2026-11-20) |
| Urutan kunci baku antar alur stok | ⬜ | audit T2 |
| Barcode / QR (per kit, bukan per item) | ⛔ | ditunda user |

## Akuntansi dan pajak

| Area | Status | Catatan |
|---|---|---|
| Laporan PSAK 1 lengkap, CALK, fiskal, PPh Badan, pajak tangguhan | ✅ | laporan memakai `Jurnal::scopeBerlaku` (2026-11-20) |
| Delapan invarian rekonsiliasi dan watchdog terjadwal | ✅ | invarian nilai layer vs GL belum ada (audit S5) |
| Maker-checker, period lock | ✅ | pembukaan kunci periode sengaja tidak di-maker-checker |
| Revaluasi kurs (PSAK 10) | 🔄 | realisasi selisih kurs saat pelunasan belum (audit T4) |
| Jurnal ketat dan idempoten (S1–S3) | ⬜ | audit |
| Fondasi identitas dan metadata pajak | ✅ | 2026-11-20 |
| Bukti potong PPh 23/4(2) dari pembayaran | ✅ | tabel, service, halaman, preflight, lampiran, Excel; XML BPPU dari contoh resmi **belum dicoba di Coretax** ([PJK-014](../backlog/pjk-pajak.md)) |
| Ekspor XML faktur keluaran Coretax | ⛔ | XSD resmi belum didapat |
| Rekap SPT Masa PPN | ⬜ | dikerjakan terakhir |
| Retensi dan legal hold dokumen pajak | ⬜ | |

## Platform

| Area | Status | Catatan |
|---|---|---|
| Autentikasi, audit login, nonaktif akun, throttle, 401/419 | ✅ | [otorisasi dan autentikasi](otorisasi-dan-autentikasi.md) |
| Notifikasi, pengingat, scheduler, watchdog | ✅ | butuh `queue:work` dan `schedule:work` berjalan |
| Tema terang/gelap dan jaring tema | ✅ | |
| Jaring test (arsitektur, smoke, tampilan, akses, N+1) | ✅ | cakupan route mutasi masih bolong, lihat [jaring pengaman](jaring-pengaman-test.md) |
| Cek visual layout guest di browser nyata | ⬜ | belum dilihat di Chrome/Safari |
| Utang sistemik: 7 controller menulis lintas model di dalam controller | ⬜ | proyek tersendiri, bukan perbaikan sambil lalu |

## Di luar lingkup

- API atau EDI eksternal (supplier, marketplace, ERP lain): dibatalkan user 2026-09-16. Sanctum hanya mengurus token untuk `GET /api/user`.
- Debit/credit note sebagai tabel master `debits`/`kredits`: dihapus 2026-09-18; bila diperlukan, dibangun sebagai dokumen sungguhan dengan jalur jurnal.
- Stok barang jadi dari produksi: output langsung ke pelanggan.
- Pembukaan kunci periode lewat maker-checker: ditawarkan dan ditolak.
