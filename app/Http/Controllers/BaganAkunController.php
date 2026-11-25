<?php

namespace App\Http\Controllers;

use App\Models\BaganAkun;
use App\Models\PermintaanPersetujuan;
use App\Services\PersetujuanOperasiService;
use RuntimeException;
use App\Http\Requests\StoreBaganAkunRequest;
use App\Http\Requests\UpdateBaganAkunRequest;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\AccountingSetting;
use App\Models\KategoriBahan;
use App\Http\Requests\UpdateAccountingMappingRequest;
use Illuminate\Support\Facades\DB;
use App\Exports\GenericTableExport;
use Maatwebsite\Excel\Facades\Excel;

class BaganAkunController extends Controller
{
    public function __construct(private PersetujuanOperasiService $persetujuan) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', BaganAkun::class);

        if ($request->ajax()) {
            $query = BaganAkun::query();

            return datatables()->of($query)
                ->addIndexColumn()
                ->addColumn('can_update', function ($row) use ($request) {
                    return $request->user()->can('update', $row);
                })
                ->addColumn('can_delete', function ($row) use ($request) {
                    return $request->user()->can('delete', $row);
                })
                ->make(true);
        }

        $accounts = BaganAkun::where('is_active', true)->where('is_postable', true)->orderBy('kode_akun')->get();
        $settings = AccountingSetting::pluck('coa_id', 'key');
        $categories = KategoriBahan::with(['coaPersediaan', 'coaBeban', 'coaClearingLpb'])->orderBy('katnama')->get();

