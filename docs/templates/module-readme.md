# Domain `<nama>`

Satu kalimat: apa yang dikerjakan domain ini di `app/Services` dan untuk siapa.

Keputusan terkait: tautkan bagian `CLAUDE.md` atau [keputusan menunggu](../backlog/keputusan-menunggu.md); jangan menyalin isinya.

## Isi

| Path | Isi |
|---|---|
| `app/Services/<Nama>Service.php` | proses utama: … |
| `app/Models/<Nama>.php` | model: … |
| `app/Policies/<Nama>Policy.php` | siapa boleh: … |
| `app/Http/Requests/Store<Nama>Request.php` | validasi: … |
| `app/Http/Controllers/<Nama>Controller.php` | orkestrasi HTTP |
| `routes/<domain>.php` | route: … |
| `database/migrations/…` | tabel `wms_<nama>` |
| `tests/Feature/<Nama>Test.php` | pengunci perilaku |

## Dipakai oleh / memakai

- Dipakai oleh: controller, service, atau command mana.
- Memakai: service lain, akun mapping (`AccountingSetting::KUNCI`), `StokGudangService`, `DocumentNumberService`.
- Dijaga aturan [peta lapisan](../architecture/module-map.md) dan `ArchitectureConventionTest`.

## Aturan yang tidak terlihat dari kode

Alasan sebuah keputusan, invarian yang melindunginya, dan apa yang rusak bila dibalik. Hanya yang tidak bisa dijawab `grep`.

## Perintah

| Perintah | Fungsi |
|---|---|
| `php artisan test --filter=<Nama>Test` | menjalankan test domain ini |
| `php artisan <wms:perintah>` | bila ada command terjadwal |

## Belum dibuat

- … (tautkan ke tugas di [backlog](../backlog/README.md) bila ada)
