<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Contract extends Model
{
    use HasFactory, HasTeamScope, SoftDeletes;

    protected $fillable = [
        'team_id',
        'created_by',
        'title',
        'description',
        'file_path',
        'file_size',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class);
    }

    public function externalSigners(): HasMany
    {
        return $this->hasMany(ContractExternalSigner::class);
    }

    /**
     * The team members who are expected to sign: everyone whose role may create
     * records. A read-only viewer cannot sign (ContractPolicy::sign), so waiting
     * for one would leave the contract unfinishable.
     *
     * @return Collection<int, User>
     */
    public function signingMembers(): Collection
    {
        return $this->team->allUsers()->filter(function (User $member): bool {
            $permissions = $member->teamPermissions($this->team);

            return in_array('create', $permissions, true) || in_array('*', $permissions, true);
        });
    }

    /**
     * A contract is fully signed once every signing team member and every invited
     * external signer has a recorded signature.
     */
    public function requiredSignatureCount(): int
    {
        return $this->signingMembers()->count() + $this->externalSigners()->count();
    }
}
