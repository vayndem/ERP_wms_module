<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAsetRequest;
use App\Http\Requests\StorePenyusutanAsetRequest;
use App\Http\Requests\StoreAutomaticDepreciationRequest;
use App\Http\Requests\StorePelepasanAsetRequest;
use App\Models\Aset;
use App\Models\Supplier;
use App\Models\KategoriAset;
use App\Models\BaganAkun;
use App\Services\AsetAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\DocumentNumberService;
use App\Exports\GenericTableExport;
use Maatwebsite\Excel\Facades\Excel;

class AsetController extends Controller
{
    public function __construct(private AsetAccountingService $accounting, private DocumentNumberService $numbers) {}
    public function index(Request $request)
    {
        $this->authorize('viewAny', Aset::class);
        $financial = $request->user()->can('viewFinancials', Aset::class);
        $statusFilter = (string) $request->input('status', Aset::ACTIVE);
        $query = Aset::with('category')->when($request->filled('q'), fn($q) => $q->where(fn($x) => $x->where('nomor_aset', 'like', '%' . $request->q . '%')->orWhere('name', 'like', '%' . $request->q . '%')->orWhere('location', 'like', '%' . $request->q . '%')))
            ->when($statusFilter !== '', fn($q) => $q->where('status', $statusFilter))->latest();
        $perPage = $this->perPage($request, $query->count());
        $assets = $query->paginate($perPage)->withQueryString();
        return view('aset.index', compact('assets', 'financial'));
    }

    private function perPage(Request $request, int $total): int
    {
        $value = strtolower((string) $request->input('per_page', 10));
        return $value === 'all' ? max(1, $total) : (in_array((int) $value, [10, 20, 50, 100], true) ? (int) $value : 10);
    }
    public function reportPdf(Request $request)
    {
        $this->authorize('viewAny', Aset::class);
        $financial = $request->user()->can('viewFinancials', Aset::class);
        $rows = Aset::with('category')->when($request->filled('q'), fn($q) => $q->where(fn($x) => $x->where('nomor_aset', 'like', '%' . $request->q . '%')->orWhere('name', 'like', '%' . $request->q . '%')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))->get()->map(function ($a) use ($financial) {
                $row = ['number' => $a->nomor_aset, 'name' => $a->name, 'category' => $a->category->name, 'location' => $a->location ?: '-', 'status' => $a->status];
                if ($financial) {
                    $row['cost'] = 'Rp ' . number_format($a->acquisition_cost, 0, ',', '.');
                    $row['book'] = 'Rp ' . number_format($a->book_value, 0, ',', '.');
                }
                return $row;
            });
        $columns = [['key' => 'number', 'label' => 'No Aset'], ['key' => 'name', 'label' => 'Nama'], ['key' => 'category', 'label' => 'Kategori'], ['key' => 'location', 'label' => 'Lokasi']];
        if ($financial) {
            $columns[] = ['key' => 'cost', 'label' => 'Harga Perolehan', 'align' => 'right'];
            $columns[] = ['key' => 'book', 'label' => 'Nilai Buku', 'align' => 'right'];
        }
        $columns[] = ['key' => 'status', 'label' => 'Status'];
        return Pdf::loadView('reports.table-pdf', compact('columns', 'rows') + ['title' => 'Daftar Aset Tetap', 'search' => $request->q, 'filters' => collect(['status' => $request->status]), 'generatedAt' => now()])->setPaper('a4', 'landscape')->stream('aset-tetap.pdf');
    }

