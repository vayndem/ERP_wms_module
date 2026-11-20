<?php

namespace App\Services;

use App\Models\BuktiPotong;
use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use App\Models\KodeObjekPajak;
use App\Models\PembayaranFaktur;
use App\Support\IdentitasPajak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class KepatuhanPajakService
{
    public const IDENTITAS_KOSONG = 'IDENTITAS_KOSONG';
    public const IDENTITAS_FORMAT_SALAH = 'IDENTITAS_FORMAT_SALAH';
    public const NSFP_FORMAT_SALAH = 'NSFP_FORMAT_SALAH';
    public const NSFP_DUPLIKAT = 'NSFP_DUPLIKAT';
    public const NPWP_PERUSAHAAN_TIDAK_VALID = 'NPWP_PERUSAHAAN_TIDAK_VALID';
    public const FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK = 'FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK';
    public const PPH_TANPA_KODE_OBJEK = 'PPH_TANPA_KODE_OBJEK';

    public const PEMBAYARAN_PPH_TANPA_BUKTI_POTONG = 'PEMBAYARAN_PPH_TANPA_BUKTI_POTONG';
    public const BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP = 'BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP';
    public const BUKTI_POTONG_TANPA_KODE_OBJEK = 'BUKTI_POTONG_TANPA_KODE_OBJEK';
    public const PENERIMA_TANPA_NPWP_VALID = 'PENERIMA_TANPA_NPWP_VALID';
    public const BUKTI_POTONG_PEMBAYARAN_VOID = 'BUKTI_POTONG_PEMBAYARAN_VOID';

    public const PENGHALANG_EKSPOR_BUPOT = [
        self::NPWP_PERUSAHAAN_TIDAK_VALID,
        self::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG,
        self::BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP,
        self::BUKTI_POTONG_TANPA_KODE_OBJEK,
        self::BUKTI_POTONG_PEMBAYARAN_VOID,
    ];

    public const KATEGORI = [
        self::IDENTITAS_KOSONG,
        self::IDENTITAS_FORMAT_SALAH,
        self::NSFP_FORMAT_SALAH,
        self::NSFP_DUPLIKAT,
        self::NPWP_PERUSAHAAN_TIDAK_VALID,
        self::FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK,
        self::PPH_TANPA_KODE_OBJEK,
        self::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG,
        self::BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP,
        self::BUKTI_POTONG_TANPA_KODE_OBJEK,
        self::PENERIMA_TANPA_NPWP_VALID,
        self::BUKTI_POTONG_PEMBAYARAN_VOID,
    ];

    private array $jenisBerkode = [];

    public function periksa(string $masa): array
    {
        [$awal, $akhir] = $this->rentang($masa);

        $this->jenisBerkode = KodeObjekPajak::aktif()->distinct()->pluck('jenis_pph')->all();

        $penjualan = FakturPenjualan::with('pelanggan')
            ->whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->whereBetween('tanggal', [$awal, $akhir])
            ->orderBy('tanggal')->orderBy('id')
            ->get();

        $pembelian = FakturPembelian::with('supplier')
            ->where('status', '!=', FakturPembelian::VOID)
            ->whereBetween('tanggal', [$awal, $akhir])
            ->orderBy('tanggal')->orderBy('id')
            ->get();

        $temuan = collect()
            ->merge($this->temuanPerusahaan())
            ->merge($this->temuanIdentitas($penjualan, $pembelian))
            ->merge($this->temuanFormatNsfp($penjualan, $pembelian))
            ->merge($this->temuanNsfpGanda($penjualan, $pembelian))
            ->merge($this->temuanTanggalFakturPajak($penjualan, $pembelian))
            ->merge($this->temuanKodeObjek($pembelian))
            ->merge($this->temuanBuktiPotong($masa))
            ->values();

        $ringkasan = [];

        foreach (self::KATEGORI as $kategori) {
            $ringkasan[$kategori] = $temuan->where('kategori', $kategori)->count();
        }

        return [
            'masa' => $masa,
            'temuan' => $temuan,
            'ringkasan' => $ringkasan,
            'total' => $temuan->count(),
        ];
    }

    private function temuanPerusahaan(): Collection
    {
        $temuan = collect();

        if (!IdentitasPajak::npwpValid((string) config('app.npwp_perusahaan'))) {
            $temuan->push($this->baris(
                self::NPWP_PERUSAHAAN_TIDAK_VALID,
                'Perusahaan',
                'NPWP_PERUSAHAAN',
                'Perusahaan',
                'NPWP perusahaan (konfigurasi NPWP_PERUSAHAAN) kosong atau bukan 16 digit angka.'
            ));
        }

        if (!IdentitasPajak::nitkuValid((string) config('app.nitku_perusahaan'))) {
            $temuan->push($this->baris(
                self::NPWP_PERUSAHAAN_TIDAK_VALID,
                'Perusahaan',
                'NITKU_PERUSAHAAN',
                'Perusahaan',
                'NITKU perusahaan (konfigurasi NITKU_PERUSAHAAN) kosong atau bukan 22 digit angka.'
            ));
        }

        return $temuan;
    }

    private function temuanIdentitas(Collection $penjualan, Collection $pembelian): Collection
    {
        $temuan = collect();

        foreach ($penjualan->where('is_ppn', true)->groupBy('pelanggan_id') as $daftar) {
            $master = $daftar->first()->pelanggan;

            if ($master) {
                $temuan = $temuan->merge($this->periksaPihak($master, 'Pelanggan', (string) $master->kode, $daftar->count()));
            }
        }

        $kena = $pembelian->filter(fn (FakturPembelian $faktur) => $faktur->jenis_pajak === 'PPN' || $this->kenaPph($faktur));

        foreach ($kena->groupBy('kode_supplier') as $daftar) {
            $master = $daftar->first()->supplier;

            if ($master) {
                $temuan = $temuan->merge($this->periksaPihak($master, 'Supplier', '#' . $master->id, $daftar->count()));
            }
        }

        return $temuan;
    }

    private function periksaPihak(Model $pihak, string $jenis, string $nomor, int $jumlahDokumen): Collection
    {
        $temuan = collect();
        $akhiran = " ({$jumlahDokumen} dokumen pada masa ini)";
        $tipe = $pihak->tipe_identitas ?: 'NPWP';
        $kolomUtama = $tipe === 'NIK' ? 'nik' : 'npwp';

        if (blank($pihak->{$kolomUtama})) {
            $temuan->push($this->baris(
                self::IDENTITAS_KOSONG,
                $jenis,
                $nomor,
                $pihak->nama,
                strtoupper($kolomUtama) . ' belum diisi untuk tipe identitas ' . $tipe . $akhiran
            ));
        }

        if ($tipe === 'NPWP' && blank($pihak->nitku)) {
            $temuan->push($this->baris(
                self::IDENTITAS_KOSONG,
                $jenis,
                $nomor,
                $pihak->nama,
                'NITKU belum diisi' . $akhiran
            ));
        }

        $pemeriksa = [
            'npwp' => [fn ($nilai) => IdentitasPajak::npwpValid($nilai), 'NPWP harus 16 digit angka'],
            'nik' => [fn ($nilai) => IdentitasPajak::nikValid($nilai), 'NIK harus 16 digit angka'],
            'nitku' => [fn ($nilai) => IdentitasPajak::nitkuValid($nilai), 'NITKU harus 22 digit angka'],
        ];

        foreach ($pemeriksa as $kolom => [$valid, $pesan]) {
            $nilai = $pihak->{$kolom};

            if (filled($nilai) && !$valid((string) $nilai)) {
                $temuan->push($this->baris(
                    self::IDENTITAS_FORMAT_SALAH,
                    $jenis,
                    $nomor,
                    $pihak->nama,
                    "{$pesan}; nilai tersimpan: {$nilai}" . $akhiran
                ));
            }
        }

        return $temuan;
    }

    private function temuanFormatNsfp(Collection $penjualan, Collection $pembelian): Collection
    {
        $temuan = collect();

        foreach ($penjualan as $faktur) {
            if (filled($faktur->no_faktur_pajak) && !IdentitasPajak::nsfpValid($faktur->no_faktur_pajak)) {
                $temuan->push($this->baris(
                    self::NSFP_FORMAT_SALAH,
                    'Faktur penjualan',
                    $faktur->nomor,
                    $faktur->pelanggan?->nama,
                    "NSFP {$faktur->no_faktur_pajak} bukan 17 digit angka maupun format lama."
                ));
            }
        }

        foreach ($pembelian as $faktur) {
            if (filled($faktur->no_faktur_pajak) && !IdentitasPajak::nsfpValid($faktur->no_faktur_pajak)) {
                $temuan->push($this->baris(
                    self::NSFP_FORMAT_SALAH,
                    'Faktur pembelian',
                    $faktur->no_invoice,
                    $faktur->supplier?->nama,
                    "NSFP {$faktur->no_faktur_pajak} bukan 17 digit angka maupun format lama."
                ));
            }
        }

        return $temuan;
    }

    private function temuanNsfpGanda(Collection $penjualan, Collection $pembelian): Collection
    {
        $temuan = collect();

        $gandaJual = FakturPenjualan::query()
            ->where('status', '!=', FakturPenjualan::VOID)
            ->whereNotNull('no_faktur_pajak')
            ->groupBy('no_faktur_pajak')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('no_faktur_pajak');

        foreach ($penjualan->whereIn('no_faktur_pajak', $gandaJual) as $faktur) {
            $temuan->push($this->baris(
                self::NSFP_DUPLIKAT,
                'Faktur penjualan',
                $faktur->nomor,
                $faktur->pelanggan?->nama,
                "NSFP {$faktur->no_faktur_pajak} dipakai lebih dari satu faktur penjualan."
            ));
        }

        $gandaBeli = FakturPembelian::query()
            ->where('status', '!=', FakturPembelian::VOID)
            ->whereNotNull('no_faktur_pajak')
            ->groupBy('kode_supplier', 'no_faktur_pajak')
            ->havingRaw('COUNT(*) > 1')
            ->get(['kode_supplier', 'no_faktur_pajak'])
            ->map(fn ($baris) => $baris->kode_supplier . '|' . $baris->no_faktur_pajak)
            ->flip();

        foreach ($pembelian as $faktur) {
            if ($gandaBeli->has($faktur->kode_supplier . '|' . $faktur->no_faktur_pajak)) {
                $temuan->push($this->baris(
                    self::NSFP_DUPLIKAT,
                    'Faktur pembelian',
                    $faktur->no_invoice,
                    $faktur->supplier?->nama,
                    "NSFP {$faktur->no_faktur_pajak} muncul lebih dari satu kali untuk supplier yang sama."
                ));
            }
        }

        return $temuan;
    }

    private function temuanTanggalFakturPajak(Collection $penjualan, Collection $pembelian): Collection
    {
        $temuan = collect();

        foreach ($penjualan->where('is_ppn', true)->whereNull('tanggal_faktur_pajak') as $faktur) {
            $temuan->push($this->baris(
                self::FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK,
                'Faktur penjualan',
                $faktur->nomor,
                $faktur->pelanggan?->nama,
                'Faktur ber-PPN belum memiliki tanggal faktur pajak.'
            ));
        }

        foreach ($pembelian->where('jenis_pajak', 'PPN')->whereNull('tanggal_faktur_pajak') as $faktur) {
            $temuan->push($this->baris(
                self::FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK,
                'Faktur pembelian',
                $faktur->no_invoice,
                $faktur->supplier?->nama,
                'Faktur ber-PPN belum memiliki tanggal faktur pajak.'
            ));
        }

        return $temuan;
    }

    private function temuanKodeObjek(Collection $pembelian): Collection
    {
        $temuan = collect();

        foreach ($pembelian as $faktur) {
            if ($this->kenaPph($faktur) && blank($faktur->kode_objek_pajak)) {
                $temuan->push($this->baris(
                    self::PPH_TANPA_KODE_OBJEK,
                    'Faktur pembelian',
                    $faktur->no_invoice,
                    $faktur->supplier?->nama,
                    'Faktur berjenis ' . $faktur->jenis_pph . ' belum memiliki kode objek pajak.'
                ));
            }
        }

        return $temuan;
    }

    public function temuanBuktiPotong(string $masa): Collection
    {
        [$awal, $akhir] = $this->rentang($masa);
        $service = app(BuktiPotongService::class);
        $temuan = collect();

        $pembayaran = PembayaranFaktur::with(['invoice.supplier' => fn ($query) => $query->withTrashed()])
            ->where('status', PembayaranFaktur::POSTED)
            ->where('potongan_pph', '>', 0)
            ->whereBetween('tanggal_pembayaran', [$awal, $akhir])
            ->whereHas('invoice', fn ($query) => $query->whereIn('jenis_pph', BuktiPotong::JENIS_DIDUKUNG))
            ->get();

        $adaBukti = BuktiPotong::whereIn('pembayaran_id', $pembayaran->pluck('id'))
            ->whereIn('status', [BuktiPotong::DRAFT, BuktiPotong::DITERBITKAN])
            ->pluck('pembayaran_id')
            ->flip();

        foreach ($pembayaran as $bayar) {
            if (!$adaBukti->has($bayar->id)) {
                $temuan->push($this->baris(
                    self::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG,
                    'Pembayaran faktur',
                    $bayar->payment_number,
                    $bayar->invoice?->supplier?->nama,
                    'Pembayaran memotong ' . $bayar->invoice?->jenis_pph . ' sebesar Rp ' . number_format((float) $bayar->potongan_pph, 2, ',', '.') . ' tetapi belum memiliki bukti potong; jalankan sinkronisasi bukti potong.'
                ));
            }
        }

        $bukti = BuktiPotong::with(['supplier', 'pembayaran'])
            ->whereBetween('tanggal_pemotongan', [$awal, $akhir])
            ->whereIn('status', [BuktiPotong::DRAFT, BuktiPotong::DITERBITKAN])
            ->orderBy('id')
            ->get();

        foreach ($bukti as $baris) {
            $rujukan = 'Bukti potong #' . $baris->id;

            $identitas = $service->masalahIdentitas($baris);

            if ($identitas !== []) {
                $temuan->push($this->baris(
                    self::BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP,
                    'Bukti potong',
                    $rujukan,
                    $baris->penerima_nama,
                    implode(' ', $identitas)
                ));
            }

            if (blank($baris->kode_objek_pajak)) {
                $temuan->push($this->baris(
                    self::BUKTI_POTONG_TANPA_KODE_OBJEK,
                    'Bukti potong',
                    $rujukan,
                    $baris->penerima_nama,
                    'Bukti potong ' . $baris->jenis_pph . ' belum memiliki kode objek pajak.'
                ));
            }

            if ($baris->jenis_pph === 'PPH23' && !IdentitasPajak::npwpValid($baris->penerima_npwp)) {
                $temuan->push($this->baris(
                    self::PENERIMA_TANPA_NPWP_VALID,
                    'Bukti potong',
                    $rujukan,
                    $baris->penerima_nama,
                    'Penerima tanpa NPWP valid: Pasal 23 ayat (1a) UU PPh menyebut tarif lebih tinggi 100%, sedangkan sistem tetap memotong sesuai tarif faktur. Keputusan produk masih terbuka; periksa potongan sebelum menerbitkan.'
                ));
            }

            if ($baris->pembayaran?->status === PembayaranFaktur::VOID || $baris->perlu_pembatalan) {
                $temuan->push($this->baris(
                    self::BUKTI_POTONG_PEMBAYARAN_VOID,
                    'Bukti potong',
                    $baris->nomor_bukti_djp ?: $rujukan,
                    $baris->penerima_nama,
                    'Pembayaran sumber sudah dibatalkan (VOID) padahal bukti potong masih ' . $baris->status . '; batalkan atau sinkronkan.'
                ));
            }
        }

        return $temuan;
    }

    private function kenaPph(FakturPembelian $faktur): bool
    {
        return filled($faktur->jenis_pph)
            && in_array($faktur->jenis_pph, $this->jenisBerkode, true)
            && ((float) $faktur->dasar_pph > 0 || (float) $faktur->pph > 0);
    }

    private function baris(string $kategori, string $dokumen, ?string $nomor, ?string $pihak, string $keterangan): array
    {
        return [
            'kategori' => $kategori,
            'dokumen' => $dokumen,
            'nomor' => $nomor,
            'pihak' => $pihak,
            'keterangan' => $keterangan,
        ];
    }

    private function rentang(string $masa): array
    {
        $awal = Carbon::createFromFormat('Y-m-d', $masa . '-01')->startOfDay();

        return [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()];
    }
}
