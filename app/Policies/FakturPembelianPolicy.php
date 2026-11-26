<?php

namespace App\Policies;

use App\Models\FakturPembelian;
use App\Models\User;

class FakturPembelianPolicy
{
    private function canViewInvoices(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_PURCHASING, User::ROLE_FINANCE, User::ROLE_ACCOUNTING, User::ROLE_ACCOUNTING_MANAGER]);
    }

    private function canManageInvoices(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_PURCHASING, User::ROLE_ACCOUNTING]);
    }

    private function canPayInvoices(User $user): bool
    {
        return $user->isFinance();
    }

    private function canApproveInvoices(User $user): bool
    {
        return $user->isAccountingManager();
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewInvoices($user);
    }

    public function view(User $user, FakturPembelian $invoice): bool
    {
        return $this->canViewInvoices($user);
    }

    public function create(User $user): bool
    {
        return $this->canManageInvoices($user);
    }

    public function update(User $user, FakturPembelian $invoice): bool
    {
        return $this->canManageInvoices($user)
            && $invoice->status !== FakturPembelian::VOID
            && !$invoice->payments()->exists()
            && $invoice->match_status === 'PENDING'
            && !\App\Models\Jurnal::where('sumber_transaksi', 'INVOICE_SUPPLIER')->where('reff_id', $invoice->id)->exists();
    }

    public function delete(User $user, FakturPembelian $invoice): bool
    {
        return $this->canManageInvoices($user) && $invoice->status !== FakturPembelian::VOID && !$invoice->payments()->exists();
    }

    public function pay(User $user, FakturPembelian $invoice): bool
    {
        return $this->canPayInvoices($user)
            && $invoice->status !== FakturPembelian::VOID
            && $invoice->status !== FakturPembelian::PENDING_APPROVAL
            && (float) $invoice->sisa_tagihan > 0;
    }

    public function voidPayment(User $user, FakturPembelian $invoice): bool
    {
        return $this->canPayInvoices($user) && $invoice->status !== FakturPembelian::VOID;
    }

    public function approve(User $user, FakturPembelian $invoice): bool
    {
        return $this->canApproveInvoices($user) && $invoice->status === FakturPembelian::PENDING_APPROVAL;
    }
}
