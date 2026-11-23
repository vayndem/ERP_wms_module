@php
    $statusLabels = [
        'SEMUA' => 'Semua',
        'DRAFT' => 'Draf',
        'POSTED' => 'Diposting',
        'VOID' => 'Dibatalkan',
        'DITERBITKAN' => 'Diterbitkan',
        'DIBATALKAN' => 'Dibatalkan',
        'DIGANTI' => 'Diganti',
    ];
@endphp
{{ $statusLabels[$statusCode] ?? ucfirst(strtolower((string) $statusCode)) }}
