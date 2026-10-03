<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Adjustment extends Model
{
    public const TYPE_LATE_PAYMENT = 'late_payment';
    public const TYPE_ROOM_CHARGE_CORRECTION = 'room_charge_correction';
    public const TYPE_ADDON_CORRECTION = 'addon_correction';
    public const TYPE_REFUND_CORRECTION = 'refund_correction';

    public const TYPES = [
        self::TYPE_LATE_PAYMENT,
        self::TYPE_ROOM_CHARGE_CORRECTION,
        self::TYPE_ADDON_CORRECTION,
        self::TYPE_REFUND_CORRECTION,
    ];

    protected $fillable = [
        'adjustment_no', 'business_date', 'folio_id', 'original_item_id',
        'type', 'amount_cents', 'reason_code', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'business_date' => \App\Casts\DateOnlyString::class,
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** 原房账条目：财务可由此追到原交易。 */
    public function originalItem(): BelongsTo
    {
        return $this->belongsTo(FolioItem::class, 'original_item_id');
    }

    /** 调整在房账上生成的条目（落在当前开放营业日）。 */
    public function ledgerItem(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FolioItem::class, 'adjustment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
