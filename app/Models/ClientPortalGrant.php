<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientPortalGrant extends Model
{
    protected $fillable = ['email_key', 'email', 'token_hash', 'notification_id', 'expires_at'];

    protected $hidden = ['email_key', 'email', 'token_hash'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'immutable_datetime'];
    }
}
