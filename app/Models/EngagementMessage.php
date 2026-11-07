<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementMessage extends Model
{
    protected $fillable = ['engagement_id', 'participant_id', 'user_id', 'author_name', 'body', 'mentions', 'is_system'];

    protected function casts(): array
    {
        return ['mentions' => 'array', 'is_system' => 'boolean'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class);
    }
}
