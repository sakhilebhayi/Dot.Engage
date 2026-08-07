<?php

namespace App\Livewire\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationBell extends Component
{
    public int $unreadCount = 0;

    public function refresh(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->unreadCount = $user->unreadNotifications()->count();
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user();
        $this->unreadCount = $user->unreadNotifications()->count();

        return view('livewire.notifications.notification-bell');
    }
}