        return view('bagan_akun.index', compact('accounts', 'settings', 'categories'));
    }

    public function reportPdf(Request $request)
    {
        $this->authorize('viewAny', BaganAkun::class);

        $filters = collect($request->input('filters', []))->filter(fn($value) => $value !== '');
        $search = trim((string) $request->input('search', ''));
        $fields = ['kode_akun', 'nama_akun', 'kategori_akun', 'posisi_normal', 'keterangan'];
        $query = BaganAkun::query()->orderBy('kode_akun');

        if ($search !== '') {
            $query->where(function ($builder) use ($fields, $search) {
                foreach ($fields as $field) {
                    $builder->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        foreach ($filters as $field => $value) {
            if (in_array($field, $fields, true)) {
                $query->where($field, 'like', "%{$value}%");
            }
        }

        $rows = $query->limit(5000)->get()->map(fn($row) => [
            'kode_akun' => $row->kode_akun,
            'nama_akun' => $row->nama_akun,
            'kategori_akun' => $row->kategori_akun,
            'posisi_normal' => $row->posisi_normal,
            'keterangan' => $row->keterangan ?: '-',
        ]);

        return Pdf::loadView('reports.table-pdf', [
            'title' => 'Daftar Chart of Accounts',
            'columns' => [
                ['key' => 'kode_akun', 'label' => 'Kode Akun', 'align' => 'left'],
                ['key' => 'nama_akun', 'label' => 'Nama Akun', 'align' => 'left'],
                ['key' => 'kategori_akun', 'label' => 'Kategori', 'align' => 'left'],
                ['key' => 'posisi_normal', 'label' => 'Posisi Normal', 'align' => 'left'],
                ['key' => 'keterangan', 'label' => 'Keterangan', 'align' => 'left'],
            ],
            'rows' => $rows,
            'search' => $search,
            'filters' => $filters,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->stream('daftar-coa.pdf');
    }

    public function reportExcel(Request $request)
    {
        $this->authorize('viewAny', BaganAkun::class);

        $filters = collect($request->input('filters', []))->filter(fn($value) => $value !== '');
        $search = trim((string) $request->input('search', ''));
        $fields = ['kode_akun', 'nama_akun', 'kategori_akun', 'posisi_normal', 'keterangan'];
        $query = BaganAkun::query()->orderBy('kode_akun');

        if ($search !== '') {
            $query->where(function ($builder) use ($fields, $search) {
                foreach ($fields as $field) {
                    $builder->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        foreach ($filters as $field => $value) {
            if (in_array($field, $fields, true)) {
                $query->where($field, 'like', "%{$value}%");
            }
        }

        $rows = $query->limit(5000)->get()->map(fn($row) => [
            'kode_akun' => $row->kode_akun,
            'nama_akun' => $row->nama_akun,
            'kategori_akun' => $row->kategori_akun,
            'posisi_normal' => $row->posisi_normal,
            'keterangan' => $row->keterangan ?: '-',
        ]);

        $columns = [
            ['key' => 'kode_akun', 'label' => 'Kode Akun'],
            ['key' => 'nama_akun', 'label' => 'Nama Akun'],
            ['key' => 'kategori_akun', 'label' => 'Kategori'],
            ['key' => 'posisi_normal', 'label' => 'Posisi Normal'],
            ['key' => 'keterangan', 'label' => 'Keterangan'],
        ];

        return Excel::download(new GenericTableExport($columns, $rows), 'daftar-coa-' . now()->format('Ymd-His') . '.xlsx');
    }

    public function create()
    {
        $this->authorize('create', BaganAkun::class);

        return view('bagan_akun.create');
    }

    public function store(StoreBaganAkunRequest $request)
    {
        $validated = $request->validated();

        $coa = BaganAkun::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Akun COA berhasil ditambahkan.',
            'data'    => $coa
        ], 201);
    }

    public function show($id)
    {
        $coa = BaganAkun::findOrFail($id);
        $this->authorize('view', $coa);

        return response()->json([
            'success' => true,
            'data'    => $coa
        ]);
    }

    public function edit($id)
    {
        $coa = BaganAkun::findOrFail($id);
        $this->authorize('update', $coa);

        return view('bagan_akun.edit', compact('coa'));
    }

    public function update(UpdateBaganAkunRequest $request, $id)
    {
        $coa = BaganAkun::findOrFail($id);
        $this->authorize('update', $coa);

        $validated = $request->validated();
        if ($coa->isMapped() && (empty($validated['is_active']) || empty($validated['is_postable']))) {
            abort(422, 'Akun yang masih dipakai mapping tidak boleh dinonaktifkan. Pindahkan mapping terlebih dahulu.');
        }
        if (
            ($coa->isMapped() || $coa->jurnalDetails()->exists())
            && (
                $validated['kategori_akun'] !== $coa->kategori_akun
                || $validated['posisi_normal'] !== $coa->posisi_normal
            )
        ) {
            abort(422, 'Kategori akun dan posisi normal tidak boleh diubah setelah akun dipakai mapping atau jurnal.');
        }
        if ($coa->isMapped() || $coa->jurnalDetails()->exists()) {
            try {
                $permintaan = $this->persetujuan->ajukan(
                    PermintaanPersetujuan::PERUBAHAN_COA,
                    PersetujuanOperasiService::COA_UBAH,
                    "Perubahan akun {$coa->kode_akun} - {$coa->nama_akun}",
                    $validated,
                    $request->input('alasan') ?: 'Perubahan akun yang sudah dipakai mapping atau jurnal.',
                    $request->user(),
                    $coa
                );
            } catch (RuntimeException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return response()->json([
                'success' => true,
                'message' => "Akun ini sudah dipakai mapping atau jurnal, jadi perubahannya diajukan sebagai {$permintaan->nomor} dan menunggu persetujuan Accounting Manager.",
                'data' => $coa,
            ]);
        }

        $coa->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Akun COA berhasil diperbarui.',
            'data'    => $coa
        ]);
    }

    public function destroy($id)
    {
        $coa = BaganAkun::findOrFail($id);
        $this->authorize('delete', $coa);

        $coa->update(['is_active' => false, 'is_cash_bank' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Akun COA dinonaktifkan agar histori jurnal tetap utuh.'
        ]);
    }

    public function getKasBank()
    {
        $coas = BaganAkun::where('is_active', true)
            ->where('is_postable', true)
            ->where('is_cash_bank', true)
            ->orderBy('kode_akun', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $coas,
            'postable' => BaganAkun::where('is_active', true)->where('is_postable', true)
                ->orderBy('kode_akun')->get(['id', 'kode_akun', 'nama_akun', 'kategori_akun']),
        ]);
    }

    public function updateMapping(UpdateAccountingMappingRequest $request)
    {
        try {
            $permintaan = $this->persetujuan->ajukan(
                PermintaanPersetujuan::PERUBAHAN_COA,
                PersetujuanOperasiService::COA_MAPPING,
                'Perubahan mapping akuntansi',
                [
                    'global' => $request->validated('global'),
                    'categories' => $request->validated('categories'),
                ],
                $request->input('alasan') ?: 'Perubahan mapping akuntansi.',
                $request->user()
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Mapping diajukan sebagai {$permintaan->nomor} dan menunggu persetujuan Accounting Manager sebelum berlaku.",
        ]);
    }
}
