<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarberSalaryRate extends Model
{
    protected $fillable = [
        'barbershop_id',
        'user_id',
        'salary_base_cents',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'salary_base_cents' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function barber()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
