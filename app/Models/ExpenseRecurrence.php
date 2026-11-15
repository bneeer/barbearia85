<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseRecurrence extends Model
{
    protected $fillable = [
        'barbershop_id',
        'created_by',
        'title',
        'category',
        'amount_cents',
        'due_day',
        'starts_on',
        'ends_on',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'due_day' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'recurrence_id');
    }
}
