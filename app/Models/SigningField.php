<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SigningField extends Model
{
    protected $fillable = ['envelope_id', 'signer_id', 'type', 'page', 'x', 'y', 'w', 'h', 'required', 'label', 'value', 'filled_at'];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'filled_at' => 'datetime',
            'x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float',
        ];
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(SigningEnvelope::class, 'envelope_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(SigningSigner::class, 'signer_id');
    }
}
