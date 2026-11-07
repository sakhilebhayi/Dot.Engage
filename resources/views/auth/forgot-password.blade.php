<x-guest-layout title="Reset your password">
    <x-authentication-card title="Reset your password" lede="Give us your email address and we will send a link to choose a new password.">
        @session('status')
            <div class="flash is-ok" role="status">{{ $value }}</div>
        @endsession

        <x-validation-errors />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="field">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div class="door-actions">
                <a href="{{ route('login') }}">Back to sign in</a>
                <x-button>{{ __('Email the reset link') }}</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
