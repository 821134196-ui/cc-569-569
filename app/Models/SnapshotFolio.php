<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnapshotFolio extends Model
{
    protected $fillable = [
        'snapshot_id', 'folio_id', 'folio_no', 'guest_name', 'room_no',
        'charge_cents', 'payment_cents', 'refund_cents', 'balance_cents',
        'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(NightAuditSnapshot::class, 'snapshot_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
