<?php

namespace App\Models;

use App\Concerns\BelongsToBarbershop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use BelongsToBarbershop, HasFactory, SoftDeletes;

    protected $fillable = [
        'barbershop_id',
        'name',
        'description',
        'duration',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function barbers()
    {
        return $this->belongsToMany(User::class, 'barber_service');
    }
}
