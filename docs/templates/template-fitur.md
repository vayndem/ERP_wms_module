# Fitur: <nama fitur>

Satu kalimat: apa yang ditambahkan fitur ini dan untuk siapa.

Salin file ini sebelum membangun fitur dan isi seluruhnya sebelum menulis kode. Hapus petunjuk di dalam kurung bila sudah diisi.

## Latar

Masalah nyata yang diselesaikan dan buktinya (tiket, kejadian, angka bertanggal). Mengapa sekarang.

## Keputusan produk

| Keputusan | Siapa memutuskan | Tanggal | Tempat tercatat |
|---|---|---|---|
| … | pemilik produk | YYYY-MM-DD | bagian "Decisions the user has already made" di `CLAUDE.md` |

(Keputusan yang belum diambil: salin [template keputusan menunggu](template-keputusan-menunggu.md) dan jangan membangun bagian yang bergantung padanya.)

## Lingkup

- Yang dibangun, dalam kalimat pendek.

## Di luar lingkup

- Yang sengaja tidak dibangun dan alasannya, agar tidak ditemukan ulang sebagai celah.

## Skema

- Tabel dan kolom (prefix `wms_`, status uppercase, DECIMAL), foreign key, indeks bernama pendek (maks 64 karakter).
- `$fillable` atau `$guarded` model yang disentuh.
- Kolom yang dikirim Yajra ke JS (jangan dinamai ulang).

## Aturan bisnis

- Aturan yang bisa diuji, satu per baris: kondisi → hasil.
- Dampak jurnal: akun mana, debit/kredit, dimensi gudang, mapping `AccountingSetting` baru (konstanta **dan** whitelist).
- Dampak stok: layer, status, `StokGudangService`.
- Penomoran: dialokasikan saat simpan; format.
- Otorisasi: role, policy, gate, scoping gudang.
- Invarian rekonsiliasi baru atau yang terpengaruh.

## Test

- Test lewat route (HTTP → service → ledger), test service per cabang aturan, test penolakan (periode terkunci, status, role), dan efek ke invarian.
- Jaring yang otomatis ikut: smoke, tampilan, tema, tanpa efek samping, N+1, akses per role (tambah route ke `AksesPerRoleTest`).

## Risiko

- Jebakan yang relevan dari bagian "Behavioural rules" `CLAUDE.md` dan bagaimana dihindari.
- Dampak pada data yang sudah ada (migrasi, backfill).

## Pertanyaan terbuka

- Hal yang belum diketahui, ditandai "belum diverifikasi" bila fakta eksternal.

## Dokumentasi yang ikut berubah

Lihat [governance](../architecture/development-governance.md#dokumentasi-ikut-berubah).
