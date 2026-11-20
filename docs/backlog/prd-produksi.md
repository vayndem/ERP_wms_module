# PRD: Produksi dan WIP

Pekerjaan perintah kerja, WIP, BOM, dan routing. Konteks di [gambaran umum](../architecture/overview.md#alur-jual-order-to-cash-dan-produksi) dan [catatan fitur](../architecture/catatan-fitur.md) bagian "Production work orders and WIP". Temuan audit terkait WIP ada di [ALG](alg-audit-algoritma.md).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| PRD-001 | Serapan tenaga kerja dan overhead ke WIP dari jam kerja dan tarif pusat kerja (akun 5203, 5204) | ✅ | `app/Services/SerapanProduksiService.php`, `tests/Feature/SerapanTenagaKerjaTest.php`, migrasi `2026_10_01_000004` | — |
| PRD-002 | Pembatalan perintah kerja yang sudah menyerap biaya, menghapus WIP ke 5308 | ✅ | `app/Services/DataPesananService.php`, `tests/Feature/PembatalanPerintahKerjaTest.php` | — |
| PRD-003 | BOM sebagai standar bahan dan laporan varians (tanpa jurnal) | ✅ | `app/Services/BomService.php`, `tests/Feature/CrossDockDanBomTest.php` | — |
| PRD-004 | Routing deskriptif dan pusat kerja dengan kapasitas harian | ✅ | `app/Services/RoutingProduksiService.php`, `tests/Feature/RoutingProduksiTest.php` | — |
| PRD-005 | Stok barang jadi dari produksi | ➖ | keputusan user 2026-09-16: output langsung ke pelanggan, tidak menjadi stok barang jadi | — |
