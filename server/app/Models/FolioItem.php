<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioItem extends Model
{
    public const KIND_ROOM = 'room_charge';
    public const KIND_ADDON = 'addon';

    protected $fillable = [
        'folio_id', 'kind', 'description', 'amount_cents', 'business_date', 'occurred_at',
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
}
