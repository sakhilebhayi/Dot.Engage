<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Amendment extends Model
{
    protected $fillable = [
        'engagement_id', 'contract_id', 'base_version_id', 'applied_in_version_id', 'participant_id', 'user_id',
        'author_name', 'type', 'clause_ref', 'page', 'original_text', 'proposed_text', 'explanation', 'status',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class, 'reviewed_by');
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'base_version_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'proposed';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'replace' => 'Replace text',
            'add' => 'Add clause',
            'remove' => 'Remove clause',
            default => ucfirst($this->type),
        };
    }
}
