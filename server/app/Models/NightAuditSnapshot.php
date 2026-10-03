<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * 夜审快照：封账瞬间营业日汇总的不可变记录。
 * 任何修改 / 删除尝试都会在模型层被拒绝。
 */
class NightAuditSnapshot extends Model
{
    protected $fillable = [
        'business_day_id',
        'room_total_cents', 'addon_total_cents',
        'cash_total_cents', 'card_total_cents', 'refund_total_cents',
        'adjustment_total_cents', 'net_total_cents',
        'folio_count', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('夜审快照不可修改'));
        static::deleting(fn () => throw new RuntimeException('夜审快照不可删除'));
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    public function folios(): HasMany
    {
        return $this->hasMany(SnapshotFolio::class);
    }
}
