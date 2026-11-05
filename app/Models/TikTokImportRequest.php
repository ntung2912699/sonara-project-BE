<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'video_url', 'rights_attested_at', 'status', 'review_note', 'track_id'])]
class TikTokImportRequest extends Model
{
    protected $table = 'tiktok_import_requests';

    protected function casts(): array
    {
        return ['rights_attested_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
