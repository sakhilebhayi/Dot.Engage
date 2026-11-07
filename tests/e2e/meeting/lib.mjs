// Shared helpers for the meeting room end-to-end scripts. Plain Node + playwright-core, no test runner.
//
// Environment:
//   BASE_URL     the running app (default http://127.0.0.1:8781)
//   ENGAGE_TREE  the Laravel tree, used for `php artisan tinker` (default: three folders up from here)
//   SHOTS_DIR    where screenshots go (default ./shots)
//   HEADED=1     show the browsers
import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const TREE = process.env.ENGAGE_TREE || path.resolve(here, '../../..');
export const BASE = process.env.BASE_URL || 'http://127.0.0.1:8781';
export const SHOTS = process.env.SHOTS_DIR || path.join(here, 'shots');
export const ENGAGEMENT = 'ENG-2026-00001';
fs.mkdirSync(SHOTS, { recursive: true });

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export const CHROME_ARGS = [
    '--use-fake-ui-for-media-stream',
    '--use-fake-device-for-media-stream',
    '--autoplay-policy=no-user-gesture-required',
    '--disable-features=WebRtcHideLocalIpsWithMdns',
    '--auto-select-desktop-capture-source=Entire screen',
    '--enable-usermedia-screen-capturing',
    '--allow-http-screen-capture',
];

export async function launch() {
    const headless = process.env.HEADED ? false : true;
    try {
        return await chromium.launch({ channel: 'chrome', headless, args: CHROME_ARGS, ignoreDefaultArgs: ['--mute-audio'] });
    } catch (e) {
        return chromium.launch({ headless, args: CHROME_ARGS, ignoreDefaultArgs: ['--mute-audio'] });
    }
}

/** Wraps RTCPeerConnection so the tests can read real WebRTC stats independently of the app code. */
export const PC_HOOK = () => {
    const Orig = window.RTCPeerConnection;
    window.__pcs = [];
    function Wrapped(...args) {
        const pc = new Orig(...args);
        window.__pcs.push(pc);
        return pc;
    }
    Wrapped.prototype = Orig.prototype;
    Object.setPrototypeOf(Wrapped, Orig);
    window.RTCPeerConnection = Wrapped;

    // Remember every capture track and audio context, so the tests can prove that leaving frees them.
    window.__tracks = [];
    window.__ctxs = [];
    const md = navigator.mediaDevices;
    if (md) {
        for (const name of ['getUserMedia', 'getDisplayMedia']) {
            const fn = md[name] && md[name].bind(md);
            if (!fn) continue;
            md[name] = async (...a) => { const s = await fn(...a); s.getTracks().forEach((t) => window.__tracks.push(t)); return s; };
        }
    }
    const OrigAC = window.AudioContext;
    if (OrigAC) {
        function WrappedAC(...a) { const c = new OrigAC(...a); window.__ctxs.push(c); return c; }
        WrappedAC.prototype = OrigAC.prototype;
        Object.setPrototypeOf(WrappedAC, OrigAC);
        window.AudioContext = WrappedAC;
    }
};

/** A canvas-backed getDisplayMedia, used only where headless Chromium cannot capture a screen. */
export const FAKE_SCREEN = () => {
    navigator.mediaDevices.getDisplayMedia = async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 1280; canvas.height = 720;
        const g = canvas.getContext('2d');
        let n = 0;
        const timer = setInterval(() => {
            n += 1;
            g.fillStyle = '#fff'; g.fillRect(0, 0, 1280, 720);
            g.fillStyle = '#000'; g.font = '48px sans-serif'; g.fillText('Shared screen ' + n, 80, 200);
            g.fillStyle = `hsl(${(n * 7) % 360} 70% 50%)`; g.fillRect((n * 9) % 1000, 300, 200, 200);
        }, 60);
        const stream = canvas.captureStream(15);
        const track = stream.getVideoTracks()[0];
        const stop = track.stop.bind(track);
        track.stop = () => { clearInterval(timer); stop(); };
        window.__fakeScreen = { track, stop: () => { track.stop(); track.dispatchEvent(new Event('ended')); } };
        return stream;
    };
};

// ── php artisan helpers ──────────────────────────────────────────────────────

export function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: TREE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

export function ensureGuest(n) {
    const email = `e2e-guest-${n}@karoofresh.example`;
    tinker(`App\\Models\\EngagementParticipant::withoutGlobalScopes()->firstOrCreate(['engagement_id'=>1,'email'=>'${email}'],['party_id'=>2,'name'=>'Guest ${n}','role'=>'external','capabilities'=>['sign','approve'],'required'=>false,'invited_by'=>1,'invitation_sent_at'=>now(),'accepted_at'=>now(),'token_expires_at'=>now()->addDays(14)]);`);
    return { email, name: `Guest ${n}` };
}

