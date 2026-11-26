<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 0;
    public const ROLE_PURCHASING = 1;
    public const ROLE_FINANCE = 2;
    public const ROLE_WAREHOUSE = 3;
    public const ROLE_ACCOUNTING = 4;
    public const ROLE_PRODUCTION = 5;
    public const ROLE_ACCOUNTING_MANAGER = 6;

    public const ROLE_NAMES = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_PURCHASING => 'Purchasing',
        self::ROLE_FINANCE => 'Finance',
        self::ROLE_WAREHOUSE => 'Warehouse',
        self::ROLE_ACCOUNTING => 'Accounting',
        self::ROLE_PRODUCTION => 'Produksi',
        self::ROLE_ACCOUNTING_MANAGER => 'Accounting Manager',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'type' => 'integer',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'type');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'deactivated_by');
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBerperan($query, array|int $roles)
    {
        return $query->whereIn('type', (array) $roles);
    }

    public function pembagianGudangs(): HasMany
    {
        return $this->hasMany(PembagianGudang::class, 'user_id');
    }

    public function isSuperAdmin(): bool
    {
        return (int) $this->type === self::ROLE_SUPER_ADMIN;
    }

    public function isPurchasing(): bool
    {
        return (int) $this->type === self::ROLE_PURCHASING;
    }

    public function isFinance(): bool
    {
        return (int) $this->type === self::ROLE_FINANCE;
    }

    public function isWarehouse(): bool
    {
        return (int) $this->type === self::ROLE_WAREHOUSE;
    }

    public function isAccounting(): bool
    {
        return (int) $this->type === self::ROLE_ACCOUNTING;
    }

    public function isProduction(): bool
    {
        return (int) $this->type === self::ROLE_PRODUCTION;
    }

    public function isAccountingManager(): bool
    {
        return (int) $this->type === self::ROLE_ACCOUNTING_MANAGER;
    }

    public function isWarehouseOperator(): bool
    {
        return $this->hasAnyRole([self::ROLE_WAREHOUSE, self::ROLE_PRODUCTION]);
    }

    public function hasAnyRole(array $roles): bool
    {
        return in_array((int) $this->type, $roles, true);
    }

    public function accessibleGudangIds(?string $ability = null): array
    {
        $gudangQuery = Gudang::query()->where('aktif', true);

        if ($ability !== null) {
            $gudangQuery->where($this->gudangAbilityColumn($ability), true);
        }

        if ($this->isSuperAdmin()) {
            return $gudangQuery->pluck('id')->all();
        }

        if (!$this->isWarehouseOperator()) {
            return [];
        }

        if ($this->relationLoaded('pembagianGudangs')) {
            return $this->pembagianGudangs
                ->when($ability !== null, fn($rows) => $rows->where($this->assignmentAbilityColumn($ability), true))
                ->pluck('gudang_id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $assignmentQuery = $this->pembagianGudangs();

        if ($ability !== null) {
            $assignmentQuery->where($this->assignmentAbilityColumn($ability), true);
        }

        return $gudangQuery
            ->whereIn('id', $assignmentQuery->pluck('gudang_id'))
            ->pluck('id')
            ->all();
    }

    public function canAccessGudang(?int $gudangId, ?string $ability = null): bool
    {
        if (!$gudangId) {
            return false;
        }

        return in_array($gudangId, $this->accessibleGudangIds($ability), true);
    }

    public function getRoleNameAttribute(): string
    {
        return self::ROLE_NAMES[(int) $this->type] ?? 'Unknown';
    }

    private function gudangAbilityColumn(string $ability): string
    {
        return match ($ability) {
            'receive' => 'boleh_penerimaan',
            'npk' => 'boleh_npk',
            'transfer' => 'boleh_transfer',
            'opname' => 'boleh_opname',
            default => 'aktif',
        };
    }

    private function assignmentAbilityColumn(string $ability): string
    {
        return match ($ability) {
            'receive' => 'boleh_menerima',
            'npk' => 'boleh_npk',
            'transfer' => 'boleh_transfer',
            'opname' => 'boleh_opname',
            default => 'gudang_id',
        };
    }
}
