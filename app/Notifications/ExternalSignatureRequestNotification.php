<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\ContractExternalSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExternalSignatureRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractExternalSigner $signer,
        public readonly string $signedUrl,
    ) {}

    /**
     * External signers have no User account, so this is always routed via
     * Notification::route('mail', ...) -- mail only, no database channel.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitedBy = $this->contract->creator;

        return (new MailMessage)
            ->subject($invitedBy->name.' sent you a contract to sign — '.$this->contract->title)
            ->greeting('Hi '.$this->signer->name.'!')
            ->line($invitedBy->name.' has sent you the contract **'.$this->contract->title.'** to review and sign.')
            ->action('Review & Sign', $this->signedUrl)
            ->line('This link is unique to you and expires on '.$this->signer->expires_at->format('jS F Y').'. No account is required.');
    }
}
