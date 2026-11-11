<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable delivery outbox. Authentication secrets and rendered content are encrypted.
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
        'user_id', 'barbershop_id', 'recipient', 'audience', 'event_id',
        'deduplication_key', 'secret', 'message', 'provider_id', 'delivery_status',
        'attempts', 'failure_code', 'next_attempt_at', 'locked_until', 'lock_token',
        'first_attempt_at', 'expires_at', 'delivery_updated_at',
    ];

    protected $hidden = ['payload', 'secret', 'message', 'lock_token'];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'secret' => 'encrypted:array',
        'message' => 'encrypted:array',
        'next_attempt_at' => 'datetime',
        'locked_until' => 'datetime',
        'first_attempt_at' => 'datetime',
        'expires_at' => 'datetime',
        'delivery_updated_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function attemptsHistory()
    {
        return $this->hasMany(NotificationAttempt::class)->orderBy('id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
