<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngagementParty extends Model
{
    protected $fillable = ['engagement_id', 'name', 'is_host', 'position'];

    protected function casts(): array
    {
        return ['is_host' => 'boolean'];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(Engagement::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EngagementParticipant::class, 'party_id');
    }
}
