<?php

namespace App\Http\Controllers;

use App\Services\LaporanPajakDjpService;
use Illuminate\Http\Request;

class LaporanPajakDjpController extends Controller
{
    public function __construct(private LaporanPajakDjpService $pajak) {}

    public function index(Request $request)
    {
        $this->authorize('viewFinancialStatements');

        $masa = $this->masa($request);

        return view('laporan_pajak_djp.index', $this->pajak->ringkasan($masa) + [
            'templateAcuan' => LaporanPajakDjpService::TEMPLATE_FAKTUR,
            'converterAcuan' => LaporanPajakDjpService::CONVERTER_FAKTUR,
        ]);
    }

    public function xml(Request $request)
    {
        $this->authorize('viewFinancialStatements');

        $masa = $this->masa($request);
        $xml = $this->pajak->xmlFakturKeluaran($masa, config('app.npwp_perusahaan'));

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="faktur-keluaran-' . $masa . '.xml"',
        ]);
    }

    private function masa(Request $request): string
    {
        $masa = (string) $request->input('masa', today()->format('Y-m'));

        return preg_match('/^\d{4}-\d{2}$/', $masa) ? $masa : today()->format('Y-m');
    }
}
