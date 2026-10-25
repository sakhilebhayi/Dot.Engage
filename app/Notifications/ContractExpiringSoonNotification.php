<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class ContractExpiringSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Contract $contract) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('contracts.show', $this->contract);

        return (new MailMessage)
            ->subject('Reminder: '.$this->contract->title.' expires '.$this->contract->expires_at->diffForHumans())
            ->greeting('Hi '.$notifiable->name.'!')
            ->line('The contract **'.$this->contract->title.'** is still awaiting your signature and expires on '.$this->contract->expires_at->format('jS F Y').'.')
            ->action('Review & Sign', $url)
            ->line('Once it expires it will no longer be able to be signed.');
    }

    public function toArray(object $notifiable): array
    {
        $expiresAt = Carbon::make($this->contract->expires_at);

        return [
            'type' => 'contract_expiring_soon',
            'contract_id' => $this->contract->id,
            'contract_title' => $this->contract->title,
            'expires_at' => $expiresAt?->toIso8601String(),
            'message' => $this->contract->title.' is waiting for your signature'
                .($expiresAt ? ' and expires on '.$expiresAt->format('jS F Y') : ''),
        ];
    }
}
