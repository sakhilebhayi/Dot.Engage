// The lobby: preview, device choice, a live microphone meter and honest connection checks.

import { $, h, swapIcon } from './util.js';
import { LevelMeter } from './audio.js';
import { gatherCandidates, describe } from './netcheck.js';

const TONE = { ok: 'is-ok', warn: 'is-warn', none: '' };

export class Lobby {
    constructor({ media, api, cfg, announcer }) {
        this.media = media;
        this.api = api;
        this.cfg = cfg;
        this.announcer = announcer;
        this.meter = null;
        this.checks = {};
        this.closed = false;
        this.el = {
            video: $('lobby-video'), nocam: $('lobby-nocam'), nocamText: $('lobby-nocam-text'),
            mic: $('lobby-mic'), cam: $('lobby-cam'),
            selCam: $('sel-cam'), selMic: $('sel-mic'), selSpk: $('sel-spk'), spkWrap: $('sel-spk-wrap'),
            level: $('lobby-level'), msg: $('lobby-msg'), list: $('lobby-checks'), join: $('btn-join'),
        };
        this.el.list.replaceChildren();
        this.bindEvents();
    }

    bindEvents() {
        const e = this.el;
        this.off = [];
        const on = (node, name, fn) => { node.addEventListener(name, fn); this.off.push(() => node.removeEventListener(name, fn)); };
        on(e.mic, 'click', () => { this.media.setMic(!this.media.micOn); this.render(); });
        on(e.cam, 'click', async () => {
            e.cam.disabled = true;
            try { await this.media.setCam(!this.media.camOn); } catch (err) { this.announcer.say('The camera could not be turned on'); }
            this.render();
        });
        on(e.selCam, 'change', async () => {
            try { await this.media.switchCamera(e.selCam.value); } catch (err) { this.announcer.say('That camera could not be opened'); }
            this.render();
        });
        on(e.selMic, 'change', async () => {
            try { await this.media.switchMic(e.selMic.value); } catch (err) { this.announcer.say('That microphone could not be opened'); }
            this.render();
            this.startMeter();
        });
        on(e.selSpk, 'change', () => this.media.setSpeaker(e.selSpk.value));
        this.unlisten = this.media.on('devices', () => this.render());
    }

    async start() {
        this.setCheck('cam', 'Camera', { tone: 'none', text: 'Checking…' });
        this.setCheck('mic', 'Microphone', { tone: 'none', text: 'Checking…' });
        this.setCheck('direct', 'Direct connection', { tone: 'none', text: 'Checking…' });
        this.setCheck('relay', 'Relay', { tone: 'none', text: 'Checking…' });
        this.el.msg.textContent = 'Looking for your camera and microphone. Your browser may ask for permission.';
        this.networkCheck();
        await this.media.acquire();
        if (this.closed) return;
        this.render();
        this.startMeter();
    }

    async retry() {
        this.el.msg.textContent = 'Trying again…';
        await this.media.acquire();
        if (this.closed) return;
        this.render();
        this.startMeter();
    }

    startMeter() {
        this.meter?.stop();
        this.meter = null;
        if (this.closed || !this.media.hasAudio) { this.paintLevel(0); return; }
        // Its own AudioContext, closed in stop() when the lobby is left.
        this.meter = new LevelMeter(new MediaStream([this.media.audioTrack]), { ownContext: true, onLevel: (level) => this.paintLevel(level) });
    }

    paintLevel(level) {
        const bar = this.el.level;
        if (!bar) return;
        bar.style.transform = `scaleX(${level.toFixed(3)})`;
        bar.parentElement.setAttribute('data-active', level > 0.05 ? 'true' : 'false');
    }

