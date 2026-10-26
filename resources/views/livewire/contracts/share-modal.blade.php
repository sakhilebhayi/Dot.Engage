<div>
    <button wire:click="$set('show', true)"
            class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-indigo-600 border border-indigo-600 rounded-md hover:bg-indigo-50">
        Share
    </button>

    @if($show)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md space-y-4">
            <h3 class="text-lg font-semibold text-gray-900">Share Contract</h3>

            @if($hasTeam)
            <div>
                <label for="livewire-contracts-share-modal-selectedusers" class="block text-sm font-medium text-gray-700 mb-1">Add team members</label>
                <select id="livewire-contracts-share-modal-selectedusers" wire:model="selectedUsers" multiple
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500">
                    @foreach($teamMembers as $member)
                        <option value="{{ $member->id }}">{{ $member->name }} ({{ $member->email }})</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Hold Ctrl / Cmd to select multiple.</p>
            </div>
            @else
            <p class="text-sm text-gray-700">Pick or create a team before sharing.</p>
            @endif

            @if(session()->has('shared'))
                <p class="text-sm text-green-600">{{ session('shared') }}</p>
            @endif

            <div class="flex justify-end gap-3 pt-2">
                <button wire:click="$set('show', false)" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
                @if($hasTeam)
                <button wire:click="share" class="px-4 py-2 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700">Send Invitation</button>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
