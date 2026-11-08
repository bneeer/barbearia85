<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Booking confirmations are an outbox: pending records snapshot the booking
 * details for a future email/WhatsApp worker. No delivery is performed yet.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'appointment_id',
        'payload',
        'client_id',
        'type',
        'template',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