/** Issue a join link and a real emailed code (read back from the log mailer) for a seeded participant. */
export function issueAccess(email) {
    const out = tinker(`$p=App\\Models\\EngagementParticipant::withoutGlobalScopes()->where('email','${email}')->first(); $pa=app(App\\Services\\Engage\\ParticipantAccess::class); $t=$pa->issueToken($p); $p->forceFill(['otp_sent_at'=>null,'otp_attempts'=>0,'revoked_at'=>null])->save(); $pa->sendCode($p,$p->engagement()->withoutGlobalScopes()->first()); echo 'TOKEN='.$t;`);
    const token = /TOKEN=([A-Za-z0-9]{64})/.exec(out)?.[1];
    if (!token) throw new Error('no token from tinker: ' + out.slice(-300));
    const log = fs.readFileSync(path.join(TREE, 'storage/logs/laravel.log'), 'utf8');
    const codes = [...log.matchAll(/Subject: Your Dot\.Engage code: (\d{6})/g)];
    const code = codes.at(-1)?.[1];
    if (!code) throw new Error('no code in laravel.log');
    return { token, code };
}

// ── People ───────────────────────────────────────────────────────────────────

const OPEN_PAGES = new Set();

function watch(page, name, bag) {
    page.on('console', (m) => {
        if (m.type() === 'error') bag.errors.push(m.text());
        if (m.type() === 'warning') bag.warnings.push(m.text());
    });
    page.on('pageerror', (e) => bag.errors.push('pageerror: ' + e.message));
    page.on('dialog', (d) => { bag.dialogs.push(d.message()); d.accept().catch(() => {}); });
}

