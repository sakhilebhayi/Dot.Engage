<x-guest-layout title="Create an account">
    <x-authentication-card title="Create an account" lede="Your name and a password for Dot.Engage.">
        <x-validation-errors />

        <form method="POST" action="{{ route('register') }}">
            @csrf

            <div class="field">
                <x-label for="name" value="{{ __('Name') }}" />
                <x-input id="name" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            </div>

            <div class="field">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" type="email" name="email" :value="old('email')" required autocomplete="username" />
            </div>

            <div class="field">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" type="password" name="password" required autocomplete="new-password" />
            </div>

            <div class="field">
                <x-label for="password_confirmation" value="{{ __('Confirm password') }}" />
                <x-input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>

            @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                <label class="checkline" for="terms">
                    <x-checkbox name="terms" id="terms" required />
                    <span>
                        {!! __('I agree to the :terms_of_service and :privacy_policy', [
                            'terms_of_service' => '<a target="_blank" rel="noopener" href="'.route('terms.show').'">'.__('Terms of Service').'</a>',
                            'privacy_policy' => '<a target="_blank" rel="noopener" href="'.route('policy.show').'">'.__('Privacy Policy').'</a>',
                        ]) !!}
                    </span>
                </label>
            @endif

            <div class="door-actions">
                <a href="{{ route('login') }}">{{ __('Already registered?') }}</a>
                <x-button>{{ __('Create account') }}</x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
