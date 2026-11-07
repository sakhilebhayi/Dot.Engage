<?php

namespace App\Models;

use App\Enums\EngagementKind;
use App\Enums\EngagementState;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Engagement extends Model
{
    use HasTeamScope, SoftDeletes;

    protected $fillable = [
        'team_id', 'reference', 'title', 'kind', 'description', 'owner_id', 'state', 'state_changed_at',
        'expires_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'declined_at', 'decline_reason', 'expired_at',
        'agreed_version_id', 'agreed_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => EngagementState::class,
            'kind' => EngagementKind::class,
            'state_changed_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'agreed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(EngagementParty::class)->orderBy('position');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EngagementParticipant::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EngagementMessage::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DocumentComment::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Amendment::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(EngagementApproval::class);
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(SigningEnvelope::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(EngagementAuditEvent::class);
    }

    /** The document the parties are working on (an engagement may carry several; the newest is "primary"). */
    public function primaryContract(): ?Contract
    {
        return $this->contracts()->orderByDesc('id')->first();
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    public static function nextReference(): string
    {
        $year = now()->format('Y');
        $prefix = 'ENG-'.$year.'-';
        $last = static::withoutGlobalScopes()->withTrashed()->where('reference', 'like', $prefix.'%')->orderByDesc('id')->value('reference');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }
}