export async function newPerson(browser, { name, kind = 'guest', email, viewport = { width: 1280, height: 800 }, permissions = ['camera', 'microphone'], fakeScreen = false, colorScheme = 'light', mobile = false, extraInit = [] }) {
    const ctx = await browser.newContext({
        viewport, permissions, colorScheme, baseURL: BASE, ignoreHTTPSErrors: true,
        ...(mobile ? { isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : {}),
    });
    await ctx.addInitScript(PC_HOOK);
    if (fakeScreen) await ctx.addInitScript(FAKE_SCREEN);
    for (const s of extraInit) await ctx.addInitScript(s);
    const page = await ctx.newPage();
    const person = { name, kind, email, ctx, page, errors: [], warnings: [], dialogs: [] };
    watch(page, name, person);
    OPEN_PAGES.add(person);
    return person;
}

export async function login(person) {
    const { page } = person;
    await page.goto('/login');
    await page.fill('input[name="email"]', 'owner@demo.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }), page.click('button[type="submit"]')]);
}

export async function csrfOf(page) {
    return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
}

export async function createMeeting(host, title = 'E2E meeting') {
    const { page } = host;
    await page.goto(`/e/${ENGAGEMENT}`);
    const token = await csrfOf(page);
    const res = await page.request.post(`/e/${ENGAGEMENT}/meetings`, { form: { _token: token, title }, maxRedirects: 0 });
    const loc = res.headers()['location'] || '';
    const key = /\/meetings\/([A-Za-z0-9]{32})/.exec(loc)?.[1];
    if (!key) throw new Error(`could not create a meeting (status ${res.status()}, location ${loc})`);
    return key;
}

export async function guestSignIn(person, key) {
    const { page } = person;
    const { token, code } = issueAccess(person.email);
    await page.goto(`/join/${token}?to=meeting:${key}`);
    const csrf = await csrfOf(page);
    const res = await page.request.post(`/join/${token}/verify`, { form: { _token: csrf, code }, maxRedirects: 0 });
    if (res.status() !== 302) throw new Error(`code check failed for ${person.name}: ${res.status()}`);
}

export async function openRoom(person, key) {
    const res = await person.page.goto(`/meetings/${key}`);
    return res;
}

/** Wait for the lobby to finish its device checks, then press Join. Resolves with the phase reached. */
export async function press(person, { timeout = 30000 } = {}) {
    const { page } = person;
    await page.waitForSelector('#btn-join:not([disabled])', { timeout });
    await page.click('#btn-join');
    return page.waitForFunction(() => document.body.dataset.state, null, { timeout }).then(() => phase(person));
}

export async function phase(person) {
    return person.page.evaluate(() => document.body.dataset.state);
}

export async function waitPhase(person, wanted, timeout = 30000) {
    await person.page.waitForFunction((w) => document.body.dataset.state === w, wanted, { timeout });
}

export async function admitAll(host, names) {
    const { page } = host;
    for (const name of names) {
        await page.waitForSelector(`#waiters button[data-act="admit"][data-name="${name}"], #banner .banner-item`, { timeout: 15000 });
        // The host panel is the reliable control; the banner has the same action.
        const open = await page.getAttribute('#ctl-people', 'aria-pressed');
        if (open !== 'true') await page.click('#ctl-people');
        await page.click(`#waiters button[data-act="admit"][data-name="${name}"]`);
    }
}

// ── Reading real WebRTC state ────────────────────────────────────────────────

/** For each media connection on the page: state and inbound counters from getStats(). */
export async function rtcSnapshot(page) {
    return page.evaluate(async () => {
        const out = [];
        for (const pc of window.__pcs || []) {
            if (pc.signalingState === 'closed' || pc.getTransceivers().length < 2) continue;
            const r = { connectionState: pc.connectionState, ice: pc.iceConnectionState, signaling: pc.signalingState, audio: null, video: null, screen: null, outVideoBytes: 0, outAudioBytes: 0, route: null };
            const stats = await pc.getStats();
            const byId = new Map(); stats.forEach((s) => byId.set(s.id, s));
            let videoIdx = 0;
            stats.forEach((s) => {
                if (s.type === 'inbound-rtp' && !s.isRemote) {
                    const rec = { bytes: s.bytesReceived, packets: s.packetsReceived, frames: s.framesDecoded ?? 0, level: s.audioLevel ?? null, energy: s.totalAudioEnergy ?? null, w: s.frameWidth ?? null };
                    if (s.kind === 'audio') r.audio = rec;
                    else if (!r.video || (rec.bytes > r.video.bytes)) { if (r.video) r.screen = r.video; r.video = rec; } else r.screen = rec;
                    videoIdx++;
                } else if (s.type === 'outbound-rtp') {
                    if (s.kind === 'audio') r.outAudioBytes += s.bytesSent || 0; else r.outVideoBytes += s.bytesSent || 0;
                } else if (s.type === 'transport' && s.selectedCandidatePairId) {
                    const pair = byId.get(s.selectedCandidatePairId);
                    const local = pair && byId.get(pair.localCandidateId);
                    r.route = local ? local.candidateType : null;
                }
            });
            out.push(r);
        }
        return out;
    });
}

/**
 * Two samples apart: assert N connections are connected and that remote bytes grow for audio and video.
 * Returns the numbers for the report.
 */
export async function assertFlow(page, { peers, video = true, audio = true, gap = 2000, label = '' }) {
    const a = await rtcSnapshot(page);
    await sleep(gap);
    const b = await rtcSnapshot(page);
    const live = b.filter((p) => p.connectionState === 'connected');
    if (live.length !== peers) throw new Error(`${label} expected ${peers} connected peer connections, saw ${live.length}: ${JSON.stringify(b.map((p) => p.connectionState))}`);
    const detail = [];
    for (let i = 0; i < b.length; i++) {
        const pb = b[i];
        const pa = a[i] || {};
        if (video) {
            if (!pb.video || !(pb.video.frames > 0)) throw new Error(`${label} peer ${i}: no video frames decoded ${JSON.stringify(pb.video)}`);
            if (!(pb.video.bytes > (pa.video?.bytes ?? 0))) throw new Error(`${label} peer ${i}: video bytes not growing`);
        }
        if (audio) {
            if (!pb.audio || !(pb.audio.bytes > (pa.audio?.bytes ?? 0))) throw new Error(`${label} peer ${i}: audio bytes not growing ${JSON.stringify(pb.audio)}`);
            if (!(pb.audio.energy > 0)) throw new Error(`${label} peer ${i}: no audio energy (fake mic should beep)`);
        }
        detail.push({ frames: pb.video?.frames ?? 0, vBytes: pb.video?.bytes ?? 0, aBytes: pb.audio?.bytes ?? 0, energy: pb.audio?.energy ?? 0, route: pb.route });
    }
    return detail;
}

export async function tiles(page) {
    return page.evaluate(() => [...document.querySelectorAll('#grid .tile')].map((t) => ({
        key: t.dataset.key, kind: t.dataset.kind, name: t.querySelector('.tile-name')?.textContent, state: t.dataset.state,
        cam: t.dataset.cam, status: t.querySelector('.tile-status .state')?.textContent || '',
        micOff: !!t.querySelector('[aria-label="Microphone off"]'), camOff: !!t.querySelector('[aria-label="Camera off"]'),
        speaking: t.dataset.speaking, net: t.querySelector('.tile-net')?.textContent?.trim() || '',
        focus: t.classList.contains('is-focus'), box: (() => { const r = t.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; })(),
    })));
}

export async function waitTile(page, pred, desc, timeout = 30000) {
    const end = Date.now() + timeout;
    let last = [];
    while (Date.now() < end) {
        last = await tiles(page);
        if (pred(last)) return last;
        await sleep(300);
    }
    throw new Error(`timeout waiting for ${desc}; tiles: ${JSON.stringify(last)}`);
}

export async function shot(person, file, opts = {}) {
    const p = path.join(SHOTS, file);
    await person.page.screenshot({ path: p, ...opts });
    return p;
}

export async function closeAll() {
    for (const p of OPEN_PAGES) { try { await p.ctx.close(); } catch (e) { /* closed */ } }
    OPEN_PAGES.clear();
}

export function report(results) {
    for (const r of results) console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.name}${r.info ? '  ' + r.info : ''}${r.error ? '\n      ' + r.error : ''}`);
}
