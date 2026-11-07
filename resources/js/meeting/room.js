// Dot.Engage meeting room. Browsers connect to each other (WebRTC mesh); this page talks to the server only to
// find out who is in the room, to pass connection messages, and to say "I am still here".

import { $, fmtClock, h, isTyping, penOf, randomId, safeParse, sleep, swapIcon, worstQuality, QUALITY_WORD } from './util.js';
import { ApiError, createApi } from './api.js';
import { Announcer } from './announce.js';
import { LocalMedia } from './media.js';
import { LevelMeter, closeShared, resumeShared } from './audio.js';
import { Lobby } from './lobby.js';
import { Peer } from './peer.js';
import { Grid } from './grid.js';
import { Panel } from './panel.js';
import { Banner } from './banner.js';

const POLL_BUSY_MS = 1000;
const POLL_CALM_MS = 2500;
const POLL_HIDDEN_MS = 5000;
const POLL_WAITING_MS = 2000;
const BUSY_AFTER_CHANGE_MS = 20000;
const STATS_MS = 2000;
const REPORT_MS = 5000;
const LOST_TILE_GRACE_MS = 4000;
const SPEAKING_RMS = 0.012;
const SPEAKING_HOLD_MS = 450;

class Room {
    constructor(root) {
        this.root = root;
        this.cfg = safeParse(root.dataset.config) || {};
        this.urls = this.cfg.urls || {};
        this.home = root.dataset.leave || this.urls.leaveTo || '/';
        this.clientId = randomId(10);
        this.sid = this.clientId;
        this.api = createApi({ clientId: this.clientId });
        this.announcer = new Announcer();
        this.media = new LocalMedia();
        this.ac = new AbortController();
        this.baseTitle = document.title;

        this.phase = 'lobby'; // lobby | entering | waiting | live | ended
        this.myId = null;
        this.isHost = !!this.cfg.isHost;
        this.since = 0;
        this.peers = new Map();
        this.roster = [];
        this.rosterById = new Map();
        this.rosterReady = false;
        this.lostSince = new Map();
        this.lastRosterChange = 0;
        this.meeting = { status: 'live', locked: false };
        this.iceServers = [];
        this.joinedAt = 0;
        this.pollFailures = 0;
        this.pollTimer = null;
        this.polling = false;
        this.pollAgain = false;
        this.pollDelayOverride = 0;
        this.net = 'ok'; // ok | reconnecting
        this.ownQuality = null;
        this.timers = {};
        this.sendQueue = [];
        this.pumping = false;
        this.stopped = false;
        this.joining = false;
        this.leaving = false;
        this.pinnedKey = null;
        this.meters = new Map();
        this.speaking = new Map();
        this.selfPreview = { track: null, stream: null };
        this.waitingIds = new Set();
        this.sharingIds = new Set();
        this.lastBandwidthCount = -1;

        this.el = {
            status: $('room-status'), clock: $('room-clock'), net: $('room-net'),
            lobby: $('lobby'), waiting: $('waiting'), stage: $('stage'), controls: $('controls'), notice: $('notice'),
            grid: $('grid'), mic: $('ctl-mic'), cam: $('ctl-cam'), share: $('ctl-share'), people: $('ctl-people'),
            lock: $('ctl-lock'), leave: $('ctl-leave'), end: $('ctl-end'), devices: $('ctl-devices'), devicesPanel: $('devices-panel'),
            join: $('btn-join'), cancelWait: $('btn-cancel-wait'), waitingH: $('waiting-h'), waitingText: $('waiting-text'),
        };

        this.banner = new Banner($('banner'));
        this.panel = new Panel($('side'), {
            onClose: () => this.closePanel(),
            onAction: (act, id, name) => this.onPanelAction(act, id, name),
        });
        this.grid = new Grid(this.el.grid, {
            onPin: (key) => this.togglePin(key),
            onRetry: (key) => this.retryTile(key),
            onBlocked: () => this.soundBlocked(),
        });
        this.lobby = new Lobby({ media: this.media, api: this.api, cfg: { ...this.cfg, urls: this.urls }, announcer: this.announcer });

        const room = this;
        this.peerCtx = {
            get myId() { return room.myId; },
            sid: this.sid,
            media: this.media,
            iceServers: () => this.iceServers,
            send: (type, to, payload) => this.queueSignal(type, to, payload),
            changed: (peer, what) => this.onPeerChanged(peer, what),
            log: (...args) => this.log(...args),
        };

        this.bind();
        this.setPhase('lobby');
        this.lobby.start().then(() => { if (this.phase === 'lobby') this.el.join.focus({ preventScroll: true }); });
    }

    log(...args) {
        try { if (localStorage.getItem('engage-debug')) console.debug('[meeting]', ...args); } catch (e) { /* storage blocked */ }
    }

    // ── Wiring ───────────────────────────────────────────────────────────────

    bind() {
        const signal = this.ac.signal;
        const on = (target, name, fn, opts = {}) => target.addEventListener(name, fn, { signal, ...opts });
        const el = this.el;

        on(el.join, 'click', () => this.onJoinClick());
        on(el.cancelWait, 'click', () => this.leave());
        on(el.mic, 'click', () => this.toggleMic());
        on(el.cam, 'click', () => this.toggleCam());
        on(el.share, 'click', () => this.toggleShare());
        on(el.people, 'click', () => (this.panel.isOpen ? this.closePanel() : this.openPanel()));
        on(el.leave, 'click', () => this.leave());
        el.lock?.addEventListener('click', () => this.hostAction(this.meeting.locked ? 'unlock' : 'lock'), { signal });
        el.end?.addEventListener('click', () => this.endForEveryone(), { signal });
        on(el.devices, 'toggle', () => { if (el.devices.open) this.renderDevices(); });

        on(document, 'keydown', (event) => this.onKey(event));
        on(document, 'click', (event) => {
            if (el.devices.open && !el.devices.contains(event.target)) el.devices.open = false;
        });
        on(document, 'visibilitychange', () => this.onVisibility());
        on(window, 'online', () => this.onOnline());
        on(window, 'offline', () => this.onOffline());
        on(window, 'pagehide', () => this.onPageHide());

        this.media.on('track', (kind, track) => this.onLocalTrack(kind, track));
        this.media.on('state', () => this.syncControls());
        this.media.on('devices', () => { if (this.phase === 'live') this.renderDevices(); });
        this.media.on('speaker', (id) => this.grid.setSink(id));
        this.media.on('screen-ended', () => this.stopShare({ announce: true }));
        this.media.on('lost', (kind) => {
            this.announcer.say(kind === 'audio' ? 'Your microphone stopped working' : 'Your camera stopped working');
            this.banner.set('device', { text: kind === 'audio' ? 'Your microphone stopped working. Pick another in Devices.' : 'Your camera stopped working. Pick another in Devices.', tone: 'warn', ttl: 10000 });
            this.syncControls();
            this.queueState();
        });
    }

