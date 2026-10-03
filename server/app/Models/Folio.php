<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folio extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CHECKED_OUT = 'checked_out';

    protected $fillable = [
        'folio_no', 'guest_name', 'room_no',
        'check_in_at', 'planned_check_out_at', 'actual_check_out_at',
        'nights', 'room_rate_cents', 'room_charges_posted', 'status',
    ];

    protected $casts = [
        'check_in_at' => 'datetime',
        'planned_check_out_at' => 'datetime',
        'actual_check_out_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FolioItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(FolioCheck::class);
    }
}
