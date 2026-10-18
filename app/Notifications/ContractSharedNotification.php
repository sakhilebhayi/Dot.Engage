<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractSharedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Contract $contract) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sharedByName = $this->sharedByName();
        $url = route('contracts.show', $this->contract);

        return (new MailMessage)
            ->subject($sharedByName.' shared a contract with you')
            ->greeting('Hi '.$notifiable->name.'!')
            ->line($sharedByName.' has shared the contract **'.$this->contract->title.'** with you.')
            ->action('Review Contract', $url)
            ->line('Please review and sign at your earliest convenience.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contract_shared',
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'shared_by_id' => $this->contract->created_by,
            'shared_by_name' => $this->contract->creator?->name,
            'message' => $this->sharedByName().' shared '.$this->contract->title.' with you',
        ];
    }

    /**
     * The contract creator's name, or a neutral fallback when the creator
     * can no longer be resolved.
     */
    private function sharedByName(): string
    {
        return $this->contract->creator?->name ?? 'Someone';
    }
}