    setPhase(phase) {
        this.phase = phase;
        document.body.dataset.state = phase;
        const { lobby, waiting, stage, controls, notice } = this.el;
        lobby.hidden = phase !== 'lobby' && phase !== 'entering';
        waiting.hidden = phase !== 'waiting';
        stage.hidden = phase !== 'live';
        controls.hidden = phase !== 'live';
        if (phase !== 'ended') notice.hidden = true;
        this.renderStatus();
    }

    renderStatus() {
        const words = {
            lobby: ['Not joined', ''], entering: ['Not joined', ''], waiting: ['Waiting', 'is-warn'],
            live: this.net === 'reconnecting' ? ['Reconnecting', 'is-warn'] : ['Live', 'is-live'], ended: ['Ended', 'is-ink'],
        }[this.phase];
        this.el.status.className = `state ${words[1]}`.trim();
        this.el.status.textContent = words[0];
    }

    // ── Entering ─────────────────────────────────────────────────────────────

    async onJoinClick() {
        if (this.phase !== 'lobby') return;
        resumeShared(); // a click is the gesture that lets sound play
        this.phase = 'entering';
        this.el.join.setAttribute('data-busy', 'true');
        this.el.join.disabled = true;
        this.lobby.stop();
        await this.enter();
    }

    async enter() {
        try {
            const reply = await this.api.post(this.urls.enter, { client_id: this.clientId });
            this.myId = reply.attendee_id;
            this.isHost = !!reply.is_host || this.isHost;
            this.since = 0;
            this.afterEnter(reply.state);
        } catch (e) {
            this.onEnterError(e);
        }
    }

    afterEnter(state) {
        if (state === 'waiting') {
            this.showWaiting();
        } else if (state === 'admitted' || state === 'joined') {
            this.doJoin();
        } else if (state === 'denied') {
            this.finish('denied');
        } else if (state === 'removed') {
            this.finish('removed');
        } else {
            this.showWaiting();
        }
    }

    showWaiting() {
        this.setPhase('waiting');
        this.el.waitingH.textContent = 'Waiting for the host';
        this.el.waitingText.textContent = 'The host will let you in shortly. You can leave this page open.';
        this.startPolling();
        this.el.cancelWait.focus({ preventScroll: true });
    }

    onEnterError(e) {
        this.log('enter failed', e);
        if (e instanceof ApiError && e.displaced) return this.displaced();
        if (e instanceof ApiError && e.status === 403) {
            if (/locked/i.test(e.message)) return this.finish('locked', e.message);
            if (/removed/i.test(e.message)) return this.finish('removed', e.message);
            if (/ended/i.test(e.message)) return this.finish('ended', 'ended');
            return this.finish('refused', e.message);
        }
        if (e instanceof ApiError && (e.status === 419 || e.status === 401)) return this.finish('expired');
        // Anything else: back to the lobby so the person can try again.
        this.setPhase('lobby');
        this.lobby = new Lobby({ media: this.media, api: this.api, cfg: { ...this.cfg, urls: this.urls }, announcer: this.announcer });
        this.lobby.render();
        this.lobby.startMeter();
        this.el.join.disabled = false;
        this.el.join.removeAttribute('data-busy');
        this.el.join.focus({ preventScroll: true });
        const message = e instanceof ApiError && e.status === 429 ? 'Too many tries. Wait a moment and press Join again.'
            : e instanceof ApiError && e.network ? 'Could not reach the server. Check your connection and press Join again.'
                : `${e.message || 'Could not join.'} Press Join to try again.`;
        $('lobby-msg').textContent = message;
    }

    async doJoin() {
        if (this.joining || this.stopped) return;
        this.joining = true;
        try {
            // Anything addressed to an earlier visit of ours is stale: read past it.
            let page;
            do {
                page = await this.api.get(`${this.urls.poll}?since=${this.since}`);
                this.since = Math.max(this.since, page.last_id | 0);
            } while (page.signals.length >= 200);
            const reply = await this.api.post(this.urls.join, {});
            this.iceServers = reply.ice || [];
            this.relay = !!reply.relay;
            this.myId = reply.attendee_id ?? this.myId;
            this.enterCall();
        } catch (e) {
            this.onJoinError(e);
        } finally {
            this.joining = false;
        }
    }

    onJoinError(e) {
        this.log('join failed', e);
        if (e instanceof ApiError && e.displaced) return this.displaced();
        if (e instanceof ApiError && e.status === 403) {
            if (/waiting room/i.test(e.message)) return this.showWaiting();
            if (/full/i.test(e.message)) return this.finish('full', e.message);
            if (/ended/i.test(e.message)) return this.finish('ended', 'ended');
            return this.finish('refused', e.message);
        }
        if (e instanceof ApiError && (e.status === 419 || e.status === 401)) return this.finish('expired');
        if (this.phase === 'waiting' || this.phase === 'entering') {
            // Network trouble while joining: the poll loop will notice the admitted state again and retry.
            this.banner.set('join', { text: 'Could not join yet. Trying again.', tone: 'warn', ttl: 6000 });
            if (this.phase === 'entering') this.showWaiting();
        }
    }

    enterCall() {
        this.setPhase('live');
        this.joinedAt = Date.now();
        this.lastRosterChange = Date.now();
        this.rosterReady = false;
        this.net = 'ok';
        this.renderStatus();
        this.syncControls();
        this.startTimers();
        this.renderTiles();
        this.queueState();
        this.startPolling();
        this.pollNow();
        this.el.mic.focus({ preventScroll: true });
        this.announcer.say('You joined the meeting');
        if (!this.media.canShare) {
            this.el.share.disabled = true;
            this.el.share.title = 'Screen sharing is not available on this device';
        }
    }

    // ── Polling ──────────────────────────────────────────────────────────────

    startPolling() {
        this.stopped = false;
        this.schedulePoll(this.phase === 'waiting' ? 400 : 0);
    }

    schedulePoll(delay) {
        clearTimeout(this.pollTimer);
        if (this.stopped) return;
        this.pollTimer = setTimeout(() => this.pollTick(), delay ?? this.nextPollDelay());
    }

