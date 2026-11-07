<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SigningSigner extends Model
{
    protected $fillable = [
        'envelope_id', 'participant_id', 'sign_order', 'status', 'notified_at', 'viewed_at', 'signed_at', 'declined_at',
        'decline_reason', 'consent_at', 'consent_text_sha256', 'method', 'typed_name', 'signature_path', 'initials_path',
        'identity_method', 'identity_verified_at', 'signed_email', 'ip', 'user_agent', 'completion_key', 'reminder_count',
        'last_reminded_at', 'reassigned_to_id',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'consent_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class, 'participant_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SigningField::class, 'signer_id');
    }

    public function hasSigned(): bool
    {
        return $this->status === 'signed';
    }

    /** Still expected to sign (not signed, declined or replaced). */
    public function isOutstanding(): bool
    {
        return in_array($this->status, ['pending', 'notified', 'viewed'], true);
    }
}
