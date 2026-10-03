<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioCheck extends Model
{
    protected $fillable = ['folio_id', 'business_day_id', 'auditor', 'checked_at'];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }
}
