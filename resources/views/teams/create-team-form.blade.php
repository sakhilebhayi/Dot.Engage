<x-form-section submit="createTeam">
    <x-slot name="title">
        {{ __('Business details') }}
    </x-slot>

    <x-slot name="description">
        {{ __('You will be its owner. You can add people once it exists.') }}
    </x-slot>

    <x-slot name="form">
        <div class="field">
            <span class="label">{{ __('Owner') }}</span>
            <div class="who-line">
                <span class="ini ini-lg pen-1" aria-hidden="true">{{ strtoupper(mb_substr($this->user->name, 0, 1)) }}</span>
                <div>
                    <div class="nm">{{ $this->user->name }}</div>
                    <div class="em">{{ $this->user->email }}</div>
                </div>
            </div>
        </div>

        <div class="field">
            <x-label for="name" value="{{ __('Business name') }}" />
            <x-input id="name" type="text" wire:model="state.name" autofocus />
            <x-input-error for="name" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-button>
            {{ __('Create business') }}
        </x-button>
    </x-slot>
</x-form-section>
