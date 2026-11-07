<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. The database refuses UPDATE and DELETE; see AuditTrail for the hash chain. */
class EngagementAuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
