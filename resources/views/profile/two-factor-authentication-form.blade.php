<x-action-section>
    <x-slot name="title">
        {{ __('Two-step sign-in') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Ask for a code from your phone each time you sign in, on top of your password.') }}
    </x-slot>

    <x-slot name="content">
        <p class="sect-state">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Finish turning on two-step sign-in.') }}
                @else
                    {{ __('Two-step sign-in is on.') }}
                @endif
            @else
                {{ __('Two-step sign-in is off.') }}
            @endif
        </p>

        <p class="prose-sm">
            {{ __('When it is on, signing in asks for a code from an authenticator app on your phone. Use any authenticator app you already trust.') }}
        </p>

        @if ($this->enabled)
            @if ($showingQrCode)
                <p class="prose-sm" style="margin-top:16px">
                    @if ($showingConfirmation)
                        {{ __('To finish, scan this QR code with your authenticator app, or type in the setup key, then enter the code the app shows.') }}
                    @else
                        {{ __('Scan this QR code with your authenticator app, or type in the setup key.') }}
                    @endif
                </p>

                <div class="qr">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <p class="setup-key">
                    {{ __('Setup key') }}: <span class="fig">{{ decrypt($this->user->two_factor_secret) }}</span>
                </p>

                @if ($showingConfirmation)
                    <div class="field" style="margin-top:16px;max-width:260px">
                        <x-label for="code" value="{{ __('Code from the app') }}" />

                        <x-input id="code" type="text" name="code" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        <x-input-error for="code" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <p class="prose-sm" style="margin-top:16px">
                    {{ __('Keep these recovery codes in a password manager. Each one signs you in once if you lose your phone.') }}
                </p>

                <div class="codes">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="page-actions" style="margin-top:18px">
            @if (! $this->enabled)
                <x-confirms-password wire:then="enableTwoFactorAuthentication">
                    <x-button type="button" wire:loading.attr="disabled">
                        {{ __('Turn on') }}
                    </x-button>
                </x-confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-confirms-password wire:then="regenerateRecoveryCodes">
                        <x-secondary-button>
                            {{ __('Make new recovery codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @elseif ($showingConfirmation)
                    <x-confirms-password wire:then="confirmTwoFactorAuthentication">
                        <x-button type="button" wire:loading.attr="disabled">
                            {{ __('Confirm') }}
                        </x-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="showRecoveryCodes">
                        <x-secondary-button>
                            {{ __('Show recovery codes') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-secondary-button wire:loading.attr="disabled">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                    </x-confirms-password>
                @else
                    <x-confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-danger-button wire:loading.attr="disabled">
                            {{ __('Turn off') }}
                        </x-danger-button>
                    </x-confirms-password>
                @endif
            @endif
        </div>
    </x-slot>
</x-action-section>
