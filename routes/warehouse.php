<?php

use App\Http\Controllers\GudangController;
use App\Http\Controllers\InventoryFinancialControlController;
use App\Http\Controllers\PenerimaanBarangController;
use App\Http\Controllers\MutasiStokController;
use App\Http\Controllers\PemakaianBarangController;
use App\Http\Controllers\PembagianGudangController;
use App\Http\Controllers\PemeriksaanConsiderController;
use App\Http\Controllers\PengaturanBahanGudangController;
use App\Http\Controllers\RekonsiliasiGudangController;
use App\Http\Controllers\ReturPembelianController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\StokGudangController;
use App\Http\Controllers\TransferGudangController;
use App\Http\Controllers\WarehouseExecutionController;
use App\Http\Controllers\WmsControlController;
use App\Http\Controllers\WmsTraceabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::resource('gudangs', GudangController::class)->except('destroy');
    Route::resource('stok-gudangs', StokGudangController::class)->only(['index', 'show']);
    Route::resource('pembagian-gudangs', PembagianGudangController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('pengaturan-bahan-gudangs', PengaturanBahanGudangController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::post('transfer-gudangs/{transfer_gudang}/submit', [TransferGudangController::class, 'submit'])->name('transfer-gudangs.submit');
    Route::post('transfer-gudangs/{transfer_gudang}/confirm', [TransferGudangController::class, 'confirm'])->name('transfer-gudangs.confirm');
    Route::post('transfer-gudangs/{transfer_gudang}/receive', [TransferGudangController::class, 'receive'])->name('transfer-gudangs.receive');
    Route::resource('transfer-gudangs', TransferGudangController::class);

    Route::post('pemeriksaan-considers/{pemeriksaan_consider}/confirm', [PemeriksaanConsiderController::class, 'confirm'])->name('pemeriksaan-considers.confirm');
    Route::resource('pemeriksaan-considers', PemeriksaanConsiderController::class);
    Route::resource('mutasi-stoks', MutasiStokController::class)->only('index');
    Route::get('rekonsiliasi-gudangs', [RekonsiliasiGudangController::class, 'index'])->name('rekonsiliasi-gudangs.index');

    Route::get('penerimaan-barang/po/{no_po}', [PenerimaanBarangController::class, 'getPoDetail'])->name('penerimaan-barang.get-po-detail');
    Route::get('penerimaan-barang-report/pdf', [PenerimaanBarangController::class, 'reportPdf'])->name('penerimaan-barang.report.pdf');
    Route::get('penerimaan-barang-report/excel', [PenerimaanBarangController::class, 'reportExcel'])->name('penerimaan-barang.report.excel');
    Route::post('penerimaan-barang/{lpb}/details', [PenerimaanBarangController::class, 'storeDetail'])->name('penerimaan-barang.details.store');
    Route::put('penerimaan-barang/{lpb}/details/{detail}', [PenerimaanBarangController::class, 'updateDetail'])->name('penerimaan-barang.details.update');
    Route::delete('penerimaan-barang/{lpb}/details/{detail}', [PenerimaanBarangController::class, 'destroyDetail'])->name('penerimaan-barang.details.destroy');
    Route::resource('penerimaan-barang', PenerimaanBarangController::class)->parameters(['penerimaan-barang' => 'lpb'])->except(['edit']);

    Route::get('retur-pembelian/lpb-detail/{id_lpb}', [ReturPembelianController::class, 'getLpbDetail'])->name('retur-pembelian.get-lpb-detail');
    Route::resource('retur-pembelian', ReturPembelianController::class)->only(['index', 'create', 'store', 'show']);

    Route::get('pemakaian-barang-report/pdf', [PemakaianBarangController::class, 'reportPdf'])->name('pemakaian-barang.report.pdf');
    Route::get('pemakaian-barang-report/excel', [PemakaianBarangController::class, 'reportExcel'])->name('pemakaian-barang.report.excel');
    Route::resource('pemakaian-barang', PemakaianBarangController::class)->parameters(['pemakaian-barang' => 'npk']);

    Route::get('stock-opname-report/pdf', [StockOpnameController::class, 'reportListPdf'])->name('stock-opname.report.pdf');
    Route::get('stock-opname-report/excel', [StockOpnameController::class, 'reportListExcel'])->name('stock-opname.report.excel');
    Route::get('stock-opname-export/excel', [StockOpnameController::class, 'exportInventory'])->name('stock-opname.export.excel');
    Route::get('stock-opname/{stock_opname}/detail-data', [StockOpnameController::class, 'detailData'])->name('stock-opname.detail-data');
    Route::get('stock-opname/{stock_opname}/pdf', [StockOpnameController::class, 'reportPdf'])->name('stock-opname.pdf');
    Route::get('stock-opname/{stock_opname}/excel', [StockOpnameController::class, 'reportExcel'])->name('stock-opname.excel');
    Route::post('stock-opname/{stock_opname}/submit', [StockOpnameController::class, 'submit'])->name('stock-opname.submit');
    Route::post('stock-opname/{stock_opname}/approve', [StockOpnameController::class, 'approve'])->name('stock-opname.approve');
    Route::post('stock-opname/{stock_opname}/reject', [StockOpnameController::class, 'reject'])->name('stock-opname.reject');
    Route::post('stock-opname/{stock_opname}/post', [StockOpnameController::class, 'post'])->name('stock-opname.post');
    Route::resource('stock-opname', StockOpnameController::class);

    Route::prefix('wms-control')->name('wms-control.')->group(function () {
        Route::get('/', [WmsControlController::class, 'index'])->name('index');
        Route::post('locations', [WmsTraceabilityController::class, 'storeLocation'])->name('locations.store');
        Route::post('lots', [WmsTraceabilityController::class, 'storeLot'])->name('lots.store');
        Route::post('serials', [WmsTraceabilityController::class, 'storeSerial'])->name('serials.store');
        Route::patch('lots/{lot}/block', [WmsTraceabilityController::class, 'updateLotBlock'])->name('lots.block');
        Route::patch('serials/{serial}/status', [WmsTraceabilityController::class, 'updateSerialStatus'])->name('serials.status');
        Route::post('penerimaan-barang/{lpb}/inspect', [WarehouseExecutionController::class, 'inspect'])->name('penerimaan-barang.inspect');
        Route::post('penerimaan-barang/{lpb}/putaway', [WarehouseExecutionController::class, 'putaway'])->name('penerimaan-barang.putaway');
        Route::post('reservations', [WarehouseExecutionController::class, 'reserve'])->name('reservations.store');
        Route::post('reservations/{reservation}/release', [WarehouseExecutionController::class, 'releaseReservation'])->name('reservations.release');
        Route::post('reservations/{reservation}/pick', [WarehouseExecutionController::class, 'createPick'])->name('reservations.pick');
        Route::post('picking-orders/{pickingOrder}/complete', [WarehouseExecutionController::class, 'completePick'])->name('picking-orders.complete');
        Route::post('picking-orders/{pickingOrder}/issue', [WarehouseExecutionController::class, 'issuePick'])->name('picking-orders.issue');
        Route::post('replenishment', [WarehouseExecutionController::class, 'replenish'])->name('replenishment');
        Route::post('replenishment/{suggestion}/request', [WarehouseExecutionController::class, 'requestReplenishment'])->name('replenishment.request');
        Route::post('faktur-pembelian/{invoice}/match', [InventoryFinancialControlController::class, 'matchInvoice'])->name('faktur-pembelian.match');
        Route::post('landed-costs', [InventoryFinancialControlController::class, 'storeLandedCost'])->name('landed-costs.store');
        Route::post('landed-costs/{landedCost}/post', [InventoryFinancialControlController::class, 'postLandedCost'])->name('landed-costs.post');
        Route::post('penerimaan-barang/{lpb}/reverse', [InventoryFinancialControlController::class, 'reverseLpb'])->name('penerimaan-barang.reverse');
        Route::post('pemakaian-barang/{npk}/reverse', [InventoryFinancialControlController::class, 'reverseNpk'])->name('pemakaian-barang.reverse');
        Route::post('retur-pembelian/{returPembelian}/reverse', [InventoryFinancialControlController::class, 'reverseReturPembelian'])->name('retur-pembelian.reverse');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('antrean-kerja', [App\Http\Controllers\AntreanKerjaController::class, 'index'])->name('antrean-kerja.index');
    Route::get('slotting', [App\Http\Controllers\SlottingController::class, 'index'])->name('slotting.index');

    Route::get('gelombang-pengambilan', [App\Http\Controllers\GelombangPengambilanController::class, 'index'])->name('gelombang-pengambilan.index');
    Route::post('gelombang-pengambilan', [App\Http\Controllers\GelombangPengambilanController::class, 'store'])->name('gelombang-pengambilan.store');
    Route::post('gelombang-pengambilan/{gelombang}/rilis', [App\Http\Controllers\GelombangPengambilanController::class, 'release'])->name('gelombang-pengambilan.release');
    Route::post('gelombang-pengambilan/{gelombang}/selesai', [App\Http\Controllers\GelombangPengambilanController::class, 'complete'])->name('gelombang-pengambilan.complete');
    Route::delete('gelombang-pengambilan/{gelombang}', [App\Http\Controllers\GelombangPengambilanController::class, 'destroy'])->name('gelombang-pengambilan.destroy');

    Route::get('kit', [App\Http\Controllers\KitController::class, 'index'])->name('kit.index');
    Route::post('kit', [App\Http\Controllers\KitController::class, 'store'])->name('kit.store');
    Route::get('kit/{kit}/ketersediaan', [App\Http\Controllers\KitController::class, 'ketersediaan'])->name('kit.ketersediaan');
    Route::post('kit/{kit}/rakit', [App\Http\Controllers\KitController::class, 'rakit'])->name('kit.rakit');
    Route::delete('kit/{kit}', [App\Http\Controllers\KitController::class, 'destroy'])->name('kit.destroy');

    Route::get('subkontrak', [App\Http\Controllers\SubkontrakController::class, 'index'])->name('subkontrak.index');
    Route::post('subkontrak', [App\Http\Controllers\SubkontrakController::class, 'store'])->name('subkontrak.store');
    Route::post('subkontrak/{subkontrak}/terima', [App\Http\Controllers\SubkontrakController::class, 'terima'])->name('subkontrak.terima');
    Route::get('cross-dock', [App\Http\Controllers\CrossDockController::class, 'index'])->name('cross-dock.index');
    Route::post('cross-dock', [App\Http\Controllers\CrossDockController::class, 'store'])->name('cross-dock.store');
    Route::post('cross-dock/{crossDock}/batalkan', [App\Http\Controllers\CrossDockController::class, 'batalkan'])->name('cross-dock.batalkan');

    Route::get('stock-opname-siklus', [App\Http\Controllers\StockOpnameController::class, 'cycleBoard'])->name('stock-opname.siklus');
    Route::post('stock-opname-siklus', [App\Http\Controllers\StockOpnameController::class, 'startCycle'])->name('stock-opname.siklus.store');
});
