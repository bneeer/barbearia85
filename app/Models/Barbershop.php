<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Barbershop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'description',
        'address',
        'zip_code',
        'phones',
        'opening_hours',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'phones' => 'array',
            'is_open' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function photos()
    {
        return $this->hasMany(BarbershopPhoto::class);
    }
}
