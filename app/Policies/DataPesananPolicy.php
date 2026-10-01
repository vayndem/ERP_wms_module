<?php

namespace App\Policies;

use App\Models\DataPesanan;
use App\Models\User;

class DataPesananPolicy
{
    private function pengamat(User $user): bool
    {
        return $user->isProduction()
            || $user->isWarehouseOperator()
            || $user->isPurchasing()
            || $user->isAccounting();
    }

    public function viewAny(User $user): bool
    {
        return $this->pengamat($user);
    }

    public function view(User $user, DataPesanan $pesanan): bool
    {
        return $this->pengamat($user);
    }

    public function create(User $user): bool
    {
        return $user->isProduction() || $user->isPurchasing();
    }

    public function rilis(User $user, DataPesanan $pesanan): bool
    {
        return ($user->isProduction() || $user->isPurchasing()) && $pesanan->status === DataPesanan::DRAFT;
    }

    public function selesaikan(User $user, DataPesanan $pesanan): bool
    {
        return $user->isProduction() && $pesanan->status === DataPesanan::DIRILIS;
    }

    public function catatJamKerja(User $user, DataPesanan $pesanan): bool
    {
        return $user->isProduction() && $pesanan->menerimaBiaya();
    }

    public function batalkan(User $user, DataPesanan $pesanan): bool
    {
        return ($user->isProduction() || $user->isPurchasing())
            && !$pesanan->sudahSelesai()
            && $pesanan->status !== DataPesanan::DIBATALKAN;
    }
}
