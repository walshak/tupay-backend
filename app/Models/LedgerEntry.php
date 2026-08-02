<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $guarded = [];
    // ensure that amount is always an integer in response body
    protected $casts = [
        'amount' => 'integer',
    ];
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