    nextPollDelay() {
        if (this.pollDelayOverride) {
            const d = this.pollDelayOverride;
            this.pollDelayOverride = 0;
            return d;
        }
        if (this.phase === 'waiting') return POLL_WAITING_MS;
        const negotiating = [...this.peers.values()].some((p) => p.status !== 'connected');
        const recent = Date.now() - this.lastRosterChange < BUSY_AFTER_CHANGE_MS;
        const busy = negotiating || recent || this.sendQueue.length > 0;
        if (document.hidden && !busy) return POLL_HIDDEN_MS;
        return busy ? POLL_BUSY_MS : POLL_CALM_MS;
    }

    pollNow() {
        if (this.stopped) return;
        if (this.polling) { this.pollAgain = true; return; }
        clearTimeout(this.pollTimer);
        this.pollTick();
    }

    async pollTick() {
        if (this.polling || this.stopped) return;
        this.polling = true;
        const started = performance.now();
        try {
            const data = await this.api.get(`${this.urls.poll}?since=${this.since}`);
            this.latency = performance.now() - started;
            this.pollFailures = 0;
            if (this.net === 'reconnecting') this.setNet('ok');
            if (!this.stopped) this.onSnapshot(data);
        } catch (e) {
            this.onPollError(e);
        } finally {
            this.polling = false;
            if (!this.stopped) {
                if (this.pollAgain) { this.pollAgain = false; this.schedulePoll(50); } else this.schedulePoll();
            }
        }
    }

    onPollError(e) {
        this.log('poll failed', e);
        if (!(e instanceof ApiError)) { this.pollFailures += 1; return; }
        if (e.displaced) { this.displaced(); return; }
        if (e.status === 419 || e.status === 401) { this.finish('expired'); return; }
        if (e.notEntered) { this.reenter(); return; }
        if (e.status === 403 || e.status === 404) { this.finish('refused', e.status === 404 ? 'This meeting is no longer available.' : e.message); return; }
        if (e.status === 429) {
            this.pollDelayOverride = Math.max(5000, (e.retryAfter || 0) * 1000);
            return;
        }
        this.pollFailures += 1;
        this.pollDelayOverride = Math.min(15000, 1000 * 2 ** Math.min(this.pollFailures - 1, 4)) + Math.random() * 400;
        if ((this.pollFailures >= 2 || !navigator.onLine) && this.phase === 'live') this.setNet('reconnecting');
    }

    setNet(state) {
        if (this.net === state) return;
        this.net = state;
        this.renderStatus();
        if (state === 'reconnecting') {
            this.banner.set('net', { text: 'Reconnecting. Your connection dropped; the call will resume when it is back.', tone: 'warn' });
            this.announcer.say('Reconnecting');
        } else {
            this.banner.clear('net');
            this.banner.set('net-ok', { text: 'Back online.', tone: 'info', ttl: 3000 });
            this.announcer.say('Connection restored');
            this.pumpSignals();
            for (const peer of this.peers.values()) peer.kick();
        }
        this.updateNet();
    }

    onOffline() {
        if (this.phase === 'live') this.setNet('reconnecting');
    }

    onOnline() {
        this.pollFailures = 0;
        this.pumpSignals();
        for (const peer of this.peers.values()) peer.kick();
        this.pollNow();
    }

    onVisibility() {
        if (document.hidden) return;
        this.pollNow();
        this.grid.layout();
        if (this.phase === 'live') {
            for (const peer of this.peers.values()) peer.kick();
            this.grid.playAll();
        }
    }

    onSnapshot(data) {
        this.since = Math.max(this.since, data.last_id | 0);
        this.meeting = data.meeting || this.meeting;
        const me = data.me || {};
        this.myId = me.id ?? this.myId;
        this.isHost = !!me.is_host || this.isHost;

        if (this.meeting.status === 'ended' || this.meeting.status === 'cancelled') {
            this.finish('ended', this.meeting.ended_reason);
            return;
        }
        if (this.phase === 'waiting') {
            if (me.state === 'admitted' || me.state === 'joined') this.doJoin();
            else if (me.state === 'denied') this.finish('denied');
            else if (me.state === 'removed') this.finish('removed');
            return;
        }
        if (this.phase !== 'live') return;

        if (me.state === 'removed') { this.finish('removed'); return; }
        if (me.state === 'denied') { this.finish('denied'); return; }
        if (me.state === 'left' || me.state === 'admitted') { this.rejoin(); return; }

        this.applyRoster(data.roster || []);
        this.dispatchSignals(data.signals || []);
        this.updateAll();
    }

    async reenter() {
        if (this.reentering) return;
        this.reentering = true;
        try { await this.enter(); } finally { this.reentering = false; }
    }

    async rejoin() {
        if (this.rejoining || this.stopped) return;
        this.rejoining = true;
        try {
            await this.api.post(this.urls.enter, { client_id: this.clientId });
            await this.api.post(this.urls.join, {});
            this.queueState();
        } catch (e) {
            this.onPollError(e);
        } finally {
            this.rejoining = false;
        }
    }

    // ── Roster and peers ─────────────────────────────────────────────────────

    applyRoster(roster) {
        const now = new Map(roster.map((r) => [r.id, r]));
        const prev = this.rosterById;
        let changed = false;

        for (const r of roster) {
            if (r.me) continue;
            const old = prev.get(r.id);
            if (!old || old.state !== r.state || old.sharing !== r.sharing || old.is_host !== r.is_host) changed = true;
            if (!this.rosterReady) continue;
            if (r.state === 'waiting' && (!old || old.state !== 'waiting') && this.isHost) this.announcer.say(`${r.name} is waiting to join`);
            if (r.state === 'joined' && (!old || old.state !== 'joined') && !(old && old.state === 'lost')) this.announcer.say(`${r.name} joined`);
            if (r.state === 'lost' && old && old.state === 'joined') this.announcer.say(`${r.name} lost connection`);
            if (old && old.state === 'joined' && r.state === 'joined') {
                if (old.mic !== r.mic && r.mic === false) this.announcer.say(`${r.name} muted`);
                if (old.mic !== r.mic && r.mic === true && old.mic === false) this.announcer.say(`${r.name} unmuted`);
                if (!old.sharing && r.sharing) this.announcer.say(`${r.name} started sharing their screen`);
                if (old.sharing && !r.sharing) this.announcer.say(`${r.name} stopped sharing`);
            }
        }
        for (const old of prev.values()) {
            if (old.me || now.has(old.id)) continue;
            changed = true;
            if (this.rosterReady && (old.state === 'joined' || old.state === 'lost')) this.announcer.say(`${old.name} left`);
        }
        if (changed) this.lastRosterChange = Date.now();

        this.roster = roster;
        this.rosterById = now;
        this.rosterReady = true;

        // Peers follow the roster.
        for (const r of roster) {
            if (r.me) continue;
            let peer = this.peers.get(r.id);
            if (r.state === 'joined' || r.state === 'lost') {
                if (!peer && r.state === 'joined') peer = this.createPeer(r);
                if (peer) {
                    peer.remote = r;
                    if (r.state === 'lost') { if (!this.lostSince.has(r.id)) this.lostSince.set(r.id, Date.now()); } else this.lostSince.delete(r.id);
                }
            } else if (peer) {
                this.removePeer(r.id);
            }
        }
        for (const id of [...this.peers.keys()]) {
            if (!now.has(id)) this.removePeer(id);
        }
        for (const [id, since] of this.lostSince) {
            if (Date.now() - since >= LOST_TILE_GRACE_MS && this.peers.has(id)) this.removePeer(id);
            if (!this.peers.has(id)) this.lostSince.delete(id);
        }
        this.refreshBandwidth();
    }

