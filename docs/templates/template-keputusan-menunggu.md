# Keputusan menunggu: <pertanyaan satu kalimat>

Satu kalimat: keputusan apa yang dibutuhkan dari siapa, dan pekerjaan apa yang tertahan olehnya.

Salin sebagai entri baru di [keputusan menunggu](../backlog/keputusan-menunggu.md) dengan ID `KEP-nnn` berikutnya, dan tambahkan barisnya di tabel di bagian atas file itu. Engineer tidak memutuskan hal yang mengubah jurnal, tarif, atau perilaku posting.

## Konteks

Keadaan sekarang di kode (sebut file atau service) dan mengapa pertanyaan ini muncul. Fakta eksternal ditulis dengan sumber dan label terverifikasi atau belum.

## Pilihan

| Pilihan | Artinya | Kelebihan | Biaya |
|---|---|---|---|
| A | … | … | … |
| B | … | … | … |

## Rekomendasi

Pilihan yang disarankan dan alasannya, atau "tidak ada rekomendasi: keputusan kebijakan". Bila ada default sementara yang dipakai kode, sebutkan.

## Dampak

- Jurnal, stok, laporan, dan invarian yang berubah.
- Skema dan data yang sudah ada.
- Tugas di [backlog](../backlog/README.md) yang terbuka bila diputuskan (beri ID).

## Pemilik keputusan

Nama peran (pemilik produk, akuntansi, pajak). Tanggal pertanyaan diajukan dan tanggal keputusan.

## Setelah diputuskan

1. Pindahkan ke bagian "Decisions the user has already made" di `CLAUDE.md` dengan tanggal.
2. Hapus entri ini dan ubah status tugas terkait di backlog.
3. Perbarui dokumen yang menyebutnya.
