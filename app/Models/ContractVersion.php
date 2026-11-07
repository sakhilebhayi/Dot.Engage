<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractVersion extends Model
{
    protected $fillable = [
        'contract_id',
        'created_by',
        'version_number',
        'kind',
        'file_path',
        'sha256',
        'size',
        'page_count',
        'mime',
        'label',
        'stampable',
        'change_notes',
    ];

    protected function casts(): array
    {
        return ['stampable' => 'boolean'];
    }

    public function isSigned(): bool
    {
        return $this->kind === 'signed';
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
