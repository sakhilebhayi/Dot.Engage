<x-guest-layout title="Confirm your password">
    <x-authentication-card title="Confirm your password" lede="This part of Dot.Engage is protected. Enter your password to continue.">
        <x-validation-errors />

        <form method="POST" action="{{ route('password.confirm') }}">
            @csrf

            <div class="field">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" type="password" name="password" required autocomplete="current-password" autofocus />
            </div>

            <div class="door-actions">
                <span></span>
                <x-button>{{ __('Confirm') }}</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
