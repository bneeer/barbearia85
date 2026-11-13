<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentCheckout extends Model
{
    protected $fillable = [
        'appointment_id', 'cash_register_id', 'created_by', 'services', 'products',
        'services_total_cents', 'products_total_cents', 'discount_cents',
        'total_cents', 'payment_method', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'products' => 'array',
            'services_total_cents' => 'integer',
            'products_total_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }
}
