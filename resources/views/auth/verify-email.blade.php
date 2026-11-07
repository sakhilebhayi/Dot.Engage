<x-guest-layout title="Verify your email">
    <x-authentication-card title="Verify your email" lede="Open the link we just emailed you to confirm this address. If it did not arrive, we can send another.">
        @if (session('status') == 'verification-link-sent')
            <div class="flash is-ok" role="status">
                {{ __('A new verification link has been sent to the email address in your profile.') }}
            </div>
        @endif

        <div class="door-actions">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-button type="submit">{{ __('Send the link again') }}</x-button>
            </form>

            <div class="door-links">
                <a href="{{ route('profile.show') }}">{{ __('Edit profile') }}</a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="linkbtn">{{ __('Sign out') }}</button>
                </form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
