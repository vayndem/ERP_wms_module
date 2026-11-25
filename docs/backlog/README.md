# Backlog

Yang belum dikerjakan, yang sudah selesai baru-baru ini, dan keputusan yang menunggu, dipecah menjadi tugas ber-ID per area. [Roadmap](../architecture/roadmap.md) menunjukkan status besar; halaman ini memecahnya supaya bisa dikerjakan dan dicek satu per satu ("kerjakan ALG-017").

Status tugas diperiksa dari kode (2026-11-20). "Open-items index" di `CLAUDE.md` kini hanya ringkasan halaman ini dan [keputusan menunggu](keputusan-menunggu.md); butir lamanya yang sudah dibangun dicatat [di bawah](#sudah-diputuskan-dan-dibangun). Kolom Commit berisi `—` selama belum ada commit: user yang mengurus git.

## Legenda status

| Tanda | Arti |
|---|---|
| ✅ | selesai: ada di kode dan terkunci test atau bukti lain yang disebut di kolom Rujukan |
| 🔄 | sedang dikerjakan atau sebagian |
| ⬜ | belum |
| ⛔ | tertahan, menunggu keputusan produk atau pihak luar (mis. XSD resmi DJP) |
| ➖ | tidak dikerjakan: dibatalkan atau digantikan keputusan lain; alasannya di tugasnya |

## Area

Hitungan di kolom Status dihasilkan dengan perintah di [bawah](#memperbarui-hitungan), bukan ditebak.

| Area | File | Isi | Status |
|---|---|---|---|
| AUT | [aut-autentikasi.md](aut-autentikasi.md) | login, sesi, audit akun, nonaktif, sesi habis, atribusi aktor, layout guest | ✅ 8 · ⬜ 2 |
| AKT | [akt-akuntansi.md](akt-akuntansi.md) | akun landed cost, mapping jasa, penyusutan ganda di UI, catatan `CLAUDE.md`, period lock | ✅ 4 · ⬜ 0 · ➖ 1 |
| STK | [stk-stok-gudang.md](stk-stok-gudang.md) | Lacak Pembelian, subkontrak, barcode, putaway | ✅ 2 · ⬜ 1 · ⛔ 1 |
| PRD | [prd-produksi.md](prd-produksi.md) | serapan produksi, pembatalan perintah kerja, BOM, routing, barang jadi | ✅ 4 · ➖ 1 |
| JUA | [jua-penjualan.md](jua-penjualan.md) | plafon kredit, uang muka pelanggan, umur piutang, integrasi eksternal | ✅ 3 · ➖ 1 |
| PJK | [pjk-pajak.md](pjk-pajak.md) | fondasi identitas pajak, bukti potong, ekspor Coretax, SPT Masa PPN, retensi, peta jalan dan prasyarat | ✅ 6 · ⬜ 4 · ⛔ 4 |
| PLT | [plt-platform-test.md](plt-platform-test.md) | jaring test, N+1, database test, CI, utang sistemik | ✅ 4 · ⬜ 7 · ⛔ 1 |
| ALG | [alg-audit-algoritma.md](alg-audit-algoritma.md) | audit algoritma backend 2026-11: K1, K2, T1–T5, S1–S11, R1–R6 | ✅ 5 · ⬜ 19 |
| KEP | [keputusan-menunggu.md](keputusan-menunggu.md) | keputusan produk yang belum diambil: konteks, pilihan, dampak | ⛔ 9 |

Beberapa tugas muncul di dua tempat dengan ID berbeda karena melihat sisi berbeda dari hal yang sama: tugas bertanda ⛔ merujuk ID `KEP-*` yang menahannya, dan temuan audit yang menyentuh domain lain hanya diberi ID di ALG.

## Cara membaca

- **Mulai dari mana.** Untuk mengambil pekerjaan: ALG (cacat yang terbukti di kode), lalu area yang kamu sentuh. Untuk keputusan yang sedang ditunggu: KEP.
- **Tugas ber-ID.** Kode area tiga huruf besar ditambah nomor urut, tidak dipakai ulang. Kolom Rujukan menunjuk ke file kode, test, atau dokumen; bila status ✅, harus ada test atau bukti yang bisa dijalankan ulang.
- **⛔ bukan "sulit".** Artinya engineer tidak boleh memutuskannya. Tanyakan ke pemilik produk memakai [template keputusan menunggu](../templates/template-keputusan-menunggu.md).
- **Satu kode, dua nama.** Temuan audit mempertahankan kode lamanya (K1, T2, S4, …) di kolom Tugas supaya cocok dengan ringkasan audit yang beredar.
- **Dua dokumen yang diminta berdiri sendiri dilipat.** Audit algoritma kini [alg-audit-algoritma.md](alg-audit-algoritma.md), dan peta jalan pajak kini bagian dari [pjk-pajak.md](pjk-pajak.md) (urutan dan prasyarat); keduanya lebih rapi bersama tabel tugasnya.

## Sudah diputuskan dan dibangun

Butir ini dulu tercantum sebagai belum dibangun di "Open-items index" `CLAUDE.md`, tetapi kode dan test-nya ada (diperiksa 2026-11-20); daftar itu sudah dikoreksi di hari yang sama. Jangan membukanya lagi sebagai pekerjaan:

| Butir lama | Keadaan sekarang | Bukti |
|---|---|---|
| Plafon kredit pelanggan tidak ditegakkan | ditegakkan | `PlafonKreditService`, `PlafonKreditTest` ([JUA-001](jua-penjualan.md)) |
| Uang muka pelanggan | dibangun | `UangMukaPelanggan`, `UangMukaPelangganTest` ([JUA-002](jua-penjualan.md)) |
| Akun lawan landed cost | akun 2111 dan mapping `BIAYA_MASIH_HARUS_DIBAYAR` | migrasi `2026_10_01_000001` ([AKT-001](akt-akuntansi.md)) |
| Kategori jasa vs aturan mapping | kategori jasa dinilai terpisah | `UpdateAccountingMappingRequest` ([AKT-002](akt-akuntansi.md)) |
| Serapan tenaga kerja dan overhead ke WIP | dibangun | `SerapanProduksiService` ([PRD-001](prd-produksi.md)) |
| Bucket hilang dalam perjalanan di Lacak Pembelian | ada | `LacakPembelianService` ([STK-001](stk-stok-gudang.md)) |
| Mengirim persediaan keluar untuk subkontrak | dibangun lewat gudang subkontrak | `SubkontrakService` ([STK-002](stk-stok-gudang.md)) |

Alasan keputusannya ada di [catatan fitur](../architecture/catatan-fitur.md) (blok "Koreksi 2026-11-20") dan di bagian "Feature digest" `CLAUDE.md`.

## Memperbarui hitungan

Setiap file area berisi satu tabel tugas yang barisnya dimulai `| KODE-nnn |`. Hitung status satu file:

```sh
cd docs/backlog
grep -cE "^\| [A-Z]{3}-[0-9]+ \|.*\| ✅ \|" alg-audit-algoritma.md
```

Ganti `✅` dengan 🔄, ⬜, ⛔, atau ➖ untuk status lain, dan `grep -cE "^\| [A-Z]{3}-[0-9]+ \|"` untuk total. Tabel tambahan di dalam file area (misalnya prasyarat) tidak boleh dimulai dengan ID agar hitungan tidak ganda. Perbarui kolom Status tabel Area bersamaan dengan status tugasnya.

## Menambah tugas atau area

- Tugas baru: baris baru di file area dengan nomor urut berikutnya; jangan memakai ulang nomor.
- Area baru: kode tiga huruf besar, satu file `kode-nama.md`, satu baris di tabel Area dan di [indeks](../README.md).
- Tugas selesai: ubah status dan isi Rujukan dengan test yang mengunci; ubah status besar di [roadmap](../architecture/roadmap.md) bila area bergeser.
