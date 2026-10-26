<div @open-new-conversation.window="show = true" x-data="{ show: false }">
    <div x-show="show" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md space-y-4" @click.stop>
            <h3 class="text-lg font-semibold text-gray-900">New Conversation</h3>

            <div>
                <label for="livewire-chat-new-conversation-name" class="block text-sm font-medium text-gray-700">Name (optional for groups)</label>
                <input id="livewire-chat-new-conversation-name" wire:model="name" type="text" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
            </div>

            <div class="flex items-center gap-2">
                <input wire:model="isGroup" type="checkbox" id="is-group" class="rounded border-gray-300 text-indigo-600">
                <label for="is-group" class="text-sm text-gray-700">Group conversation</label>
            </div>

            <div>
                <label for="livewire-chat-new-conversation-selectedusers" class="block text-sm font-medium text-gray-700 mb-1">Participants</label>
                <select id="livewire-chat-new-conversation-selectedusers" wire:model="selectedUsers" multiple
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                    @foreach($teamMembers as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </div>

            @error('selectedUsers') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex justify-end gap-3 pt-2">
                <button @click="show = false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                <button wire:click="create" @click="show = false" class="px-4 py-2 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Start</button>
            </div>
        </div>
    </div>
</div>
