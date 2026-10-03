<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stay extends Model
{
    public const STATUS_IN_HOUSE = 'in_house';
    public const STATUS_CHECKED_OUT = 'checked_out';

    protected $fillable = ['room_no', 'guest_name', 'check_in_at', 'check_out_at', 'status'];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
        ];
    }

    public function folio(): HasOne
    {
        return $this->hasOne(Folio::class);
    }
}
