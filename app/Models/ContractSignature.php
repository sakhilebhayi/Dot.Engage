<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSignature extends Model
{
    protected $fillable = [
        'contract_id',
        'user_id',
        'contract_external_signer_id',
        'signer_name',
        'signer_email',
        'signature_image_path',
        'ip_address',
        'user_agent',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalSigner(): BelongsTo
    {
        return $this->belongsTo(ContractExternalSigner::class);
    }

    /**
     * The signer's display name, whether they signed as a team member
     * (via `user`) or as an invited external signer (snapshotted directly
     * on this row since that signer's own record may later be pruned).
     */
    public function signerName(): string
    {
        return $this->signer_name ?? $this->user?->name ?? 'Unknown';
    }
}
