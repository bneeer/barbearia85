<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    protected $fillable = [
        'barbershop_id',
        'opened_by',
        'closed_by',
        'opening_amount_cents',
        'opened_at',
        'closed_at',
        'expected_cash_cents',
        'expected_pix_cents',
        'expected_credit_card_cents',
        'expected_debit_card_cents',
        'counted_cash_cents',
        'counted_pix_cents',
        'counted_credit_card_cents',
        'counted_debit_card_cents',
        'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount_cents' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'expected_cash_cents' => 'integer',
            'expected_pix_cents' => 'integer',
            'expected_credit_card_cents' => 'integer',
            'expected_debit_card_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'counted_pix_cents' => 'integer',
            'counted_credit_card_cents' => 'integer',
            'counted_debit_card_cents' => 'integer',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function withdrawals()
    {
        return $this->hasMany(CashRegisterWithdrawal::class);
    }

    public function checkouts()
    {
        return $this->hasMany(AppointmentCheckout::class);
    }
}
