<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const METHOD_CASH = 'cash';
    public const METHOD_CARD = 'card';

    protected $fillable = [
        'folio_id', 'method', 'amount_cents', 'business_date', 'occurred_at', 'receipt_no',
    ];

    protected $casts = [
        'business_date' => 'date:Y-m-d',
        'occurred_at' => 'datetime',
        'amount_cents' => 'integer',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function isRefund(): bool
    {
        return $this->amount_cents < 0;
    }
}
