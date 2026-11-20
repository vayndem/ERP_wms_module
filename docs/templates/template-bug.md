# Bug: <ringkasan satu kalimat>

Satu kalimat: apa yang salah dan siapa yang terdampak.

Salin file ini saat mencatat bug dan perbaikannya. Isi dari kenyataan (kode, data, query), bukan dugaan.

## Gejala

Apa yang terlihat pengguna atau pengembang: pesan, angka salah, halaman 500. Sertakan tanggal ditemukan.

## Langkah mereproduksi

1. Keadaan data awal (seeder, dokumen mana).
2. Tindakan (route, perintah).
3. Hasil yang terjadi.

## Harapan

Apa yang seharusnya terjadi, dan sumber aturannya (migrasi, policy, service, keputusan user).

## Akar masalah

Mekanisme sampai ke file dan baris. Jelaskan **mengapa** tidak ada yang menangkapnya (jalur kode tanpa test, string yang lolos rename, data seeder tipis, dsb.).

## Perbaikan

- Apa yang diubah, per file.
- Mengapa bentuk ini dan bukan alternatifnya.

## Test pengunci

- Test yang gagal **bila perbaikannya dibalikkan** (nama kelas dan method).
- Jaring lain yang sekarang menangkap kelas cacat ini, bila ada.

## Dampak data

- Baris yang sudah salah di database dan cara membetulkannya (migrasi atau backfill), atau "tidak ada".
- Laporan atau invarian yang terpengaruh selama bug ada.

## Pelajaran

Satu atau dua kalimat yang layak masuk bagian "Behavioural rules" `CLAUDE.md`, bila ini bisa terulang.
