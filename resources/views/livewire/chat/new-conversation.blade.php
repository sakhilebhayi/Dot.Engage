<div x-on:open-new-conversation.window="show = true" x-data="{ show: false }" x-on:keydown.escape.window="show = false">
    <div x-show="show" class="dlg" style="display: none;">
        <div class="dlg-back" x-on:click="show = false" aria-hidden="true"></div>

        <div class="dlg-panel" style="--dlg-w: 480px" role="dialog" aria-modal="true" aria-labelledby="new-conversation-title" x-trap.inert.noscroll="show">
            <div class="sheet-head">
                <h2 class="dlg-title" id="new-conversation-title">New conversation</h2>
                <button type="button" class="iconbtn dlg-close" x-on:click="show = false" aria-label="Close"><x-icon name="x" /></button>
            </div>

            <div class="sheet-body">
                <div class="field field-first">
                    <label for="livewire-chat-new-conversation-name">Name <span class="muted" style="font-weight:400">(optional for groups)</span></label>
                    <input id="livewire-chat-new-conversation-name" class="input" wire:model="name" type="text">
                </div>

                <label class="checkline" for="is-group">
                    <input class="check-box" wire:model="isGroup" type="checkbox" id="is-group">
                    <span>Group conversation</span>
                </label>

                <fieldset class="fieldset" id="livewire-chat-new-conversation-selectedusers" style="margin-top:14px">
                    <legend>Who is in it</legend>
                    @forelse($teamMembers as $member)
                        <label class="checkline">
                            <input class="check-box" type="checkbox" wire:model="selectedUsers" value="{{ $member->id }}">
                            <span>{{ $member->name }}</span>
                        </label>
                    @empty
                        <p class="hint">There is nobody else in this business yet. Add people from Business and members.</p>
                    @endforelse
                </fieldset>

                @error('selectedUsers') <p class="err">{{ $message }}</p> @enderror
            </div>

            <div class="sheet-foot">
                <button type="button" x-on:click="show = false" class="btn btn-quiet"><span>Cancel</span></button>
                <button type="button" wire:click="create" x-on:click="show = false" class="btn"><span>Start</span></button>
            </div>
        </div>
    </div>
</div>
