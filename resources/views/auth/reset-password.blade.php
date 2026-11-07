<x-guest-layout title="Choose a new password">
    <x-authentication-card title="Choose a new password" lede="Pick something long that you do not use anywhere else.">
        <x-validation-errors />

        <form method="POST" action="{{ route('password.update') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="field">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
            </div>

            <div class="field">
                <x-label for="password" value="{{ __('New password') }}" />
                <x-input id="password" type="password" name="password" required autocomplete="new-password" />
            </div>

            <div class="field">
                <x-label for="password_confirmation" value="{{ __('Confirm new password') }}" />
                <x-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>

            <div class="door-actions">
                <span></span>
                <x-button>{{ __('Save new password') }}</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
