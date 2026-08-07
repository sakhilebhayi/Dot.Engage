<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\ContractSignature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractSignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractSignature $signature,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $signer = $this->signature->user;
        $url = route('contracts.show', $this->contract);
        $allSigned = $this->contract->status === 'signed';

        $subject = $allSigned
            ? 'Contract fully signed: '.$this->contract->title
            : $signer->name.' signed '.$this->contract->title;

        $body = $allSigned
            ? 'All parties have signed the contract **'.$this->contract->title.'**. It is now complete.'
            : $signer->name.' has added their signature to **'.$this->contract->title.'**.';

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hi '.$notifiable->name.'!')
            ->line($body)
            ->action('View Contract', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contract_signed',
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'contract_status' => $this->contract->status,
            'signed_by_id' => $this->signature->user_id,
            'signed_by_name' => $this->signature->user->name,
        ];
    }
}
