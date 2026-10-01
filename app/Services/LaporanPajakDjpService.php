<?php

namespace App\Services;

use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use Illuminate\Support\Collection;

class LaporanPajakDjpService
{
    public const TEMPLATE_FAKTUR = 'Sample Faktur PK Template v.1.4.xml';
    public const CONVERTER_FAKTUR = 'ConverterEfakturCoretax v1.6 (23/01/2026)';

    public function fakturKeluaran(string $masa): Collection
    {
        [$tahun, $bulan] = $this->pecahMasa($masa);

        return FakturPenjualan::with(['pelanggan', 'details.bahan'])
            ->whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->orderBy('tanggal')
            ->get()
            ->map(fn (FakturPenjualan $faktur) => [
                'nomor_faktur' => $faktur->nomor,
                'nomor_faktur_pajak' => $faktur->no_faktur_pajak,
                'tanggal' => $faktur->tanggal?->toDateString(),
                'masa_pajak' => (int) $bulan,
                'tahun_pajak' => (int) $tahun,
                'npwp_pembeli' => $faktur->pelanggan?->npwp,
                'nama_pembeli' => $faktur->pelanggan?->nama,
                'alamat_pembeli' => $faktur->pelanggan?->alamat,
                'dpp' => round((float) $faktur->total_dpp, 2),
                'ppn' => round((float) $faktur->total_ppn, 2),
                'tarif_ppn' => round((float) $faktur->tarif_ppn, 2),
                'jumlah_baris' => $faktur->details->count(),
                'baris' => $faktur->details->map(fn ($detail) => [
                    'nama_barang' => $detail->bahan?->nama,
                    'jumlah' => round((float) $detail->jumlah, 6),
                    'harga_satuan' => round((float) $detail->harga_satuan, 2),
                    'total_harga' => round((float) $detail->total_harga, 2),
                ])->all(),
                'siap_lapor' => $faktur->is_ppn && !empty($faktur->no_faktur_pajak) && !empty($faktur->pelanggan?->npwp),
                'catatan' => $this->catatanKesiapan($faktur),
            ]);
    }

    public function buktiPotong(string $masa): Collection
    {
        [$tahun, $bulan] = $this->pecahMasa($masa);

        return FakturPembelian::with('supplier')
            ->where('status', '!=', FakturPembelian::VOID)
            ->where('pph', '>', 0)
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->orderBy('tanggal')
            ->get()
            ->map(fn (FakturPembelian $faktur) => [
                'nomor_invoice' => $faktur->no_invoice,
                'tanggal' => $faktur->tanggal?->toDateString(),
                'masa_pajak' => (int) $bulan,
                'tahun_pajak' => (int) $tahun,
                'npwp_lawan_transaksi' => $faktur->supplier?->npwp,
                'nama_lawan_transaksi' => $faktur->supplier?->nama,
                'jenis_pph' => $faktur->jenis_pph,
                'dasar_pengenaan' => round((float) $faktur->dasar_pph, 2),
                'tarif' => round((float) $faktur->tarif_pph, 4),
                'pph_dipotong' => round((float) $faktur->pph, 2),
                'siap_lapor' => !empty($faktur->supplier?->npwp) && (float) $faktur->pph > 0,
                'catatan' => empty($faktur->supplier?->npwp)
                    ? 'NPWP lawan transaksi belum diisi; Coretax menolak bukti potong tanpa identitas yang tervalidasi.'
                    : null,
            ]);
    }

    public function ringkasan(string $masa): array
    {
        $faktur = $this->fakturKeluaran($masa);
        $bupot = $this->buktiPotong($masa);

        return [
            'masa' => $masa,
            'faktur' => $faktur,
            'bupot' => $bupot,
            'total_dpp' => round((float) $faktur->sum('dpp'), 2),
            'total_ppn' => round((float) $faktur->sum('ppn'), 2),
            'total_pph' => round((float) $bupot->sum('pph_dipotong'), 2),
            'faktur_belum_siap' => $faktur->where('siap_lapor', false)->count(),
            'bupot_belum_siap' => $bupot->where('siap_lapor', false)->count(),
        ];
    }

    public function xmlFakturKeluaran(string $masa, ?string $npwpPenjual = null): string
    {
        $faktur = $this->fakturKeluaran($masa);

        $dokumen = new \DOMDocument('1.0', 'UTF-8');
        $dokumen->formatOutput = true;

        $root = $dokumen->createElement('FakturKeluaranBulk');
        $root->setAttribute('masaPajak', (string) $this->pecahMasa($masa)[1]);
        $root->setAttribute('tahunPajak', (string) $this->pecahMasa($masa)[0]);
        $root->setAttribute('npwpPenjual', (string) ($npwpPenjual ?? config('app.npwp_perusahaan', '')));
        $root->setAttribute('skemaAcuan', self::TEMPLATE_FAKTUR);
        $dokumen->appendChild($root);

        foreach ($faktur as $baris) {
            $node = $dokumen->createElement('FakturKeluaran');

            foreach ([
                'NomorFakturPajak' => $baris['nomor_faktur_pajak'],
                'TanggalFaktur' => $baris['tanggal'],
                'NpwpPembeli' => $baris['npwp_pembeli'],
                'NamaPembeli' => $baris['nama_pembeli'],
                'AlamatPembeli' => $baris['alamat_pembeli'],
                'JumlahDpp' => number_format($baris['dpp'], 2, '.', ''),
                'JumlahPpn' => number_format($baris['ppn'], 2, '.', ''),
                'ReferensiInternal' => $baris['nomor_faktur'],
            ] as $nama => $nilai) {
                $node->appendChild($dokumen->createElement($nama, htmlspecialchars((string) $nilai, ENT_XML1)));
            }

            $daftarBaris = $dokumen->createElement('DetailTransaksi');

            foreach ($baris['baris'] as $detail) {
                $item = $dokumen->createElement('Barang');

                foreach ([
                    'Nama' => $detail['nama_barang'],
                    'Jumlah' => number_format($detail['jumlah'], 6, '.', ''),
                    'HargaSatuan' => number_format($detail['harga_satuan'], 2, '.', ''),
                    'HargaTotal' => number_format($detail['total_harga'], 2, '.', ''),
                ] as $nama => $nilai) {
                    $item->appendChild($dokumen->createElement($nama, htmlspecialchars((string) $nilai, ENT_XML1)));
                }

                $daftarBaris->appendChild($item);
            }

            $node->appendChild($daftarBaris);
            $root->appendChild($node);
        }

        return $dokumen->saveXML();
    }

    private function catatanKesiapan(FakturPenjualan $faktur): ?string
    {
        if (!$faktur->is_ppn) {
            return 'Faktur non-PPN; tidak dilaporkan sebagai faktur pajak keluaran.';
        }

        if (empty($faktur->no_faktur_pajak)) {
            return 'NSFP belum diisi.';
        }

        if (empty($faktur->pelanggan?->npwp)) {
            return 'NPWP pembeli belum diisi; Coretax memvalidasi identitas lawan transaksi.';
        }

        return null;
    }

    private function pecahMasa(string $masa): array
    {
        [$tahun, $bulan] = array_pad(explode('-', $masa), 2, null);

        return [(int) $tahun, (int) $bulan];
    }
}
