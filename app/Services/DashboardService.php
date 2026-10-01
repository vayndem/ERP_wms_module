<?php

namespace App\Services;

use App\Models\Aset;
use App\Models\Bahan;
use App\Models\FakturPembelian;
use App\Models\Jurnal;
use App\Models\LayerPersediaan;
use App\Models\MaterialRequest;
use App\Models\PemakaianBarang;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanJasa;
use App\Models\PesananPembelian;
use App\Models\ReturPembelian;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public const AMBANG_KEDALUWARSA_HARI = PengingatService::AMBANG_KEDALUWARSA_HARI;
    public const AMBANG_JATUH_TEMPO_HARI = PengingatService::AMBANG_JATUH_TEMPO_HARI;
    private const TTL_REKONSILIASI = 300;

    public function __construct(private PengingatService $pengingatan) {}

    public function untuk(User $user): array
    {
        return match (true) {
            $user->isSuperAdmin() => ['view' => 'dashboard', 'data' => $this->superAdmin()],
            $user->isPurchasing() => ['view' => 'purchasing.dashboard', 'data' => $this->purchasing()],
            $user->isFinance() => ['view' => 'finance.payment-dashboard', 'data' => $this->finance()],
            $user->isWarehouse() => ['view' => 'gudang.dashboard', 'data' => $this->gudang($user)],
            $user->isProduction() => ['view' => 'produksi.dashboard', 'data' => $this->produksi($user)],
            $user->isAccounting() || $user->isAccountingManager() => ['view' => 'accounting.dashboard', 'data' => $this->akuntansi($user)],
            default => ['view' => 'dashboard', 'data' => []],
        };
    }

    private function progres(string $label, float $selesai, float $total, string $catatan = ''): array
    {
        $persen = $total > 0 ? min(100, round($selesai / $total * 100, 1)) : 0.0;

        return [
            'label' => $label,
            'selesai' => $selesai,
            'total' => $total,
            'persen' => $persen,
            'catatan' => $catatan,
        ];
    }

    private function hitungKedaluwarsa(?array $warehouseIds = null): array
    {
        $dasar = fn () => LayerPersediaan::where('remaining_quantity', '>', 0)
            ->where('stock_status', 'AVAILABLE')
            ->when($warehouseIds !== null, fn ($query) => $query->whereIn('gudang_id', $warehouseIds));

        return [
            'expired' => $dasar()->whereHas('lot', fn ($lot) => $lot
                ->whereNotNull('expires_at')->whereDate('expires_at', '<', today()))->count(),
            'near_expiry' => $dasar()->whereHas('lot', fn ($lot) => $lot
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', today())
                ->whereDate('expires_at', '<=', today()->addDays(self::AMBANG_KEDALUWARSA_HARI)))->count(),
        ];
    }

    private function uangMukaTersedia(): int
    {
        return DB::table('wms_pembayaran_faktur as p')
            ->leftJoin('wms_pembayaran_faktur as c', 'c.uang_muka_sumber_payment_id', '=', 'p.id')
            ->where('p.status', PembayaranFaktur::POSTED)
            ->where('p.jenis_selisih', 'UANG_MUKA_SUPPLIER')
            ->groupBy('p.id', 'p.kelebihan_pembayaran')
            ->havingRaw('p.kelebihan_pembayaran - COALESCE(SUM(c.uang_muka_dipakai), 0) > 0.01')
            ->select('p.id')
            ->get()->count();
    }

    private function masalahRekonsiliasi(): int
    {
        return (int) Cache::remember('dashboard.rekonsiliasi.invalid', self::TTL_REKONSILIASI, fn () => app(AccountingReconciliationService::class)->checks()->sum('invalid'));
    }

    private function superAdmin(): array
    {
        $kedaluwarsa = $this->hitungKedaluwarsa();
        $draftJurnals = Jurnal::where('status', 'DRAFT')->count();
        $postedJurnals = Jurnal::where('status', 'POSTED')->count();
        $masalahRekonsiliasi = $this->masalahRekonsiliasi();
        $putawayTertunda = PenerimaanBarang::where('document_type', 'GOODS')->where('receiving_status', '!=', 'PUTAWAY')->count();
        $totalPenerimaan = PenerimaanBarang::where('document_type', 'GOODS')->count();

        $poLines = DB::table('wms_pesanan_pembelian_detail as d')
            ->join('wms_pesanan_pembelian as p', 'p.no_po', '=', 'd.no_po')
            ->where('p.status', PesananPembelian::OPEN)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN d.diterima >= d.jumlah THEN 1 ELSE 0 END) as selesai')
            ->first();

        return [
            'tasks' => collect([
                ['label' => 'Request Menunggu Approval', 'count' => MaterialRequest::where('status', MaterialRequest::PENDING)->count(), 'url' => route('request.index'), 'icon' => 'fa-file-circle-question', 'tone' => 'warning'],
                ['label' => 'PO Terbuka', 'count' => PesananPembelian::where('status', PesananPembelian::OPEN)->count(), 'url' => route('pembelian.index'), 'icon' => 'fa-file-invoice', 'tone' => 'info'],
                ['label' => 'Penerimaan Belum Ditagih', 'count' => PenerimaanBarang::whereNull('no_invoice')->count(), 'url' => route('penerimaan-barang.index'), 'icon' => 'fa-receipt', 'tone' => 'neutral'],
                ['label' => 'Invoice Menunggu Approval', 'count' => FakturPembelian::where('status', FakturPembelian::PENDING_APPROVAL)->count(), 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'info'],
                ['label' => 'Invoice Jatuh Tempo', 'count' => FakturPembelian::whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])->where('sisa_tagihan', '>', 0)->whereDate('tgl_deadline_pembayaran', '<', today())->count(), 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-clock', 'tone' => 'error'],
                ['label' => 'Stok Kedaluwarsa', 'count' => $kedaluwarsa['expired'], 'url' => route('wms-control.index'), 'icon' => 'fa-circle-exclamation', 'tone' => 'error'],
                ['label' => 'Mendekati Kedaluwarsa (30 Hari)', 'count' => $kedaluwarsa['near_expiry'], 'url' => route('wms-control.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'warning'],
                ['label' => 'Penerimaan Menunggu Putaway', 'count' => $putawayTertunda, 'url' => route('wms-control.index'), 'icon' => 'fa-dolly', 'tone' => 'warning'],
                ['label' => 'Transfer Gudang Berjalan', 'count' => DB::table('transfer_gudangs')->whereIn('status', ['DRAFT', 'DIAJUKAN', 'DIKIRIM'])->count(), 'url' => route('transfer-gudangs.index'), 'icon' => 'fa-truck-fast', 'tone' => 'info'],
                ['label' => 'Stock Opname Terbuka', 'count' => StockOpname::whereIn('status', [StockOpname::DRAFT, StockOpname::REJECTED, StockOpname::SUBMITTED, StockOpname::APPROVED])->count(), 'url' => route('stock-opname.index'), 'icon' => 'fa-clipboard-list', 'tone' => 'warning'],
                ['label' => 'Jurnal Draft', 'count' => $draftJurnals, 'url' => route('jurnal.index'), 'icon' => 'fa-book', 'tone' => 'warning'],
                ['label' => 'Masalah Rekonsiliasi', 'count' => $masalahRekonsiliasi, 'url' => route('reconciliation.index'), 'icon' => 'fa-scale-unbalanced', 'tone' => $masalahRekonsiliasi > 0 ? 'error' : 'success'],
            ]),
            'reminders' => $this->pengingatan->invoiceJatuhTempo()
                ->merge($this->pengingatan->piutangJatuhTempo())
                ->merge($this->pengingatan->lotSegeraKedaluwarsa())
                ->merge($this->pengingatan->transferMenggantung())
                ->merge($this->pengingatan->crossDockBasi())
                ->merge($this->pengingatan->subkontrakBelumKembali())
                ->sortBy('hari')->values(),
            'progress' => [
                $this->progres('Realisasi Baris PO Terbuka', (float) ($poLines->selesai ?? 0), (float) ($poLines->total ?? 0), 'Baris PO yang sudah diterima penuh'),
                $this->progres('Penerimaan Sudah Putaway', (float) max($totalPenerimaan - $putawayTertunda, 0), (float) $totalPenerimaan, 'Dokumen penerimaan yang sudah ditempatkan ke bin'),
                $this->progres('Jurnal Sudah Diposting', (float) $postedJurnals, (float) ($postedJurnals + $draftJurnals), 'Jurnal POSTED dibanding draft'),
            ],
        ];
    }

    private function purchasing(): array
    {
        $metrics = [
            'pending_requests' => MaterialRequest::where('status', MaterialRequest::PENDING)->count(),
            'approved_unrealized' => DB::table('request_details')
                ->join('requests', 'requests.id', '=', 'request_details.request_id')
                ->where('requests.status', MaterialRequest::APPROVED)
                ->whereRaw('COALESCE(request_details.realisasi, 0) < COALESCE(request_details.jumlah_acc, request_details.jumlah_minta)')
                ->count(),
            'open_purchase_orders' => PesananPembelian::where('status', PesananPembelian::OPEN)->count(),
            'awaiting_receipt' => PesananPembelian::where('status', PesananPembelian::OPEN)
                ->whereHas('details', fn ($query) => $query->whereColumn('diterima', '<', 'jumlah'))
                ->count(),
            'unbilled_receipts' => PenerimaanBarang::whereNull('no_invoice')->count(),
            'unpaid_invoices' => FakturPembelian::where('status', '!=', FakturPembelian::VOID)->where('sisa_tagihan', '>', 0)->count(),
            'overdue_invoices' => FakturPembelian::where('status', '!=', FakturPembelian::VOID)->where('sisa_tagihan', '>', 0)
                ->whereDate('tgl_deadline_pembayaran', '<', today())->count(),
            'stock_attention' => Bahan::whereColumn('stok_onhand', '<', 'planning')->count(),
        ];

        $poLines = DB::table('wms_pesanan_pembelian_detail as d')
            ->join('wms_pesanan_pembelian as p', 'p.no_po', '=', 'd.no_po')
            ->where('p.status', PesananPembelian::OPEN)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN d.diterima >= d.jumlah THEN 1 ELSE 0 END) as selesai')
            ->first();

        $requestLines = DB::table('request_details as rd')
            ->join('requests as r', 'r.id', '=', 'rd.request_id')
            ->where('r.status', MaterialRequest::APPROVED)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN COALESCE(rd.realisasi,0) >= COALESCE(rd.jumlah_acc, rd.jumlah_minta) THEN 1 ELSE 0 END) as selesai')
            ->first();

        $pendingApprovalInvoices = FakturPembelian::where('status', FakturPembelian::PENDING_APPROVAL)->count();
        $openServiceOrders = PesananPembelian::where('document_type', 'SERVICE')->where('status', PesananPembelian::OPEN)->count();
        $unbilledServiceReceipts = PenerimaanJasa::whereNull('no_invoice')->count();
        $activeReturs = ReturPembelian::where('status', ReturPembelian::POSTED)->count();

        return [
            'metrics' => $metrics,
            'pendingRequests' => MaterialRequest::withCount('details')->where('status', MaterialRequest::PENDING)->latest()->limit(5)->get(),
            'openPurchaseOrders' => PesananPembelian::with('supplier')
                ->withSum('details as ordered_quantity', 'jumlah')
                ->withSum('details as received_quantity', 'diterima')
                ->where('status', PesananPembelian::OPEN)->latest('tanggal')->limit(5)->get(),
            'unbilledReceipts' => PenerimaanBarang::with('pembelian.supplier')->whereNull('no_invoice')->latest('tanggal')->limit(5)->get(),
            'dueInvoices' => FakturPembelian::with('supplier')->where('status', '!=', FakturPembelian::VOID)
                ->where('sisa_tagihan', '>', 0)
                ->orderByRaw('tgl_deadline_pembayaran IS NULL')
                ->orderBy('tgl_deadline_pembayaran')->limit(5)->get(),
            'reminders' => $this->pengingatan->invoiceJatuhTempo(),
            'progress' => [
                $this->progres('Realisasi Baris PO Terbuka', (float) ($poLines->selesai ?? 0), (float) ($poLines->total ?? 0), 'Baris PO yang sudah diterima penuh'),
                $this->progres('Request Disetujui Sudah Di-PO', (float) ($requestLines->selesai ?? 0), (float) ($requestLines->total ?? 0), 'Baris request approved yang kuotanya sudah penuh'),
            ],
            'tasks' => collect([
                ['label' => 'Request Menunggu Approval', 'count' => $metrics['pending_requests'], 'url' => route('request.index'), 'icon' => 'fa-file-circle-question', 'tone' => 'warning'],
                ['label' => 'Request Disetujui Belum Di-PO-kan', 'count' => $metrics['approved_unrealized'], 'url' => route('request.index'), 'icon' => 'fa-cart-plus', 'tone' => 'info'],
                ['label' => 'PO Terbuka', 'count' => $metrics['open_purchase_orders'], 'url' => route('pembelian.index'), 'icon' => 'fa-file-invoice', 'tone' => 'info'],
                ['label' => 'PO Menunggu Penerimaan', 'count' => $metrics['awaiting_receipt'], 'url' => route('pembelian.index'), 'icon' => 'fa-truck', 'tone' => 'warning'],
                ['label' => 'Penerimaan Barang Belum Ditagih', 'count' => $metrics['unbilled_receipts'], 'url' => route('penerimaan-barang.index'), 'icon' => 'fa-receipt', 'tone' => 'neutral'],
                ['label' => 'Invoice Menunggu Persetujuan Manager', 'count' => $pendingApprovalInvoices, 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'info'],
                ['label' => 'Invoice Belum Lunas', 'count' => $metrics['unpaid_invoices'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-file-invoice-dollar', 'tone' => 'neutral'],
                ['label' => 'Invoice Jatuh Tempo', 'count' => $metrics['overdue_invoices'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-clock', 'tone' => 'error'],
                ['label' => 'Bahan Di Bawah Planning', 'count' => $metrics['stock_attention'], 'url' => route('bahan.index'), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning'],
                ['label' => 'PO Jasa Terbuka', 'count' => $openServiceOrders, 'url' => route('pesanan-jasa.index'), 'icon' => 'fa-handshake', 'tone' => 'info'],
                ['label' => 'Penerimaan Jasa Belum Ditagih', 'count' => $unbilledServiceReceipts, 'url' => route('penerimaan-jasa.index'), 'icon' => 'fa-receipt', 'tone' => 'neutral'],
                ['label' => 'Retur Pembelian Aktif', 'count' => $activeReturs, 'url' => route('retur-pembelian.index'), 'icon' => 'fa-rotate-left', 'tone' => 'neutral'],
            ]),
        ];
    }

    private function finance(): array
    {
        $aktif = fn () => FakturPembelian::query()
            ->whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])
            ->where('sisa_tagihan', '>', 0);

        $awalBulan = now()->startOfMonth()->toDateString();
        $akhirBulan = now()->endOfMonth()->toDateString();
        $bulanIni = DB::table('wms_pembayaran_faktur')
            ->where('status', PembayaranFaktur::POSTED)
            ->whereBetween('tanggal_pembayaran', [$awalBulan, $akhirBulan])
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(jumlah_pembayaran),0) as nilai')
            ->first();

        $metrics = [
            'unpaid_count' => $aktif()->count(),
            'outstanding_value' => (float) $aktif()->sum('sisa_tagihan'),
            'overdue_count' => $aktif()->whereDate('tgl_deadline_pembayaran', '<', today())->count(),
            'due_soon_count' => $aktif()->whereBetween('tgl_deadline_pembayaran', [today(), today()->addDays(7)])->count(),
            'paid_this_month' => (float) ($bulanIni->nilai ?? 0),
            'payments_this_month' => (int) ($bulanIni->jumlah ?? 0),
        ];

        $jatuhTempoNilai = (float) $aktif()->whereDate('tgl_deadline_pembayaran', '<', today())->sum('sisa_tagihan');

        return [
            'metrics' => $metrics,
            'priorityInvoices' => FakturPembelian::with('supplier')
                ->whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])
                ->where('sisa_tagihan', '>', 0)
                ->orderByRaw('tgl_deadline_pembayaran IS NULL')
                ->orderBy('tgl_deadline_pembayaran')->limit(8)->get(),
            'recentPayments' => PembayaranFaktur::with(['invoice.supplier', 'coaKasBank'])
                ->where('status', PembayaranFaktur::POSTED)->latest('tanggal_pembayaran')->latest('id')->limit(8)->get(),
            'reminders' => $this->pengingatan->invoiceJatuhTempo(8)
                ->merge($this->pengingatan->piutangJatuhTempo(8))
                ->sortBy('hari')->values(),
            'progress' => [
                $this->progres('Hutang Belum Jatuh Tempo', max($metrics['outstanding_value'] - $jatuhTempoNilai, 0), max($metrics['outstanding_value'], 0.0001), 'Makin penuh makin sehat: porsi hutang yang belum lewat tanggal'),
                $this->progres('Invoice Terbayar Bulan Ini', (float) $metrics['payments_this_month'], (float) ($metrics['payments_this_month'] + $metrics['unpaid_count']), 'Pembayaran bulan ini dibanding sisa tagihan terbuka'),
            ],
            'tasks' => collect([
                ['label' => 'Invoice Siap Dibayar', 'count' => $metrics['unpaid_count'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-file-invoice-dollar', 'tone' => 'neutral'],
                ['label' => 'Invoice Jatuh Tempo', 'count' => $metrics['overdue_count'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-clock', 'tone' => 'error'],
                ['label' => 'Jatuh Tempo 7 Hari Ke Depan', 'count' => $metrics['due_soon_count'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-calendar-day', 'tone' => 'warning'],
                ['label' => 'Invoice Menunggu Persetujuan Manager', 'count' => FakturPembelian::where('status', FakturPembelian::PENDING_APPROVAL)->count(), 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'info'],
                ['label' => 'Uang Muka Supplier Tersedia', 'count' => $this->uangMukaTersedia(), 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-piggy-bank', 'tone' => 'info'],
                ['label' => 'Pembayaran Bulan Ini', 'count' => $metrics['payments_this_month'], 'url' => route('faktur-pembelian.index'), 'icon' => 'fa-money-bill-transfer', 'tone' => 'success'],
            ]),
        ];
    }

    private function gudang(User $user): array
    {
        $stockAttention = Bahan::whereColumn('stok_onhand', '<=', 'planning')->count();
        $receiptsToday = PenerimaanBarang::whereDate('tanggal', today())->count();
        $issuesToday = PemakaianBarang::whereDate('tanggal', today())->count();
        $statusOpnameTerbuka = [StockOpname::DRAFT, StockOpname::REJECTED, StockOpname::SUBMITTED, StockOpname::APPROVED];
        $openOpnames = StockOpname::whereIn('status', $statusOpnameTerbuka)->count();
        $serviceBapsToday = PenerimaanJasa::whereDate('tanggal', today())->count();
        $transfersInProgress = DB::table('transfer_gudangs')->whereIn('status', ['DRAFT', 'DIAJUKAN', 'DIKIRIM'])->count();
        $myPendingRequests = MaterialRequest::where('requested_by', $user->id)->where('status', MaterialRequest::PENDING)->count();
        $expiry = $this->hitungKedaluwarsa();

        $putawayTertunda = PenerimaanBarang::where('document_type', 'GOODS')->where('receiving_status', '!=', 'PUTAWAY')->count();
        $totalPenerimaan = PenerimaanBarang::where('document_type', 'GOODS')->count();
        $opnameSelesai = StockOpname::where('status', StockOpname::POSTED)->count();

        return [
            'tasks' => collect([
                ['label' => 'Stok Kedaluwarsa', 'count' => $expiry['expired'], 'url' => route('wms-control.index'), 'icon' => 'fa-circle-exclamation', 'tone' => 'error'],
                ['label' => 'Mendekati Kedaluwarsa (30 Hari)', 'count' => $expiry['near_expiry'], 'url' => route('wms-control.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'warning'],
                ['label' => 'Penerimaan Menunggu Putaway', 'count' => $putawayTertunda, 'url' => route('wms-control.index'), 'icon' => 'fa-dolly', 'tone' => 'warning'],
                ['label' => 'Bahan Perlu Perhatian Stok', 'count' => $stockAttention, 'url' => route('bahan.index'), 'icon' => 'fa-triangle-exclamation', 'tone' => 'warning'],
                ['label' => 'Penerimaan Hari Ini', 'count' => $receiptsToday, 'url' => route('penerimaan-barang.index'), 'icon' => 'fa-truck-ramp-box', 'tone' => 'info'],
                ['label' => 'Pemakaian Hari Ini', 'count' => $issuesToday, 'url' => route('pemakaian-barang.index'), 'icon' => 'fa-boxes-packing', 'tone' => 'info'],
                ['label' => 'Stock Opname Terbuka', 'count' => $openOpnames, 'url' => route('stock-opname.index'), 'icon' => 'fa-clipboard-list', 'tone' => 'warning'],
                ['label' => 'BAP Jasa Hari Ini', 'count' => $serviceBapsToday, 'url' => route('penerimaan-jasa.index'), 'icon' => 'fa-handshake', 'tone' => 'neutral'],
                ['label' => 'Transfer Gudang Berjalan', 'count' => $transfersInProgress, 'url' => route('transfer-gudangs.index'), 'icon' => 'fa-truck-fast', 'tone' => 'info'],
                ['label' => 'Request Saya Menunggu Approval', 'count' => $myPendingRequests, 'url' => route('request.index'), 'icon' => 'fa-file-circle-question', 'tone' => 'neutral'],
            ]),
            'reminders' => $this->pengingatan->lotSegeraKedaluwarsa()
                ->merge($this->pengingatan->transferMenggantung())
                ->merge($this->pengingatan->crossDockBasi())
                ->merge($this->pengingatan->subkontrakBelumKembali())
                ->sortBy('hari')->values(),
            'progress' => [
                $this->progres('Penerimaan Sudah Putaway', (float) max($totalPenerimaan - $putawayTertunda, 0), (float) $totalPenerimaan, 'Dokumen penerimaan barang yang sudah ditempatkan ke bin'),
                $this->progres('Stock Opname Selesai', (float) $opnameSelesai, (float) ($opnameSelesai + $openOpnames), 'Opname berstatus POSTED dibanding yang masih berjalan'),
            ],
            'warehouseMetrics' => [
                'total_materials' => Bahan::count(),
                'stock_attention' => $stockAttention,
                'receipts_today' => $receiptsToday,
                'issues_today' => $issuesToday,
                'open_opnames' => $openOpnames,
                'service_baps_today' => $serviceBapsToday,
            ],
            'recentReceipts' => PenerimaanBarang::with('pembelian.supplier')->latest('tanggal')->latest('id')->limit(5)->get(),
            'recentIssues' => PemakaianBarang::with('barang')->latest('tanggal')->latest('id')->limit(5)->get(),
        ];
    }

    private function produksi(User $user): array
    {
        $warehouseIds = $user->accessibleGudangIds('npk');
        $opnameIds = $user->accessibleGudangIds('opname');
        $issuesToday = PemakaianBarang::whereIn('id_gudang_asal', $warehouseIds)->whereDate('tanggal', today())->count();
        $transfersInProgress = DB::table('transfer_gudangs')
            ->where(fn ($query) => $query->whereIn('gudang_asal_id', $warehouseIds)->orWhereIn('gudang_tujuan_id', $warehouseIds))
            ->whereIn('status', ['DRAFT', 'DIAJUKAN'])->count();
        $statusOpnameTerbuka = [StockOpname::DRAFT, StockOpname::REJECTED, StockOpname::SUBMITTED, StockOpname::APPROVED];
        $openOpnames = StockOpname::whereIn('warehouse_id', $opnameIds)->whereIn('status', $statusOpnameTerbuka)->count();
        $opnameSelesai = StockOpname::whereIn('warehouse_id', $opnameIds)->where('status', StockOpname::POSTED)->count();
        $myPendingRequests = MaterialRequest::where('requested_by', $user->id)->where('status', MaterialRequest::PENDING)->count();
        $expiry = $this->hitungKedaluwarsa($warehouseIds);

        return [
            'tasks' => collect([
                ['label' => 'Stok Kedaluwarsa', 'count' => $expiry['expired'], 'url' => route('wms-control.index'), 'icon' => 'fa-circle-exclamation', 'tone' => 'error'],
                ['label' => 'Mendekati Kedaluwarsa (30 Hari)', 'count' => $expiry['near_expiry'], 'url' => route('wms-control.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'warning'],
                ['label' => 'Pemakaian Hari Ini', 'count' => $issuesToday, 'url' => route('pemakaian-barang.index'), 'icon' => 'fa-boxes-packing', 'tone' => 'info'],
                ['label' => 'Transfer Sedang Berjalan', 'count' => $transfersInProgress, 'url' => route('transfer-gudangs.index'), 'icon' => 'fa-truck-fast', 'tone' => 'info'],
                ['label' => 'Stock Opname Terbuka', 'count' => $openOpnames, 'url' => route('stock-opname.index'), 'icon' => 'fa-clipboard-list', 'tone' => 'warning'],
                ['label' => 'Request Saya Menunggu Approval', 'count' => $myPendingRequests, 'url' => route('request.index'), 'icon' => 'fa-file-circle-question', 'tone' => 'neutral'],
            ]),
            'reminders' => $this->pengingatan->lotSegeraKedaluwarsa($warehouseIds)
                ->merge($this->pengingatan->transferMenggantung($warehouseIds))
                ->merge($this->pengingatan->crossDockBasi($warehouseIds))
                ->merge($this->pengingatan->perintahKerjaMandek())
                ->sortBy('hari')->values(),
            'progress' => [
                $this->progres('Stock Opname Selesai', (float) $opnameSelesai, (float) ($opnameSelesai + $openOpnames), 'Opname gudang yang di-assign ke Anda'),
            ],
            'productionMetrics' => [
                'assigned_warehouses' => count($user->accessibleGudangIds()),
                'issues_today' => $issuesToday,
                'transfers_in_progress' => $transfersInProgress,
                'open_opnames' => $openOpnames,
            ],
            'recentIssues' => PemakaianBarang::with(['barang', 'gudangAsal'])
                ->whereIn('id_gudang_asal', $warehouseIds)->latest('tanggal')->latest('id')->limit(5)->get(),
            'recentTransfers' => DB::table('transfer_gudangs as tg')
                ->leftJoin('gudangs as asal', 'asal.id', '=', 'tg.gudang_asal_id')
                ->leftJoin('gudangs as tujuan', 'tujuan.id', '=', 'tg.gudang_tujuan_id')
                ->where(fn ($query) => $query->whereIn('tg.gudang_asal_id', $warehouseIds)->orWhereIn('tg.gudang_tujuan_id', $warehouseIds))
                ->orderByDesc('tg.tanggal')->orderByDesc('tg.id')->limit(5)
                ->get(['tg.nomor_transfer', 'tg.tanggal', 'tg.status', 'asal.nama as asal_nama', 'tujuan.nama as tujuan_nama']),
        ];
    }

    private function akuntansi(User $user): array
    {
        $pendingApprovalInvoices = FakturPembelian::where('status', FakturPembelian::PENDING_APPROVAL)->count();
        $draftJurnals = Jurnal::where('status', 'DRAFT')->count();
        $postedJurnals = Jurnal::where('status', 'POSTED')->count();
        $reconciliationIssues = $this->masalahRekonsiliasi();
        $opnameAwaitingAccounting = StockOpname::where('status', StockOpname::SUBMITTED)->count();
        $activeReturs = ReturPembelian::where('status', ReturPembelian::POSTED)->count();
        $pendingRequests = MaterialRequest::where('status', MaterialRequest::PENDING)->count();

        $asetAktif = Aset::where('status', Aset::ACTIVE)
            ->whereIn('depreciation_method', [Aset::STRAIGHT_LINE, Aset::DECLINING_BALANCE]);
        $assetsDueDepreciation = (clone $asetAktif)->whereRaw('book_value > residual_value')->count();
        $asetBerkelompokFiskal = (clone $asetAktif)->whereNotNull('kelompok_fiskal')->count();
        $totalAsetAktif = (clone $asetAktif)->count();

        $invoicePendingLama = FakturPembelian::with('supplier')
            ->where('status', FakturPembelian::PENDING_APPROVAL)
            ->orderBy('tanggal')->limit(6)->get();

        return [
            'tasks' => collect(array_filter([
                $user->isAccountingManager() ? [
                    'label' => 'Invoice Menunggu Persetujuan Saya', 'count' => $pendingApprovalInvoices,
                    'url' => route('faktur-pembelian.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'warning',
                ] : [
                    'label' => 'Invoice Menunggu Persetujuan Manager', 'count' => $pendingApprovalInvoices,
                    'url' => route('faktur-pembelian.index'), 'icon' => 'fa-hourglass-half', 'tone' => 'info',
                ],
                ['label' => 'Jurnal Draft Belum Diposting', 'count' => $draftJurnals, 'url' => route('jurnal.index'), 'icon' => 'fa-book', 'tone' => 'warning'],
                ['label' => 'Masalah Rekonsiliasi', 'count' => $reconciliationIssues, 'url' => route('reconciliation.index'), 'icon' => 'fa-scale-unbalanced', 'tone' => $reconciliationIssues > 0 ? 'error' : 'success'],
                ['label' => 'Stock Opname Menunggu Konfirmasi', 'count' => $opnameAwaitingAccounting, 'url' => route('stock-opname.index'), 'icon' => 'fa-clipboard-check', 'tone' => 'warning'],
                ['label' => 'Retur Pembelian Aktif', 'count' => $activeReturs, 'url' => route('retur-pembelian.index'), 'icon' => 'fa-rotate-left', 'tone' => 'neutral'],
                ['label' => 'Request Menunggu Approval', 'count' => $pendingRequests, 'url' => route('request.index'), 'icon' => 'fa-file-circle-question', 'tone' => 'neutral'],
                ['label' => 'Aset Belum Disusutkan Periode Ini', 'count' => $assetsDueDepreciation, 'url' => route('aset.index'), 'icon' => 'fa-building-shield', 'tone' => 'neutral'],
            ])),
            'reminders' => $this->pengingatan->urutkan($invoicePendingLama->map(fn ($inv) => $this->pengingatan->susun(
                'Invoice menunggu approval',
                $inv->no_invoice . ' · ' . ($inv->supplier->nama ?? '-'),
                $inv->tanggal,
                route('faktur-pembelian.index'),
                (float) $inv->grand_total,
            ))->all())->merge($this->pengingatan->invoiceJatuhTempo())->sortBy('hari')->values(),
            'progress' => [
                $this->progres('Jurnal Sudah Diposting', (float) $postedJurnals, (float) ($postedJurnals + $draftJurnals), 'Jurnal POSTED dibanding yang masih draft'),
                $this->progres('Aset Punya Kelompok Fiskal', (float) $asetBerkelompokFiskal, (float) $totalAsetAktif, 'Tanpa kelompok fiskal, pajak tangguhan aset itu tidak bisa dihitung'),
            ],
        ];
    }
}