    public function reportExcel(Request $request)
    {
        $this->authorize('viewAny', Aset::class);
        $financial = $request->user()->can('viewFinancials', Aset::class);
        $rows = Aset::with('category')->when($request->filled('q'), fn($q) => $q->where(fn($x) => $x->where('nomor_aset', 'like', '%' . $request->q . '%')->orWhere('name', 'like', '%' . $request->q . '%')))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))->get()->map(function ($a) use ($financial) {
                $row = ['number' => $a->nomor_aset, 'name' => $a->name, 'category' => $a->category->name, 'location' => $a->location ?: '-', 'status' => $a->status];
                if ($financial) {
                    $row['cost'] = (float) $a->acquisition_cost;
                    $row['book'] = (float) $a->book_value;
                }
                return $row;
            });
        $columns = [['key' => 'number', 'label' => 'No Aset'], ['key' => 'name', 'label' => 'Nama'], ['key' => 'category', 'label' => 'Kategori'], ['key' => 'location', 'label' => 'Lokasi']];
        if ($financial) {
            $columns[] = ['key' => 'cost', 'label' => 'Harga Perolehan'];
            $columns[] = ['key' => 'book', 'label' => 'Nilai Buku'];
        }
        $columns[] = ['key' => 'status', 'label' => 'Status'];

        return Excel::download(new GenericTableExport($columns, $rows), 'aset-tetap-' . now()->format('Ymd-His') . '.xlsx');
    }

    public function create()
    {
        $this->authorize('create', Aset::class);
        return view('aset.form', $this->formData() + [
            'documentNumber' => $this->numbers->preview(DocumentNumberService::FINANCIAL, 'AS'),
        ]);
    }
    public function store(StoreAsetRequest $request)
    {
        $data = $request->validated();
        $asset = DB::transaction(function () use ($data) {
            $cost = (float)$data['acquisition_cost'];
            $opening = (float)($data['opening_accumulated_depreciation'] ?? 0);
            $data['nomor_aset'] = $this->numbers->financial('AS');
            $asset = Aset::create($data + [
                'accumulated_depreciation' => $opening,
                'book_value' => $cost - $opening,
                'created_by' => Auth::id(),
                'status' => Aset::ACTIVE,
            ]);
            $this->accounting->postAcquisition($asset);
            return $asset;
        });
        return redirect()->route('aset.show', $asset)->with('success', 'Aset dan jurnal perolehan berhasil dibuat.');
    }
    public function show(Request $request, Aset $aset)
    {
        $this->authorize('view', $aset);
        $aset->load(['category', 'acquisitionCreditAccount', 'depreciations.journal', 'disposal.journal', 'lampiran.user']);
        $financial = $request->user()->can('viewFinancials', Aset::class);
        $cashBanks = BaganAkun::where('is_active', true)->where('is_cash_bank', true)->orderBy('kode_akun')->get();
        return view('aset.show', [
            'asset' => $aset,
            'financial' => $financial,
            'cashBanks' => $cashBanks,
            'suppliers' => Supplier::orderBy('nama')->get(),
        ]);
    }
    public function edit(Aset $aset)
    {
        $this->authorize('update', $aset);
        return view('aset.form', $this->formData($aset) + ['asset' => $aset]);
    }
    public function update(StoreAsetRequest $request, Aset $aset)
    {
        if ($aset->depreciations()->exists()) abort(422, 'Aset yang sudah disusutkan tidak dapat mengubah data perolehan.');
        DB::transaction(function () use ($request, $aset) {
            $data = $request->validated();
            $cost = (float)$data['acquisition_cost'];
            $opening = (float)($data['opening_accumulated_depreciation'] ?? 0);
            $aset->update($data + ['accumulated_depreciation' => $opening, 'book_value' => $cost - $opening]);
            $this->accounting->postAcquisition($aset->fresh());
        });
        return redirect()->route('aset.show', $aset)->with('success', 'Aset dan jurnal perolehan diperbarui.');
    }
    public function depreciate(StorePenyusutanAsetRequest $request, Aset $aset)
    {
        $this->accounting->depreciate($aset, $request->validated(), $request->user()->id);
        return back()->with('success', 'Penyusutan berhasil diposting.');
    }
    public function dispose(StorePelepasanAsetRequest $request, Aset $aset)
    {
        $this->accounting->dispose($aset, $request->validated());
        return back()->with('success', 'Pelepasan aset berhasil diposting.');
    }
    public function runAutomaticDepreciation(StoreAutomaticDepreciationRequest $request)
    {
        $result = $this->accounting->runAutomaticDepreciation($request->validated('posting_date'), $request->validated('period_label'));
        $postedCount = count($result['posted']);
        $skippedCount = count($result['skipped']);
        $failedCount = count($result['failed']);
        return response()->json([
            'success' => true,
            'message' => "Penyusutan otomatis selesai: {$postedCount} aset diposting, {$skippedCount} dilewati, {$failedCount} gagal.",
            'data' => $result,
        ]);
    }
    private function formData(?Aset $asset = null): array
    {
        return [
            'categories' => KategoriAset::where('is_active', true)->orderBy('name')->get(),
            'accounts' => BaganAkun::where('is_active', true)->where('is_postable', true)->orderBy('kode_akun')->get()
        ];
    }
}