    createPeer(remote, { fromOffer = false } = {}) {
        const peer = new Peer(this.peerCtx, remote, { fromOffer });
        this.peers.set(remote.id, peer);
        this.log('peer created', remote.id, remote.name, peer.polite ? 'polite' : 'impolite');
        this.syncMeters();
        return peer;
    }

    removePeer(id) {
        const peer = this.peers.get(id);
        if (!peer) return;
        peer.close();
        this.peers.delete(id);
        this.lostSince.delete(id);
        if (this.pinnedKey === `p${id}` || this.pinnedKey === `s${id}`) this.pinnedKey = null;
        this.syncMeters();
        this.refreshBandwidth();
    }

    refreshBandwidth() {
        const count = this.peers.size;
        if (count === this.lastBandwidthCount) return;
        this.lastBandwidthCount = count;
        for (const peer of this.peers.values()) peer.applyBandwidth(count);
    }

    onPeerChanged(peer, what) {
        if (this.stopped) return;
        if (what === 'connected' || what === 'recovered') {
            peer.applyBandwidth(this.peers.size);
            if (what === 'recovered') this.announcer.say(`Reconnected to ${peer.name}`);
        }
        if (what === 'tracks' || what === 'rebuilt') this.syncMeters();
        if (this.phase === 'live') this.updateAll();
    }

    dispatchSignals(signals) {
        for (const s of signals) {
            const payload = safeParse(s.payload);
            if (!payload || typeof payload !== 'object') continue;
            const sender = this.rosterById.get(s.from);
            if (s.type === 'state' && payload.command) {
                if (sender && sender.is_host) this.onHostCommand(payload.command);
                continue;
            }
            if (!sender || (sender.state !== 'joined' && sender.state !== 'lost')) continue; // gone: ignore what is left of them
            if (s.type !== 'offer' && s.type !== 'answer' && s.type !== 'candidate') continue;
            let peer = this.peers.get(s.from);
            if (!peer) {
                if (s.type !== 'offer') continue;
                peer = this.createPeer(sender, { fromOffer: true });
            }
            peer.receive(s.type, payload);
        }
    }

    // ── Sending signals ──────────────────────────────────────────────────────

    queueSignal(type, to, payload) {
        if (this.stopped) return;
        this.sendQueue.push({ type, to, payload, at: Date.now(), tries: 0 });
        this.pumpSignals();
    }

    async pumpSignals() {
        if (this.pumping) return;
        this.pumping = true;
        try {
            while (this.sendQueue.length && !this.stopped) {
                const item = this.sendQueue[0];
                if (item.type === 'candidate' && Date.now() - item.at > 30000) { this.sendQueue.shift(); continue; }
                try {
                    await this.api.post(this.urls.signal, { to: item.to, type: item.type, payload: item.payload });
                    this.sendQueue.shift();
                } catch (e) {
                    if (e instanceof ApiError && e.displaced) { this.displaced(); break; }
                    if (e instanceof ApiError && (e.status === 422 || e.status === 409 || e.status === 403 || e.status === 404)) {
                        this.sendQueue.shift(); // the other person is gone, or we are not in: nothing to wait for
                        continue;
                    }
                    if (e instanceof ApiError && (e.status === 419 || e.status === 401)) { this.finish('expired'); break; }
                    item.tries += 1;
                    if (item.tries > 8) { this.sendQueue.shift(); continue; }
                    const wait = e instanceof ApiError && e.status === 429 ? Math.max(3000, (e.retryAfter || 0) * 1000) : Math.min(6000, 400 * 2 ** item.tries);
                    await sleep(wait);
                }
            }
        } finally {
            this.pumping = false;
        }
    }

    // ── My own state ─────────────────────────────────────────────────────────

    get micOn() { return this.media.hasAudio && this.media.micOn; }
    get camOn() { return this.media.hasVideo && this.media.camOn; }
    get sharing() { return !!this.media.screenTrack; }

    queueState() {
        clearTimeout(this.timers.state);
        this.timers.state = setTimeout(() => this.postState(), 120);
    }

    async postState() {
        if (this.stopped || this.phase !== 'live') return;
        const body = { mic: this.micOn, cam: this.camOn, sharing: this.sharing };
        if (this.ownQuality) body.quality = this.ownQuality;
        try {
            await this.api.post(this.urls.state, body);
        } catch (e) {
            if (e instanceof ApiError && e.displaced) this.displaced();
            else if (e instanceof ApiError && (e.status === 419 || e.status === 401)) this.finish('expired');
        }
    }

    // ── Controls ─────────────────────────────────────────────────────────────

    toggleMic() {
        if (!this.media.hasAudio) {
            this.announcer.say('No microphone is available');
            this.banner.set('device', { text: 'No microphone is available, so you are listening only.', tone: 'warn', ttl: 6000 });
            return;
        }
        this.media.setMic(!this.media.micOn);
        this.announcer.say(this.media.micOn ? 'Microphone on' : 'Microphone off');
        this.afterLocalChange();
    }

    async toggleCam() {
        const wasOn = this.camOn;
        this.el.cam.disabled = true;
        try {
            await this.media.setCam(!wasOn);
            this.announcer.say(this.camOn ? 'Camera on' : 'Camera off');
        } catch (e) {
            this.banner.set('device', { text: 'The camera could not be turned on. It may be blocked or in use by another app.', tone: 'warn', ttl: 8000 });
        }
        this.afterLocalChange();
    }

    afterLocalChange() {
        this.syncControls();
        this.renderTiles();
        this.queueState();
    }

