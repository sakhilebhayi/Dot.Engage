<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementParticipant extends Model
{
    protected $fillable = [
        'engagement_id', 'party_id', 'user_id', 'name', 'email', 'role', 'capabilities', 'required', 'invited_by',
        'invitation_sent_at', 'accepted_at', 'declined_at', 'decline_reason', 'revoked_at', 'access_token_hash',
        'token_expires_at', 'otp_hash', 'otp_sent_at', 'otp_expires_at', 'otp_attempts', 'verified_at', 'last_seen_at',
    ];

    protected $hidden = ['access_token_hash', 'otp_hash'];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'capabilities' => 'array',
            'required' => 'boolean',
            'invitation_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'otp_sent_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(EngagementParty::class, 'party_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && $this->declined_at === null;
    }

    /** Can this person do `$ability`? (Role set plus the capability flags.) */
    public function can(string $ability): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return match ($ability) {
            'approve' => $this->hasCapability('approve'),
            'sign' => $this->hasCapability('sign'),
            'host_meeting' => $this->hasCapability('host') || $this->role->allows('host_meeting'),
            default => $this->role->allows($ability),
        };
    }

    /** Joined the engagement: an internal member who was added, or an outside party who opened and verified the invitation. */
    public function hasJoined(): bool
    {
        return $this->accepted_at !== null;
    }
}
