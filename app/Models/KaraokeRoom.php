<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code',
    'created_by',
    'joined_by',
    'beat_type',
    'beat_id',
    'beat_title',
    'beat_artist',
    'status',
    'starts_at',
    'expires_at',
])]
class KaraokeRoom extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'webrtc_offer' => 'array',
            'webrtc_answer' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'joined_by');
    }
}
