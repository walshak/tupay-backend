<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $guarded = [];
    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
    ];
    /**
     * @return BelongsTo<Wallet, LedgerEntry>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
