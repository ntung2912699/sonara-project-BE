<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable([
    'public_id',
    'name',
    'email',
    'phone',
    'channel',
    'password_hash',
    'code_hash',
    'expires_at',
    'last_sent_at',
    'attempts',
])]
class RegistrationOtp extends Model
{
    use Prunable;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now());
    }
}
