<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BarbershopScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($barbershopId = app('currentBarbershopId')) {
            $builder->where(
                $model->getTable().'.barbershop_id',
                $barbershopId
            );
        }
    }
}
