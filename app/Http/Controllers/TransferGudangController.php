<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferGudangRequest;
use App\Http\Requests\UpdateTransferGudangRequest;
use App\Http\Requests\ReceiveTransferGudangRequest;
use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\TransferGudang;
use App\Services\DocumentNumberService;
use App\Services\TransferGudangService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransferGudangController extends Controller
{
    public function __construct(private DocumentNumberService $numbers, private TransferGudangService $service) {}
    public function index()
    {
        $this->authorize('viewAny', TransferGudang::class);
        $user = request()->user();
        $warehouseIds = $user->accessibleGudangIds('transfer');
        $rows = TransferGudang::with(['gudangAsal', 'gudangTujuan'])
            ->when($user->isProduction(), function ($query) use ($warehouseIds) {
                $query->where(function ($builder) use ($warehouseIds) {
                    $builder->whereIn('gudang_asal_id', $warehouseIds)
                        ->orWhereIn('gudang_tujuan_id', $warehouseIds);
                });
            })
            ->latest('tanggal')
            ->paginate(50);

        return view('transfer_gudangs.index', ['rows' => $rows]);
    }
    public function create()
    {
        $this->authorize('create', TransferGudang::class);
        return view('transfer_gudangs.form', $this->formData() + ['transfer' => new TransferGudang(['nomor_transfer' => $this->numbers->preview(DocumentNumberService::INTERNAL, 'TRF', 'GDG'), 'tanggal' => today()])]);
    }
    public function store(StoreTransferGudangRequest $r)
    {
        $data = $r->validated();
        abort_unless($r->user()->canAccessGudang((int) $data['gudang_asal_id'], 'transfer'), 403);
        abort_unless($r->user()->canAccessGudang((int) $data['gudang_tujuan_id'], 'transfer'), 403);

        $m = DB::transaction(function () use ($r) {
            $d = $r->validated();
            $details = $d['details'];
            unset($d['details']);
            $d['nomor_transfer'] = $this->numbers->internal('TRF', 'GDG');
            $d += ['status' => TransferGudang::DRAFT, 'dibuat_oleh' => Auth::id()];
            $m = TransferGudang::create($d);
            $m->details()->createMany($details);
            return $m;
        });
        return redirect()->route('transfer-gudangs.show', $m)->with('success', 'Draft transfer dibuat.');
    }
    public function show(TransferGudang $transferGudang)
    {
        $this->authorize('view', $transferGudang);
        return view('transfer_gudangs.show', ['transfer' => $transferGudang->load(['gudangAsal', 'gudangTujuan', 'details.bahan', 'details.alokasi'])]);
    }
    public function edit(TransferGudang $transferGudang)
    {
        $this->authorize('update', $transferGudang);
        return view('transfer_gudangs.form', $this->formData() + ['transfer' => $transferGudang->load('details')]);
    }
    public function update(UpdateTransferGudangRequest $r, TransferGudang $transferGudang)
    {
        $data = $r->validated();
        abort_unless($r->user()->canAccessGudang((int) $data['gudang_asal_id'], 'transfer'), 403);
        abort_unless($r->user()->canAccessGudang((int) $data['gudang_tujuan_id'], 'transfer'), 403);

        DB::transaction(function () use ($r, $transferGudang) {
            $d = $r->validated();
            $details = $d['details'];
            unset($d['details']);
            $transferGudang->update($d);
            $transferGudang->details()->delete();
            $transferGudang->details()->createMany($details);
        });
        return redirect()->route('transfer-gudangs.show', $transferGudang)->with('success', 'Draft transfer diperbarui.');
    }
    public function destroy(TransferGudang $transferGudang)
    {
        $this->authorize('delete', $transferGudang);
        $transferGudang->delete();
        return redirect()->route('transfer-gudangs.index')->with('success', 'Draft transfer dihapus.');
    }
    public function submit(TransferGudang $transferGudang)
    {
        $this->authorize('submit', $transferGudang);
        $transferGudang->update(['status' => TransferGudang::DIAJUKAN, 'diajukan_oleh' => Auth::id(), 'diajukan_pada' => now()]);
        return back()->with('success', 'Transfer diajukan.');
    }
    public function confirm(TransferGudang $transferGudang)
    {
        $this->authorize('confirm', $transferGudang);
        $this->service->konfirmasi($transferGudang);
        return back()->with('success', 'Transfer dikirim dan stok tercatat dalam perjalanan.');
    }
    public function receive(ReceiveTransferGudangRequest $request, TransferGudang $transferGudang)
    {
        $data = $request->validated();
        $this->service->terima($transferGudang, $data['received'] ?? [], $data['notes'] ?? null, $data['locations'] ?? [], $request->user()->id);
        return back()->with('success', 'Transfer diterima; stok tujuan dan selisih penerimaan telah dicatat.');
    }
    private function formData(): array
    {
        $user = request()->user();

        return [
            'gudangs' => Gudang::whereIn('id', $user->accessibleGudangIds('transfer'))
                ->orderBy('nama')
                ->get(),
            'bahans' => Bahan::orderBy('nama')->get()
        ];
    }
}
