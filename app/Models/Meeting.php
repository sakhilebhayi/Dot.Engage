<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $fillable = [
        'engagement_id', 'created_by', 'title', 'kind', 'status', 'provider', 'room_key', 'scheduled_for', 'duration_minutes',
        'waiting_room', 'locked', 'started_at', 'ended_at', 'ended_reason', 'contract_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'waiting_room' => 'boolean',
            'locked' => 'boolean',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(MeetingSignal::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isOver(): bool
    {
        return in_array($this->status, ['ended', 'cancelled'], true);
    }
}