    async toggleShare() {
        if (this.sharing) return this.stopShare({ announce: true });
        const other = this.roster.find((r) => !r.me && r.sharing);
        if (other) {
            this.banner.set('share-busy', { text: `${other.name} is already sharing. Only one person can share at a time.`, tone: 'warn', ttl: 6000 });
            this.announcer.say(`${other.name} is already sharing`);
            return;
        }
        let track;
        try {
            track = await this.media.startScreen();
        } catch (e) {
            const cancelled = e && (e.name === 'NotAllowedError' || e.name === 'AbortError');
            this.banner.set('share-fail', {
                text: cancelled ? 'Screen sharing was cancelled.' : 'Screen sharing is not available in this browser.',
                tone: 'warn', ttl: 5000,
            });
            return;
        }
        for (const peer of this.peers.values()) peer.setScreenTrack(track);
        this.announcer.say('You are sharing your screen');
        this.afterLocalChange();
        this.queueState();
        this.pollNow();
    }

    stopShare({ announce = false } = {}) {
        if (!this.media.screenTrack && !this.sharing) return;
        this.media.stopScreen();
        for (const peer of this.peers.values()) peer.setScreenTrack(null);
        if (announce) this.announcer.say('You stopped sharing your screen');
        this.afterLocalChange();
        this.pollNow();
    }

    onLocalTrack(kind, track) {
        for (const peer of this.peers.values()) peer.setLocalTrack(kind, track);
        this.syncMeters();
        if (this.phase === 'live') this.afterLocalChange();
    }

    syncControls() {
        const el = this.el;
        const mic = this.micOn;
        const cam = this.camOn;
        this.setToggle(el.mic, mic, !this.media.hasAudio ? 'Microphone not available' : mic ? 'Microphone on' : 'Microphone off', !this.media.hasAudio ? 'No microphone' : mic ? 'Mute (m)' : 'Unmute (m)');
        swapIcon(el.mic, mic ? 'mic' : 'mic-off');
        const camAvailable = this.media.status.cam === 'ok' || this.media.hasVideo || this.media.status.cam === 'pending';
        this.setToggle(el.cam, cam, !camAvailable && !cam ? 'Camera not available' : cam ? 'Camera on' : 'Camera off', !camAvailable && !cam ? 'Camera not available' : cam ? 'Turn camera off (v)' : 'Turn camera on (v)');
        el.cam.disabled = !camAvailable && !cam;
        swapIcon(el.cam, cam ? 'cam' : 'cam-off');

        const other = this.roster.find((r) => !r.me && r.sharing);
        const sharing = this.sharing;
        this.setToggle(el.share, sharing, sharing ? 'Sharing your screen' : other ? `${other.name} is sharing` : 'Share your screen', sharing ? 'Stop sharing (s)' : other ? `${other.name} is sharing, only one person can` : 'Share your screen (s)');
        el.share.disabled = !this.media.canShare || (!!other && !sharing);

        const panelOpen = this.panel.isOpen;
        const waiting = this.waitingList().length;
        el.people.setAttribute('aria-pressed', panelOpen ? 'true' : 'false');
        el.people.setAttribute('aria-label', waiting && this.isHost ? `People and documents, ${waiting} waiting` : 'People and documents');
        el.people.title = 'People and documents';
        this.renderBadge(waiting);

        if (el.lock) {
            const locked = !!this.meeting.locked;
            this.setToggle(el.lock, locked, locked ? 'Meeting locked' : 'Meeting unlocked', locked ? 'Unlock the meeting' : 'Lock the meeting');
        }
    }

    setToggle(button, pressed, label, title) {
        button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.title = title;
    }

    renderBadge(count) {
        let badge = this.el.people.querySelector('.ctl-badge');
        if (!count || !this.isHost) { badge?.remove(); return; }
        if (!badge) { badge = h('span', { class: 'ctl-badge fig', 'aria-hidden': 'true' }); this.el.people.append(badge); }
        badge.textContent = String(count);
    }

    openPanel(tab) {
        this.panel.open(tab || 'people');
        this.root.dataset.panel = 'open';
        this.syncControls();
        this.grid.layout();
    }

    closePanel({ restoreFocus = true } = {}) {
        if (!this.panel.isOpen) return;
        this.panel.close();
        delete this.root.dataset.panel;
        this.syncControls();
        this.grid.layout();
        if (restoreFocus) this.el.people.focus({ preventScroll: true });
    }

    onKey(event) {
        if (this.phase !== 'live') return;
        if (event.key === 'Escape') {
            if (this.el.devices.open) { this.el.devices.open = false; this.el.devices.querySelector('summary')?.focus(); return; }
            if (this.panel.isOpen) { this.closePanel(); event.preventDefault(); }
            return;
        }
        if (event.ctrlKey || event.metaKey || event.altKey || isTyping(event.target)) return;
        const key = event.key.toLowerCase();
        if (key === 'm') { this.toggleMic(); event.preventDefault(); }
        else if (key === 'v') { this.toggleCam(); event.preventDefault(); }
        else if (key === 's') { if (!this.el.share.disabled) this.toggleShare(); event.preventDefault(); }
    }

    // ── Devices menu ─────────────────────────────────────────────────────────

    renderDevices() {
        const panel = this.el.devicesPanel;
        if (!panel.isConnected) return;
        const m = this.media;
        const build = (id, label, devices, selected, onChange, emptyText) => {
            const select = h('select', { class: 'select', id, 'aria-label': label },
                devices.length ? devices.map((d, i) => h('option', { value: d.deviceId, selected: d.deviceId === selected }, d.label || `${label} ${i + 1}`)) : h('option', { value: '' }, emptyText));
            select.disabled = !devices.length;
            if (selected) select.value = selected;
            select.addEventListener('change', () => onChange(select.value));
            return h('div', { class: 'field' }, h('label', { for: id }, label), select);
        };
        const focused = document.activeElement?.id;
        const nodes = [
            build('dev-cam', 'Camera', m.devices.cams, m.camId, async (v) => {
                try { await m.switchCamera(v); this.announcer.say('Camera changed'); } catch (e) { this.banner.set('device', { text: 'That camera could not be opened.', tone: 'warn', ttl: 6000 }); }
            }, m.status.cam === 'denied' ? 'Camera is blocked' : 'No camera found'),
            build('dev-mic', 'Microphone', m.devices.mics, m.micId, async (v) => {
                try { await m.switchMic(v); this.announcer.say('Microphone changed'); } catch (e) { this.banner.set('device', { text: 'That microphone could not be opened.', tone: 'warn', ttl: 6000 }); }
            }, m.status.mic === 'denied' ? 'Microphone is blocked' : 'No microphone found'),
        ];
        if (m.canPickSpeaker) {
            nodes.push(build('dev-spk', 'Speaker', [{ deviceId: '', label: 'Default' }, ...m.devices.spks.filter((d) => d.deviceId !== 'default')], m.spkId, (v) => { m.setSpeaker(v); this.announcer.say('Speaker changed'); }, 'Default speaker'));
        }
        panel.replaceChildren(...nodes);
        if (focused && $(focused)) $(focused).focus();
    }

