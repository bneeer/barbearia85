<?php

namespace App\Concerns;

use App\Models\Scopes\BarbershopScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToBarbershop
{
    protected static function bootBelongsToBarbershop()
    {
        // aplica o scope automaticamente
        static::addGlobalScope(new BarbershopScope);

        // seta automaticamente no create
        static::creating(function (Model $model) {
            if (!$model->barbershop_id && app()->bound('currentBarbershopId')) {
                $model->barbershop_id = app('currentBarbershopId');
            }
        });
    }
}
