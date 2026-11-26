<div role="tablist" class="tabs tabs-boxed mb-4">
    <a role="tab" href="{{ route('financial-statements.neraca-saldo') }}" class="tab {{ request()->routeIs('financial-statements.neraca-saldo') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-scale-balanced"></i>&nbsp;Neraca Saldo
    </a>
    <a role="tab" href="{{ route('financial-statements.buku-besar') }}" class="tab {{ request()->routeIs('financial-statements.buku-besar') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-book"></i>&nbsp;Buku Besar
    </a>
    <a role="tab" href="{{ route('financial-statements.laba-rugi') }}" class="tab {{ request()->routeIs('financial-statements.laba-rugi') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-chart-line"></i>&nbsp;Laba Rugi
    </a>
    <a role="tab" href="{{ route('financial-statements.neraca') }}" class="tab {{ request()->routeIs('financial-statements.neraca') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-building-columns"></i>&nbsp;Neraca
    </a>
    <a role="tab" href="{{ route('financial-statements.perubahan-ekuitas') }}" class="tab {{ request()->routeIs('financial-statements.perubahan-ekuitas') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-arrow-trend-up"></i>&nbsp;Perubahan Ekuitas
    </a>
    <a role="tab" href="{{ route('financial-statements.arus-kas') }}" class="tab {{ request()->routeIs('financial-statements.arus-kas') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-money-bill-transfer"></i>&nbsp;Arus Kas
    </a>
    <a role="tab" href="{{ route('financial-statements.calk') }}" class="tab {{ request()->routeIs('financial-statements.calk') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-book-open"></i>&nbsp;CALK
    </a>
    <a role="tab" href="{{ route('financial-statements.rekonsiliasi-fiskal') }}" class="tab {{ request()->routeIs('financial-statements.rekonsiliasi-fiskal') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-scale-unbalanced"></i>&nbsp;Rekonsiliasi Fiskal
    </a>
    <a role="tab" href="{{ route('financial-statements.pajak-penghasilan') }}" class="tab {{ request()->routeIs('financial-statements.pajak-penghasilan') ? 'tab-active' : '' }}">
        <i class="fa-solid fa-file-invoice-dollar"></i>&nbsp;PPh Badan
    </a>
</div>
