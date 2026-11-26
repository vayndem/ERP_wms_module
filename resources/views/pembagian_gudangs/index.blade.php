@extends('layouts.app')
@section('content')
    <div class="content-page">
        <h3 class="text-2xl font-bold">Pembagian Gudang</h3>
        <p class="mb-4 text-base-content/60">Atur gudang mana saja yang boleh diakses oleh user Warehouse atau Produksi.</p>
        @include('warehouse_partials.alerts')
        @can('create', App\Models\PembagianGudang::class)
            <form method="POST" action="{{ route('pembagian-gudangs.store') }}" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                @csrf
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <select name="user_id" class="select select-bordered" required>
                        <option value="">Pilih user gudang/produksi</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->role_name }})</option>
                        @endforeach
                    </select>
                    <select name="gudang_id" class="select select-bordered" required>
                        <option value="">Pilih gudang</option>
                        @foreach ($gudangs as $g)
                            <option value="{{ $g->id }}">{{ $g->nama }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary">Simpan Pembagian</button>
                </div>
                <div class="mt-3 flex flex-wrap gap-6">
                    @foreach (['boleh_menerima' => 'Terima', 'boleh_npk' => 'Pemakaian Barang', 'boleh_transfer' => 'Transfer', 'boleh_opname' => 'Opname'] as $f => $label)
                        <label class="flex cursor-pointer items-center gap-2">
                            <input type="hidden" name="{{ $f }}" value="0">
                            <input class="checkbox" type="checkbox" name="{{ $f }}" value="1" checked>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </form>
        @endcan
        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Gudang</th>
                            <th>Akses</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="font-semibold">{{ $r->user->name }}</td>
                                <td>{{ $r->gudang->nama }}</td>
                                <td>
                                    {{ collect([
                                        'Terima' => $r->boleh_menerima,
                                        'Pemakaian Barang' => $r->boleh_npk,
                                        'Transfer' => $r->boleh_transfer,
                                        'Opname' => $r->boleh_opname,
                                    ])->filter()->keys()->join(', ') ?: '-' }}
                                </td>
                                <td>
                                    @can('delete', $r)
                                        <form method="POST" action="{{ route('pembagian-gudangs.destroy', $r) }}"
                                            onsubmit="return confirm('Cabut akses {{ $r->user?->name }} ke gudang {{ $r->gudang?->nama }}? Operator tanpa assignment tidak akan melihat gudang manapun.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-outline btn-error btn-sm">Hapus</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    </div>
@endsection
