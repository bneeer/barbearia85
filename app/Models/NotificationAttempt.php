<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
