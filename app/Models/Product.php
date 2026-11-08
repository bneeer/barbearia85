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
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }
}
