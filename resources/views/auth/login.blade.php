<x-guest-layout title="Sign in">
    <x-authentication-card title="Sign in" lede="Use the email and password for your Dot.Engage account.">
        <x-validation-errors />

        @session('status')
            <div class="flash is-ok" role="status">{{ $value }}</div>
        @endsession

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="field">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div class="field">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" type="password" name="password" required autocomplete="current-password" />
            </div>

            <label class="checkline" for="remember_me">
                <x-checkbox id="remember_me" name="remember" />
                <span>{{ __('Remember me') }}</span>
            </label>

            <div class="door-actions">
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
                @endif

                <x-button>{{ __('Sign in') }}</x-button>
            </div>
        </form>

        <p class="door-note">
            In production, sign-in goes through InfoDot. Open Dot.Engage from InfoDot to arrive signed in, and your account is created the first time you do. This form is for accounts that already have a password here.
            <a href="{{ config('ecosystem.platforms.infodot.url', 'https://infodot.co.za') }}">Go to InfoDot</a>
        </p>
    </x-authentication-card>
</x-guest-layout>