    // ── Host ─────────────────────────────────────────────────────────────────

    waitingList() {
        return this.roster.filter((r) => r.state === 'waiting');
    }

    onPanelAction(act, id, name) {
        if (act === 'admit' || act === 'deny' || act === 'mute') return this.hostAction(act, id);
        if (act === 'remove') {
            if (window.confirm(`Remove ${name} from the meeting? They will not be able to come back in.`)) this.hostAction('remove', id);
        }
    }

    async hostAction(action, attendeeId) {
        if (!this.isHost) return;
        try {
            await this.api.post(this.urls.host, attendeeId ? { action, attendee_id: attendeeId } : { action });
            if (action === 'lock') this.meeting.locked = true;
            if (action === 'unlock') this.meeting.locked = false;
            this.announcer.say({ admit: 'Admitted', deny: 'Denied', remove: 'Removed', mute: 'Muted', lock: 'Meeting locked', unlock: 'Meeting unlocked' }[action] || 'Done');
            this.syncControls();
            this.pollNow();
        } catch (e) {
            if (e instanceof ApiError && e.displaced) return this.displaced();
            this.banner.set('host-fail', { text: e.message || 'That did not work.', tone: 'warn', ttl: 6000 });
        }
    }

    async endForEveryone() {
        if (!this.isHost) return;
        if (!window.confirm('End the meeting for everyone? Nobody will be able to rejoin.')) return;
        try {
            await this.api.post(this.urls.host, { action: 'end' });
        } catch (e) {
            this.banner.set('host-fail', { text: e.message || 'The meeting could not be ended.', tone: 'warn', ttl: 6000 });
            return;
        }
        this.finish('ended', 'host_ended', { self: true });
    }

    onHostCommand(command) {
        if (command === 'mute') {
            if (this.media.hasAudio && this.media.micOn) {
                this.media.setMic(false);
                this.afterLocalChange();
            }
            this.banner.set('muted', { text: 'The host muted you. Turn your microphone on when you are ready to speak.', tone: 'info', ttl: 9000 });
            this.announcer.say('The host muted you');
        } else if (command === 'removed') {
            this.finish('removed');
        }
    }

    // ── Tiles ────────────────────────────────────────────────────────────────

    togglePin(key) {
        this.pinnedKey = this.pinnedKey === key ? null : key;
        const tile = this.grid.tiles.get(key);
        this.announcer.say(this.pinnedKey ? `Spotlight on ${tile ? tile.nameEl.textContent : 'this tile'}` : 'Spotlight cleared');
        this.renderTiles();
    }

    retryTile(key) {
        const peer = this.peers.get(Number(String(key).replace(/^[ps]/, '')));
        peer?.retry();
        this.renderTiles();
    }

    soundBlocked() {
        this.banner.set('sound', {
            text: 'Your browser is holding back sound from others.', tone: 'warn',
            actions: [{ label: 'Turn sound on', run: () => { resumeShared(); this.grid.playAll(); this.banner.clear('sound'); } }],
        });
    }

    selfStream() {
        const track = this.media.hasVideo && this.media.camOn ? this.media.videoTrack : null;
        if (this.selfPreview.track !== track) {
            this.selfPreview = { track, stream: track ? new MediaStream([track]) : null };
        }
        return this.selfPreview.stream;
    }

    renderTiles() {
        if (this.phase !== 'live') return;
        const models = [];
        const mic = this.micOn;
        const camShown = this.camOn;
        const viewer = !this.media.hasAudio && !this.media.hasVideo && this.media.status.cam !== 'pending';
        models.push({
            key: 'me', kind: 'camera', name: 'You', initialsOf: this.cfg.me, pen: penOf(this.myId), mine: true, muted: true, mirror: true,
            stream: this.selfStream(), showVideo: camShown, micOff: !mic, camOff: !camShown, host: this.isHost,
            speaking: !!this.speaking.get('me') && mic, hitLabel: 'Spotlight yourself', pinned: this.pinnedKey === 'me',
            note: viewer ? 'Listening only' : camShown ? '' : (this.media.status.cam === 'ok' || this.media.status.cam === 'pending' ? 'Camera off' : 'No camera'),
            state: 'connected',
        });

        const screens = [];
        const others = this.roster.filter((r) => !r.me && (r.state === 'joined' || r.state === 'lost') && (this.peers.has(r.id) || r.state === 'lost'));
        for (const r of others) {
            const peer = this.peers.get(r.id);
            if (!peer) continue;
            const status = r.state === 'lost' && peer.status === 'connected' ? 'lost' : peer.status;
            const connected = status === 'connected';
            const hasVideo = peer.media.getVideoTracks().some((t) => t.readyState === 'live');
            const camOff = r.cam === false;
            const showVideo = connected && hasVideo && !camOff;
            const text = { connecting: 'Connecting…', lost: 'Connection lost, trying to reconnect', dead: 'Could not connect' }[status] || '';
            models.push({
                key: `p${r.id}`, kind: 'camera', name: r.name, pen: penOf(r.id), stream: peer.media, showVideo, muted: false,
                micOff: r.mic === false, camOff, host: r.is_host, sharing: r.sharing, speaking: !!this.speaking.get(`p${r.id}`) && r.mic !== false,
                hitLabel: `Spotlight ${r.name}`, pinned: this.pinnedKey === `p${r.id}`,
                note: camOff ? 'Camera off' : connected ? '' : '', state: status,
                statusText: text, statusTone: 'is-warn', canRetry: status === 'dead',
                quality: connected ? peer.quality : null,
                qualityTitle: peer.stats ? `Round trip ${peer.stats.rtt ?? '?'} ms, loss ${peer.stats.loss}%, jitter ${peer.stats.jitter} ms${peer.route === 'relay' ? ', via relay' : ''}` : '',
            });
            if (r.sharing && connected && peer.screen.getVideoTracks().length) {
                screens.push({
                    key: `s${r.id}`, kind: 'screen', name: `${r.name}'s screen`, pen: penOf(r.id), stream: peer.screen, showVideo: true, muted: true,
                    hitLabel: `Spotlight ${r.name}'s screen`, pinned: this.pinnedKey === `s${r.id}`, state: 'connected',
                });
            }
        }
        models.push(...screens);

        const keys = new Set(models.map((m) => m.key));
        if (this.pinnedKey && !keys.has(this.pinnedKey)) this.pinnedKey = null;
        const focusKey = this.pinnedKey || (screens[0] ? screens[0].key : null);
        for (const m of models) m.pinned = this.pinnedKey === m.key;
        this.grid.render(models, focusKey);
        if (this.media.spkId) this.grid.setSink(this.media.spkId);
    }

