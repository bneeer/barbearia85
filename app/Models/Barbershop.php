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
        'cancellation_notice_hours',
        'no_show_tolerance_minutes',
        'loyalty_enabled',
        'loyalty_stamp_goal',
        'loyalty_reward_description',
        'loyalty_enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'phones' => 'array',
            'is_open' => 'boolean',
            'cancellation_notice_hours' => 'integer',
            'no_show_tolerance_minutes' => 'integer',
            'loyalty_enabled' => 'boolean',
            'loyalty_stamp_goal' => 'integer',
            'loyalty_enabled_at' => 'immutable_datetime',
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

    public function supplies()
    {
        return $this->hasMany(Supply::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class);
    }

    public function photos()
    {
        return $this->hasMany(BarbershopPhoto::class);
    }

    public function loyaltyAccounts()
    {
        return $this->hasMany(LoyaltyAccount::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function expenseRecurrences()
    {
        return $this->hasMany(ExpenseRecurrence::class);
    }
}
