<?php

namespace App\Policies;

use App\Models\PengirimanSubkontrak;
use App\Models\User;

class PengirimanSubkontrakPolicy
{
    private function operator(User $user): bool
    {
        return $user->isWarehouseOperator();
    }

    public function viewAny(User $user): bool
    {
        return $this->operator($user) || $user->isPurchasing();
    }

    public function view(User $user, PengirimanSubkontrak $pengiriman): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->operator($user);
    }

    public function terima(User $user, PengirimanSubkontrak $pengiriman): bool
    {
        return $this->operator($user)
            && $pengiriman->masihDiVendor()
            && $user->canAccessGudang((int) $pengiriman->gudang_asal_id, 'transfer');
    }
}
