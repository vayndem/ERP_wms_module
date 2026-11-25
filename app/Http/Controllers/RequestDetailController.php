<?php

namespace App\Http\Controllers;

use App\Models\RequestDetail;
use App\Models\MaterialRequest;
use App\Http\Requests\StoreRequestDetailRequest;
use App\Http\Requests\UpdateRequestDetailRequest;
use Illuminate\Http\Request;
use App\Models\PenerimaanBarangDetail;
use App\Models\PenerimaanBarang;

class RequestDetailController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', RequestDetail::class);

        if ($request->ajax()) {
            $mayViewPrice = $request->user()->hasAnyRole([\App\Models\User::ROLE_PURCHASING, \App\Models\User::ROLE_ACCOUNTING]);
            $query = RequestDetail::with('bahan', 'request')
                ->whereHas('request', function ($q) {
                    $q->where('status', MaterialRequest::APPROVED);
                })
                ->whereRaw('jumlah_acc > realisasi');

            $searchValue = $request->input('search.value');
            if (!empty($searchValue)) {
                $query->where(function ($q) use ($searchValue) {
                    $q->where('nama_barang', 'like', "%{$searchValue}%")
                        ->orWhereHas('request', function ($header) use ($searchValue) {
                            $header->where('no_request', 'like', "%{$searchValue}%");
                        })
                        ->orWhereHas('bahan', function ($b) use ($searchValue) {
                            $b->where('nama', 'like', "%{$searchValue}%");
                        });
                });
            }

            return datatables()->of($query)
                ->addColumn('id_permintaan', function ($row) {
                    return $row->id;
                })
                ->addColumn('no_request', function ($row) {
                    return $row->request?->no_request ?? '-';
                })
                ->addColumn('bahan', function ($row) {
                    return $row->nama_barang;
                })
                ->addColumn('id_bahan', function ($row) {
                    return $row->bahan_id;
                })
                ->addColumn('jumlah_order', function ($row) {
                    return $row->jumlah_acc;
                })
                ->addColumn('harga', function ($row) use ($mayViewPrice, $query, &$rataRata) {
                    $rataRata ??= $mayViewPrice ? $this->rataLpbTerakhirBanyak((clone $query)->pluck('bahan_id')) : [];

                    return $mayViewPrice ? ($rataRata[(int) $row->bahan_id] ?? 0) : null;
                })
                ->addColumn('harga_referensi', function ($row) use ($mayViewPrice, $query, &$rataRata) {
                    $rataRata ??= $mayViewPrice ? $this->rataLpbTerakhirBanyak((clone $query)->pluck('bahan_id')) : [];

                    return $mayViewPrice ? ($rataRata[(int) $row->bahan_id] ?? 0) : null;
                })
                ->make(true);
        }

        return response()->json(['message' => 'Invalid endpoint'], 400);
    }

    private function rataLpbTerakhirBanyak($bahanIds): array
    {
        $bahanIds = collect($bahanIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($bahanIds->isEmpty()) {
            return [];
        }

        $urut = PenerimaanBarangDetail::query()
            ->join('wms_penerimaan_barang', 'wms_penerimaan_barang.id_lpb', '=', 'wms_penerimaan_barang_detail.id_lpb')
            ->whereIn('wms_penerimaan_barang_detail.id_bahan', $bahanIds)
            ->where('wms_penerimaan_barang_detail.jumlah_barang_diterima', '>', 0)
            ->where('wms_penerimaan_barang.status', PenerimaanBarang::POSTED)
            ->selectRaw('wms_penerimaan_barang_detail.id_bahan as bahan_id, wms_penerimaan_barang_detail.jumlah_barang_diterima as jumlah, wms_penerimaan_barang_detail.harga as harga, ROW_NUMBER() OVER (PARTITION BY wms_penerimaan_barang_detail.id_bahan ORDER BY wms_penerimaan_barang.tanggal DESC, wms_penerimaan_barang_detail.id DESC) as urutan');

        return \Illuminate\Support\Facades\DB::query()
            ->fromSub($urut, 'r')
            ->where('urutan', '<=', 5)
            ->get()
            ->groupBy('bahan_id')
            ->map(function ($baris) {
                $quantity = (float) $baris->sum('jumlah');

                return $quantity > 0
                    ? round($baris->sum(fn ($row) => (float) $row->jumlah * (float) $row->harga) / $quantity, 4)
                    : 0;
            })
            ->all();
    }

    public function store(StoreRequestDetailRequest $request)
    {
        $validated = $request->validated();
        $validated['realisasi'] = 0;

        $detail = RequestDetail::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Detail request berhasil ditambahkan.',
            'data'    => $detail,
        ], 201);
    }

    public function show(RequestDetail $requestdetail)
    {
        $this->authorize('view', $requestdetail);

        return response()->json([
            'success' => true,
            'data'    => $requestdetail->load('bahan', 'request'),
        ]);
    }

    public function update(UpdateRequestDetailRequest $request, RequestDetail $requestdetail)
    {
        $validated = $request->validated();

        $requestdetail->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Detail request berhasil diperbarui.',
            'data'    => $requestdetail,
        ]);
    }

    public function destroy(RequestDetail $requestdetail)
    {
        $this->authorize('delete', $requestdetail);

        $requestdetail->delete();

        return response()->json([
            'success' => true,
            'message' => 'Detail request berhasil dihapus.',
        ]);
    }
}
