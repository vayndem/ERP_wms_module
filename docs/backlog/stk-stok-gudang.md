# STK: Stok dan gudang

Pekerjaan persediaan dan gudang di luar temuan audit (yang ada di [ALG](alg-audit-algoritma.md)). Konteks di [persediaan dan FIFO](../architecture/persediaan-dan-fifo.md).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| STK-001 | Bucket "hilang dalam perjalanan" di Lacak Pembelian, sehingga selisih transfer tidak lagi masuk residu tak terlacak | ✅ | `app/Services/LacakPembelianService.php` (`hilangTransit`) | — |
| STK-002 | Pengiriman bahan ke subkontrak lewat transfer ke gudang subkontrak, tanpa menyentuh layer FIFO secara terpisah | ✅ | `app/Services/SubkontrakService.php`, `tests/Feature/SubkontrakPersediaanTest.php`, migrasi `2026_10_01_000005` | — |
| STK-003 | Barcode / QR untuk putaway dan picking, per kit bukan per item | ⛔ | [KEP-008](keputusan-menunggu.md#kep-008-barcode--qr) | — |
| STK-004 | Penjaga tertahan di `putaway()` hanya memeriksa layer `LPB_DETAIL`, bukan `QC_REJECT_*`; prioritas rendah, aktif bila terbukti menggigit | ⬜ | `WarehouseExecutionService::putaway` | — |
