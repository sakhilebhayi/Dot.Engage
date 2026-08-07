<?php

namespace App\Livewire\Chat;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageComposer extends Component
{
    use WithFileUploads;

    public int $conversationId;

    public string $body = '';

    public array $attachments = [];

    public function send(): void
    {
        $this->validate([
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480',
        ]);

        if (empty(trim($this->body)) && empty($this->attachments)) {
            return;
        }

        $conversation = Conversation::findOrFail($this->conversationId);
        $this->authorize('sendMessage', $conversation);

        $type = $this->attachments ? 'file' : 'text';

        $message = Message::create([
            'conversation_id' => $this->conversationId,
            'user_id' => Auth::id(),
            'body' => trim($this->body) ?: null,
            'type' => $type,
        ]);

        foreach ($this->attachments as $attachment) {
            $path = $attachment->store('/', 'message-attachments');
            MessageAttachment::create([
                'message_id' => $message->id,
                'file_path' => $path,
                'original_filename' => $attachment->getClientOriginalName(),
                'mime_type' => $attachment->getMimeType(),
                'file_size' => $attachment->getSize(),
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
        MessageSent::dispatch($message);

        $this->reset(['body', 'attachments']);
    }

    public function removeAttachment(int $index): void
    {
        array_splice($this->attachments, $index, 1);
    }

    public function render()
    {
        return view('livewire.chat.message-composer');
    }
}
