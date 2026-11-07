Dot.Engage meeting room: end-to-end tests
=========================================

What this drives: real Chromium browsers (Google Chrome from /Applications, via playwright-core) against the running
app, using Chrome's fake camera and microphone, with real WebRTC between the browsers. Evidence comes from
RTCPeerConnection.getStats() read independently of the app code (the scripts wrap RTCPeerConnection in an init script).

One-time setup (installs only playwright-core, inside this folder; it does not touch the app's package.json):

    cd tests/e2e/meeting
    npm install

The app must be running and reachable (default http://127.0.0.1:8781) with its dev database seeded
(owner@demo.test / password, engagement ENG-2026-00001 with participants Thandi and Pieter). Vite assets must be built:

    npm run build            # in the app tree

Run (from tests/e2e/meeting):

    node run.mjs             # everything below, about 4 minutes
    node run.mjs call        # (a) to (k): one meeting, three browsers (host, external on desktop, external on a 390x844 phone)
    node run.mjs full        # (l) six people meshed, seventh refused as "full" (7 browser contexts; heavy)
    node run.mjs perms       # (n) camera / microphone denied (launches Chrome WITHOUT --use-fake-ui-for-media-stream)
    node run.mjs misc        # keyboard, focus, light/dark, reduced motion, agreement tab, spotlight, 1/2-tile screenshots
    node smoke.mjs           # just the lobby and a screenshot

Environment variables:
    BASE_URL      http://127.0.0.1:8781
    ENGAGE_TREE   the Laravel tree (default: three folders above this one); used for `php artisan tinker`
    SHOTS_DIR     screenshot folder (default ./shots)
    HEADED=1      show the browsers

What the scripts change in the dev database (through the app or tinker, never migrate:fresh, never deleting):
    - create meetings through POST /e/ENG-2026-00001/meetings (one per run group)
    - issue a join link and a real emailed code for the seeded external participants (read back from storage/logs/laravel.log,
      MAIL_MAILER=log) with ParticipantAccess::issueToken and sendCode
    - add a few extra external participants named "Guest N" (e2e-guest-N@karoofresh.example) so there are enough people for
      the 6-person and "full" scenarios. They are created once (firstOrCreate) and reused.

Scenario map (each prints PASS/FAIL with the numbers it measured):
    (a) host + external 1:1, waiting room, banner, title prefix, Admit, media flowing both ways (frames, bytes, audio energy)
    (b) third participant on a phone viewport, full mesh, phone screenshots, 44 px touch targets, no horizontal scroll
    (c) camera off/on and mic mute/unmute, checked on the other side's tile and in audio energy
    (d) device switch (replaceTrack): sender tracks change, local SDP does not (no renegotiation), media still flows
    (e) screen share: banner, large tile + strip, one sharer at a time, stop via the browser's own stop and via the toolbar
    (f) HTTP offline for 8 s (Reconnecting shown, back to Live, same page); simulated ICE disconnect (tile "Connection lost",
        restartIce, new ICE credentials, healthy again)
    (g) crashed renderer (no leave request): tile gone after the presence timeout; (g2) polite close: tile gone in about a second
    (h) host lock refuses a new joiner; (i) host removes a person (cannot come back, resources released);
    (j) host mute command; (k) host ends the meeting for everyone (message, redirect, resources released)
    (l) six meshed, seventh refused as full
    (n) camera denied -> audio-only join; both denied -> viewer; microphone denied -> listen only
    (o) keyboard shortcuts (ignored while typing), focus management, lazy Agreement iframe, spotlight, reduced motion, lobby themes

Honest limits of this test setup:
    - Screen sharing is real getDisplayMedia: headless Chrome auto-selects the screen with --auto-select-desktop-capture-source
      (a 1280x800 "screen:" track). If your Chrome cannot do that, run with FAKE_SCREEN=1 for a canvas.captureStream-backed
      override. Pressing the browser's own "Stop sharing" bar cannot be automated; it is simulated by stopping the track and
      firing its 'ended' event.
    - The ICE restart path is exercised with a simulated connectionState flap on a healthy connection (the restart offer/answer
      and new ICE credentials are real); a real UDP outage cannot be produced from Playwright. The HTTP offline test is real
      (it breaks the signalling and heartbeat requests, not the media path).
    - All browsers run on one machine, so the connections are "host" candidates. No TURN server exists in dev, so relay is not tested.
    - Chrome only. Not run on Safari, Firefox or real phones (the phone viewport is Chrome emulation).
    - The app throttles by IP for people without an account, so several external participants on one machine share one budget.
