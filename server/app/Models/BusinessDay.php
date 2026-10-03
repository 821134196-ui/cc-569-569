<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessDay extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = ['date', 'status', 'closed_at', 'closed_by'];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'closed_at' => 'datetime',
    ];

    public function snapshot(): HasOne
    {
        return $this->hasOne(NightAuditSnapshot::class);
    }

    public function folioChecks(): HasMany
    {
        return $this->hasMany(FolioCheck::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
