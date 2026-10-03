<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceipt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = ['receipt_no', 'folio_item_id', 'amount_cents', 'status', 'settled_at'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function folioItem(): BelongsTo
    {
        return $this->belongsTo(FolioItem::class);
    }
}
