<x-action-section>
    <x-slot name="title">
        {{ __('Where you are signed in') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Sign out of Dot.Engage on other browsers and devices.') }}
    </x-slot>

    <x-slot name="content">
        <p class="prose-sm">
            {{ __('If you do not recognise a session, sign out of the others and change your password. This list shows recent sessions and may not be complete.') }}
        </p>

        @if (count($this->sessions) > 0)
            <ul class="session-list">
                @foreach ($this->sessions as $session)
                    <li>
                        <x-icon :name="$session->agent->isDesktop() ? 'monitor' : 'phone'" size="lg" />

                        <div>
                            <div class="nm">
                                {{ $session->agent->platform() ? $session->agent->platform() : __('Unknown system') }}, {{ $session->agent->browser() ? $session->agent->browser() : __('unknown browser') }}
                            </div>

                            <div class="meta">
                                <span class="fig">{{ $session->ip_address }}</span>,
                                @if ($session->is_current_device)
                                    <span class="tag is-ok">{{ __('This device') }}</span>
                                @else
                                    {{ __('last active') }} {{ $session->last_active }}
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="sect-actions" style="margin-top:16px">
            <x-action-message on="loggedOut">
                {{ __('Done.') }}
            </x-action-message>

            <x-button wire:click="confirmLogout" wire:loading.attr="disabled">
                {{ __('Sign out other sessions') }}
            </x-button>
        </div>

        <x-dialog-modal wire:model.live="confirmingLogout" maxWidth="md">
            <x-slot name="title">
                {{ __('Sign out other sessions') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Enter your password to sign out of every other browser and device.') }}

                <div class="field" x-data="{}" x-on:confirming-logout-other-browser-sessions.window="setTimeout(() => $refs.password.focus(), 250)">
                    <label for="logout_password">{{ __('Password') }}</label>
                    <x-input id="logout_password" type="password"
                                autocomplete="current-password"
                                x-ref="password"
                                wire:model="password"
                                wire:keydown.enter="logoutOtherBrowserSessions" />

                    <x-input-error for="password" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingLogout')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-button wire:click="logoutOtherBrowserSessions" wire:loading.attr="disabled">
                    {{ __('Sign out other sessions') }}
                </x-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
