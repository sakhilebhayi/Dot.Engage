<?php

namespace App\Mail;

use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoSessionInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly VideoSession $session,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        $initiator = $this->session->initiator;
        $subject = $initiator->name.' has invited you to a video session';

        if ($this->session->contract_id) {
            $subject .= ' — document signing required';
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.video-invite',
            with: [
                'session' => $this->session,
                'initiator' => $this->session->initiator,
                'recipient' => $this->recipient,
                'joinUrl' => route('video.room', ['room' => $this->session->room_id]),
                'contract' => $this->session->contract_id ? $this->session->contract : null,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
