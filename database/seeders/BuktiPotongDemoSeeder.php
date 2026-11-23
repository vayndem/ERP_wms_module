<?php

namespace Database\Seeders;

use App\Models\BaganAkun;
use App\Models\BuktiPotong;
use App\Models\FakturPembelian;
use App\Models\KategoriJasa;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanJasa;
use App\Models\PesananJasa;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BuktiPotongService;
use App\Services\DocumentNumberService;
use App\Services\WmsAccountingService;
use App\Support\IdentitasPajak;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuktiPotongDemoSeeder extends Seeder
{
    public function run(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->orderBy('id')->first();
        $purchasing = User::where('type', User::ROLE_PURCHASING)->orderBy('id')->first();

        if (!$finance || !$purchasing || BuktiPotong::exists()) {
            return;
        }

        $pemasok = Supplier::orderBy('id')->get()
            ->filter(fn (Supplier $supplier) => IdentitasPajak::npwpValid((string) $supplier->npwp) && IdentitasPajak::nitkuValid((string) $supplier->nitku))
            ->values();

        if ($pemasok->isEmpty()) {
            $this->command?->warn('Skenario bukti potong dilewati: tidak ada supplier dengan NPWP dan NITKU valid.');

            return;
        }

        $pilih = fn (int $urutan) => $pemasok[$urutan % $pemasok->count()];

        $bulanLalu = today()->subMonthNoOverflow()->startOfMonth()->addDays(14);
        $hariIni = today();

        $this->skenario('PPh 23 satu faktur dua pembayaran di dua masa', function () use ($purchasing, $finance, $bulanLalu, $hariIni, $pilih) {
            $faktur = $this->fakturJasa($purchasing, $pilih(0), 'PPH23', '24-104-29', 2, 10000000, $bulanLalu->copy()->subDays(3), 'Jasa perawatan mesin jahit berkala');
            $this->bayar($finance, $faktur, $bulanLalu, 4900000, 100000);
            $this->bayar($finance, $faktur, $hariIni, 4900000, 100000);
        });

        $this->skenario('PPh 4(2) sewa gudang', function () use ($purchasing, $finance, $hariIni, $pilih) {
            $faktur = $this->fakturJasa($purchasing, $pilih(1), 'PPH4A2', '28-403-02', 10, 5000000, $hariIni->copy()->subDays(2), 'Sewa ruang gudang tambahan');
            $this->bayar($finance, $faktur, $hariIni, 4500000, 500000);
        });

        $this->skenario('Pembayaran ber-PPh yang kemudian VOID', function () use ($purchasing, $finance, $hariIni, $bulanLalu, $pilih) {
            $faktur = $this->fakturJasa($purchasing, $pilih(2), 'PPH23', '24-104-29', 2, 2000000, $hariIni->copy()->subDays(1), 'Jasa servis AC kantor');
            $pembayaran = $this->bayar($finance, $faktur, $hariIni, 1960000, 40000);

            $service = app(BuktiPotongService::class);
            $service->sinkron($hariIni->format('Y-m'), $finance);
            $service->sinkron($bulanLalu->format('Y-m'), $finance);

            $this->batalkanPembayaran($finance, $faktur, $pembayaran);
        });

        $service = app(BuktiPotongService::class);

        foreach ([$bulanLalu->format('Y-m'), $hariIni->format('Y-m')] as $masa) {
            $service->sinkron($masa, $finance);
        }

        $this->skenario('Penerbitan satu bukti potong', function () use ($service, $finance, $bulanLalu) {
            $bukti = BuktiPotong::where('status', BuktiPotong::DRAFT)
                ->where('jenis_pph', 'PPH23')
                ->whereDate('tanggal_pemotongan', $bulanLalu->toDateString())
                ->orderBy('id')
                ->first();

            if ($bukti) {
                $service->terbitkan($bukti, 'DEMO-BP-0001', $bulanLalu->toDateString(), $finance);
            }
        });
    }

    private function skenario(string $nama, callable $isi): void
    {
        try {
            DB::transaction($isi);
        } catch (Throwable $e) {
            $this->command?->warn("Skenario bukti potong dilewati ({$nama}): " . $e->getMessage());
        }
    }

    private function fakturJasa(User $pembuat, Supplier $supplier, string $jenis, string $kode, float $tarif, float $dasar, Carbon $tanggal, string $uraian): FakturPembelian
    {
        Auth::setUser($pembuat);

        $numbers = app(DocumentNumberService::class);
        $kategori = KategoriJasa::where('code', KategoriJasa::OPERATIONAL)->firstOrFail();

        $po = PesananJasa::create([
            'no_po' => $numbers->financial('PJ', $tanggal),
            'document_type' => 'SERVICE',
            'tanggal' => $tanggal,
            'supplier_id' => $supplier->id,
            'no_order' => '-',
            'untuk_perhatian' => 'Demo Bukti Potong',
            'term' => '14 hari',
            'notes' => 'PO jasa demo bukti potong: ' . $uraian,
            'total_exclude' => $dasar,
            'total_include' => $dasar,
            'grand_total' => $dasar,
        ]);

        $detailPo = $po->serviceDetails()->create([
            'service_category_id' => $kategori->id,
            'id_kategori' => $kategori->kategori_bahan_id,
            'service_type' => $kategori->code,
            'description' => $uraian,
            'quantity' => 1,
            'unit' => 'JOB',
            'unit_price' => $dasar,
            'subtotal' => $dasar,
            'accepted_amount' => $dasar,
        ]);

        $bap = PenerimaanJasa::create([
            'id_lpb' => $numbers->external('BAP', $tanggal),
            'document_type' => 'SERVICE_BAP',
            'tanggal' => $tanggal,
            'no_po' => $po->no_po,
            'no_sj' => 'DEMO-BP-BA-' . $po->id,
            'id_user' => $pembuat->id,
            'status' => PenerimaanBarang::POSTED,
            'jenis_lpb' => 3,
            'kunci' => 1,
        ]);

        $bap->serviceDetails()->create([
            'service_po_detail_id' => $detailPo->id,
            'id_kategori' => $detailPo->id_kategori,
            'progress_percent' => 100,
            'amount' => $dasar,
            'department_cost_center' => 'Gudang',
        ]);

        $faktur = FakturPembelian::create([
            'no_invoice' => 'DEMO-BP-INV-' . $po->id,
            'kode_supplier' => $supplier->id,
            'tanggal' => $tanggal,
            'tgl_deadline_pembayaran' => $tanggal->copy()->addDays(30),
            'sub_total' => $dasar,
            'jenis_pajak' => 'NON_PPN',
            'dpp_ppn' => 0,
            'tarif_ppn' => 0,
            'ppn' => 0,
            'jenis_pph' => $jenis,
            'kode_objek_pajak' => $kode,
            'dasar_pph' => $dasar,
            'tarif_pph' => $tarif,
            'diskon' => 0,
            'ongkir' => 0,
            'pph' => 0,
            'grand_total' => $dasar,
            'total_pembayaran' => 0,
            'sisa_tagihan' => $dasar,
            'note' => 'Invoice demo untuk bukti potong: ' . $uraian,
            'status' => FakturPembelian::UNPAID,
        ]);

        $faktur->receipts()->create(['lpb_id' => $bap->id, 'amount' => $dasar]);
        $bap->update(['no_invoice' => $faktur->no_invoice]);
        app(WmsAccountingService::class)->postInvoice($faktur);

        return $faktur;
    }

    private function bayar(User $finance, FakturPembelian $faktur, Carbon $tanggal, float $tunai, float $pph): PembayaranFaktur
    {
        Auth::setUser($finance);

        $bank = BaganAkun::where('kode_akun', '1102')->firstOrFail();

        $pembayaran = PembayaranFaktur::create([
            'payment_number' => app(DocumentNumberService::class)->financial('PY', $tanggal),
            'invoice_lpb_id' => $faktur->id,
            'supplier_id' => $faktur->kode_supplier,
            'tanggal_pembayaran' => $tanggal,
            'metode_pembayaran' => 'Transfer Bank BCA',
            'coa_kas_bank_id' => $bank->id,
            'jumlah_pembayaran' => $tunai,
            'potongan_pph' => $pph,
            'potongan_materai' => 0,
            'biaya_transfer_bank' => 0,
            'selisih_bayar' => 0,
            'kelebihan_pembayaran' => 0,
            'uang_muka_dipakai' => 0,
            'total_transaksi_pengurang_hutang' => $tunai + $pph,
            'keterangan' => 'Pembayaran demo bukti potong',
            'finance_user_id' => $finance->id,
        ]);

        $this->hitungUlangFaktur($faktur);
        app(WmsAccountingService::class)->postPayment($pembayaran);

        return $pembayaran;
    }

    private function batalkanPembayaran(User $finance, FakturPembelian $faktur, PembayaranFaktur $pembayaran): void
    {
        Auth::setUser($finance);

        app(WmsAccountingService::class)->reverseAutomaticJournal(
            'PELUNASAN_HUTANG',
            $pembayaran->id,
            'Pembatalan pembayaran invoice ' . $faktur->no_invoice
        );

        $pembayaran->update([
            'status' => PembayaranFaktur::VOID,
            'voided_by' => $finance->id,
            'voided_at' => now(),
            'void_reason' => 'Dibatalkan melalui sistem',
        ]);

        $this->hitungUlangFaktur($faktur);
    }

    private function hitungUlangFaktur(FakturPembelian $faktur): void
    {
        $total = (float) $faktur->payments()->sum('total_transaksi_pengurang_hutang');

        $faktur->update([
            'total_pembayaran' => $total,
            'sisa_tagihan' => max(0, (float) $faktur->grand_total - $total),
            'status' => FakturPembelian::paymentStatus((float) $faktur->grand_total, $total),
            'pph' => $faktur->payments()->sum('potongan_pph'),
        ]);
    }
}
