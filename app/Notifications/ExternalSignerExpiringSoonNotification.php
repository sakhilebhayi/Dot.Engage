<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\ContractExternalSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExternalSignerExpiringSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractExternalSigner $signer,
        public readonly string $signedUrl,
    ) {}

    /**
     * External signers have no User account -- mail only, no database channel.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: '.$this->contract->title.' expires '.$this->signer->expires_at->diffForHumans())
            ->greeting('Hi '.$this->signer->name.'!')
            ->line('The contract **'.$this->contract->title.'** is still awaiting your signature and your signing link expires on '.$this->signer->expires_at->format('jS F Y').'.')
            ->action('Review & Sign', $this->signedUrl)
            ->line('Once it expires this link will no longer work.');
    }
}
