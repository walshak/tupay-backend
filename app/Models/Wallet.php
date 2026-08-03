<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @property int $balance
 */
class Wallet extends Model
{
    use HasFactory;

    protected $guarded = [];
    /**
     * @return HasMany<LedgerEntry>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
    /**
     * we calculate  balance on the fly using an indexed sql sum 
     * Accessible via $wallet->balance
     * @return Attribute<int, never>
     */
    protected function balance(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->ledgerEntries()
                ->selectRaw('COALESCE(SUM(CASE WHEN type = "credit" THEN amount ELSE -amount END), 0) as total')
                ->value('total') ?? 0,
        );
    }
}
