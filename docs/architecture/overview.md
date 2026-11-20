# Gambaran umum

Bagaimana ERP - Modul WMS tersusun, bagaimana data mengalir dari permintaan sampai jurnal, dan apa yang terjadi saat sesuatu gagal.

Sistem ini bukan WMS mandiri: ia modul ERP yang menyambungkan gudang, pembelian, penjualan, keuangan, dan akuntansi. Setiap pergerakan stok langsung memposting jurnal (persediaan perpetual, bukan periodik), sehingga stok, nilai FIFO, hutang, piutang, dan buku besar bergerak bersama. Aplikasi satu proses Laravel 12 dengan MySQL; tidak ada layanan lain dan tidak ada API publik (integrasi eksternal sengaja dibatalkan, lihat [roadmap](roadmap.md#di-luar-lingkup)).

## Komponen

```text
 pengguna (browser) ──► Laravel 12 (satu aplikasi)
   7 role                │
                         ├─ routes/*.php (per domain) ─► Controller ─► Form Request (validasi)
                         │                                   │             └─ Policy / Gate (otorisasi)
                         │                                   ▼
                         │                               Service (proses lintas model, transaksi)
                         │                                   │
                         │                                   ▼
                         │                         Model Eloquent ──► MySQL (wms_* + tabel framework)
                         │
                         ├─ Blade + Tailwind + daisyUI + Alpine (dibangun Vite → public/build)
                         ├─ queue worker  (php artisan queue:work)     ─► notifikasi (tabel jobs)
                         └─ scheduler     (php artisan schedule:work)  ─► pengingat, replenishment,
                                                                          ABC, penyusutan, watchdog invarian
 disk privat `lampiran` ──► lampiran dokumen (pdf/jpg/png/webp, maks 5 MB), diunduh lewat route berotorisasi
```

| Komponen | Tempat | Tugas |
|---|---|---|
| Routes | `routes/*.php` | endpoint dan middleware, dipecah per domain; harus bisa di-`route:cache` |
| Controller | `app/Http/Controllers` | orkestrasi HTTP saja |
| Form Request | `app/Http/Requests` | validasi dan sebagian otorisasi |
| Policy dan Gate | `app/Policies`, `AuthServiceProvider` | role dan keadaan dokumen; gate untuk kemampuan lintas model |
| Service | `app/Services` | FIFO, posting jurnal, penomoran, opname, aset, rekonsiliasi, transfer, pembalikan |
| Model | `app/Models` | relasi, cast, helper domain |
| Migrasi dan seeder | `database/` | skema dan batasan; seeder master dan data demo |
| View | `resources/views` | Blade; interaktivitas dengan Alpine |
| Command terjadwal | `app/Console/Commands`, `bootstrap/app.php` | lima command `wms:*`, didaftarkan di `withSchedule()` |

Rincian lapisan dan aturan siapa-boleh-memanggil-siapa ada di [peta lapisan dan domain](module-map.md).

## Alur beli (procure-to-pay)

```text
 Material Request ──► Pesanan Pembelian (PO) ──► LPB (penerimaan barang)
   PENDING→APPROVED     OPEN→CLOSED                │  memposting layer FIFO + stok gudang
   (reservasi stok      (revisi setelah cetak      │  jurnal: Dr Persediaan / Cr GRNI
    sebanyak yang bebas) diarsipkan)               ▼
                                             Stok multi-gudang ──► NPK (pemakaian)
                                               transfer dua fase      Dr Beban / Cr Persediaan
                                               (1304 saat di jalan)   atau ke WIP bila ada perintah kerja
                                                   │
 Faktur supplier ◄── three-way match PO–LPB–faktur ┘
   Dr GRNI (+ PPN Masukan) / Cr Hutang Usaha
        │
        ▼
 Pembayaran ──► Dr Hutang Usaha / Cr Kas (+ potongan PPh ke akun hutang PPh)
```

Retur pembelian mengurangi hutang, GRNI, dan persediaan; belum mengoreksi PPN Masukan ([keputusan menunggu](../backlog/keputusan-menunggu.md)).

## Alur jual (order-to-cash) dan produksi

```text
 Pelanggan ──► Pesanan Penjualan ──► Surat Jalan ──► Faktur Penjualan ──► Penerimaan Pembayaran
  (plafon kredit  (cross-dock         FIFO + HPP       piutang + PPN        melunasi piutang
   dihitung)       menandai stok)     Dr HPP/Cr Persed. Keluaran (2109)      (uang muka dipakai di sini)
                                           │
 Retur Penjualan ◄─────────────────────────┘   membuat layer BARU di biaya surat jalan

 Perintah Kerja (selalu untuk pesanan pelanggan):
   NPK bertanda perintah kerja ─► WIP 1303 ◄─ serapan jam kerja (5203/5204) + jasa subkontrak
   selesai ─► biaya per unit dibekukan ─► dilepas proporsional tiap surat jalan
```

Keluaran produksi langsung ke pelanggan dan tidak pernah menjadi stok barang jadi (keputusan user, [CLAUDE.md](../../CLAUDE.md) bagian "Decisions the user has already made").

## Apa disimpan di mana

| Data | Tempat | Catatan |
|---|---|---|
| Dokumen bisnis, master, stok, layer, jurnal | MySQL, tabel `wms_*` dan 21 tabel domain lama tanpa prefix (diukur 2026-09-18) | sumber kebenaran skema adalah migrasi |
| Akun, sesi, antrean, notifikasi | tabel framework (`users`, `jobs`, `notifications`, `sessions`, …) | tidak berprefix `wms_` |
| Nomor dokumen | `document_sequences` | dialokasikan saat simpan, bukan saat form dibuka |
| Log audit | `wms_log_audit` (trait `Auditable`) dan audit login lewat `UserAccountService` | |
| Lampiran dokumen | disk `lampiran` (privat), metadata di `wms_lampiran_dokumen` dengan checksum SHA-256 | disk bisa diganti lewat `LAMPIRAN_DISK` |
| Aset front-end | `public/build` hasil `npm run build` | tidak di-commit; tanpanya hampir semua halaman 500 |
| Rahasia dan konfigurasi | `.env` | tidak di-commit; contoh nama variabel di [konfigurasi](../guides/configuration.md) |

## Kegagalan dan ketahanan

| Kejadian | Dampak |
|---|---|
| Queue worker tidak jalan | notifikasi menumpuk di tabel `jobs`; tabel `notifications` kosong. Itu perilaku benar, makanya command menulis "diantrikan", bukan "terkirim" |
| Scheduler tidak jalan | tidak ada pengingat harian, replenishment, ABC, penyusutan bulanan, atau pemeriksaan invarian: sistem hanya tahu masalah saat orang mengklik |
| Invarian rekonsiliasi merah | `wms:periksa-invarian` (06:30) keluar dengan kode non-nol dan mengirim `InvarianMenyimpang` ke akuntansi sebelum ringkasan harian 07:00 |
| Posting di periode terkunci | `AccountingPeriodService::assertOpen()` melempar `RuntimeException`; transaksi dokumen di-rollback seluruhnya |
| Dua klik bersamaan pada aksi posting | dicegah kunci baris (`lockForUpdate`), unique index (jurnal `(sumber_transaksi, reff_id)`, transfer lewat `DocumentOperationGuard`); pesan yang muncul bisa berupa `RuntimeException` biasa |
| Sesi habis saat memakai tabel atau modal | JS mendeteksi 401/419 dan mengarahkan ke login dengan pesan; lihat [otorisasi dan autentikasi](otorisasi-dan-autentikasi.md#sesi-habis) |
| Akun dinonaktifkan saat sedang masuk | middleware `PastikanAkunAktif` mengeluarkannya di request berikutnya (401 untuk JSON) |
| Manifest Vite hilang | `ViteManifestNotFoundException`, halaman ber-layout 500. Jalankan `npm run build` |
| Database dev terhapus | data dev sekali pakai dan boleh direset; lihat [database](../guides/database.md#mereset-data-dev) |
