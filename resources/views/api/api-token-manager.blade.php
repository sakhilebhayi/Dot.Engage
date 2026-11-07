<div>
    <x-form-section submit="createApiToken">
        <x-slot name="title">
            {{ __('Create a token') }}
        </x-slot>

        <x-slot name="description">
            {{ __('A token lets a service you choose sign in to Dot.Engage on your behalf.') }}
        </x-slot>

        <x-slot name="form">
            <div class="field">
                <x-label for="name" value="{{ __('Token name') }}" />
                <x-input id="name" type="text" wire:model="createApiTokenForm.name" autofocus />
                <x-input-error for="name" />
            </div>

            @if (Laravel\Jetstream\Jetstream::hasPermissions())
                <fieldset class="fieldset">
                    <legend>{{ __('Permissions') }}</legend>

                    <div class="perm-grid">
                        @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                            <label class="checkline">
                                <x-checkbox wire:model="createApiTokenForm.permissions" :value="$permission"/>
                                <span>{{ $permission }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif
        </x-slot>

        <x-slot name="actions">
            <x-action-message on="created">
                {{ __('Created.') }}
            </x-action-message>

            <x-button>
                {{ __('Create token') }}
            </x-button>
        </x-slot>
    </x-form-section>

    @if ($this->user->tokens->isNotEmpty())
        <x-action-section>
            <x-slot name="title">
                {{ __('Your tokens') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Delete a token when the service that uses it no longer needs access.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="rows token-list">
                    @foreach ($this->user->tokens->sortBy('name') as $token)
                        <li>
                            <div>
                                <div class="nm">{{ $token->name }}</div>
                                @if ($token->last_used_at)
                                    <div class="row-sub">{{ __('Last used') }} {{ $token->last_used_at->diffForHumans() }}</div>
                                @endif
                            </div>

                            <div class="member-end">
                                @if (Laravel\Jetstream\Jetstream::hasPermissions())
                                    <button type="button" class="linkbtn" wire:click="manageApiTokenPermissions({{ $token->id }})">
                                        {{ __('Permissions') }}
                                    </button>
                                @endif

                                <button type="button" class="btn btn-wax btn-sm" wire:click="confirmApiTokenDeletion({{ $token->id }})"><span>{{ __('Delete') }}</span></button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <x-dialog-modal wire:model.live="displayingToken" maxWidth="md">
        <x-slot name="title">
            {{ __('Your new token') }}
        </x-slot>

        <x-slot name="content">
            <p>{{ __('Copy this token now. For your security it is not shown again.') }}</p>

            <x-input x-ref="plaintextToken" type="text" readonly :value="$plainTextToken"
                class="input-mono" style="margin-top:14px"
                aria-label="{{ __('New API token') }}"
                autofocus autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                @showing-token-modal.window="setTimeout(() => $refs.plaintextToken.select(), 250)"
            />
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('displayingToken', false)" wire:loading.attr="disabled">
                {{ __('Close') }}
            </x-secondary-button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model.live="managingApiTokenPermissions" maxWidth="md">
        <x-slot name="title">
            {{ __('Token permissions') }}
        </x-slot>

        <x-slot name="content">
            <div class="perm-grid">
                @foreach (Laravel\Jetstream\Jetstream::$permissions as $permission)
                    <label class="checkline">
                        <x-checkbox wire:model="updateApiTokenForm.permissions" :value="$permission"/>
                        <span>{{ $permission }}</span>
                    </label>
                @endforeach
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('managingApiTokenPermissions', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateApiToken" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <x-confirmation-modal wire:model.live="confirmingApiTokenDeletion">
        <x-slot name="title">
            {{ __('Delete this token') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Any service using this token will stop working. This cannot be undone.') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingApiTokenDeletion')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="deleteApiToken" wire:loading.attr="disabled">
                {{ __('Delete token') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
