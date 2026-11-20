<?php

namespace App\Services;

use App\Models\BuktiPotong;
use App\Support\IdentitasPajak;
use DOMDocument;
use Illuminate\Support\Collection;
use RuntimeException;

class BuktiPotongExportService
{
    public const SUMBER_CONTOH = 'bppu.xml (contoh resmi DJP, pajak.go.id/sites/default/files/2024-12/bppu.zip)';
    public const SUMBER_KAMUS = 'BPPU Excel to XML v.3 (pajak.go.id, 2025-09)';
    public const PERINGATAN = 'Struktur XML ini disusun dari contoh resmi DJP (bppu.xml) dan kamus kolom converter v.3. XSD resmi belum ditemukan dan belum diverifikasi, sehingga berkas ini wajib dicoba lebih dulu di Coretax sebelum dipakai melapor.';

    public const STATUS_PILIHAN = ['DRAFT', 'DITERBITKAN', 'SEMUA'];

    public function __construct(
        private BuktiPotongService $bukti,
        private KepatuhanPajakService $kepatuhan,
    ) {}

    public function penghalang(string $masa): Collection
    {
        $temuan = $this->kepatuhan->periksa($masa)['temuan'];

        return $temuan->filter(fn (array $baris) => in_array($baris['kategori'], KepatuhanPajakService::PENGHALANG_EKSPOR_BUPOT, true))->values();
    }

    public function baris(string $masa, string $status): Collection
    {
        $filter = $status === 'SEMUA' ? null : $status;

        return $this->bukti->daftar($masa, $filter)
            ->filter(fn (BuktiPotong $baris) => $baris->isDraft() || $baris->isDiterbitkan())
            ->values();
    }

    public function xml(string $masa, string $status): string
    {
        $penghalang = $this->penghalang($masa);

        if ($penghalang->isNotEmpty()) {
            throw new RuntimeException('Ekspor diblokir: ' . $penghalang->count() . ' temuan preflight harus dibereskan lebih dulu (' . $penghalang->pluck('kategori')->unique()->implode(', ') . ').');
        }

        $baris = $this->baris($masa, $status);

        if ($baris->isEmpty()) {
            throw new RuntimeException('Tidak ada bukti potong berstatus ' . $status . ' pada masa ini.');
        }

        $tanpaTku = $baris->filter(fn (BuktiPotong $item) => !IdentitasPajak::nitkuValid($item->penerima_nitku));

        if ($tanpaTku->isNotEmpty()) {
            throw new RuntimeException('Ekspor diblokir: elemen IDPlaceOfBusinessActivityOfIncomeRecipient wajib (22 digit), tetapi kosong pada bukti potong #' . $tanpaTku->pluck('id')->implode(', #') . '.');
        }

        $dokumen = new DOMDocument('1.0', 'UTF-8');
        $dokumen->formatOutput = true;

        $akar = $dokumen->createElement('BpuBulk');
        $akar->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dokumen->appendChild($akar);

        $akar->appendChild($dokumen->createElement('TIN', IdentitasPajak::normalisasiNpwp((string) config('app.npwp_perusahaan'))));
        $daftar = $dokumen->createElement('ListOfBpu');
        $akar->appendChild($daftar);

        $tkuPemotong = IdentitasPajak::bersihkan((string) config('app.nitku_perusahaan'));

        foreach ($baris as $item) {
            $item->loadMissing('pembayaran.invoice');
            $faktur = $item->pembayaran->invoice;

            $node = $dokumen->createElement('Bpu');
            $this->tambah($dokumen, $node, 'TaxPeriodMonth', (string) $item->masa_pajak);
            $this->tambah($dokumen, $node, 'TaxPeriodYear', (string) $item->tahun_pajak);
            $this->tambah($dokumen, $node, 'CounterpartTin', $this->tinPenerima($item));
            $this->tambah($dokumen, $node, 'IDPlaceOfBusinessActivityOfIncomeRecipient', IdentitasPajak::bersihkan($item->penerima_nitku));
            $this->tambah($dokumen, $node, 'TaxCertificate', 'N/A');
            $this->tambah($dokumen, $node, 'TaxObjectCode', $item->kode_objek_pajak);
            $this->tambah($dokumen, $node, 'TaxBase', $this->angka((float) $item->dasar_pengenaan, 2));
            $this->tambah($dokumen, $node, 'Rate', $this->angka((float) $item->tarif, 4));
            $this->tambah($dokumen, $node, 'Document', 'CommercialInvoice');
            $this->tambah($dokumen, $node, 'DocumentNumber', $faktur->no_invoice);
            $this->tambah($dokumen, $node, 'DocumentDate', $faktur->tanggal->toDateString());
            $this->tambah($dokumen, $node, 'IDPlaceOfBusinessActivity', $tkuPemotong);
            $this->tambah($dokumen, $node, 'GovTreasurerOpt', 'N/A');

            $sp2d = $dokumen->createElement('SP2DNumber');
            $sp2d->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:nil', 'true');
            $node->appendChild($sp2d);

            $this->tambah($dokumen, $node, 'WithholdingDate', $item->tanggal_pemotongan->toDateString());
            $daftar->appendChild($node);
        }

        return $dokumen->saveXML();
    }