    // ── Speaking ─────────────────────────────────────────────────────────────

    syncMeters() {
        const wanted = new Map();
        if (this.media.hasAudio) wanted.set('me', this.media.audioTrack);
        for (const [id, peer] of this.peers) {
            const track = peer.media.getAudioTracks()[0];
            if (track) wanted.set(`p${id}`, track);
        }
        for (const [key, entry] of this.meters) {
            if (wanted.get(key) !== entry.track) {
                entry.meter.stop();
                this.meters.delete(key);
                this.speaking.delete(key);
                this.grid.setSpeaking(key, false);
            }
        }
        for (const [key, track] of wanted) {
            if (this.meters.has(key)) continue;
            const state = { until: 0 };
            const meter = new LevelMeter(new MediaStream([track]), {
                onLevel: (level, rms) => {
                    const now = performance.now();
                    if (rms > SPEAKING_RMS) state.until = now + SPEAKING_HOLD_MS;
                    const on = now < state.until;
                    if (this.speaking.get(key) !== on) {
                        this.speaking.set(key, on);
                        this.grid.setSpeaking(key, on);
                    }
                },
            });
            this.meters.set(key, { meter, track });
        }
    }

    // ── Timers, stats, quality ───────────────────────────────────────────────

    startTimers() {
        const tick = () => { this.el.clock.textContent = fmtClock((Date.now() - this.joinedAt) / 1000); };
        tick();
        this.timers.clock = setInterval(tick, 1000);
        this.timers.stats = setInterval(() => this.sampleAll(), STATS_MS);
        this.timers.report = setInterval(() => this.postState(), REPORT_MS);
    }

    async sampleAll() {
        if (this.phase !== 'live') return;
        await Promise.all([...this.peers.values()].map((p) => p.sample()));
        this.updateNet();
        if (this.phase === 'live') this.renderTiles();
    }

    updateNet() {
        let q;
        if (this.net === 'reconnecting') q = 'poor';
        else {
            q = worstQuality([...this.peers.values()].filter((p) => p.status === 'connected').map((p) => p.quality));
            if (!q && this.latency > 2500) q = 'fair';
            if (!q) q = this.peers.size ? null : 'good';
        }
        // Report only what has settled.
        this.ownQuality = this.net === 'reconnecting' ? this.ownQuality : q;
        const net = this.el.net;
        net.dataset.q = q || '';
        const text = this.net === 'reconnecting' ? 'Offline, reconnecting' : q ? `${QUALITY_WORD[q]} connection` : 'Measuring connection';
        const label = net.querySelector('span:last-child');
        if (label.textContent !== text) label.textContent = text;
        net.title = `Your connection: ${text.toLowerCase()}`;
    }

    // ── Rendering the rest ───────────────────────────────────────────────────

    updateAll() {
        this.renderTiles();
        this.renderPeopleAndBanners();
        this.syncControls();
        this.updateNet();
        this.renderStatus();
    }

    renderPeopleAndBanners() {
        const people = [];
        for (const r of this.roster) {
            if (r.state === 'waiting') continue;
            const peer = this.peers.get(r.id);
            let state = 'live';
            let text = 'In the call';
            let tone = 'is-ok';
            if (r.me) {
                if (this.net === 'reconnecting') { state = 'lost'; text = 'Reconnecting'; tone = 'is-warn'; }
            } else if (r.state === 'admitted') { state = 'joining'; text = 'Joining'; tone = ''; }
            else if (r.state === 'lost') { state = 'lost'; text = 'Not responding'; tone = 'is-warn'; }
            else if (peer && peer.status === 'connecting') { state = 'connecting'; text = 'Connecting'; tone = 'is-warn'; }
            else if (peer && peer.status === 'lost') { state = 'lost'; text = 'Connection lost'; tone = 'is-warn'; }
            else if (peer && peer.status === 'dead') { state = 'lost'; text = 'Could not connect'; tone = 'is-warn'; }
            people.push({
                id: r.id, name: r.name, you: r.me, host: r.is_host, state, stateText: text, tone,
                mic: r.me ? this.micOn : r.mic, cam: r.me ? this.camOn : r.cam, sharing: r.me ? this.sharing : r.sharing, quality: r.me ? this.ownQuality : r.quality,
                pen: penOf(r.id),
                canMute: this.isHost && !r.me && r.state === 'joined' && r.mic !== false,
                canRemove: this.isHost && !r.me && !r.is_host,
            });
        }
        this.panel.setPeople(people);

        const waiters = this.waitingList().map((w) => ({ id: w.id, name: w.name, pen: penOf(w.id) }));
        this.panel.setWaiters(this.isHost ? waiters : [], this.isHost);

        // Host: a banner and a title prefix while someone waits at the door.
        if (this.isHost && waiters.length) {
            const first = waiters[0];
            const text = waiters.length === 1 ? `${first.name} is waiting to join` : `${first.name} and ${waiters.length - 1} other${waiters.length > 2 ? 's' : ''} are waiting to join`;
            this.banner.set('waiters', {
                text, tone: 'info',
                actions: [
                    { label: 'Admit', run: () => this.hostAction('admit', first.id) },
                    { label: 'Deny', run: () => this.hostAction('deny', first.id) },
                    ...(waiters.length > 1 ? [{ label: 'See all', run: () => this.openPanel('people') }] : []),
                ],
            });
            document.title = `(${waiters.length} waiting) ${this.baseTitle}`;
        } else {
            this.banner.clear('waiters');
            document.title = this.baseTitle;
        }

        // Screen sharing banners and the one-sharer rule.
        const sharers = this.roster.filter((r) => !r.me && r.sharing && (r.state === 'joined' || r.state === 'lost'));
        const key = sharers.map((s) => s.id).join(',');
        if (sharers.length) {
            this.banner.set('share', { text: `${sharers[0].name} is sharing their screen`, tone: 'info' });
            if (this.sharing && this.myId > sharers[0].id) {
                this.stopShare({ announce: true });
                this.banner.set('share-yield', { text: `${sharers[0].name} started sharing first, so your sharing stopped.`, tone: 'warn', ttl: 7000 });
            }
        } else {
            this.banner.clear('share');
        }
        if (this.sharing) {
            this.banner.set('myshare', { text: 'You are sharing your screen.', tone: 'info', actions: [{ label: 'Stop sharing', run: () => this.stopShare({ announce: true }) }] });
        } else {
            this.banner.clear('myshare');
        }
        this.sharingIds = new Set(key ? key.split(',') : []);
    }

