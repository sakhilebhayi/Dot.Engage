<x-action-section>
    <x-slot name="title">
        {{ __('Delete account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Remove your account and its data for good.') }}
    </x-slot>

    <x-slot name="content">
        <p class="prose-sm danger-note">
            {{ __('Deleting your account removes it and its data permanently. Download anything you want to keep first.') }}
        </p>

        <div class="page-actions" style="margin-top:16px">
            <x-danger-button wire:click="confirmUserDeletion" wire:loading.attr="disabled">
                {{ __('Delete account') }}
            </x-danger-button>
        </div>

        <x-dialog-modal wire:model.live="confirmingUserDeletion" maxWidth="md">
            <x-slot name="title">
                {{ __('Delete account') }}
            </x-slot>

            <x-slot name="content">
                {{ __('This removes your account and its data permanently and cannot be undone. Enter your password to confirm.') }}

                <div class="field" x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                    <label for="delete_password">{{ __('Password') }}</label>
                    <x-input id="delete_password" type="password"
                                autocomplete="current-password"
                                x-ref="password"
                                wire:model="password"
                                wire:keydown.enter="deleteUser" />

                    <x-input-error for="password" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('Delete account') }}
                </x-danger-button>
            </x-slot>
        </x-dialog-modal>
    </x-slot>
</x-action-section>
