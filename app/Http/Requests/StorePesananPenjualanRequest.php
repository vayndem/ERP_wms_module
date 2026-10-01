<?php

namespace App\Http\Requests;

use App\Models\PesananPenjualan;
use Illuminate\Foundation\Http\FormRequest;

class StorePesananPenjualanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PesananPenjualan::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'pelanggan_id' => ['required', 'integer', 'exists:pelanggans,id'],
            'sales_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'gudang_id' => ['required', 'integer', 'exists:gudangs,id'],
            'nomor_po_pelanggan' => ['nullable', 'string', 'max:50'],
            'is_ppn' => ['nullable', 'boolean'],
            'tarif_ppn' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'alasan_plafon' => ['nullable', 'string', 'max:500'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.bahan_id' => ['required', 'integer', 'exists:bahans,id'],
            'details.*.jumlah' => ['required', 'numeric', 'gt:0'],
            'details.*.harga_satuan' => ['required', 'numeric', 'min:0'],
            'details.*.satuan' => ['nullable', 'string', 'max:30'],
        ];
    }
}
