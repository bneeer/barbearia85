<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'loyalty_account_id',
        'client_id',
        'appointment_checkout_id',
        'created_by',
        'type',
        'stamps',
        'reward_description',
    ];

    protected function casts(): array
    {
        return ['stamps' => 'integer'];
    }
}
