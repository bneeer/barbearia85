<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'barbershop_id',
        'recurrence_id',
        'created_by',
        'title',
        'category',
        'amount_cents',
        'competence_date',
        'due_date',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'competence_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function recurrence()
    {
        return $this->belongsTo(ExpenseRecurrence::class, 'recurrence_id');
    }

    public function payments()
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
