<?php

namespace App\Models;

use App\Concerns\BelongsToBarbershop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supply extends Model
{
    use BelongsToBarbershop, SoftDeletes;

    protected $fillable = [
        'barbershop_id',
        'name',
        'brand',
        'description',
        'unit',
        'stock_quantity',
        'min_stock',
    ];

    protected function casts(): array
    {
        return [
            'stock_quantity' => 'decimal:3',
            'min_stock' => 'decimal:3',
        ];
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }
}
