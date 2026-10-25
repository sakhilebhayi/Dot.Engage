<div class="flex flex-col h-full" @if($polling) wire:poll.5s.visible="refreshFromPoll" @endif>
    {{-- Header --}}
    <div class="px-4 py-3 border-b border-gray-200 bg-white flex items-center gap-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">{{ $conversation->name ?? 'Direct Message' }}</h2>
            <p class="text-xs text-gray-500">{{ $conversation->participants->count() }} participants</p>
        </div>
    </div>

    {{-- Messages --}}
    <div class="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50" id="message-thread">
        @if($hasMorePages)
            <div class="text-center">
                <button wire:click="loadMore" class="text-sm text-indigo-600 hover:underline">Load older messages</button>
            </div>
        @endif
        @foreach($messages as $message)
            <div class="flex {{ $message->user_id === auth()->id() ? 'justify-end' : 'justify-start' }} gap-2">
                @if($message->user_id !== auth()->id())
                    <img src="{{ $message->sender->profile_photo_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                @endif
                <div class="max-w-xs lg:max-w-md">
                    @if($message->user_id !== auth()->id())
                        <p class="text-xs text-gray-500 mb-1">{{ $message->sender->name }}</p>
                    @endif
                    <div class="px-4 py-2 rounded-2xl text-sm
                        {{ $message->user_id === auth()->id() ? 'bg-indigo-600 text-white rounded-br-sm' : 'bg-white shadow text-gray-900 rounded-bl-sm' }}">
                        @if($message->body) <p>{{ $message->body }}</p> @endif
                        @foreach($message->attachments as $attachment)
                            <a href="{{ Storage::url($attachment->file_path) }}" target="_blank"
                               class="flex items-center gap-1 text-xs underline mt-1 {{ $message->user_id === auth()->id() ? 'text-indigo-200' : 'text-indigo-600' }}">
                                📎 {{ $attachment->original_filename }}
                            </a>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-400 mt-1 {{ $message->user_id === auth()->id() ? 'text-right' : '' }}">
                        {{ $message->created_at->format('H:i') }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Composer --}}
    @can('sendMessage', $conversation)
        <div class="border-t border-gray-200 bg-white p-3">
            <livewire:chat.message-composer :conversationId="$conversationId" />
        </div>
    @else
        <div class="border-t border-gray-200 bg-white p-3 text-center text-xs text-gray-500">
            Your role is read-only here, so you can read this conversation but not reply.
        </div>
    @endcan
</div>
