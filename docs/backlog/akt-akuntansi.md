# AKT: Akuntansi dan jurnal

Pekerjaan akuntansi di luar temuan audit (yang ada di [ALG](alg-audit-algoritma.md)). Konteks di [akuntansi dan jurnal](../architecture/akuntansi-dan-jurnal.md).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| AKT-001 | Akun 2111 Biaya Masih Harus Dibayar sebagai lawan landed cost (mapping `BIAYA_MASIH_HARUS_DIBAYAR`) | ✅ | `database/migrations/2026_10_01_000001_create_akun_landed_cost_uang_muka_dan_serapan.php`, `WmsControlController::akunLawanLandedCost` | — |
| AKT-002 | Kategori jasa tidak lagi dinilai dengan aturan mapping barang | ✅ | `app/Http/Requests/UpdateAccountingMappingRequest.php` (cabang `KategoriBahan::idKategoriJasa()`) | — |
| AKT-003 | Klik ganda "Susutkan" pada aset: `AsetController::depreciate` kini menangkap `RuntimeException` dari service (galat form `penyusutan` untuk halaman, 422 JSON untuk AJAX); data tetap tidak ganda karena kunci baris aset dan unik periode | ✅ | `app/Http/Controllers/AsetController.php`, `PenyusutanGandaTest::test_a_second_depreciation_request_through_the_route_is_a_validation_error_not_a_500` | — |
| AKT-004 | Catat di `CLAUDE.md` jebakan yang baru: laporan hanya `POSTED` padahal ada `REVERSED`, nomor mutasi 999, pembulatan WIP, aktor eksplisit; dan koreksi fakta (CI, `RefreshDatabase`, Open-items usang) | ✅ | `CLAUDE.md` trap 15 sampai 18 dan bagian "Algorithm audit fixes (2026-11-20)" | — |
| AKT-005 | Pembukaan kunci periode lewat maker-checker | ➖ | ditawarkan dan ditolak user 2026-09-16 (`CLAUDE.md`, Decisions) | — |
