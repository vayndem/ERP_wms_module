<?php

use App\Http\Controllers\AccountingPeriodLockController;
use App\Http\Controllers\AccountingReconciliationController;
use App\Http\Controllers\BaganAkunController;
use App\Http\Controllers\ExecutiveDashboardController;
use App\Http\Controllers\FinancialStatementController;
use App\Http\Controllers\JurnalController;
use App\Http\Controllers\JurnalDetailController;
use App\Http\Controllers\PermintaanPersetujuanController;
use App\Http\Controllers\RevaluasiKursController;
use App\Http\Controllers\TaxRateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('executive-dashboard', [ExecutiveDashboardController::class, 'index'])->name('executive-dashboard.index');
    Route::get('bagan-akun/kas-bank', [BaganAkunController::class, 'getKasBank'])->name('bagan-akun.kas-bank');
    Route::put('bagan-akun/mapping', [BaganAkunController::class, 'updateMapping'])->name('bagan-akun.mapping.update');
    Route::get('bagan-akun-report/pdf', [BaganAkunController::class, 'reportPdf'])->name('bagan-akun.report.pdf');
    Route::get('bagan-akun-report/excel', [BaganAkunController::class, 'reportExcel'])->name('bagan-akun.report.excel');
    Route::resource('bagan-akun', BaganAkunController::class)->parameters(['bagan-akun' => 'chart_of_account']);

    Route::get('jurnal-report/pdf', [JurnalController::class, 'reportPdf'])->name('jurnal.report.pdf');
    Route::get('jurnal-report/excel', [JurnalController::class, 'reportExcel'])->name('jurnal.report.excel');
    Route::post('jurnal/{jurnal}/post', [JurnalController::class, 'post'])->name('jurnal.post');
    Route::post('jurnal/{jurnal}/approve', [JurnalController::class, 'approve'])->name('jurnal.approve');
    Route::post('jurnal/{jurnal}/reject', [JurnalController::class, 'reject'])->name('jurnal.reject');
    Route::post('jurnal/{jurnal}/reverse', [JurnalController::class, 'reverse'])->name('jurnal.reverse');
    Route::resource('jurnal', JurnalController::class);
    Route::post('jurnal-detail', [JurnalDetailController::class, 'store'])->name('jurnal-detail.store');
    Route::put('jurnal-detail/{id}', [JurnalDetailController::class, 'update'])->name('jurnal-detail.update');
    Route::delete('jurnal-detail/{id}', [JurnalDetailController::class, 'destroy'])->name('jurnal-detail.destroy');

    Route::get('permintaan-persetujuan', [PermintaanPersetujuanController::class, 'index'])->name('permintaan-persetujuan.index');
    Route::post('permintaan-persetujuan/{permintaan}/approve', [PermintaanPersetujuanController::class, 'approve'])->name('permintaan-persetujuan.approve');
    Route::post('permintaan-persetujuan/{permintaan}/reject', [PermintaanPersetujuanController::class, 'reject'])->name('permintaan-persetujuan.reject');

    Route::get('reconciliation', [AccountingReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('reconciliation/{check}', [AccountingReconciliationController::class, 'show'])->name('reconciliation.show');
    Route::post('period-lock/{period_lock}/unlock', [AccountingPeriodLockController::class, 'unlock'])->name('period-lock.unlock');
    Route::resource('period-lock', AccountingPeriodLockController::class)->only(['index', 'store']);
    Route::resource('tax-rate', TaxRateController::class)->only(['index', 'store', 'update']);

    Route::get('financial-statements/neraca-saldo', [FinancialStatementController::class, 'neracaSaldo'])->name('financial-statements.neraca-saldo');
    Route::get('financial-statements/neraca-saldo/pdf', [FinancialStatementController::class, 'neracaSaldoPdf'])->name('financial-statements.neraca-saldo.pdf');
    Route::get('financial-statements/neraca-saldo/excel', [FinancialStatementController::class, 'neracaSaldoExcel'])->name('financial-statements.neraca-saldo.excel');
    Route::get('financial-statements/buku-besar', [FinancialStatementController::class, 'bukuBesar'])->name('financial-statements.buku-besar');
    Route::get('financial-statements/buku-besar/pdf', [FinancialStatementController::class, 'bukuBesarPdf'])->name('financial-statements.buku-besar.pdf');
    Route::get('financial-statements/buku-besar/excel', [FinancialStatementController::class, 'bukuBesarExcel'])->name('financial-statements.buku-besar.excel');
    Route::get('financial-statements/laba-rugi', [FinancialStatementController::class, 'labaRugi'])->name('financial-statements.laba-rugi');
    Route::get('financial-statements/laba-rugi/pdf', [FinancialStatementController::class, 'labaRugiPdf'])->name('financial-statements.laba-rugi.pdf');
    Route::get('financial-statements/laba-rugi/excel', [FinancialStatementController::class, 'labaRugiExcel'])->name('financial-statements.laba-rugi.excel');
    Route::get('financial-statements/pajak-penghasilan', [FinancialStatementController::class, 'pajakPenghasilan'])->name('financial-statements.pajak-penghasilan');
    Route::post('financial-statements/pajak-penghasilan', [FinancialStatementController::class, 'postingPajakPenghasilan'])->name('financial-statements.pajak-penghasilan.posting');
    Route::get('financial-statements/rekonsiliasi-fiskal', [FinancialStatementController::class, 'rekonsiliasiFiskal'])->name('financial-statements.rekonsiliasi-fiskal');
    Route::get('financial-statements/rekonsiliasi-fiskal/pdf', [FinancialStatementController::class, 'rekonsiliasiFiskalPdf'])->name('financial-statements.rekonsiliasi-fiskal.pdf');
    Route::get('financial-statements/rekonsiliasi-fiskal/excel', [FinancialStatementController::class, 'rekonsiliasiFiskalExcel'])->name('financial-statements.rekonsiliasi-fiskal.excel');
    Route::get('financial-statements/arus-kas', [FinancialStatementController::class, 'arusKas'])->name('financial-statements.arus-kas');
    Route::get('financial-statements/arus-kas/pdf', [FinancialStatementController::class, 'arusKasPdf'])->name('financial-statements.arus-kas.pdf');
    Route::get('financial-statements/arus-kas/excel', [FinancialStatementController::class, 'arusKasExcel'])->name('financial-statements.arus-kas.excel');
    Route::get('financial-statements/perubahan-ekuitas', [FinancialStatementController::class, 'perubahanEkuitas'])->name('financial-statements.perubahan-ekuitas');
    Route::get('financial-statements/perubahan-ekuitas/pdf', [FinancialStatementController::class, 'perubahanEkuitasPdf'])->name('financial-statements.perubahan-ekuitas.pdf');
    Route::get('financial-statements/perubahan-ekuitas/excel', [FinancialStatementController::class, 'perubahanEkuitasExcel'])->name('financial-statements.perubahan-ekuitas.excel');
    Route::get('laporan-pajak-djp', [App\Http\Controllers\LaporanPajakDjpController::class, 'index'])->name('laporan-pajak-djp.index');
    Route::get('laporan-pajak-djp/xml', [App\Http\Controllers\LaporanPajakDjpController::class, 'xml'])->name('laporan-pajak-djp.xml');
    Route::get('revaluasi-kurs', [RevaluasiKursController::class, 'index'])->name('revaluasi-kurs.index');
    Route::post('revaluasi-kurs/kurs', [RevaluasiKursController::class, 'simpanKurs'])->name('revaluasi-kurs.kurs');
    Route::post('revaluasi-kurs/posting', [RevaluasiKursController::class, 'posting'])->name('revaluasi-kurs.posting');

    Route::get('financial-statements/calk', [FinancialStatementController::class, 'calk'])->name('financial-statements.calk');
    Route::post('financial-statements/calk', [FinancialStatementController::class, 'simpanCalk'])->name('financial-statements.calk.simpan');
    Route::get('financial-statements/calk/pdf', [FinancialStatementController::class, 'calkPdf'])->name('financial-statements.calk.pdf');
    Route::get('financial-statements/calk/excel', [FinancialStatementController::class, 'calkExcel'])->name('financial-statements.calk.excel');
    Route::get('financial-statements/neraca', [FinancialStatementController::class, 'neraca'])->name('financial-statements.neraca');
    Route::get('financial-statements/neraca/pdf', [FinancialStatementController::class, 'neracaPdf'])->name('financial-statements.neraca.pdf');
    Route::get('financial-statements/neraca/excel', [FinancialStatementController::class, 'neracaExcel'])->name('financial-statements.neraca.excel');
});
