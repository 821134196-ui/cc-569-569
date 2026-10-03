<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NightAuditSnapshot extends Model
{
    protected $fillable = [
        'business_day_id', 'business_date',
        'room_charge_cents', 'addon_cents', 'cash_cents', 'card_cents',
        'refund_cents', 'adjustment_cents', 'net_cents',
        'folio_count', 'item_count', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => \App\Casts\DateOnlyString::class,
            'closed_at' => 'datetime',
        ];
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    public function folios(): HasMany
    {
        return $this->hasMany(SnapshotFolio::class, 'snapshot_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SnapshotItem::class, 'snapshot_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
