<?php

namespace App\Services;

use App\Models\Aset;
use App\Models\BuktiPotong;
use App\Models\FakturPembelian;
use App\Models\LampiranDokumen;
use App\Models\PembayaranFaktur;
use App\Models\PemeriksaanKualitas;
use App\Models\PenerimaanBarang;
use App\Models\PesananPembelian;
use App\Models\ReturPembelian;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LampiranService
{
    public const UKURAN_MAKS_KB = 5120;
    public const MIME_DIIZINKAN = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const INDUK = [
        'faktur-pembelian' => FakturPembelian::class,
        'pesanan-pembelian' => PesananPembelian::class,
        'penerimaan-barang' => PenerimaanBarang::class,
        'pembayaran-faktur' => PembayaranFaktur::class,
        'retur-pembelian' => ReturPembelian::class,
        'pemeriksaan-kualitas' => PemeriksaanKualitas::class,
        'aset' => Aset::class,
        'bukti-potong' => BuktiPotong::class,
    ];

    public function disk(): string
    {
        return config('filesystems.lampiran_disk', 'lampiran');
    }

    public function induk(string $tipe, int $id): Model
    {
        $kelas = self::INDUK[$tipe] ?? null;

        if (!$kelas) {
            throw new RuntimeException('Jenis dokumen induk tidak dikenal.');
        }

        return $kelas::findOrFail($id);
    }

    public function kunciInduk(Model $induk): string
    {
        $kunci = array_search($induk::class, self::INDUK, true);

        if ($kunci === false) {
            throw new RuntimeException('Dokumen ini belum mendukung lampiran.');
        }

        return $kunci;
    }

    public function simpan(Model $induk, UploadedFile $berkas, string $kategori, ?string $keterangan, User $oleh): LampiranDokumen
    {
        $kunci = $this->kunciInduk($induk);
        $disk = $this->disk();
        $path = $berkas->store($kunci . '/' . $induk->getKey(), $disk);

        if (!$path) {
            throw new RuntimeException('Berkas gagal disimpan. Periksa konfigurasi penyimpanan.');
        }

        return LampiranDokumen::create([
            'lampiran_type' => $induk::class,
            'lampiran_id' => $induk->getKey(),
            'kategori' => $kategori,
            'nama_asli' => $berkas->getClientOriginalName(),
            'path' => $path,
            'disk' => $disk,
            'mime' => $berkas->getClientMimeType(),
            'ukuran' => $berkas->getSize(),
            'checksum' => hash_file('sha256', $berkas->getRealPath()),
            'keterangan' => $keterangan,
            'user_id' => $oleh->id,
        ]);
    }

    public function hapus(LampiranDokumen $lampiran): void
    {
        Storage::disk($lampiran->disk)->delete($lampiran->path);
        $lampiran->delete();
    }

    public function unduh(LampiranDokumen $lampiran)
    {
        if (!Storage::disk($lampiran->disk)->exists($lampiran->path)) {
            throw new RuntimeException('Berkas tidak ditemukan di penyimpanan.');
        }

        return Storage::disk($lampiran->disk)->download($lampiran->path, $lampiran->nama_asli);
    }
}
