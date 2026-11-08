<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class BarbershopPhoto extends Model
{
    protected $fillable = ['path'];

    protected $appends = ['url'];

    protected function url(): Attribute
    {
        return Attribute::get(fn () => route('barbershop-photos.show', $this));
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }
}
