<div class="cmp">
    <div class="cmp-field">
        @if($attachments)
            <div class="cmp-chips">
                @foreach($attachments as $i => $attachment)
                    <span class="chip">
                        <span>{{ $attachment->getClientOriginalName() }}</span>
                        <button type="button" wire:click="removeAttachment({{ $i }})" aria-label="Remove {{ $attachment->getClientOriginalName() }}"><x-icon name="x" size="sm" /></button>
                    </span>
                @endforeach
            </div>
        @endif
        @error('body')<p class="err">{{ $message }}</p>@enderror
        @error('attachments')<p class="err">{{ $message }}</p>@enderror
        @error('attachments.*')<p class="err">{{ $message }}</p>@enderror
        <div class="cmp-row">
            <label class="cmp-attach" title="Attach files">
                <x-icon name="clip" />
                <span class="sr-only">Attach files</span>
                <input wire:model="attachments" type="file" multiple accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
            </label>
            <label for="livewire-chat-message-composer-body" class="sr-only">Message</label>
            <input id="livewire-chat-message-composer-body" class="cmp-input" wire:model="body" wire:keydown.enter.prevent="send"
                   type="text" placeholder="Write a message" autocomplete="off">
        </div>
    </div>
    <button type="button" wire:click="send" aria-label="{{ __('Send message') }}" class="btn cmp-send" wire:loading.attr="disabled">
        <x-icon name="send" />
    </button>
</div>
