import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Production has no websocket server. Open a connection only when a Reverb key
// was provided at build time AND broadcasting is not switched off ("null");
// otherwise the browser would retry a dead host forever. Pages that rely on
// live updates poll as a fallback when window.Echo is absent.
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const broadcastConnection = import.meta.env.VITE_BROADCAST_CONNECTION;
const realtimeEnabled = Boolean(reverbKey) && broadcastConnection !== 'null';

if (realtimeEnabled) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? `ws.${location.hostname}`,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