    public function kolomExcel(): array
    {
        return [
            ['key' => 'id', 'label' => 'Ref. Internal'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'nomor_bukti_djp', 'label' => 'Nomor Bukti DJP'],
            ['key' => 'masa_pajak', 'label' => 'Masa'],
            ['key' => 'tahun_pajak', 'label' => 'Tahun'],
            ['key' => 'tanggal_pemotongan', 'label' => 'Tanggal Pemotongan'],
            ['key' => 'jenis_pph', 'label' => 'Jenis PPh'],
            ['key' => 'kode_objek_pajak', 'label' => 'Kode Objek Pajak'],
            ['key' => 'penerima_nama', 'label' => 'Penerima'],
            ['key' => 'penerima_npwp', 'label' => 'NPWP'],
            ['key' => 'penerima_nik', 'label' => 'NIK'],
            ['key' => 'penerima_nitku', 'label' => 'NITKU'],
            ['key' => 'dasar_pengenaan', 'label' => 'Dasar Pengenaan'],
            ['key' => 'tarif', 'label' => 'Tarif (%)'],
            ['key' => 'jumlah_pph', 'label' => 'PPh Dipotong'],
            ['key' => 'pembayaran', 'label' => 'No. Pembayaran'],
            ['key' => 'invoice', 'label' => 'No. Invoice'],
        ];
    }

    public function barisExcel(string $masa, string $status): Collection
    {
        return $this->baris($masa, $status)->map(fn (BuktiPotong $item) => [
            'id' => $item->id,
            'status' => $item->status,
            'nomor_bukti_djp' => $item->nomor_bukti_djp,
            'masa_pajak' => $item->masa_pajak,
            'tahun_pajak' => $item->tahun_pajak,
            'tanggal_pemotongan' => $item->tanggal_pemotongan->toDateString(),
            'jenis_pph' => $item->label_jenis,
            'kode_objek_pajak' => $item->kode_objek_pajak,
            'penerima_nama' => $item->penerima_nama,
            'penerima_npwp' => $item->penerima_npwp,
            'penerima_nik' => $item->penerima_nik,
            'penerima_nitku' => $item->penerima_nitku,
            'dasar_pengenaan' => (float) $item->dasar_pengenaan,
            'tarif' => (float) $item->tarif,
            'jumlah_pph' => (float) $item->jumlah_pph,
            'pembayaran' => $item->pembayaran?->payment_number,
            'invoice' => $item->pembayaran?->invoice?->no_invoice,
        ]);
    }

    private function tinPenerima(BuktiPotong $item): string
    {
        return filled($item->penerima_npwp)
            ? IdentitasPajak::normalisasiNpwp($item->penerima_npwp)
            : IdentitasPajak::bersihkan($item->penerima_nik);
    }

    private function angka(float $nilai, int $desimal): string
    {
        $teks = number_format($nilai, $desimal, '.', '');

        return str_contains($teks, '.') ? rtrim(rtrim($teks, '0'), '.') : $teks;
    }

    private function tambah(DOMDocument $dokumen, \DOMElement $induk, string $nama, ?string $nilai): void
    {
        $elemen = $dokumen->createElement($nama);
        $elemen->appendChild($dokumen->createTextNode((string) $nilai));
        $induk->appendChild($elemen);
    }
}
