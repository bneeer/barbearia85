<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $appends = ['avatar'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'barbershop_id',
        'role',
        'phone',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected function avatar(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path
            ? route('barber.photo', ['user' => $this->id, 'v' => hash('sha256', $this->photo_path)])
            : null);
    }

    public function barbershop()
    {
        return $this->belongsTo(Barbershop::class);
    }

    public function barbershops()
    {
        return $this->belongsToMany(Barbershop::class)->withTimestamps();
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'barber_service');
    }

    public function workingHours()
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function blockedTimes()
    {
        return $this->hasMany(BlockedTime::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isBarber(): bool
    {
        return in_array($this->role, ['barber', 'admin']);
    }
}
