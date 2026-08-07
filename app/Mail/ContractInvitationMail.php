<?php

namespace App\Mail;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly User $invitedBy,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->invitedBy->name.' has shared a contract with you — '.$this->contract->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contract-invitation',
            with: [
                'contract' => $this->contract,
                'invitedBy' => $this->invitedBy,
                'recipient' => $this->recipient,
                'reviewUrl' => route('contracts.show', $this->contract),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
