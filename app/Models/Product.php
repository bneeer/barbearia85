<?php

namespace App\Models;

use App\Concerns\BelongsToBarbershop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToBarbershop, SoftDeletes;

    protected $fillable = [
        'barbershop_id',
        'name',
        'brand',
        'description',
        'price',
        'stock_quantity',
        'min_stock',
        'average_cost_milli_cents',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'min_stock' => 'integer',
            'average_cost_milli_cents' => 'integer',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }
}
