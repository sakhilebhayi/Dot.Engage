<x-app-layout title="Team chat" wide>
    <div class="page-head chat-head">
        <div>
            <h1>Team chat</h1>
        </div>
    </div>

    <div class="chat has-thread tap">
        <aside class="chat-list" aria-label="Conversations">
            <livewire:chat.conversation-list :selected-id="$conversationId" />
            <livewire:chat.new-conversation />
        </aside>
        <section class="chat-pane" aria-label="Conversation">
            <livewire:chat.conversation-thread :conversation-id="$conversationId" />
        </section>
    </div>
</x-app-layout>
