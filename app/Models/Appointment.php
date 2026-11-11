<?php

namespace App\Models;

use App\Concerns\BelongsToBarbershop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use BelongsToBarbershop, HasFactory, SoftDeletes;

    protected $fillable = [
        'barbershop_id',
        'client_id',
        'user_id',
        'service_id',
        'start_time',
        'end_time',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'email_notifications_enabled' => 'boolean',
    ];

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function barber()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'appointment_service')->withTrashed();
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function checkout()
    {
        return $this->hasOne(AppointmentCheckout::class);
    }
}
