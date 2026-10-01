<?php

use App\Http\Controllers\BomController;
use App\Http\Controllers\DataPesananController;
use App\Http\Controllers\FakturPenjualanController;
use App\Http\Controllers\KinerjaSalesController;
use App\Http\Controllers\PelangganController;
use App\Http\Controllers\PesananPenjualanController;
use App\Http\Controllers\PiutangAgingController;
use App\Http\Controllers\VariansPemakaianController;
use App\Http\Controllers\RoutingProduksiController;
use App\Http\Controllers\SuratJalanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('pelanggan', PelangganController::class)->except(['show']);

    Route::get('pesanan-penjualan', [PesananPenjualanController::class, 'index'])->name('pesanan-penjualan.index');
    Route::get('pesanan-penjualan/create', [PesananPenjualanController::class, 'create'])->name('pesanan-penjualan.create');
    Route::post('pesanan-penjualan', [PesananPenjualanController::class, 'store'])->name('pesanan-penjualan.store');
    Route::get('pesanan-penjualan/{pesanan}', [PesananPenjualanController::class, 'show'])->name('pesanan-penjualan.show');
    Route::post('pesanan-penjualan/{pesanan}/kirim', [PesananPenjualanController::class, 'kirim'])->name('pesanan-penjualan.kirim');

    Route::get('surat-jalan', [SuratJalanController::class, 'index'])->name('surat-jalan.index');
    Route::get('surat-jalan/{suratJalan}', [SuratJalanController::class, 'show'])->name('surat-jalan.show');
    Route::post('surat-jalan/{suratJalan}/post', [SuratJalanController::class, 'post'])->name('surat-jalan.post');
    Route::post('surat-jalan/{suratJalan}/faktur', [SuratJalanController::class, 'faktur'])->name('surat-jalan.faktur');
    Route::post('surat-jalan/{suratJalan}/retur', [SuratJalanController::class, 'retur'])->name('surat-jalan.retur');

    Route::get('data-pesanan', [DataPesananController::class, 'index'])->name('data-pesanan.index');
    Route::get('data-pesanan/create', [DataPesananController::class, 'create'])->name('data-pesanan.create');
    Route::post('data-pesanan', [DataPesananController::class, 'store'])->name('data-pesanan.store');
    Route::get('data-pesanan/{pesanan}', [DataPesananController::class, 'show'])->name('data-pesanan.show');
    Route::post('data-pesanan/{pesanan}/rilis', [DataPesananController::class, 'rilis'])->name('data-pesanan.rilis');
    Route::post('data-pesanan/{pesanan}/selesaikan', [DataPesananController::class, 'selesaikan'])->name('data-pesanan.selesaikan');
    Route::post('data-pesanan/{pesanan}/jam-kerja', [DataPesananController::class, 'catatJamKerja'])->name('data-pesanan.jam-kerja');
    Route::delete('data-pesanan/{pesanan}/jam-kerja/{jamKerja}', [DataPesananController::class, 'hapusJamKerja'])->name('data-pesanan.jam-kerja.hapus');
    Route::post('data-pesanan/{pesanan}/batalkan', [DataPesananController::class, 'batalkan'])->name('data-pesanan.batalkan');

    Route::get('faktur-penjualan', [FakturPenjualanController::class, 'index'])->name('faktur-penjualan.index');
    Route::get('faktur-penjualan/{faktur}', [FakturPenjualanController::class, 'show'])->name('faktur-penjualan.show');
    Route::post('faktur-penjualan/{faktur}/post', [FakturPenjualanController::class, 'post'])->name('faktur-penjualan.post');
    Route::post('faktur-penjualan/{faktur}/bayar', [FakturPenjualanController::class, 'bayar'])->name('faktur-penjualan.bayar');
    Route::post('faktur-penjualan/{faktur}/uang-muka', [FakturPenjualanController::class, 'pakaiUangMuka'])->name('faktur-penjualan.uang-muka');
    Route::delete('faktur-penjualan/{faktur}', [FakturPenjualanController::class, 'destroy'])->name('faktur-penjualan.destroy');

    Route::get('piutang-aging', [PiutangAgingController::class, 'index'])->name('piutang-aging.index');
    Route::get('piutang-aging/pdf', [PiutangAgingController::class, 'pdf'])->name('piutang-aging.pdf');
    Route::get('piutang-aging/excel', [PiutangAgingController::class, 'excel'])->name('piutang-aging.excel');

    Route::get('bom', [BomController::class, 'index'])->name('bom.index');
    Route::post('bom', [BomController::class, 'store'])->name('bom.store');
    Route::post('bom/{bom}/status', [BomController::class, 'status'])->name('bom.status');
    Route::delete('bom/{bom}', [BomController::class, 'destroy'])->name('bom.destroy');

    Route::get('routing-produksi', [RoutingProduksiController::class, 'index'])->name('routing-produksi.index');
    Route::post('routing-produksi/pusat-kerja', [RoutingProduksiController::class, 'storePusatKerja'])->name('routing-produksi.pusat-kerja');
    Route::post('routing-produksi/pusat-kerja/{pusatKerja}/status', [RoutingProduksiController::class, 'statusPusatKerja'])->name('routing-produksi.pusat-kerja.status');
    Route::post('routing-produksi/bom/{bom}/operasi', [RoutingProduksiController::class, 'storeOperasi'])->name('routing-produksi.operasi');

    Route::get('varians-pemakaian', [VariansPemakaianController::class, 'index'])->name('varians-pemakaian.index');
    Route::get('varians-pemakaian/pdf', [VariansPemakaianController::class, 'pdf'])->name('varians-pemakaian.pdf');
    Route::get('varians-pemakaian/excel', [VariansPemakaianController::class, 'excel'])->name('varians-pemakaian.excel');

    Route::get('kinerja-sales', [KinerjaSalesController::class, 'index'])->name('kinerja-sales.index');
    Route::get('kinerja-sales/pdf', [KinerjaSalesController::class, 'pdf'])->name('kinerja-sales.pdf');
    Route::get('kinerja-sales/excel', [KinerjaSalesController::class, 'excel'])->name('kinerja-sales.excel');
});
