<x-guest-layout title="Two-step sign-in">
    <x-authentication-card title="Two-step sign-in">
        <div x-data="{ recovery: false }">
            <p class="lede" x-show="! recovery">
                {{ __('Enter the code from your authenticator app to finish signing in.') }}
            </p>

            <p class="lede" x-cloak x-show="recovery">
                {{ __('Enter one of your emergency recovery codes to finish signing in.') }}
            </p>

            <x-validation-errors />

            <form method="POST" action="{{ route('two-factor.login') }}">
                @csrf

                <div class="field" x-show="! recovery">
                    <x-label for="code" value="{{ __('Code') }}" />
                    <x-input id="code" type="text" inputmode="numeric" name="code" autofocus x-ref="code" autocomplete="one-time-code" />
                </div>

                <div class="field" x-cloak x-show="recovery">
                    <x-label for="recovery_code" value="{{ __('Recovery code') }}" />
                    <x-input id="recovery_code" type="text" name="recovery_code" x-ref="recovery_code" autocomplete="one-time-code" />
                </div>

                <div class="door-actions">
                    <button type="button" class="linkbtn"
                            x-show="! recovery"
                            x-on:click="
                                recovery = true;
                                $nextTick(() => { $refs.recovery_code.focus() })
                            ">
                        {{ __('Use a recovery code') }}
                    </button>

                    <button type="button" class="linkbtn"
                            x-cloak
                            x-show="recovery"
                            x-on:click="
                                recovery = false;
                                $nextTick(() => { $refs.code.focus() })
                            ">
                        {{ __('Use an authenticator code') }}
                    </button>

                    <x-button>{{ __('Sign in') }}</x-button>
                </div>
            </form>
        </div>
    </x-authentication-card>
</x-guest-layout>
