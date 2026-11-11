<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCancellationRequest extends Model
{
    protected $fillable = ['token_hash', 'email', 'appointments', 'cancelled_ids', 'allows_all', 'expires_at'];

    protected $hidden = ['token_hash', 'email', 'appointments', 'cancelled_ids'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'appointments' => 'encrypted:array',
            'cancelled_ids' => 'encrypted:array',
            'allows_all' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
