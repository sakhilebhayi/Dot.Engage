<?php

namespace App\Livewire\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationTray extends Component
{
    public function markRead(string $notificationId): void
    {
        /** @var User $user */
        $user = Auth::user();
        $user->notifications()->where('id', $notificationId)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $notifications = $user->notifications()->latest()->limit(15)->get();

        return view('livewire.notifications.notification-tray', compact('notifications'));
    }
}
