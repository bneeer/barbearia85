<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'suggested_due_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'identity_verified' => 'boolean',
            'internal_search_done' => 'boolean',
            'external_search_done' => 'boolean',
            'shared_agents_done' => 'boolean',
            'response_sent' => 'boolean',
        ];
    }

    public function clients()
    {
        return $this->belongsToMany(Client::class, 'privacy_request_clients')->withTrashed()
            ->withPivot(['linked_by', 'created_at']);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events()
    {
        return $this->hasMany(PrivacyRequestEvent::class)->orderBy('id');
    }
}
