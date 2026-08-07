<?php

namespace App\Mail;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SignedContractMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractVersion $signedVersion,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Signed contract ready: '.$this->contract->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.signed-contract',
            with: [
                'contract' => $this->contract,
                'signedVersion' => $this->signedVersion,
                'recipient' => $this->recipient,
                'viewUrl' => route('contracts.show', $this->contract),
            ],
        );
    }

    public function attachments(): array
    {
        $path = $this->signedVersion->file_path;

        if ($path && Storage::disk('contracts')->exists($path)) {
            return [
                Attachment::fromStorageDisk('contracts', $path)
                    ->as('signed_'.$this->contract->id.'.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
