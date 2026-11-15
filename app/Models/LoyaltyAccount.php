<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyAccount extends Model
{
    protected $fillable = ['barbershop_id', 'identity_hash', 'stamps_balance'];

    protected function casts(): array
    {
        return ['stamps_balance' => 'integer'];
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
