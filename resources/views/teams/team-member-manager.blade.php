<div>
    @if (Gate::check('addTeamMember', $team))
        <x-form-section submit="addTeamMember">
            <x-slot name="title">
                {{ __('Add a person') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Give someone access to this business by email. If they have no account yet, they get an invitation.') }}
            </x-slot>

            <x-slot name="form">
                <div class="field">
                    <x-label for="member_email" value="{{ __('Email') }}" />
                    <x-input id="member_email" type="email" wire:model="addTeamMemberForm.email" />
                    <x-input-error for="email" />
                </div>

                @if (count($this->roles) > 0)
                    <div class="field" role="group" aria-labelledby="role-label">
                        <span class="label" id="role-label">{{ __('Role') }}</span>
                        <x-input-error for="role" />

                        <div class="rolepick">
                            @foreach ($this->roles as $role)
                                <button type="button" wire:click="$set('addTeamMemberForm.role', '{{ $role->key }}')" aria-pressed="{{ ($addTeamMemberForm['role'] ?? null) == $role->key ? 'true' : 'false' }}">
                                    <span class="box" aria-hidden="true"><x-icon name="check" /></span>
                                    <span>
                                        <span class="rp-name">{{ $role->name }}</span>
                                        <span class="rp-desc">{{ $role->description }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-slot>

            <x-slot name="actions">
                <x-action-message on="saved">
                    {{ __('Added.') }}
                </x-action-message>

                <x-button>
                    {{ __('Add') }}
                </x-button>
            </x-slot>
        </x-form-section>
    @endif

    @if ($team->teamInvitations->isNotEmpty() && Gate::check('addTeamMember', $team))
        <x-action-section>
            <x-slot name="title">
                {{ __('Invitations waiting') }}
            </x-slot>

            <x-slot name="description">
                {{ __('These people have been emailed an invitation. They join when they accept it.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="rows token-list">
                    @foreach ($team->teamInvitations as $invitation)
                        <li>
                            <div class="nm" style="font-weight:500">{{ $invitation->email }}</div>

                            <div class="member-end">
                                @if (Gate::check('removeTeamMember', $team))
                                    <button type="button" class="btn btn-wax btn-sm" wire:click="cancelTeamInvitation({{ $invitation->id }})"><span>{{ __('Cancel invitation') }}</span></button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    @if ($team->users->isNotEmpty())
        <x-action-section>
            <x-slot name="title">
                {{ __('People') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Everyone with access to this business.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="rows member-list">
                    @foreach ($team->users->sortBy('name') as $user)
                        <li>
                            <span class="ini pen-{{ ($loop->index % 4) + 1 }}" aria-hidden="true">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                            <div class="row-title">{{ $user->name }}</div>

                            <div class="member-end">
                                @if (Gate::check('updateTeamMember', $team) && Laravel\Jetstream\Jetstream::hasRoles())
                                    <button type="button" class="linkbtn" wire:click="manageRole('{{ $user->id }}')" aria-label="{{ __('Change role for :name, now :role', ['name' => $user->name, 'role' => Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name]) }}">
                                        {{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}
                                    </button>
                                @elseif (Laravel\Jetstream\Jetstream::hasRoles())
                                    <span>{{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}</span>
                                @endif

                                @if ($this->user->id === $user->id)
                                    <button type="button" class="btn btn-wax btn-sm" wire:click="$toggle('confirmingLeavingTeam')"><span>{{ __('Leave') }}</span></button>
                                @elseif (Gate::check('removeTeamMember', $team))
                                    <button type="button" class="btn btn-wax btn-sm" wire:click="confirmTeamMemberRemoval('{{ $user->id }}')"><span>{{ __('Remove') }}</span></button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <x-dialog-modal wire:model.live="currentlyManagingRole" maxWidth="md">
        <x-slot name="title">
            {{ __('Change role') }}
        </x-slot>

        <x-slot name="content">
            <div class="rolepick">
                @foreach ($this->roles as $role)
                    <button type="button" wire:click="$set('currentRole', '{{ $role->key }}')" aria-pressed="{{ $currentRole == $role->key ? 'true' : 'false' }}">
                        <span class="box" aria-hidden="true"><x-icon name="check" /></span>
                        <span>
                            <span class="rp-name">{{ $role->name }}</span>
                            <span class="rp-desc">{{ $role->description }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="stopManagingRole" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateRole" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <x-confirmation-modal wire:model.live="confirmingLeavingTeam">
        <x-slot name="title">
            {{ __('Leave this business') }}
        </x-slot>

        <x-slot name="content">
            {{ __('You will lose access to its engagements and chat until someone adds you again.') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingLeavingTeam')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="leaveTeam" wire:loading.attr="disabled">
                {{ __('Leave') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>

    <x-confirmation-modal wire:model.live="confirmingTeamMemberRemoval">
        <x-slot name="title">
            {{ __('Remove this person') }}
        </x-slot>

        <x-slot name="content">
            {{ __('They lose access to this business immediately.') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingTeamMemberRemoval')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="removeTeamMember" wire:loading.attr="disabled">
                {{ __('Remove') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
