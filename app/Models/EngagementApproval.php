<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementApproval extends Model
{
    protected $fillable = ['engagement_id', 'contract_version_id', 'participant_id', 'status', 'note', 'requested_at', 'decided_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EngagementParticipant::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class, 'contract_version_id');
    }
}
