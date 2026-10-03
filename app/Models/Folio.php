<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folio extends Model
{
    protected $fillable = ['stay_id', 'folio_no', 'guest_name', 'room_no', 'status'];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FolioItem::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(FolioVerification::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    /**
     * 当前余额（分）：全部已结算条目合计。pending 条目不计入。
     */
    public function balanceCents(): int
    {
        return (int) $this->items()->where('status', FolioItem::STATUS_SETTLED)->sum('amount_cents');
    }
}
