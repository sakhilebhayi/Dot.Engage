<x-action-section>
    <x-slot name="title">
        {{ __('Delete this business') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Remove the business and everything in it for good.') }}
    </x-slot>

    <x-slot name="content">
        <p class="prose-sm danger-note">
            {{ __('Deleting a business removes its engagements, documents, chat and people permanently. Download what you need to keep first.') }}
        </p>

        <div class="page-actions" style="margin-top:16px">
            <x-danger-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                {{ __('Delete business') }}
            </x-danger-button>
        </div>

        <x-confirmation-modal wire:model.live="confirmingTeamDeletion">
            <x-slot name="title">
                {{ __('Delete this business') }}
            </x-slot>

            <x-slot name="content">
                {{ __('This removes the business and everything in it permanently. It cannot be undone.') }}
            </x-slot>

            <x-slot name="footer">
                <x-secondary-button wire:click="$toggle('confirmingTeamDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button wire:click="deleteTeam" wire:loading.attr="disabled">
                    {{ __('Delete business') }}
                </x-danger-button>
            </x-slot>
        </x-confirmation-modal>
    </x-slot>
</x-action-section>
