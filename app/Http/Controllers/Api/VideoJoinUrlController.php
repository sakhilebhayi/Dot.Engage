<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VideoSession;
use App\Services\DailyCoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VideoJoinUrlController extends Controller
{
    public function __invoke(Request $request, VideoSession $session, DailyCoService $daily): JsonResponse
    {
        Gate::authorize('join', $session);

        if (! $daily->isConfigured()) {
            return response()->json([
                'configured' => false,
                'room_id' => $session->room_id,
            ]);
        }

        $url = $daily->getJoinUrl($session, $request->user());

        if (! $url) {
            return response()->json([
                'configured' => false,
                'room_id' => $session->room_id,
            ]);
        }

        return response()->json([
            'configured' => true,
            'url' => $url,
            'room_id' => $session->room_id,
        ]);
    }
}
