<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 快照中的房单明细：同样不可变。
 */
class SnapshotFolio extends Model
{
    protected $fillable = [
        'night_audit_snapshot_id', 'folio_id', 'folio_no', 'guest_name', 'room_no',
        'room_total_cents', 'addon_total_cents', 'payment_total_cents',
        'refund_total_cents', 'balance_cents', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('夜审快照明细不可修改'));
        static::deleting(fn () => throw new RuntimeException('夜审快照明细不可删除'));
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NightAuditSnapshot::class, 'night_audit_snapshot_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
