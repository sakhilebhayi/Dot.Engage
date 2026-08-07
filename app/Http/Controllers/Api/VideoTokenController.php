<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VideoSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VideoTokenController extends Controller
{
    /**
     * Return the Reverb connection credentials and session metadata needed
     * by the front-end WebRTC / broadcasting client to join a video room.
     *
     * Exposes only the public Reverb app key (never the secret) plus enough
     * session context for the client to subscribe to the correct channel.
     * For production, swap with an Agora / Daily.co token from their SDKs.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'string', 'exists:video_sessions,room_id'],
        ]);

        $session = VideoSession::where('room_id', $validated['room_id'])->firstOrFail();

        Gate::authorize('join', $session);

        return response()->json([
            'channel' => 'video-session.'.$session->id,
            'room_id' => $session->room_id,
            'session_id' => $session->id,
            'contract_id' => $session->contract_id,
            'reverb' => [
                'app_key' => config('reverb.apps.apps.0.key'),
                'host' => config('reverb.servers.reverb.host'),
                'port' => config('reverb.servers.reverb.port'),
                'scheme' => config('reverb.servers.reverb.scheme', 'http'),
            ],
            'user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
        ]);
    }
}
