<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 调整单：append-only 流水，创建后不允许修改或删除，
 * 保证差额核对依据不被篡改。
 */
class Adjustment extends Model
{
    public const KIND_LATE_PAYMENT = 'late_payment';       // 迟到收款（支付回执晚到）
    public const KIND_LATE_CHARGE = 'late_charge';         // 迟到入账（跨午夜房费/延迟退房费/加购晚到）
    public const KIND_ROOM_CORRECTION = 'room_correction'; // 房费更正
    public const KIND_ADDON_CORRECTION = 'addon_correction'; // 加购更正

    protected $fillable = [
        'adjustment_no', 'folio_id', 'business_date', 'original_business_date',
        'kind', 'amount_cents', 'affects_revenue', 'reason',
        'original_item_id', 'original_payment_id', 'created_by',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'original_business_date' => 'date:Y-m-d',
        'amount_cents' => 'integer',
        'affects_revenue' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('调整单为只增流水，禁止修改'));
        static::deleting(fn () => throw new RuntimeException('调整单为只增流水，禁止删除'));
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(FolioItem::class, 'original_item_id');
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'original_payment_id');
    }
}
