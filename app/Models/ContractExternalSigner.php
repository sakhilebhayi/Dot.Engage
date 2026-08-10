<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContractExternalSigner extends Model
{
    protected $fillable = [
        'contract_id',
        'invited_by',
        'name',
        'email',
        'status',
        'sign_order',
        'viewed_at',
        'signed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function signature(): HasOne
    {
        return $this->hasOne(ContractSignature::class);
    }

    public function isExpired(): bool
    {
        return $this->status !== 'signed' && $this->expires_at->isPast();
    }

    public function markViewed(): void
    {
        if ($this->status === 'pending') {
            $this->update(['status' => 'viewed', 'viewed_at' => now()]);
        }
    }

    /**
     * Unordered signers (sign_order null, the default) may always sign.
     * Ordered signers must wait for every other external signer on the
     * same contract with a lower sign_order to have signed first --
     * unordered signers never block an ordered one, and vice versa.
     */
    public function isSignable(): bool
    {
        if ($this->sign_order === null) {
            return true;
        }

        return ! ContractExternalSigner::where('contract_id', $this->contract_id)
            ->whereNotNull('sign_order')
            ->where('sign_order', '<', $this->sign_order)
            ->where('status', '!=', 'signed')
            ->exists();
    }
}
