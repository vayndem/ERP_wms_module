@php
    $tipe = old('tipe_identitas', $model?->tipe_identitas ?? 'NPWP');
    $pkp = old('status_pkp', $model?->status_pkp === null ? '' : ($model->status_pkp ? '1' : '0'));
@endphp
<div class="form-control">
    <label class="label"><span class="label-text font-semibold">Tipe Identitas Pajak</span></label>
    <select name="tipe_identitas" class="select select-bordered w-full">
        <option value="NPWP" @selected($tipe === 'NPWP')>NPWP (badan / OP bukan penduduk)</option>
        <option value="NIK" @selected($tipe === 'NIK')>NIK (OP penduduk)</option>
    </select>
</div>
<div class="form-control">
    <label class="label"><span class="label-text font-semibold">NPWP</span></label>
    <input type="text" name="npwp" value="{{ old('npwp', $model?->npwp) }}" inputmode="numeric" maxlength="30"
        class="input input-bordered w-full" placeholder="16 digit (NPWP lama 15 digit diberi awalan 0)">
    @error('npwp')<span class="label-text-alt mt-1 text-error">{{ $message }}</span>@enderror
</div>
<div class="form-control">
    <label class="label"><span class="label-text font-semibold">NIK</span></label>
    <input type="text" name="nik" value="{{ old('nik', $model?->nik) }}" inputmode="numeric" maxlength="20"
        class="input input-bordered w-full" placeholder="16 digit">
    @error('nik')<span class="label-text-alt mt-1 text-error">{{ $message }}</span>@enderror
</div>
<div class="form-control">
    <label class="label"><span class="label-text font-semibold">NITKU</span></label>
    <input type="text" name="nitku" value="{{ old('nitku', $model?->nitku) }}" inputmode="numeric" maxlength="30"
        class="input input-bordered w-full" placeholder="22 digit">
    @error('nitku')<span class="label-text-alt mt-1 text-error">{{ $message }}</span>@enderror
</div>
<div class="form-control">
    <label class="label"><span class="label-text font-semibold">Status PKP</span></label>
    <select name="status_pkp" class="select select-bordered w-full">
        <option value="" @selected((string) $pkp === '')>Belum diketahui</option>
        <option value="1" @selected((string) $pkp === '1')>PKP</option>
        <option value="0" @selected((string) $pkp === '0')>Bukan PKP</option>
    </select>
</div>
