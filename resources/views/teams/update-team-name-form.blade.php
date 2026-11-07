<x-form-section submit="updateTeamName">
    <x-slot name="title">
        {{ __('Business name') }}
    </x-slot>

    <x-slot name="description">
        {{ __('The name other businesses see on invitations and agreements.') }}
    </x-slot>

    <x-slot name="form">
        <div class="field">
            <span class="label">{{ __('Owner') }}</span>
            <div class="who-line">
                <span class="ini ini-lg pen-1" aria-hidden="true">{{ strtoupper(mb_substr($team->owner->name, 0, 1)) }}</span>
                <div>
                    <div class="nm">{{ $team->owner->name }}</div>
                    <div class="em">{{ $team->owner->email }}</div>
                </div>
            </div>
        </div>

        <div class="field">
            <x-label for="name" value="{{ __('Business name') }}" />

            <x-input id="name"
                        type="text"
                        wire:model="state.name"
                        :disabled="! Gate::check('update', $team)" />

            <x-input-error for="name" />
        </div>
    </x-slot>

    @if (Gate::check('update', $team))
        <x-slot name="actions">
            <x-action-message on="saved">
                {{ __('Saved.') }}
            </x-action-message>

            <x-button>
                {{ __('Save') }}
            </x-button>
        </x-slot>
    @endif
</x-form-section>