    async networkCheck() {
        // The room page hands over the same servers the call will use (cfg.ice) when the server provides them.
        // Otherwise the check runs against the default public STUN servers and says so.
        let servers = Array.isArray(this.cfg.ice) ? this.cfg.ice : null;
        const source = servers ? 'server' : 'default';
        if (!servers) servers = [{ urls: ['stun:stun.cloudflare.com:3478', 'stun:stun.l.google.com:19302'] }];
        const result = await gatherCandidates(servers);
        if (this.closed) return;
        this.netResult = result;
        const verdict = describe(result, { relayConfigured: !!this.cfg.relay });
        if (source === 'default' && verdict.relay.tone === 'warn' && this.cfg.relay) {
            verdict.relay = { tone: 'warn', text: 'The server has a relay, but it is only tested once you join' };
        }
        if (source === 'default' && verdict.direct.tone === 'ok') verdict.direct.text += ', checked with public servers';
        this.setCheck('direct', 'Direct connection', verdict.direct);
        this.setCheck('relay', 'Relay', verdict.relay);
    }

    setCheck(key, label, { tone, text }) {
        let row = this.checks[key];
        if (!row) {
            row = { dt: h('dt', {}, label), dd: h('dd', {}, h('span', { class: 'state' })) };
            this.checks[key] = row;
            this.el.list.append(row.dt, row.dd);
        }
        const state = row.dd.firstChild;
        state.className = `state ${TONE[tone] || ''}`;
        state.textContent = text;
    }

    deviceName(list, id, fallback) {
        const found = list.find((d) => d.deviceId === id) || list[0];
        return (found && found.label) || fallback;
    }

    render() {
        if (this.closed) return;
        const m = this.media;
        const e = this.el;
        const s = m.status;

        // Preview
        const preview = m.camOn ? m.previewStream() : null;
        if (preview) {
            const current = e.video.srcObject && e.video.srcObject.getVideoTracks()[0];
            if (current !== m.videoTrack) { e.video.srcObject = preview; e.video.play().catch(() => {}); }
        } else {
            e.video.srcObject = null;
        }
        e.video.hidden = !preview;
        e.nocam.hidden = !!preview;
        e.nocamText.textContent = ({
            denied: 'Camera is blocked',
            none: 'No camera found',
            busy: 'Camera is in use by another app',
            unsupported: 'This browser cannot use a camera',
            error: 'Camera could not be opened',
        })[s.cam] || (m.hasVideo || s.cam === 'ok' ? 'Camera is off' : 'No camera');

        // Toggles
        e.mic.disabled = !m.hasAudio;
        e.mic.setAttribute('aria-pressed', m.hasAudio && m.micOn ? 'true' : 'false');
        e.mic.setAttribute('aria-label', !m.hasAudio ? 'Microphone not available' : m.micOn ? 'Microphone on' : 'Microphone off');
        e.mic.title = !m.hasAudio ? 'Microphone not available' : m.micOn ? 'Mute microphone' : 'Unmute microphone';
        swapIcon(e.mic, m.hasAudio && m.micOn ? 'mic' : 'mic-off');
        const camAvailable = s.cam === 'ok' || m.hasVideo;
        e.cam.disabled = !camAvailable;
        e.cam.setAttribute('aria-pressed', m.camOn ? 'true' : 'false');
        e.cam.setAttribute('aria-label', !camAvailable ? 'Camera not available' : m.camOn ? 'Camera on' : 'Camera off');
        e.cam.title = !camAvailable ? 'Camera not available' : m.camOn ? 'Turn camera off' : 'Turn camera on';
        swapIcon(e.cam, m.camOn ? 'cam' : 'cam-off');

        // Selects
        this.fillSelect(e.selCam, m.devices.cams, m.camId, 'Camera', s.cam === 'denied' ? 'Camera is blocked' : 'No camera found');
        this.fillSelect(e.selMic, m.devices.mics, m.micId, 'Microphone', s.mic === 'denied' ? 'Microphone is blocked' : 'No microphone found');
        if (m.canPickSpeaker) {
            e.spkWrap.hidden = false;
            this.fillSelect(e.selSpk, m.devices.spks, m.spkId, 'Speaker', 'Default speaker', true);
        } else {
            e.spkWrap.hidden = true;
        }

        this.renderMessage();
        this.renderChecks();
    }

