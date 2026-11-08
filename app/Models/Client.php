<?php

namespace App\Models;

use App\Concerns\BelongsToBarbershop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToBarbershop, HasFactory, SoftDeletes;

    protected $fillable = [
        'barbershop_id',
        'name',
        'phone',
        'email',
        'notes',
    ];

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
