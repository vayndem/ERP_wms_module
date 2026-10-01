<div class="mm-sidebar sidebar-default">
    <div class="mm-sidebar-logo flex items-center justify-center">
        <a href="{{ route('dashboard') }}" class="header-logo flex items-center gap-2 no-underline text-neutral-content">
            <span class="flex h-9 w-9 flex-none items-center justify-center rounded-xl bg-primary text-primary-content">
                <i class="fa-solid fa-warehouse"></i>
            </span>
            <span class="text-lg font-bold">ERP <span class="font-normal text-neutral-content/60">·</span> WMS</span>
        </a>
    </div>
    <div class="data-scrollbar" data-scroll="1">
        <nav class="mm-sidebar-menu">
            <ul id="mm-sidebar-toggle" class="side-menu">

                <li class="">
                    <a href="{{ route('dashboard') }}" class="svg-icon">
                        <i>
                            <svg class="svg-icon" id="mm-dash" width="20" xmlns="http://www.w3.org/2000/svg"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                    style="stroke-dasharray: 77, 97; stroke-dashoffset: 0;"></path>
                            </svg>
                        </i>
                        <span class="ms-2">Dashboard</span>
                    </a>
                </li>

                @if (Auth::user()->can('viewAny', App\Models\Supplier::class) || Auth::user()->can('viewAny', App\Models\Aset::class))
                    <li
                        class="{{ request()->routeIs('supplier.*') || request()->routeIs('bahan.*') || request()->routeIs('reconciliation.*') ? 'active' : '' }}">
                        <a href="#master" class="svg-icon"
                            aria-expanded="{{ request()->routeIs('supplier.*') || request()->routeIs('bahan.*') || request()->routeIs('reconciliation.*') ? 'true' : 'false' }}">
                            <i>
                                <svg class="svg-icon" id="mm-master-1" width="20" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                            </i>
                            <span class="ms-2">Master</span>
                            <i class="fa-solid fa-chevron-right mm-arrow-right arrow-active"></i>
                            <i class="fa-solid fa-chevron-down mm-arrow-right arrow-hover"></i>
                        </a>
                        <ul id="master"
                            class="submenu {{ request()->routeIs('supplier.*') || request()->routeIs('bahan.*') || request()->routeIs('reconciliation.*') ? 'show' : '' }}">
                            @can('viewAny', App\Models\Supplier::class)
                                <li class="{{ request()->routeIs('supplier.*') ? 'active' : '' }}">
                                    <a href="{{ route('supplier.index') }}" class="svg-icon">
                                        <i>
                                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" width="20">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
                                            </svg>
                                        </i>
                                        <span class="">Supplier</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\Bahan::class)
                                <li class="{{ request()->routeIs('bahan.*') ? 'active' : '' }}">
                                    <a href="{{ route('bahan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-boxes-stacked"></i><span>Master Bahan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\AccountingReconciliation::class)
                                <li class="{{ request()->routeIs('reconciliation.*') ? 'active' : '' }}">
                                    <a href="{{ route('reconciliation.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-scale-balanced"></i><span>Rekonsiliasi WMS</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\Aset::class)
                                <li
                                    class="{{ request()->routeIs('aset.*') || request()->routeIs('kategori-aset.*') ? 'active' : '' }}">
                                    <a href="{{ route('aset.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-building-columns"></i><span>Aset Tetap</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @if (Auth::user()->can('viewAny', App\Models\TipePembebanan::class) ||
                        Auth::user()->can('viewAny', App\Models\BaganAkun::class) ||
                        Auth::user()->can('viewAny', App\Models\Jurnal::class) ||
                        Auth::user()->can('viewFinancialStatements') ||
                        Auth::user()->can('viewExecutiveDashboard'))
                    <li
                        class="{{ request()->routeIs('tipe-pembebanan.*') || request()->routeIs('kategori-bahan.*') || request()->routeIs('bagan-akun.*') || request()->routeIs('jurnal.*') || request()->routeIs('period-lock.*') || request()->routeIs('tax-rate.*') || request()->routeIs('financial-statements.*') || request()->routeIs('executive-dashboard.*') ? 'active' : '' }}">
                        <a href="#akuntansi" class="svg-icon"
                            aria-expanded="{{ request()->routeIs('tipe-pembebanan.*') || request()->routeIs('kategori-bahan.*') || request()->routeIs('bagan-akun.*') || request()->routeIs('jurnal.*') || request()->routeIs('period-lock.*') || request()->routeIs('tax-rate.*') || request()->routeIs('financial-statements.*') || request()->routeIs('executive-dashboard.*') ? 'true' : 'false' }}">
                            <i>
                                <svg class="svg-icon" id="mm-akuntansi-1" width="20"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </i>
                            <span class="ms-2">Akuntansi</span>
                            <i class="fa-solid fa-chevron-right mm-arrow-right arrow-active"></i>
                            <i class="fa-solid fa-chevron-down mm-arrow-right arrow-hover"></i>
                        </a>
                        <ul id="akuntansi"
                            class="submenu {{ request()->routeIs('tipe-pembebanan.*') || request()->routeIs('kategori-bahan.*') || request()->routeIs('bagan-akun.*') || request()->routeIs('jurnal.*') || request()->routeIs('period-lock.*') || request()->routeIs('tax-rate.*') || request()->routeIs('financial-statements.*') || request()->routeIs('executive-dashboard.*') ? 'show' : '' }}">

                            @can('viewAny', App\Models\TipePembebanan::class)
                                <li class="{{ request()->routeIs('tipe-pembebanan.*') ? 'active' : '' }}">
                                    <a href="{{ route('tipe-pembebanan.index') }}" class="svg-icon">
                                        <i>
                                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" width="20">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M7 7h10M7 12h10m-8 5h8" />
                                            </svg>
                                        </i>
                                        <span class="">Tipe Pembebanan</span>
                                    </a>
                                </li>
                            @endcan

                            @can('viewAny', App\Models\KategoriBahan::class)
                                <li class="{{ request()->routeIs('kategori-bahan.*') ? 'active' : '' }}">
                                    <a href="{{ route('kategori-bahan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-layer-group"></i><span class="">Kategori &
                                            Mapping</span>
                                    </a>
                                </li>
                            @endcan

                            @can('viewAny', App\Models\BaganAkun::class)
                                <li class="{{ request()->routeIs('bagan-akun.*') ? 'active' : '' }}">
                                    <a href="{{ route('bagan-akun.index') }}" class="svg-icon">
                                        <i>
                                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" width="20">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </i>
                                        <span class="">Bagan Akun</span>
                                    </a>
                                </li>
                            @endcan

                            @can('viewAny', App\Models\Jurnal::class)
                                <li class="{{ request()->routeIs('jurnal.*') ? 'active' : '' }}">
                                    <a href="{{ route('jurnal.index') }}" class="svg-icon">
                                        <i>
                                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" width="20">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                            </svg>
                                        </i>
                                        <span class="">Jurnal Umum</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\AccountingPeriodLock::class)
                                <li class="{{ request()->routeIs('period-lock.*') ? 'active' : '' }}">
                                    <a href="{{ route('period-lock.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-calendar-xmark"></i><span>Kunci Periode</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\TaxRate::class)
                                <li class="{{ request()->routeIs('tax-rate.*') ? 'active' : '' }}">
                                    <a href="{{ route('tax-rate.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-percent"></i><span>Tarif Pajak</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewFinancialStatements')
                                <li class="{{ request()->routeIs('laporan-pajak-djp.*') ? 'active' : '' }}">
                                    <a href="{{ route('laporan-pajak-djp.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-file-export"></i><span>Data Pelaporan DJP</span>
                                    </a>
                                </li>
                            @endcan
                            @can('controlInventoryFinance')
                                <li class="{{ request()->routeIs('revaluasi-kurs.*') ? 'active' : '' }}">
                                    <a href="{{ route('revaluasi-kurs.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-money-bill-transfer"></i><span>Revaluasi Kurs</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewFinancialStatements')
                                <li class="{{ request()->routeIs('financial-statements.*') ? 'active' : '' }}">
                                    <a href="{{ route('financial-statements.neraca-saldo') }}" class="svg-icon">
                                        <i class="fa-solid fa-file-invoice-dollar"></i><span>Laporan Keuangan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\PermintaanPersetujuan::class)
                                <li class="{{ request()->routeIs('permintaan-persetujuan.*') ? 'active' : '' }}">
                                    <a href="{{ route('permintaan-persetujuan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-user-shield"></i><span>Antrean Persetujuan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewExecutiveDashboard')
                                <li class="{{ request()->routeIs('executive-dashboard.*') ? 'active' : '' }}">
                                    <a href="{{ route('executive-dashboard.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-chart-line"></i><span>Dashboard Eksekutif</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @if (Auth::user()->can('viewAny', App\Models\Pelanggan::class) ||
                        Auth::user()->can('viewAny', App\Models\PesananPenjualan::class) ||
                        Auth::user()->can('viewAny', App\Models\SuratJalan::class) ||
                        Auth::user()->can('viewAny', App\Models\FakturPenjualan::class) ||
                        Auth::user()->can('viewAny', App\Models\DataPesanan::class) ||
                        Auth::user()->can('viewAny', App\Models\Bom::class) ||
                        Auth::user()->can('viewPiutangAging') ||
                        Auth::user()->can('viewKinerjaSales') ||
                        Auth::user()->can('viewProcurementAnalytics'))
                    <li
                        class="{{ request()->routeIs('pelanggan.*') || request()->routeIs('pesanan-penjualan.*') || request()->routeIs('surat-jalan.*') || request()->routeIs('faktur-penjualan.*') || request()->routeIs('data-pesanan.*') || request()->routeIs('lacak-pembelian.*') || request()->routeIs('supplier-scorecard.*') ? 'active' : '' }}">
                        <a href="#penjualan" class="svg-icon"
                            aria-expanded="{{ request()->routeIs('pelanggan.*') || request()->routeIs('pesanan-penjualan.*') || request()->routeIs('surat-jalan.*') || request()->routeIs('faktur-penjualan.*') || request()->routeIs('data-pesanan.*') || request()->routeIs('lacak-pembelian.*') || request()->routeIs('supplier-scorecard.*') ? 'true' : 'false' }}">
                            <i class="fa-solid fa-cart-shopping"></i><span>Penjualan &amp; Produksi</span>
                        </a>
                        <ul class="sub-menu">
                            @can('viewAny', App\Models\Pelanggan::class)
                                <li class="{{ request()->routeIs('pelanggan.*') ? 'active' : '' }}">
                                    <a href="{{ route('pelanggan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-user-tie"></i><span>Pelanggan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\PesananPenjualan::class)
                                <li class="{{ request()->routeIs('pesanan-penjualan.*') ? 'active' : '' }}">
                                    <a href="{{ route('pesanan-penjualan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-cart-arrow-down"></i><span>Pesanan Penjualan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\SuratJalan::class)
                                <li class="{{ request()->routeIs('surat-jalan.*') ? 'active' : '' }}">
                                    <a href="{{ route('surat-jalan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-truck-fast"></i><span>Surat Jalan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\DataPesanan::class)
                                <li class="{{ request()->routeIs('data-pesanan.*') ? 'active' : '' }}">
                                    <a href="{{ route('data-pesanan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-industry"></i><span>Perintah Kerja</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\Bom::class)
                                <li class="{{ request()->routeIs('bom.*') ? 'active' : '' }}">
                                    <a href="{{ route('bom.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-sitemap"></i><span>BOM</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\PusatKerja::class)
                                <li class="{{ request()->routeIs('routing-produksi.*') ? 'active' : '' }}">
                                    <a href="{{ route('routing-produksi.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-diagram-project"></i><span>Routing Produksi</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\DataPesanan::class)
                                <li class="{{ request()->routeIs('varians-pemakaian.*') ? 'active' : '' }}">
                                    <a href="{{ route('varians-pemakaian.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-scale-unbalanced"></i><span>Varians Pemakaian</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\FakturPenjualan::class)
                                <li class="{{ request()->routeIs('faktur-penjualan.*') ? 'active' : '' }}">
                                    <a href="{{ route('faktur-penjualan.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-file-invoice"></i><span>Faktur Penjualan</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewKinerjaSales')
                                <li class="{{ request()->routeIs('kinerja-sales.*') ? 'active' : '' }}">
                                    <a href="{{ route('kinerja-sales.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-user-check"></i><span>Kinerja Sales</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewPiutangAging')
                                <li class="{{ request()->routeIs('piutang-aging.*') ? 'active' : '' }}">
                                    <a href="{{ route('piutang-aging.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-hourglass-half"></i><span>Umur Piutang</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewProcurementAnalytics')
                                <li class="{{ request()->routeIs('lacak-pembelian.*') ? 'active' : '' }}">
                                    <a href="{{ route('lacak-pembelian.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-magnifying-glass-dollar"></i><span>Lacak Pembelian</span>
                                    </a>
                                </li>
                                <li class="{{ request()->routeIs('supplier-scorecard.*') ? 'active' : '' }}">
                                    <a href="{{ route('supplier-scorecard.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-ranking-star"></i><span>Kartu Skor Supplier</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @can('viewAny', App\Models\MaterialRequest::class)
                    <li class="{{ request()->routeIs('request.*') ? 'active' : '' }}">
                        <a href="{{ route('request.index') }}" class="svg-icon">
                            <i>
                                <svg class="svg-icon" id="mm-request" width="20" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </i>
                            <span class="ms-2">Request</span>
                        </a>
                    </li>
                @endcan

                @if (Auth::user()->can('viewAny', App\Models\PesananPembelian::class) ||
                        Auth::user()->can('viewAny', App\Models\PesananJasa::class))
                    <li class="{{ request()->routeIs('pembelian.*', 'pesanan-jasa.*') ? 'active' : '' }}">
                        <a href="#transaksi" class="svg-icon"
                            aria-expanded="{{ request()->routeIs('pembelian.*', 'pesanan-jasa.*') ? 'true' : 'false' }}">
                            <i>
                                <svg class="svg-icon" id="mm-transaksi-1" width="20"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                                </svg>
                            </i>
                            <span class="ms-2">Transaksi</span>
                            <i class="fa-solid fa-chevron-right mm-arrow-right arrow-active"></i>
                            <i class="fa-solid fa-chevron-down mm-arrow-right arrow-hover"></i>
                        </a>
                        <ul id="transaksi"
                            class="submenu {{ request()->routeIs('pembelian.*', 'pesanan-jasa.*') ? 'show' : '' }}">
                            @can('viewAny', App\Models\PesananPembelian::class)
                                <li class="{{ request()->routeIs('pembelian.*') ? 'active' : '' }}">
                                    <a href="{{ route('pembelian.index') }}" class="svg-icon">
                                        <i>
                                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" width="20">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" />
                                            </svg>
                                        </i>
                                        <span class="">Pembelian (PO)</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\PesananJasa::class)
                                <li class="{{ request()->routeIs('pesanan-jasa.*') ? 'active' : '' }}">
                                    <a href="{{ route('pesanan-jasa.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-file-signature"></i><span>PO Jasa</span>
                                    </a>
                                </li>
                            @endcan
                            @if (Auth::user()->can('operateWarehouse') || Auth::user()->can('viewWmsControl'))
                                <li class="{{ request()->routeIs('pengeluaran-barang.*') ? 'active' : '' }}">
                                    <a href="{{ route('pengeluaran-barang.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-right-left"></i><span>Barang Keluar &amp; Titipan</span>
                                    </a>
                                </li>
                            @endif
                        </ul>
                    </li>
                @endif

                @if (Auth::user()->can('viewAny', App\Models\PenerimaanBarang::class) || Auth::user()->can('viewAny', App\Models\PenerimaanJasa::class) || Auth::user()->can('viewAny', App\Models\ReturPembelian::class))
                    <li class="{{ request()->routeIs('penerimaan-barang.*', 'penerimaan-jasa.*', 'retur-pembelian.*') ? 'active' : '' }}">
                        <a href="#penerimaan" class="svg-icon"
                            aria-expanded="{{ request()->routeIs('penerimaan-barang.*', 'penerimaan-jasa.*', 'retur-pembelian.*') ? 'true' : 'false' }}">
                            <i>
                                <svg class="svg-icon" id="mm-penerimaan" width="20"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </i>
                            <span class="ms-2">Penerimaan</span>
                            <i class="fa-solid fa-chevron-right mm-arrow-right arrow-active"></i>
                            <i class="fa-solid fa-chevron-down mm-arrow-right arrow-hover"></i>
                        </a>
                        <ul id="penerimaan"
                            class="submenu {{ request()->routeIs('penerimaan-barang.*', 'penerimaan-jasa.*', 'retur-pembelian.*') ? 'show' : '' }}">
                            @can('viewAny', App\Models\PenerimaanBarang::class)
                                <li class="{{ request()->routeIs('penerimaan-barang.*') ? 'active' : '' }}">
                                    <a href="{{ route('penerimaan-barang.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-boxes-stacked"></i><span>Penerimaan Barang</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\PenerimaanJasa::class)
                                <li class="{{ request()->routeIs('penerimaan-jasa.*') ? 'active' : '' }}">
                                    <a href="{{ route('penerimaan-jasa.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-clipboard-check"></i><span>Penerimaan Jasa</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\ReturPembelian::class)
                                <li class="{{ request()->routeIs('retur-pembelian.*') ? 'active' : '' }}">
                                    <a href="{{ route('retur-pembelian.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-rotate-left"></i><span>Retur Pembelian</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @can('viewAny', App\Models\PemakaianBarang::class)
                    <li class="{{ request()->routeIs('pemakaian-barang.*') ? 'active' : '' }}">
                        <a href="{{ route('pemakaian-barang.index') }}" class="svg-icon">
                            <i>
                                <svg class="svg-icon" id="mm-npk" width="20" xmlns="http://www.w3.org/2000/svg"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                </svg>
                            </i>
                            <span class="ms-2">Pemakaian Barang</span>
                        </a>
                    </li>
                @endcan

                @if (auth()->check() && (
                    auth()->user()->can('viewAny', App\Models\Gudang::class) ||
                    auth()->user()->can('viewAny', App\Models\StokGudang::class) ||
                    auth()->user()->can('viewAny', App\Models\TransferGudang::class) ||
                    auth()->user()->can('viewAny', App\Models\PemeriksaanConsider::class) ||
                    auth()->user()->can('viewAny', App\Models\MutasiStok::class) ||
                    auth()->user()->can('viewAny', App\Models\PengaturanBahanGudang::class) ||
                    auth()->user()->can('viewAny', App\Models\PembagianGudang::class)
                    || auth()->user()->can('viewWmsControl')
                ))
                    <li class="{{ request()->routeIs('gudangs.*','stok-gudangs.*','pembagian-gudangs.*','pengaturan-bahan-gudangs.*','transfer-gudangs.*','pemeriksaan-considers.*','mutasi-stoks.*','rekonsiliasi-gudangs.*','wms-control.*') ? 'active' : '' }}">
                        <a href="#multi-gudang" class="svg-icon">
                            <i class="fa-solid fa-warehouse"></i><span class="ms-2">Multi Gudang</span>
                        </a>
                        <ul id="multi-gudang" class="submenu {{ request()->routeIs('gudangs.*','stok-gudangs.*','pembagian-gudangs.*','pengaturan-bahan-gudangs.*','transfer-gudangs.*','pemeriksaan-considers.*','mutasi-stoks.*','rekonsiliasi-gudangs.*','wms-control.*') ? 'show' : '' }}">
                            @can('viewAny', App\Models\Gudang::class)
                                <li><a href="{{ route('gudangs.index') }}"><span>Master Gudang</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\StokGudang::class)
                                <li><a href="{{ route('stok-gudangs.index') }}"><span>Saldo & Kartu Stok</span></a></li>
                            @endcan
                            @can('reconcile', App\Models\StokGudang::class)<li><a href="{{ route('rekonsiliasi-gudangs.index') }}"><span>Rekonsiliasi</span></a></li>@endcan
                            <li><a href="{{ route('wms-control.index') }}"><span>WMS Control Center</span></a></li>
                            <li><a href="{{ route('antrean-kerja.index') }}"><span>Antrean Kerja Saya</span></a></li>
                            @can('viewAny', App\Models\PengirimanSubkontrak::class)
                                <li class="{{ request()->routeIs('subkontrak.*') ? 'active' : '' }}">
                                    <a href="{{ route('subkontrak.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-hand-holding-hand"></i><span>Subkontrak</span>
                                    </a>
                                </li>
                            @endcan
                            @can('viewAny', App\Models\CrossDock::class)
                                <li class="{{ request()->routeIs('cross-dock.*') ? 'active' : '' }}">
                                    <a href="{{ route('cross-dock.index') }}" class="svg-icon">
                                        <i class="fa-solid fa-right-left"></i><span>Cross Dock</span>
                                    </a>
                                </li>
                            @endcan
                            @can('operateWarehouse')
                                <li><a href="{{ route('gelombang-pengambilan.index') }}"><span>Gelombang Pengambilan</span></a></li>
                                <li><a href="{{ route('slotting.index') }}"><span>Saran Slotting</span></a></li>
                                <li><a href="{{ route('kit.index') }}"><span>Kitting &amp; Bundling</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\TransferGudang::class)
                                <li><a href="{{ route('transfer-gudangs.index') }}"><span>Transfer Gudang</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\PemeriksaanConsider::class)
                                <li><a href="{{ route('pemeriksaan-considers.index') }}"><span>Pemeriksaan Consider</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\MutasiStok::class)
                                <li><a href="{{ route('mutasi-stoks.index') }}"><span>Mutasi Stok</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\PengaturanBahanGudang::class)
                                <li><a href="{{ route('pengaturan-bahan-gudangs.index') }}"><span>Planning Gudang</span></a></li>
                            @endcan
                            @can('viewAny', App\Models\PembagianGudang::class)
                                <li><a href="{{ route('pembagian-gudangs.index') }}"><span>Pembagian Gudang</span></a></li>
                            @endcan
                        </ul>
                    </li>
                @endif

                @can('viewAny', App\Models\User::class)
                    <li class="{{ request()->routeIs('user.*') ? 'active' : '' }}">
                        <a href="#administrasi" class="svg-icon">
                            <i class="fa-solid fa-users-gear"></i><span class="ms-2">Administrasi</span>
                        </a>
                        <ul id="administrasi" class="submenu {{ request()->routeIs('user.*') ? 'show' : '' }}">
                            <li><a href="{{ route('user.index') }}"><span>Manajemen Pengguna</span></a></li>
                            <li><a href="{{ route('user.login-audit') }}"><span>Audit Login</span></a></li>
                        </ul>
                    </li>
                @endcan

                @can('viewAny', App\Models\StockOpname::class)
                    <li class="{{ request()->routeIs('stock-opname.*') ? 'active' : '' }}">
                        <a href="#stock-opname" class="svg-icon">
                            <i class="fa-solid fa-clipboard-check"></i>
                            <span class="ms-2">Stock Opname</span>
                        </a>
                        <ul id="stock-opname" class="submenu {{ request()->routeIs('stock-opname.*') ? 'show' : '' }}">
                            <li><a href="{{ route('stock-opname.index') }}"><span>Daftar Opname</span></a></li>
                            <li><a href="{{ route('stock-opname.siklus') }}"><span>Cycle Count ABC</span></a></li>
                        </ul>
                    </li>
                @endcan

                @can('viewAny', App\Models\FakturPembelian::class)
                    <li class="{{ request()->routeIs('faktur-pembelian.*') ? 'active' : '' }}">
                        <a href="{{ route('faktur-pembelian.index') }}" class="svg-icon">
                            <i>
                                <svg class="svg-icon" id="mm-faktur-pembelian" width="20"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </i>
                            <span class="ms-2">Faktur Pembelian</span>
                        </a>
                    </li>
                @endcan
            </ul>
        </nav>
    </div>
</div>
