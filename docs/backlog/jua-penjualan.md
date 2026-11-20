# JUA: Penjualan dan piutang

Pekerjaan sisi jual. Konteks di [gambaran umum](../architecture/overview.md#alur-jual-order-to-cash-dan-produksi).

Legenda status ada di [backlog](README.md#legenda-status).

| ID | Tugas | Status | Rujukan | Commit |
|---|---|---|---|---|
| JUA-001 | Plafon kredit pelanggan ditegakkan: eksposur piutang + kiriman belum difaktur + pesanan belum kirim; menembusnya lewat gate | ✅ | `app/Services/PlafonKreditService.php`, `PenjualanService`, `tests/Feature/PlafonKreditTest.php` | — |
| JUA-002 | Uang muka pelanggan (akun 2112) dan pemakaiannya ke faktur | ✅ | `app/Models/UangMukaPelanggan.php`, route `faktur-penjualan.uang-muka`, `tests/Feature/UangMukaPelangganTest.php` | — |
| JUA-003 | Umur piutang direkonstruksi per tanggal acuan, dan kinerja sales atas DPP | ✅ | `app/Services/PiutangAgingService.php`, `KinerjaSalesService`, `tests/Feature/UmurPiutangDanKinerjaSalesTest.php` | — |
| JUA-004 | API atau EDI eksternal ke supplier, marketplace, atau ERP lain | ➖ | dibatalkan user 2026-09-16 (`CLAUDE.md`, Dropped from the plan) | — |
