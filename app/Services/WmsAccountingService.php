<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\FakturPembelian;
use App\Models\PembayaranFaktur;
use App\Models\JamKerjaProduksi;
use App\Models\Jurnal;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\PemakaianBarang;
use App\Models\PerakitanKit;
use App\Models\PemakaianBarangAlokasiStok;
use App\Models\LayerPersediaan;
use App\Models\BaganAkun;
use App\Models\DataPesanan;
use App\Models\DataPesananBiaya;
use App\Models\FakturPenjualan;
use App\Models\PenerimaanPembayaran;
use App\Models\ReturPembelian;
use App\Models\ReturPenjualan;
use App\Models\SuratJalan;
use App\Models\AlokasiTransferGudang;
use App\Models\TransferGudang;
use App\Models\KategoriJasa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WmsAccountingService
{
    public function __construct(
        private AccountingPeriodService $periods,
        private InventoryCostCalculator $costCalculator,
        private DocumentNumberService $numbers,
        private KapitalisasiAsetService $kapitalisasi,
        private DataPesananService $dataPesanan
    ) {}

    public function postLpb(PenerimaanBarang $lpb): Jurnal
    {
        $this->periods->assertOpen($lpb->tanggal, 'LPB');
        if ($lpb->document_type === 'SERVICE_BAP') {
            throw new RuntimeException('BAP jasa hanya menandai pekerjaan dimulai dan tidak membentuk jurnal.');
        }
        $lpb->loadMissing('details.kategori');
        $lines = [];

        foreach ($lpb->details->groupBy('id_kategori') as $details) {
            $category = $details->first()->kategori;
            $this->assertCategoryMapping($category);
            $amount = $details->sum(fn($detail) => (float) $detail->jumlah_barang_diterima * (float) $detail->harga);
            $this->line($lines, $category->coa_persediaan_id, $amount, 0, "Persediaan {$category->katnama}", $lpb->gudang_id);
            $this->line($lines, $category->coa_clearing_lpb_id, 0, $amount, "GRNI {$category->katnama}", $lpb->gudang_id);
        }

        return $this->post("LPB-{$lpb->id_lpb}", $lpb->tanggal, 'LPB', $lpb->id, "Penerimaan barang {$lpb->id_lpb}", $lines);
    }

    public function postReturPembelian(ReturPembelian $retur): Jurnal
    {
        $this->periods->assertOpen($retur->tanggal, 'Retur pembelian');
        $retur->loadMissing('details.lpbDetail.kategori', 'lpb');
        if ($retur->details->isEmpty()) {
            throw new RuntimeException('Retur pembelian harus memiliki minimal satu baris.');
        }

        $invoice = $retur->lpb->no_invoice
            ? FakturPembelian::lockForUpdate()->where('no_invoice', $retur->lpb->no_invoice)->first()
            : null;
        if ($invoice && $invoice->status === FakturPembelian::VOID) {
            throw new RuntimeException('Invoice terkait retur ini sudah dibatalkan.');
        }
        if ($invoice && $invoice->status === FakturPembelian::PENDING_APPROVAL) {
            throw new RuntimeException('Invoice terkait retur ini masih menunggu persetujuan Accounting Manager.');
        }

        $totalNilai = round((float) $retur->total_nilai, 2);
        $apPortion = 0.0;
        $advancePortion = 0.0;
        $lines = [];

        if ($invoice) {
            $apPortion = round(min($totalNilai, (float) $invoice->sisa_tagihan), 2);
            $advancePortion = round($totalNilai - $apPortion, 2);
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA), $apPortion, 0, "Pengurangan hutang atas retur {$retur->no_retur}");
            if ($advancePortion > 0) {
                $this->line($lines, AccountingSetting::accountId(AccountingSetting::UANG_MUKA_SUPPLIER), $advancePortion, 0, "Uang muka supplier dari retur {$retur->no_retur}");
            }
        }

        foreach ($retur->details->groupBy(fn($detail) => $detail->lpbDetail->id_kategori) as $details) {
            $category = $details->first()->lpbDetail->kategori;
            $this->assertCategoryMapping($category);
            $amount = $details->sum('total_harga');
            if (!$invoice) {
                $this->line($lines, $category->coa_clearing_lpb_id, $amount, 0, "Pengurangan GRNI {$category->katnama} (retur)", $retur->lpb?->gudang_id);
            }
            $this->line($lines, $category->coa_persediaan_id, 0, $amount, "Pengurangan persediaan {$category->katnama} (retur)", $retur->lpb?->gudang_id);
        }

        $journal = $this->post("RTV-{$retur->no_retur}", $retur->tanggal, 'RETUR_PEMBELIAN', $retur->id, "Retur pembelian {$retur->no_retur} atas LPB {$retur->lpb->id_lpb}", $lines);

        if ($invoice) {
            $this->applyReturToInvoice($retur, $invoice, $apPortion, $advancePortion);
        }

        return $journal;
    }

    private function applyReturToInvoice(ReturPembelian $retur, FakturPembelian $invoice, float $apPortion, float $advancePortion): void
    {
        if ($apPortion > 0) {
            $newGrandTotal = round((float) $invoice->grand_total - $apPortion, 2);
            $newSisaTagihan = max(0, round((float) $invoice->sisa_tagihan - $apPortion, 2));
            $invoice->update([
                'grand_total' => $newGrandTotal,
                'sisa_tagihan' => $newSisaTagihan,
                'status' => FakturPembelian::paymentStatus($newGrandTotal, (float) $invoice->payments()->sum('total_transaksi_pengurang_hutang')),
            ]);
        }

        $advancePaymentId = null;
        if ($advancePortion > 0) {
            $advancePayment = PembayaranFaktur::create([
                'payment_number' => $this->numbers->financial('PY', $retur->tanggal),
                'invoice_lpb_id' => $invoice->id,
                'tanggal_pembayaran' => $retur->tanggal,
                'metode_pembayaran' => 'Uang Muka dari Retur Pembelian',
                'coa_kas_bank_id' => null,
                'jumlah_pembayaran' => 0,
                'selisih_bayar' => $advancePortion,
                'jenis_selisih' => 'UANG_MUKA_SUPPLIER',
                'coa_selisih_id' => AccountingSetting::accountId(AccountingSetting::UANG_MUKA_SUPPLIER),
                'kelebihan_pembayaran' => $advancePortion,
                'total_transaksi_pengurang_hutang' => 0,
                'keterangan' => "Uang muka supplier dari retur pembelian {$retur->no_retur}",
                'finance_user_id' => Auth::id(),
                'status' => PembayaranFaktur::POSTED,
            ]);
            $advancePaymentId = $advancePayment->id;
        }

        $retur->update([
            'invoice_id' => $invoice->id,
            'hutang_reduction' => $apPortion,
            'advance_payment_id' => $advancePaymentId,
        ]);
    }

    public function postNpk(PemakaianBarang $npk): Jurnal
    {
        $this->periods->assertOpen($npk->tanggal, 'NPK');
        $npk->loadMissing('barang.tipeBarang');
        $category = $npk->barang?->tipeBarang;
        $this->assertCategoryMapping($category);

        $amount = (float) $npk->total_nilai;
        if ($amount <= 0) {
            throw new RuntimeException('Nilai pemakaian NPK harus lebih besar dari nol.');
        }

        $gudangId = $npk->id_gudang_asal;
        $pesananProduksi = $npk->data_pesanan_id ? DataPesanan::find($npk->data_pesanan_id) : null;

        if ($pesananProduksi) {
            $akunDebit = AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES);
            $keteranganDebit = "Barang dalam proses {$pesananProduksi->nomor}";
            $this->dataPesanan->catatBiaya(
                $pesananProduksi,
                DataPesananBiaya::NPK,
                (int) $npk->id,
                $amount,
                $npk->tanggal,
                "Pemakaian barang {$npk->kode}"
            );
        } else {
            $akunDebit = $category->coa_beban_id;
            $keteranganDebit = "Pemakaian {$category->katnama}";
        }

        return $this->post("NPK-{$npk->kode}-{$npk->id}", $npk->tanggal, 'NPK', $npk->id, "Pemakaian barang {$npk->kode}", [
            ['coa_id' => $akunDebit, 'gudang_id' => $gudangId, 'debit' => $amount, 'kredit' => 0, 'keterangan' => $keteranganDebit],
            ['coa_id' => $category->coa_persediaan_id, 'gudang_id' => $gudangId, 'debit' => 0, 'kredit' => $amount, 'keterangan' => "Pengurangan persediaan {$category->katnama}"],
        ]);
    }

    public function postPerakitanKit(PerakitanKit $perakitan): ?Jurnal
    {
        $this->periods->assertOpen($perakitan->tanggal, 'Perakitan kit');
        $perakitan->loadMissing('kit.bahanHasil.tipeBarang', 'details.bahan.tipeBarang');

        $kategoriKit = $perakitan->kit?->bahanHasil?->tipeBarang;
        $this->assertCategoryMapping($kategoriKit);

        $rakit = $perakitan->jenis === PerakitanKit::RAKIT;
        $lines = [];

        foreach ($perakitan->details->groupBy(fn ($detail) => $detail->bahan?->tipe_barang) as $details) {
            $kategori = $details->first()->bahan?->tipeBarang;
            $this->assertCategoryMapping($kategori);
            $nilai = round((float) $details->sum('nilai'), 2);

            if ($nilai <= 0) {
                continue;
            }

            $this->line($lines, $kategori->coa_persediaan_id, $rakit ? 0 : $nilai, $rakit ? $nilai : 0,
                ($rakit ? 'Komponen terpakai ' : 'Komponen kembali ') . $kategori->katnama, $perakitan->gudang_id);
        }

        $nilaiKit = round((float) $perakitan->nilai_total, 2);
        if ($nilaiKit > 0) {
            $this->line($lines, $kategoriKit->coa_persediaan_id, $rakit ? $nilaiKit : 0, $rakit ? 0 : $nilaiKit,
                ($rakit ? 'Persediaan kit jadi ' : 'Kit terurai ') . $kategoriKit->katnama, $perakitan->gudang_id);
        }

        $lines = array_values(array_filter($lines, fn ($line) => abs((float) $line['debit'] - (float) $line['kredit']) > 0.001));

        if ($lines === []) {
            return null;
        }

        return $this->post(
            "KIT-{$perakitan->nomor}",
            $perakitan->tanggal,
            'PERAKITAN_KIT',
            $perakitan->id,
            ($rakit ? 'Perakitan kit ' : 'Penguraian kit ') . $perakitan->nomor,
            $lines,
        );
    }

    public function postRevaluasiKurs(string $periode, string $tanggal, float $selisih): ?Jurnal
    {
        $selisih = round($selisih, 2);

        if (abs($selisih) < 0.005) {
            return null;
        }

        $this->periods->assertOpen($tanggal, 'Revaluasi kurs');

        $hutang = AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA);
        $lines = [];

        if ($selisih > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::RUGI_SELISIH_KURS), $selisih, 0,
                "Rugi selisih kurs periode {$periode}");
            $this->line($lines, $hutang, 0, $selisih, "Penyesuaian hutang usaha periode {$periode}");
        } else {
            $nilai = abs($selisih);
            $this->line($lines, $hutang, $nilai, 0, "Penyesuaian hutang usaha periode {$periode}");
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::LABA_SELISIH_KURS), 0, $nilai,
                "Laba selisih kurs periode {$periode}");
        }

        return $this->post(
            "FX-{$periode}",
            $tanggal,
            'REVALUASI_KURS',
            (int) str_replace('-', '', $periode),
            "Revaluasi kurs pos moneter periode {$periode}",
            $lines,
        );
    }

    public function postPembatalanPerintahKerja(DataPesanan $pesanan, float $nilai): ?Jurnal
    {
        $nilai = round($nilai, 2);

        if ($nilai <= 0) {
            return null;
        }

        $this->periods->assertOpen(now(), 'Pembatalan perintah kerja');

        $lines = [];

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::RUGI_PEMBATALAN_PRODUKSI), $nilai, 0,
            "Kerugian pembatalan perintah kerja {$pesanan->nomor}", $pesanan->gudang_id);
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES), 0, $nilai,
            "Pelepasan barang dalam proses {$pesanan->nomor}", $pesanan->gudang_id);

        return $this->post(
            "WOC-{$pesanan->nomor}",
            now(),
            'PEMBATALAN_PERINTAH_KERJA',
            $pesanan->id,
            "Pembatalan perintah kerja {$pesanan->nomor}",
            $lines,
        );
    }

    public function postPengirimanTransfer(TransferGudang $transfer): ?Jurnal
    {
        $this->periods->assertOpen($transfer->tanggal, 'Pengiriman transfer gudang');
        $transfer->loadMissing('details.bahan.tipeBarang');

        $transitId = AccountingSetting::accountId(AccountingSetting::PERSEDIAAN_DALAM_PERJALANAN);
        $lines = [];

        foreach ($this->nilaiTransferPerKategori($transfer, 'asal') as $baris) {
            $kategori = $baris['kategori'];
            $nilai = round($baris['nilai'], 2);

            if ($nilai <= 0) {
                continue;
            }

            $this->line($lines, $transitId, $nilai, 0,
                "Persediaan dalam perjalanan {$kategori->katnama}", $transfer->gudang_tujuan_id);
            $this->line($lines, $kategori->coa_persediaan_id, 0, $nilai,
                "Persediaan keluar {$kategori->katnama}", $transfer->gudang_asal_id);
        }

        if ($lines === []) {
            return null;
        }

        return $this->post(
            "TRF-{$transfer->nomor_transfer}-KIRIM",
            $transfer->tanggal,
            'TRANSFER_GUDANG_KIRIM',
            $transfer->id,
            "Pengiriman transfer gudang {$transfer->nomor_transfer}",
            $lines,
        );
    }

    public function postPenerimaanTransfer(TransferGudang $transfer): ?Jurnal
    {
        $this->periods->assertOpen($transfer->tanggal, 'Penerimaan transfer gudang');
        $transfer->loadMissing('details.bahan.tipeBarang');

        $transitId = AccountingSetting::accountId(AccountingSetting::PERSEDIAAN_DALAM_PERJALANAN);
        $lines = [];

        foreach ($this->nilaiTransferPerKategori($transfer, 'tujuan') as $baris) {
            $kategori = $baris['kategori'];
            $nilai = round($baris['nilai'], 2);

            if ($nilai <= 0) {
                continue;
            }

            $this->line($lines, $kategori->coa_persediaan_id, $nilai, 0,
                "Persediaan masuk {$kategori->katnama}", $transfer->gudang_tujuan_id);
            $this->line($lines, $transitId, 0, $nilai,
                "Pelepasan persediaan dalam perjalanan {$kategori->katnama}", $transfer->gudang_tujuan_id);
        }

        if ($lines === []) {
            return null;
        }

        return $this->post(
            "TRF-{$transfer->nomor_transfer}-TERIMA",
            $transfer->tanggal,
            'TRANSFER_GUDANG',
            $transfer->id,
            "Penerimaan transfer gudang {$transfer->nomor_transfer}",
            $lines,
        );
    }

    private function nilaiTransferPerKategori(TransferGudang $transfer, string $sisi): array
    {
        $nilaiPerKategori = [];

        foreach ($transfer->details as $detail) {
            $kategori = $detail->bahan?->tipeBarang;
            $this->assertCategoryMapping($kategori);

            $nilai = $sisi === 'asal'
                ? (float) AlokasiTransferGudang::where('detail_transfer_gudang_id', $detail->id)->sum('total_nilai')
                : (float) AlokasiTransferGudang::where('detail_transfer_gudang_id', $detail->id)
                    ->join('wms_layer_persediaan as tujuan', 'tujuan.id', '=', 'alokasi_transfer_gudangs.inventory_layer_tujuan_id')
                    ->sum(DB::raw('tujuan.initial_quantity * tujuan.unit_cost'));

            if ($nilai <= 0) {
                continue;
            }

            $nilaiPerKategori[$kategori->id] ??= ['kategori' => $kategori, 'nilai' => 0.0];
            $nilaiPerKategori[$kategori->id]['nilai'] += $nilai;
        }

        return $nilaiPerKategori;
    }

    public function postSerapanProduksi(JamKerjaProduksi $jamKerja): Jurnal
    {
        $this->periods->assertOpen($jamKerja->tanggal, 'Serapan jam kerja produksi');

        $jamKerja->loadMissing(['pesanan', 'pusatKerja']);

        $tenagaKerja = round((float) $jamKerja->biaya_tenaga_kerja, 2);
        $overhead = round((float) $jamKerja->biaya_overhead, 2);
        $total = round($tenagaKerja + $overhead, 2);

        if ($total <= 0) {
            throw new RuntimeException('Serapan jam kerja tidak bernilai, jadi tidak ada yang dijurnal.');
        }

        $gudangId = $jamKerja->pesanan?->gudang_id ?? $jamKerja->pusatKerja?->gudang_id;
        $lines = [];

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES), $total, 0,
            "Serapan jam kerja {$jamKerja->nomor}", $gudangId);

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BEBAN_TENAGA_KERJA_DISERAP), 0, $tenagaKerja,
            "Tenaga kerja langsung diserap {$jamKerja->nomor}", $gudangId);

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BEBAN_OVERHEAD_DISERAP), 0, $overhead,
            "Overhead pabrik diserap {$jamKerja->nomor}", $gudangId);

        return $this->post(
            "JKP-{$jamKerja->nomor}",
            $jamKerja->tanggal,
            'SERAPAN_PRODUKSI',
            $jamKerja->id,
            "Serapan jam kerja produksi {$jamKerja->nomor}",
            $lines,
        );
    }

    public function postSuratJalan(SuratJalan $suratJalan): Jurnal
    {
        $this->periods->assertOpen($suratJalan->tanggal, 'Surat jalan');
        $suratJalan->loadMissing('details.bahan.tipeBarang');

        $lines = [];
        $totalHpp = 0.0;

        $dariStok = $suratJalan->details->whereNull('data_pesanan_id');
        $dariProduksi = $suratJalan->details->whereNotNull('data_pesanan_id');

        foreach ($dariStok->groupBy(fn ($detail) => $detail->bahan?->tipe_barang) as $details) {
            $kategori = $details->first()->bahan?->tipeBarang;
            $this->assertCategoryMapping($kategori);

            $hpp = round((float) $details->sum(fn ($detail) => (float) $detail->hpp), 2);

            if ($hpp <= 0) {
                continue;
            }

            $totalHpp += $hpp;
            $this->line($lines, $kategori->coa_persediaan_id, 0, $hpp,
                "Pengurangan persediaan {$kategori->katnama} (surat jalan)", $suratJalan->gudang_id);
        }

        $hppProduksi = round((float) $dariProduksi->sum(fn ($detail) => (float) $detail->hpp), 2);

        if ($hppProduksi > 0) {
            $totalHpp += $hppProduksi;
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES), 0, $hppProduksi,
                "Pelepasan barang dalam proses (surat jalan {$suratJalan->nomor})", $suratJalan->gudang_id);
        }

        if ($totalHpp <= 0) {
            throw new RuntimeException('Surat jalan tidak memiliki nilai harga pokok untuk dijurnal.');
        }

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BEBAN_POKOK_PENJUALAN),
            round($totalHpp, 2), 0, "Beban pokok penjualan {$suratJalan->nomor}", $suratJalan->gudang_id);

        return $this->post(
            "SJ-{$suratJalan->nomor}",
            $suratJalan->tanggal,
            'SURAT_JALAN',
            $suratJalan->id,
            "Surat jalan {$suratJalan->nomor}",
            $lines,
        );
    }

    public function postFakturPenjualan(FakturPenjualan $faktur): Jurnal
    {
        $this->periods->assertOpen($faktur->tanggal, 'Faktur penjualan');

        $dpp = round((float) $faktur->total_dpp, 2);
        $ppn = round((float) $faktur->total_ppn, 2);
        $total = round((float) $faktur->grand_total, 2);

        if ($dpp <= 0) {
            throw new RuntimeException('Faktur penjualan harus memiliki nilai DPP lebih besar dari nol.');
        }

        $lines = [];
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA), $total, 0,
            "Piutang usaha {$faktur->nomor}");
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::PENJUALAN), 0, $dpp,
            "Penjualan {$faktur->nomor}");

        if ($ppn > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::PPN_KELUARAN), 0, $ppn,
                "PPN keluaran {$faktur->nomor}");
        }

        return $this->post(
            "FJ-{$faktur->nomor}",
            $faktur->tanggal,
            'FAKTUR_PENJUALAN',
            $faktur->id,
            "Faktur penjualan {$faktur->nomor}",
            $lines,
        );
    }

    public function postPenerimaanPembayaran(PenerimaanPembayaran $pembayaran): Jurnal
    {
        $this->periods->assertOpen($pembayaran->tanggal, 'Penerimaan pembayaran');

        $jumlah = round((float) $pembayaran->jumlah, 2);

        if ($jumlah <= 0 && round((float) $pembayaran->jumlah_uang_muka, 2) <= 0) {
            throw new RuntimeException('Nilai penerimaan pembayaran harus lebih besar dari nol.');
        }

        $uangMuka = round((float) $pembayaran->jumlah_uang_muka, 2);
        $lines = [];

        if ($pembayaran->uang_muka_id) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::UANG_MUKA_PELANGGAN), $jumlah, 0,
                "Pemakaian uang muka {$pembayaran->nomor}");
        } else {
            $this->line($lines, $pembayaran->coa_kas_bank_id, round($jumlah + $uangMuka, 2), 0,
                "Penerimaan {$pembayaran->nomor}");
        }

        $this->line($lines, AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA), 0, $jumlah,
            "Pelunasan piutang {$pembayaran->nomor}");

        if (!$pembayaran->uang_muka_id && $uangMuka > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::UANG_MUKA_PELANGGAN), 0, $uangMuka,
                "Kelebihan bayar diparkir sebagai uang muka {$pembayaran->nomor}");
        }

        return $this->post(
            "RC-{$pembayaran->nomor}",
            $pembayaran->tanggal,
            'PENERIMAAN_PEMBAYARAN',
            $pembayaran->id,
            "Penerimaan pembayaran {$pembayaran->nomor}",
            $lines,
        );
    }

    public function postReturPenjualan(ReturPenjualan $retur): Jurnal
    {
        $this->periods->assertOpen($retur->tanggal, 'Retur penjualan');
        $retur->loadMissing('details.bahan.tipeBarang', 'suratJalan');

        $dpp = round((float) $retur->total_dpp, 2);
        $ppn = round((float) $retur->total_ppn, 2);
        $total = round($dpp + $ppn, 2);

        if ($dpp <= 0) {
            throw new RuntimeException('Retur penjualan harus memiliki nilai DPP lebih besar dari nol.');
        }


        $lines = [];

        $faktur = $retur->faktur_penjualan_id ? FakturPenjualan::find($retur->faktur_penjualan_id) : null;
        $adaPiutang = $faktur && $faktur->isTertagih();

        if ($adaPiutang) {
            if ($total > (float) $faktur->sisa_tagihan + 0.005) {
                throw new RuntimeException(
                    "Nilai retur {$retur->nomor} melebihi sisa tagihan faktur {$faktur->nomor}. "
                    . 'Sistem ini belum punya mekanisme pengembalian uang ke pelanggan, jadi retur sebesar itu tidak bisa dijurnal.'
                );
            }

            $this->line($lines, AccountingSetting::accountId(AccountingSetting::RETUR_PENJUALAN), $dpp, 0,
                "Retur penjualan {$retur->nomor}");

            if ($ppn > 0) {
                $this->line($lines, AccountingSetting::accountId(AccountingSetting::PPN_KELUARAN), $ppn, 0,
                    "Koreksi PPN keluaran {$retur->nomor}");
            }

            $this->line($lines, AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA), 0, $total,
                "Pengurangan piutang {$retur->nomor}");
        }

        $gudangId = $retur->suratJalan?->gudang_id;
        $totalHpp = 0.0;

        foreach ($retur->details->groupBy(fn ($detail) => $detail->bahan?->tipe_barang) as $details) {
            $kategori = $details->first()->bahan?->tipeBarang;
            $this->assertCategoryMapping($kategori);

            $hpp = round((float) $details->sum(fn ($detail) => (float) $detail->hpp), 2);

            if ($hpp <= 0) {
                continue;
            }

            $totalHpp += $hpp;
            $this->line($lines, $kategori->coa_persediaan_id, $hpp, 0,
                "Persediaan kembali {$kategori->katnama} (retur penjualan)", $gudangId);
        }

        if ($totalHpp > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::BEBAN_POKOK_PENJUALAN), 0,
                round($totalHpp, 2), "Koreksi beban pokok {$retur->nomor}", $gudangId);
        }

        return $this->post(
            "RJ-{$retur->nomor}",
            $retur->tanggal,
            'RETUR_PENJUALAN',
            $retur->id,
            "Retur penjualan {$retur->nomor}",
            $lines,
        );
    }

    public function consumeStock(PemakaianBarang $npk): void
    {
        $quantity = (float) $npk->jumlah_stok > 0 ? (float) $npk->jumlah_stok : (float) $npk->jumlah;
        $layers = LayerPersediaan::query()
            ->where('bahan_id', $npk->id_barang)
            ->when($npk->id_gudang_asal, fn($query) => $query->where('gudang_id', $npk->id_gudang_asal))
            ->where('stock_status', 'AVAILABLE')
            ->where(function ($query) {
                $query->whereNull('inventory_lot_id')->orWhereHas('lot', fn ($lot) => $lot
                    ->where('blocked', false)
                    ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhereDate('expires_at', '>=', today())));
            })
            ->where('remaining_quantity', '>', 0)
            ->whereDate('transaction_date', '<=', $npk->tanggal)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($layers->sum('remaining_quantity') < $quantity) {
            throw new RuntimeException('Stok layer LPB tidak mencukupi untuk NPK ini.');
        }

        $remaining = $quantity;
        $totalCost = 0;

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $layer->remaining_quantity);
            $unitCost = (float) $layer->unit_cost;
            $cost = round($take * $unitCost, 2);
            PemakaianBarangAlokasiStok::create([
                'npk_id' => $npk->id,
                'inventory_layer_id' => $layer->id,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'total_cost' => $cost,
            ]);
            $newRemaining = (float) $layer->remaining_quantity - $take;
            $layer->update(['remaining_quantity' => $newRemaining]);
            if ($layer->source_type === 'LPB_DETAIL') {
                PenerimaanBarangDetail::whereKey($layer->source_id)->update([
                    'jumlah_dipakai' => DB::raw('jumlah_dipakai + ' . (float) $take),
                    'jumlah_tersisa' => $newRemaining,
                    'flag_dipakai' => $newRemaining > 0 ? 1 : 0,
                ]);
            }
            $remaining -= $take;
            $totalCost += $cost;
        }

        $effectiveUnitCost = $quantity > 0 ? round($totalCost / $quantity, 4) : 0;
        $npk->update(['harga_satuan' => $effectiveUnitCost, 'total_nilai' => $totalCost, 'status' => PemakaianBarang::POSTED]);
    }

    public function restoreStock(PemakaianBarang $npk, bool $deleteJournal = true): void
    {
        foreach ($npk->allocations()->lockForUpdate()->get() as $allocation) {
            $layer = LayerPersediaan::lockForUpdate()->findOrFail($allocation->inventory_layer_id);
            $layer->update([
                'remaining_quantity' => (float) $layer->remaining_quantity + (float) $allocation->quantity,
            ]);
            if ($layer->source_type === 'LPB_DETAIL') {
                PenerimaanBarangDetail::whereKey($layer->source_id)->update([
                    'jumlah_dipakai' => DB::raw('GREATEST(jumlah_dipakai - ' . (float) $allocation->quantity . ', 0)'),
                    'jumlah_tersisa' => $layer->remaining_quantity,
                    'flag_dipakai' => 1,
                ]);
            }
        }
        $npk->allocations()->delete();
        if ($deleteJournal) $this->deleteAutomaticJournal('NPK', $npk->id);
    }

    public function postInvoice(FakturPembelian $invoice): Jurnal
    {
        $this->periods->assertOpen($invoice->tanggal, 'Invoice supplier');
        $invoice->loadMissing(['receipts.lpb.details.kategori', 'receipts.lpb.serviceDetails.servicePoDetail.category']);
        if ($invoice->receipts->isEmpty()) {
            throw new RuntimeException('Invoice harus memiliki minimal satu LPB.');
        }

        $lines = [];
        foreach ($invoice->receipts->flatMap(fn($receipt) => $receipt->lpb->details)->groupBy('id_kategori') as $details) {
            $category = $details->first()->kategori;
            $this->assertCategoryMapping($category);
            $amount = $details->sum(fn($detail) => (float) $detail->jumlah_barang_diterima * (float) $detail->harga);
            $this->line($lines, $category->coa_clearing_lpb_id, $amount, 0, "Penyelesaian GRNI {$category->katnama}");
        }
        $jasaKapitalisasi = collect();
        foreach ($invoice->receipts->flatMap(fn($receipt) => $receipt->lpb->serviceDetails)->groupBy('servicePoDetail.service_category_id') as $details) {
            $category = $details->first()->servicePoDetail->category;
            if (!$category) {
                throw new RuntimeException('Kategori jasa pada BAP tidak tersedia.');
            }
            BaganAkun::assertUsable($category->grni_coa_id, [['LIABILITAS', 'KREDIT']], "GRNI jasa {$category->name}");

            $expensePairs = $category->code === KategoriJasa::PRODUCTION
                ? [['ASET', 'DEBIT']]
                : [['BEBAN', 'DEBIT']];

            $dibebankan = $details;

            if ($category->perlakuan === KategoriJasa::KAPITALISASI) {
                $dibebankan = $details->filter(fn ($detail) => $detail->servicePoDetail->aset === null);

                foreach ($details->diff($dibebankan) as $detail) {
                    $aset = $detail->servicePoDetail->aset;
                    $akunAset = $this->kapitalisasi->akunAsetUntuk($aset);
                    BaganAkun::assertUsable($akunAset, [['ASET', 'DEBIT']], "aset kapitalisasi {$aset->nomor_aset}");
                    $this->line($lines, $akunAset, (float) $detail->amount, 0, "Kapitalisasi jasa ke aset {$aset->nomor_aset}");
                    $jasaKapitalisasi->push($detail);
                }

                if ($dibebankan->isEmpty()) {
                    continue;
                }
            }

            BaganAkun::assertUsable($category->expense_coa_id, $expensePairs, "beban/WIP jasa {$category->name}");
            $this->line($lines, $category->expense_coa_id, $dibebankan->sum('amount'), 0, "Penyelesaian jasa {$category->name}");
        }

        if ((float) $invoice->ppn > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::PPN_MASUKAN), (float) $invoice->ppn, 0, 'PPN Masukan');
        }
        if ((float) $invoice->ppn_impor > 0) {
            $this->line($lines, AccountingSetting::accountId(AccountingSetting::PPN_IMPOR), (float) $invoice->ppn_impor, 0, 'PPN Masukan Impor');
        }
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BIAYA_ONGKIR), (float) $invoice->ongkir, 0, 'Biaya angkut pembelian');
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::DISKON_PEMBELIAN), 0, (float) $invoice->diskon, 'Diskon pembelian');
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA), 0, (float) $invoice->grand_total, 'Hutang supplier');

        $journal = $this->post("INV-{$invoice->no_invoice}", $invoice->tanggal, 'INVOICE_SUPPLIER', $invoice->id, "Invoice supplier {$invoice->no_invoice}", $lines);

        foreach ($jasaKapitalisasi as $detail) {
            $this->kapitalisasi->catatDariJasa($detail, $journal->id, $invoice->tanggal);
        }

        return $journal;
    }

    public function postPayment(PembayaranFaktur $payment): Jurnal
    {
        $this->periods->assertOpen($payment->tanggal_pembayaran, 'Pembayaran supplier');
        $payment->loadMissing('invoice', 'sumberUangMuka');
        $invoice = $payment->invoice;
        BaganAkun::assertUsable($payment->coa_kas_bank_id, [['ASET', 'DEBIT']], 'kas/bank pembayaran', true);
        if ((float) $payment->uang_muka_dipakai > 0) {
            if (!$payment->sumberUangMuka) {
                throw new RuntimeException('Sumber uang muka supplier tidak ditemukan.');
            }
            BaganAkun::assertUsable($payment->sumberUangMuka->coa_selisih_id, [['ASET', 'DEBIT']], 'uang muka supplier');
        }
        if ($payment->jenis_selisih) {
            $differencePairs = match ($payment->jenis_selisih) {
                'PENDAPATAN_SELISIH' => [['PENDAPATAN', 'KREDIT']],
                'BEBAN_SELISIH' => [['BEBAN', 'DEBIT']],
                'UANG_MUKA_SUPPLIER' => [['ASET', 'DEBIT']],
                default => throw new RuntimeException('Jenis selisih pembayaran tidak dikenali.'),
            };
            BaganAkun::assertUsable($payment->coa_selisih_id, $differencePairs, 'selisih pembayaran');
        }
        $apReduction = (float) $payment->total_transaksi_pengurang_hutang;
        $lines = [];
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA), $apReduction, 0, "Pelunasan {$invoice->no_invoice}");
        $this->line($lines, $payment->coa_kas_bank_id, 0, (float) $payment->jumlah_pembayaran + (float) $payment->biaya_transfer_bank + (float) $payment->potongan_materai, 'Kas/bank keluar');
        $pphAccountId = $invoice->jenis_pph ? AccountingSetting::accountId(AccountingSetting::PPH_LIABILITY_KEY[$invoice->jenis_pph]) : null;
        $this->line($lines, $pphAccountId, 0, (float) $payment->potongan_pph, "{$invoice->jenis_pph} dipotong saat pembayaran");
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BEBAN_MATERAI), (float) $payment->potongan_materai, 0, 'Beban materai');
        if ($payment->jenis_selisih === 'PENDAPATAN_SELISIH') {
            $this->line($lines, $payment->coa_selisih_id, 0, (float) $payment->selisih_bayar, 'Pendapatan selisih pembayaran');
        } elseif ($payment->jenis_selisih === 'BEBAN_SELISIH') {
            $this->line($lines, $payment->coa_selisih_id, (float) $payment->selisih_bayar, 0, 'Beban selisih pembayaran');
        } elseif ($payment->jenis_selisih === 'UANG_MUKA_SUPPLIER') {
            $this->line($lines, $payment->coa_selisih_id, (float) $payment->kelebihan_pembayaran, 0, 'Uang muka supplier');
        }
        $this->line($lines, AccountingSetting::accountId(AccountingSetting::BIAYA_BANK), (float) $payment->biaya_transfer_bank, 0, 'Biaya transfer bank');
        if ((float) $payment->uang_muka_dipakai > 0) {
            $this->line(
                $lines,
                $payment->sumberUangMuka->coa_selisih_id,
                0,
                (float) $payment->uang_muka_dipakai,
                "Pemakaian uang muka supplier ({$payment->sumberUangMuka->payment_number})"
            );
        }

        return $this->post("PAY-{$invoice->no_invoice}-{$payment->id}", $payment->tanggal_pembayaran, 'PELUNASAN_HUTANG', $payment->id, "Pembayaran invoice {$invoice->no_invoice}", $lines);
    }

    public function deleteAutomaticJournal(string $source, int $referenceId): void
    {
        Jurnal::where('sumber_transaksi', $source)->where('reff_id', $referenceId)->delete();
    }

    public function reverseAutomaticJournal(string $source, int $referenceId, string $reason): Jurnal
    {
        if (!in_array($source, ['LPB', 'NPK', 'INVOICE_SUPPLIER', 'PELUNASAN_HUTANG', 'RETUR_PEMBELIAN'], true)) {
            throw new RuntimeException('Jurnal otomatis harus dibatalkan melalui workflow dokumen sumber.');
        }
        $this->periods->assertOpen(now(), 'Pembatalan dokumen sumber');
        $original = Jurnal::with('details')->where('sumber_transaksi', $source)
            ->where('reff_id', $referenceId)->where('status', 'POSTED')->firstOrFail();
        $reversal = Jurnal::create([
            'no_jurnal' => $this->numbers->financial('JR', now()),
            'tanggal' => now()->toDateString(),
            'keterangan' => $reason,
            'sumber_transaksi' => 'REVERSAL',
            'reff_id' => $original->id,
            'status' => 'POSTED',
            'created_by' => Auth::id(),
            'posted_by' => Auth::id(),
            'posted_at' => now(),
            'reversal_of_id' => $original->id,
            'total_debit' => $original->total_kredit,
            'total_kredit' => $original->total_debit,
        ]);
        $reversal->details()->createMany($original->details->map(fn($line) => [
            'coa_id' => $line->coa_id,
            'gudang_id' => $line->gudang_id,
            'debit' => $line->kredit,
            'kredit' => $line->debit,
            'keterangan' => 'Pembalik: ' . $line->keterangan,
        ])->all());
        $original->update(['status' => 'REVERSED']);
        return $reversal;
    }

    public function postPajakPenghasilan(int $referenceId, $date, string $description, array $lines): Jurnal
    {
        return $this->post("PPH-{$referenceId}", $date, 'PAJAK_PENGHASILAN', $referenceId, $description, $lines);
    }

    private function post(string $number, $date, string $source, int $referenceId, string $description, array $lines): Jurnal
    {
        $lines = collect($lines)->filter(fn($line) => (float) $line['debit'] > 0 || (float) $line['kredit'] > 0)->values();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('kredit'), 2);
        if ($debit <= 0 || abs($debit - $credit) > 0.01) {
            throw new RuntimeException("Jurnal {$number} tidak seimbang (debit {$debit}, kredit {$credit}).");
        }

        $existing = Jurnal::where('sumber_transaksi', $source)->where('reff_id', $referenceId)->first();
        $journal = Jurnal::updateOrCreate(
            ['sumber_transaksi' => $source, 'reff_id' => $referenceId],
            [
                'no_jurnal' => $existing?->no_jurnal ?? $this->numbers->financial('JR', $date),
                'tanggal' => $date,
                'keterangan' => $description,
                'status' => 'POSTED',
                'created_by' => Auth::id(),
                'posted_by' => Auth::id(),
                'posted_at' => now(),
                'total_debit' => $debit,
                'total_kredit' => $credit
            ]
        );
        $journal->details()->delete();
        $journal->details()->createMany($lines->all());
        return $journal;
    }

    private function line(array &$lines, ?int $accountId, float $debit, float $credit, string $description, ?int $gudangId = null): void
    {
        if ($debit <= 0 && $credit <= 0) {
            return;
        }
        if (!$accountId) {
            throw new RuntimeException("Mapping akun untuk {$description} belum diatur.");
        }
        $lines[] = [
            'coa_id' => $accountId,
            'gudang_id' => $gudangId,
            'debit' => round($debit, 2),
            'kredit' => round($credit, 2),
            'keterangan' => $description,
        ];
    }

    private function assertCategoryMapping($category): void
    {
        if (!$category || !$category->coa_persediaan_id || !$category->coa_beban_id || !$category->coa_clearing_lpb_id) {
            throw new RuntimeException('Mapping Persediaan, Pemakaian, dan GRNI pada kategori bahan belum lengkap.');
        }
        BaganAkun::assertUsable($category->coa_persediaan_id, [['ASET', 'DEBIT']], 'persediaan kategori bahan');
        BaganAkun::assertUsable($category->coa_beban_id, [['BEBAN', 'DEBIT']], 'pemakaian kategori bahan');
        BaganAkun::assertUsable($category->coa_clearing_lpb_id, [['LIABILITAS', 'KREDIT']], 'GRNI kategori bahan');
    }
}
