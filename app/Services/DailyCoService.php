<?php

namespace App\Services;

use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Support\Facades\Http;

class DailyCoService
{
    private const BASE_URL = 'https://api.daily.co/v1';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.daily.api_key', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    public function getJoinUrl(VideoSession $session, User $user): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $roomName = $session->room_id;

        // Get or create the Daily.co room.
        $room = $this->getOrCreateRoom($roomName);
        if (! $room) {
            return null;
        }

        $roomUrl = $room['url'];

        // Generate a short-lived participant token.
        $token = $this->createToken($roomName, $user->name);

        return $token ? "{$roomUrl}?t={$token}" : $roomUrl;
    }

    private function getOrCreateRoom(string $name): ?array
    {
        $response = Http::withToken($this->apiKey)
            ->get(self::BASE_URL."/rooms/{$name}");

        if ($response->successful()) {
            return $response->json();
        }

        $response = Http::withToken($this->apiKey)
            ->post(self::BASE_URL.'/rooms', [
                'name' => $name,
                'properties' => [
                    'max_participants' => 50,
                    'enable_chat' => true,
                    'exp' => now()->addHours(8)->timestamp,
                ],
            ]);

        return $response->successful() ? $response->json() : null;
    }

    private function createToken(string $roomName, string $userName): ?string
    {
        $response = Http::withToken($this->apiKey)
            ->post(self::BASE_URL.'/meeting-tokens', [
                'properties' => [
                    'room_name' => $roomName,
                    'user_name' => $userName,
                    'exp' => now()->addHours(4)->timestamp,
                ],
            ]);

        return $response->successful() ? $response->json('token') : null;
    }
}