    fillSelect(select, devices, selectedId, noun, emptyText, allowDefault = false) {
        const signature = JSON.stringify([devices.map((d) => [d.deviceId, d.label]), selectedId]);
        if (select.dataset.sig === signature) return;
        select.dataset.sig = signature;
        select.replaceChildren();
        if (!devices.length) {
            select.append(h('option', { value: '' }, emptyText));
            select.disabled = true;
            return;
        }
        select.disabled = false;
        if (allowDefault) select.append(h('option', { value: '' }, 'Default'));
        devices.forEach((d, i) => {
            if (allowDefault && d.deviceId === 'default') return;
            select.append(h('option', { value: d.deviceId, selected: d.deviceId === selectedId }, d.label || `${noun} ${i + 1}`));
        });
        if (selectedId) select.value = selectedId;
    }

    renderChecks() {
        const m = this.media;
        const s = m.status;
        const cam = ({
            ok: { tone: 'ok', text: `Camera found: ${this.deviceName(m.devices.cams, m.camId, 'working')}` },
            none: { tone: 'warn', text: 'No camera found. You can still join with audio.' },
            denied: { tone: 'warn', text: 'Camera is blocked in the browser' },
            busy: { tone: 'warn', text: 'Camera is in use by another app' },
            unsupported: { tone: 'warn', text: 'This browser cannot open a camera' },
            error: { tone: 'warn', text: 'The camera could not be opened' },
            pending: { tone: 'none', text: 'Checking…' },
        })[s.cam];
        const mic = ({
            ok: { tone: 'ok', text: `Microphone found: ${this.deviceName(m.devices.mics, m.micId, 'working')}` },
            none: { tone: 'warn', text: 'No microphone found. You will be listen only.' },
            denied: { tone: 'warn', text: 'Microphone is blocked in the browser' },
            busy: { tone: 'warn', text: 'Microphone is in use by another app' },
            unsupported: { tone: 'warn', text: 'This browser cannot open a microphone' },
            error: { tone: 'warn', text: 'The microphone could not be opened' },
            pending: { tone: 'none', text: 'Checking…' },
        })[s.mic];
        this.setCheck('cam', 'Camera', cam);
        this.setCheck('mic', 'Microphone', mic);
    }

    renderMessage() {
        const { cam, mic } = this.media.status;
        const how = ' To allow it, choose the camera icon or the lock beside the address, set Camera and Microphone to Allow, then press Try again.';
        let text = '';
        let retry = false;
        if (cam === 'unsupported' || mic === 'unsupported') {
            text = 'This browser or connection cannot use a camera or microphone (video calls need a secure page). You can still join and watch and listen.';
        } else if (cam === 'denied' && mic === 'denied') {
            text = 'Camera and microphone are blocked, so others will not see or hear you. You can still join as a viewer and watch and listen.' + how;
            retry = true;
        } else if (mic === 'denied') {
            text = 'The microphone is blocked, so you can listen but others will not hear you.' + how;
            retry = true;
        } else if (cam === 'denied') {
            text = 'The camera is blocked, so you will join with audio only.' + how;
            retry = true;
        } else if (cam !== 'ok' && mic !== 'ok' && cam !== 'pending') {
            text = 'No camera or microphone was found. You can still join as a viewer and watch and listen.';
        } else if (mic !== 'ok' && mic !== 'pending') {
            text = mic === 'busy' ? 'The microphone is in use by another app, so you can listen but not speak.' : 'No microphone was found, so you can listen but not speak.';
        } else if (cam !== 'ok' && cam !== 'pending') {
            text = cam === 'busy' ? 'The camera is in use by another app, so you will join with audio only.' : 'No camera was found, so you will join with audio only.';
        }
        const msg = this.el.msg;
        msg.textContent = text;
        msg.dataset.tone = text ? 'warn' : '';
        if (retry) {
            msg.append(' ', h('button', { type: 'button', class: 'linkbtn', id: 'lobby-retry', onclick: () => this.retry() }, 'Try again'));
        }
    }

    /** Leaving the lobby: stop the meter and close its audio context. The media itself carries on into the call. */
    stop() {
        this.closed = true;
        this.meter?.stop();
        this.meter = null;
        this.unlisten?.();
        this.off.forEach((fn) => fn());
        this.off = [];
        this.el.video.srcObject = null;
    }
}
