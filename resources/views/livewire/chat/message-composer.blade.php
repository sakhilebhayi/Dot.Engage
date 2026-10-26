<div class="flex items-end gap-2">
    <div class="flex-1 space-y-2">
        @if($attachments)
            <div class="flex flex-wrap gap-2">
                @foreach($attachments as $i => $attachment)
                    <div class="flex items-center gap-1 bg-gray-100 rounded px-2 py-1 text-xs text-gray-700">
                        {{ $attachment->getClientOriginalName() }}
                        <button wire:click="removeAttachment({{ $i }})" class="ml-1 text-red-400 hover:text-red-600">&times;</button>
                    </div>
                @endforeach
            </div>
        @endif
        <div class="flex items-center gap-2 bg-gray-100 rounded-xl px-3 py-2">
            <label for="livewire-chat-message-composer-body" class="cursor-pointer text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                <input wire:model="attachments" type="file" multiple class="sr-only" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
            </label>
            <input id="livewire-chat-message-composer-body" wire:model="body" wire:keydown.enter.prevent="send"
                   type="text" placeholder="Type a message…"
                   class="flex-1 bg-transparent text-sm border-none focus:ring-0 outline-none placeholder-gray-400">
        </div>
    </div>
    <button wire:click="send"
            class="p-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 disabled:opacity-50"
            wire:loading.attr="disabled">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
    </button>
</div>
