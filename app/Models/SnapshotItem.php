<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotItem extends Model
{
    protected $fillable = [
        'snapshot_id', 'folio_id', 'folio_item_id', 'type', 'description',
        'amount_cents', 'business_date', 'posted_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'business_date' => \App\Casts\DateOnlyString::class,
            'posted_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NightAuditSnapshot::class, 'snapshot_id');
    }
}
