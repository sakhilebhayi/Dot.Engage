<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DependencyPatchProposal extends Model
{
    protected $fillable = [
        'manager', 'advisories', 'risk_summary', 'proposed_command', 'status',
        'rejected_reason', 'reviewed_by', 'reviewed_at', 'applied_log', 'applied_at',
    ];

    protected $casts = [
        'advisories' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
