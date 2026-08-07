<?php

namespace App\Livewire\Video;

use App\Events\VideoSessionEnded;
use App\Models\VideoSession;
use Livewire\Component;

class SessionRoom extends Component
{
    public int $sessionId;

    public bool $showDocument = false;

    public function mount(int $sessionId): void
    {
        $session = VideoSession::findOrFail($sessionId);
        $this->authorize('view', $session);
        $this->sessionId = $sessionId;
    }

    public function toggleDocument(): void
    {
        $this->showDocument = ! $this->showDocument;
    }

    public function endSession(): void
    {
        $session = VideoSession::findOrFail($this->sessionId);
        $this->authorize('delete', $session);

        $session->update(['status' => 'ended', 'ended_at' => now()]);
        VideoSessionEnded::dispatch($session);

        $this->redirect(route('video.index'));
    }

    public function render()
    {
        $session = VideoSession::findOrFail($this->sessionId);

        return view('livewire.video.session-room', compact('session'));
    }
}
