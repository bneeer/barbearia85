<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegisterWithdrawal extends Model
{
    protected $fillable = ['user_id', 'amount_cents', 'reason'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
