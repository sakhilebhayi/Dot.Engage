<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SigningEnvelope extends Model
{
    protected $fillable = [
        'engagement_id', 'contract_id', 'contract_version_id', 'document_sha256', 'mode', 'status', 'signature_level',
        'created_by', 'sent_at', 'expires_at', 'reminder_days', 'all_signed_at', 'finalised_at', 'cancelled_at',
        'cancel_reason', 'declined_at', 'final_path', 'final_sha256', 'certificate_path', 'certificate_sha256',
        'stamped', 'evidence', 'evidence_sha256', 'evidence_seal', 'seal_public_key', 'timestamp_token',
        'timestamp_authority', 'finalise_attempts', 'finalise_error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'all_signed_at' => 'datetime',
            'finalised_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'declined_at' => 'datetime',
            'stamped' => 'boolean',
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

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'contract_version_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SigningSigner::class, 'envelope_id')->orderBy('sign_order')->orderBy('id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(SigningField::class, 'envelope_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'sent';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'expired', 'declined'], true);
    }
}
