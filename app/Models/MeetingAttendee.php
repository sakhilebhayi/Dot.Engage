<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAttendee extends Model
{
    /** A browser that has not sent a heartbeat for this long is treated as gone. */
    public const PRESENCE_SECONDS = 20;

    protected $fillable = [
        'meeting_id', 'participant_id', 'user_id', 'name', 'is_host', 'state', 'client_id', 'requested_at', 'joined_at',
        'left_at', 'last_seen_at', 'mic_on', 'cam_on', 'sharing', 'quality', 'join_count',
    ];

    protected function casts(): array
    {
        return [
            'is_host' => 'boolean',
            'mic_on' => 'boolean', 'cam_on' => 'boolean', 'sharing' => 'boolean',
            'requested_at' => 'datetime', 'joined_at' => 'datetime', 'left_at' => 'datetime', 'last_seen_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class);
    }

    /** In the call right now: joined and heard from recently. */
    public function isPresent(): bool
    {
        return $this->state === 'joined'
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::PRESENCE_SECONDS));
    }
}
