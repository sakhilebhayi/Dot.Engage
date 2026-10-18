<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractSharedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $sharedById  The user who performed the share. When
     *                                null (unknown), the contract creator is
     *                                used instead.
     */
    public function __construct(
        public readonly Contract $contract,
        public readonly ?int $sharedById = null,
    ) {}

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
            'shared_by_id' => $this->sharedById ?? $this->contract->created_by,
            'shared_by_name' => $this->sharer()?->name,
            'message' => $this->sharedByName().' shared '.$this->contract->title.' with you',
        ];
    }

    /**
     * The user who shared the contract: the explicit sharer when known (null
     * if that account no longer exists), otherwise the contract creator.
     */
    private function sharer(): ?User
    {
        if ($this->sharedById !== null) {
            return User::find($this->sharedById);
        }

        return $this->contract->creator;
    }

    private function sharedByName(): string
    {
        return $this->sharer()?->name ?? 'Someone';
    }
}
