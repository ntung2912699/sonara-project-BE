<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['track_id', 'user_id', 'reason', 'details', 'status', 'reviewed_by', 'reviewed_at'])]
class SongReport extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
