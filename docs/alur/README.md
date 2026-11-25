# Alur per role

Enam lembar animasi yang ditampilkan di [README root](../../README.md#-alur-per-user-dari-awal-sampai-akhir). `alur.js` adalah sumbernya; GIF dan PNG di folder ini adalah hasil render.

Setiap kotak menulis nama dokumen dan role yang boleh mengerjakannya, dibaca dari policy di `app/Policies` dan gate di `app/Providers/AuthServiceProvider.php`. Kalau policy berubah, lembar yang terkena ikut diubah di perubahan yang sama dan GIF serta PNG-nya dirender ulang; kalau gambar dan kode berbeda, kode yang benar.

Lembar ditulis dengan skill `blueprint`: `check.mjs` memeriksa skena, `export.mjs` menulis GIF dan PNG (`--scene <id> --format gif|png --width 960`). Teks keterangan sengaja pendek dan memakai bahasa sehari-hari; nama dokumen dan angka mengikuti kode.
