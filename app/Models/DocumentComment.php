<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentComment extends Model
{
    protected $fillable = [
        'engagement_id', 'contract_id', 'contract_version_id', 'parent_id', 'participant_id', 'user_id', 'author_name',
        'kind', 'body', 'page', 'quote', 'x', 'y', 'w', 'h', 'status', 'resolved_by', 'resolved_at', 'discussed_at', 'mentions',
    ];

    protected function casts(): array
    {
        return [
            'mentions' => 'array',
            'resolved_at' => 'datetime',
            'discussed_at' => 'datetime',
            'x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float',
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

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
