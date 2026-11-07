<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outside participants
    |--------------------------------------------------------------------------
    | People invited to an engagement do not need an account. They open an emailed link, prove they own the
    | address with a one-time code, and then reach only that engagement.
    */
    'invitation_days' => (int) env('ENGAGE_INVITATION_DAYS', 14),
    'code_minutes' => (int) env('ENGAGE_CODE_MINUTES', 10),
    'code_attempts' => (int) env('ENGAGE_CODE_ATTEMPTS', 5),
    'code_resend_seconds' => (int) env('ENGAGE_CODE_RESEND_SECONDS', 60),
    'session_hours' => (int) env('ENGAGE_SESSION_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */
    'max_upload_kb' => (int) env('ENGAGE_MAX_UPLOAD_KB', 20480),
    // Signed agreements are kept at least this long (years); a business that holds one cannot be deleted before then.
    'retention_years' => (int) env('ENGAGE_RETENTION_YEARS', 7),

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    */
    'signing' => [
        'default_days' => (int) env('ENGAGE_SIGNING_DAYS', 14),
        'reminder_days' => (int) env('ENGAGE_SIGNING_REMINDER_DAYS', 3),
        'max_reminders' => (int) env('ENGAGE_SIGNING_MAX_REMINDERS', 5),
        // RFC 3161 timestamp authority. Off until the owner chooses one; only the document hash is sent.
        'tsa_url' => env('ENGAGE_TSA_URL'),
        'tsa_timeout' => (int) env('ENGAGE_TSA_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meetings
    |--------------------------------------------------------------------------
    | Browsers connect to each other directly (WebRTC). STUN is enough for most networks; a TURN relay is
    | needed for the rest (strict firewalls, some mobile networks). Set ENGAGE_TURN_URLS to enable it:
    |  - with ENGAGE_TURN_SECRET the server mints short-lived credentials (coturn "use-auth-secret"), or
    |  - with ENGAGE_TURN_USERNAME and ENGAGE_TURN_CREDENTIAL it hands out those fixed ones.
    */
    'rtc' => [
        'stun_urls' => array_filter(array_map('trim', explode(',', (string) env('ENGAGE_STUN_URLS', 'stun:stun.cloudflare.com:3478,stun:stun.l.google.com:19302')))),
        'turn_urls' => array_filter(array_map('trim', explode(',', (string) env('ENGAGE_TURN_URLS', '')))),
        'turn_secret' => env('ENGAGE_TURN_SECRET'),
        'turn_username' => env('ENGAGE_TURN_USERNAME'),
        'turn_credential' => env('ENGAGE_TURN_CREDENTIAL'),
        'turn_ttl' => (int) env('ENGAGE_TURN_TTL', 3600),
        'max_participants' => (int) env('ENGAGE_MEETING_MAX', 6),
        'waiting_room' => (bool) env('ENGAGE_WAITING_ROOM', true),
        'empty_minutes' => (int) env('ENGAGE_MEETING_EMPTY_MINUTES', 10),
        'max_hours' => (int) env('ENGAGE_MEETING_MAX_HOURS', 8),
    ],
];