    // ── Leaving and ending ───────────────────────────────────────────────────

    async leave() {
        if (this.leaving) return;
        this.leaving = true;
        this.el.leave.disabled = true;
        if (this.phase === 'live' || this.phase === 'waiting' || this.phase === 'entering') {
            try {
                await Promise.race([this.api.post(this.urls.leave, {}, { keepalive: true }), sleep(2500)]);
            } catch (e) { /* leaving anyway */ }
        }
        this.teardown();
        window.location.assign(this.home);
    }

    onPageHide() {
        if (this.leaving || this.stopped) return;
        if (this.phase === 'live' || this.phase === 'waiting' || this.phase === 'entering') {
            this.api.post(this.urls.leave, {}, { keepalive: true }).catch(() => {});
        }
    }

    /** Free everything: connections, tracks, timers, audio, listeners. Safe to call twice. */
    teardown() {
        this.stopped = true;
        clearTimeout(this.pollTimer);
        for (const key of Object.keys(this.timers)) { clearTimeout(this.timers[key]); clearInterval(this.timers[key]); }
        this.timers = {};
        this.sendQueue = [];
        for (const peer of this.peers.values()) peer.close();
        this.peers.clear();
        for (const entry of this.meters.values()) entry.meter.stop();
        this.meters.clear();
        this.lobby.stop();
        this.grid.destroy();
        this.media.stopScreen();
        this.media.stopAll();
        closeShared();
        this.banner.destroy();
        document.title = this.baseTitle;
        this.ac.abort();
    }

    displaced() {
        if (this.stopped) return;
        this.teardown();
        this.showNotice({
            title: 'You joined from another window',
            text: 'This meeting is open in another window or device, so this one stopped. Only one window can be in the call at a time.',
            actions: [{ label: 'Join from this window instead', primary: true, run: () => window.location.reload() }, { label: 'Close', href: this.home }],
        });
        this.setPhase('ended');
        this.el.status.className = 'state is-warn';
        this.el.status.textContent = 'Stopped';
    }

    /** The call is over for this person, in one of several ways. Shows why, and the way out. */
    finish(kind, detail, { self = false } = {}) {
        if (this.phase === 'ended') return;
        const wasLive = this.phase === 'live' || this.phase === 'waiting';
        this.teardown();
        const back = { label: 'Back to the engagement', href: this.home, primary: true };
        const copy = {
            ended: detail === 'host_ended' || self
                ? { title: 'The host ended the meeting', text: self ? 'You ended the meeting for everyone.' : 'The host ended the meeting for everyone.' }
                : { title: 'The meeting has ended', text: detail === 'expired' ? 'This meeting reached its time limit.' : 'Everyone has left, so the meeting is over.' },
            removed: { title: 'The host removed you from the meeting', text: 'You cannot come back into this meeting.' },
            denied: { title: 'The host did not let you in', text: 'You asked to join and the host declined. Contact the host if you think this is a mistake.' },
            locked: { title: 'The meeting is locked', text: 'The host has locked this meeting, so nobody new can come in. Ask the host to unlock it.' },
            full: { title: 'The meeting is full', text: `${detail || 'This meeting is full.'} Try again when someone leaves.` },
            refused: { title: 'You cannot join this meeting', text: detail || 'Something stopped you joining.' },
            expired: { title: 'Your session expired', text: 'Your session expired, reload this page to continue.' },
        }[kind];
        let actions = [back];
        if (kind === 'full' || kind === 'locked') {
            actions = [{ label: 'Try again', primary: true, run: () => window.location.reload() }, { label: 'Back to the engagement', href: this.home }];
        } else if (kind === 'expired') {
            actions = [{ label: 'Reload', primary: true, run: () => window.location.reload() }, { label: 'Back to the engagement', href: this.home }];
        }
        this.setPhase('ended');
        this.showNotice({ ...copy, actions });
        this.announcer.say(copy.title);
        if (kind === 'ended' && wasLive) {
            let left = 6;
            const countdown = h('p', { class: 'muted', id: 'notice-count' }, `Taking you back in ${left} seconds.`);
            this.el.notice.querySelector('.notice-body').append(countdown);
            this.timers.redirect = setInterval(() => {
                left -= 1;
                if (left <= 0) { clearInterval(this.timers.redirect); window.location.assign(this.home); return; }
                countdown.textContent = `Taking you back in ${left} second${left === 1 ? '' : 's'}.`;
            }, 1000);
        }
        if (kind === 'ended') this.renderStatus();
        else {
            this.el.status.className = 'state is-wax';
            this.el.status.textContent = kind === 'expired' ? 'Stopped' : 'Not in the meeting';
        }
    }

    showNotice({ title, text, actions = [] }) {
        const notice = this.el.notice;
        notice.hidden = false;
        this.el.lobby.hidden = true;
        this.el.waiting.hidden = true;
        this.el.stage.hidden = true;
        this.el.controls.hidden = true;
        notice.querySelector('.notice-body').replaceChildren(
            h('h2', { id: 'notice-h' }, title),
            h('p', { class: 'muted', id: 'notice-text' }, text),
            h('div', { class: 'page-actions', id: 'notice-actions' }, actions.map((a) => (a.href
                ? h('a', { class: `btn${a.primary ? '' : ' btn-quiet'}`, href: a.href }, h('span', {}, a.label))
                : h('button', { type: 'button', class: `btn${a.primary ? '' : ' btn-quiet'}`, onclick: a.run }, h('span', {}, a.label))))));
        notice.querySelector('a, button')?.focus({ preventScroll: true });
    }
}

const root = document.getElementById('room-root');
if (root) {
    if (typeof RTCPeerConnection === 'undefined') {
        document.getElementById('lobby-msg').textContent = 'This browser cannot make video calls. Try a recent Chrome, Edge, Firefox or Safari.';
        document.getElementById('btn-join').disabled = true;
    } else {
        window.__engageRoom = new Room(root);
    }
}
