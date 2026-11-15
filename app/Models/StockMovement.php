<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'barbershop_id',
        'user_id',
        'appointment_checkout_id',
        'stockable_name',
        'unit',
        'type',
        'quantity',
        'balance_before',
        'balance_after',
        'reason',
        'unit_cost_milli_cents',
        'cost_total_cents',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_before' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost_milli_cents' => 'integer',
            'cost_total_cents' => 'integer',
        ];
    }

    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
