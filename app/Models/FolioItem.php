<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioItem extends Model
{
    public const TYPE_ROOM_CHARGE = 'room_charge';
    public const TYPE_ADDON = 'addon';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const STATUS_SETTLED = 'settled';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUPERSEDED = 'superseded';

    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';

    protected $fillable = [
        'folio_id', 'type', 'description', 'amount_cents', 'business_date',
        'posted_at', 'status', 'method', 'adjustment_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'business_date' => \App\Casts\DateOnlyString::class,
            'posted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function receipt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }
}
