<x-app-layout title="Team chat" wide>
    <div class="page-head chat-head">
        <div>
            <h1>Team chat</h1>
        </div>
    </div>

    <div class="chat tap">
        <aside class="chat-list" aria-label="Conversations">
            <livewire:chat.conversation-list />
            <livewire:chat.new-conversation />
        </aside>
        <section class="chat-pane" aria-label="Conversation" x-data x-on:conversation-selected.window="window.location.assign('{{ url('/chat') }}/' + $event.detail.conversationId)">
            <div class="chat-none">
                <div><b>Pick a conversation</b>Choose one from the list, or start a new one with the people in your business.</div>
            </div>
        </section>
    </div>
</x-app-layout>
