// End-to-end scenarios for the Dot.Engage meeting room. See README.txt.
//   node run.mjs                 run everything
//   node run.mjs call            the main chain: a b c d e f g h j i k (one meeting, 3 browsers)
//   node run.mjs full            scenario l (7 people, 6 allowed)
//   node run.mjs perms           scenario n (camera / microphone permission denied)
//   node run.mjs misc            keyboard, panel focus, light/dark, reduced motion, phone checks
import {
    launch, newPerson, login, createMeeting, openRoom, guestSignIn, press, waitPhase, waitTile, shot, closeAll, sleep, assertFlow,
    rtcSnapshot, tiles, ensureGuest, report, BASE, CHROME_ARGS, FAKE_SCREEN,
} from './lib.mjs';
import { chromium } from 'playwright-core';

const results = [];
const want = new Set(process.argv.slice(2));
const run = (group) => want.size === 0 || want.has(group);

async function scenario(name, fn) {
    const t0 = Date.now();
    try {
        const info = await fn();
        results.push({ name, ok: true, info: (info ? String(info) + '  ' : '') + `(${((Date.now() - t0) / 1000).toFixed(1)} s)` });
    } catch (e) {
        results.push({ name, ok: false, error: (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : String(e)) });
    }
    report(results.slice(-1));
}

const ok = (cond, msg) => { if (!cond) throw new Error(msg); };
const text = (page, sel) => page.evaluate((s) => document.querySelector(s)?.innerText || '', sel);
const statusWord = (p) => text(p.page, '#room-status');

async function enter(person, key, expect) {
    await openRoom(person, key);
    await person.page.waitForSelector('#btn-join:not([disabled])', { timeout: 30000 });
    await person.page.waitForFunction(() => document.querySelectorAll('#lobby-checks dd').length >= 4 && ![...document.querySelectorAll('#lobby-checks dd')].some((d) => /Checking/.test(d.textContent)), null, { timeout: 15000 }).catch(() => {});
    await person.page.click('#btn-join');
    await waitPhase(person, expect, 30000);
}

async function admit(host, name) {
    const { page } = host;
    await page.waitForSelector(`#banner button:has-text("Admit")`, { timeout: 20000 });
    const t = Date.now();
    await page.click('#banner button:has-text("Admit")');
    return t;
}

async function connectedPeers(page) {
    return (await rtcSnapshot(page)).filter((p) => p.connectionState === 'connected').length;
}

async function waitConnected(page, n, timeout = 40000) {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if ((await connectedPeers(page)) >= n) return;
        await sleep(300);
    }
    throw new Error(`timeout waiting for ${n} connected peers, have ${await connectedPeers(page)}`);
}

// 403 is the server's deliberate refusal (locked, removed, full) that the browser logs as a failed load; setOffline logs ERR_INTERNET_DISCONNECTED.
function noErrors(people, label, induced = /status of 403|Failed to load resource: net::ERR_INTERNET_DISCONNECTED/) {
    const bad = people.flatMap((p) => p.errors.map((e) => `${p.name}: ${e}`)).filter((e) => !induced.test(e));
    ok(bad.length === 0, `${label} console/page errors: ${bad.slice(0, 3).join(' | ')}`);
}

// ─────────────────────────────────────────────────────────────────────────────
// The main chain: one meeting, three browsers.
// ─────────────────────────────────────────────────────────────────────────────
async function callChain(browser) {
    const host = await newPerson(browser, { name: 'Host Naledi', kind: 'host' });
    const g1 = await newPerson(browser, { name: 'Thandi', email: 'thandi@karoofresh.example', fakeScreen: process.env.FAKE_SCREEN === '1' });
    const g2 = await newPerson(browser, { name: 'Pieter (phone)', email: 'pieter@karoofresh.example', mobile: true, viewport: { width: 390, height: 844 } });
    await login(host);
    // Screen capture is real getDisplayMedia (auto-selected screen); FAKE_SCREEN=1 swaps in a canvas stream instead.
    const key = await createMeeting(host, 'E2E call chain');
    let state = { key };

    await scenario('(a) host + external 1:1 with waiting room and admit', async () => {
        await enter(host, key, 'live');
        ok((await statusWord(host)) === 'Live', 'host status word should be Live');
        await guestSignIn(g1, key);
        await enter(g1, key, 'waiting');
        const guestWait = await text(g1.page, '#waiting-h');
        ok(/Waiting for the host/.test(guestWait), 'guest should see the waiting room');
        ok((await statusWord(g1)) === 'Waiting', 'guest status word should be Waiting');
        await host.page.waitForSelector('#banner:not([hidden])', { timeout: 15000 });
        const banner = await text(host.page, '#banner');
        ok(/Thandi Mokoena is waiting to join/.test(banner), 'host banner should name the waiter: ' + banner);
        ok(/^\(1 waiting\)/.test(await host.page.title()), 'host document.title should carry a waiting prefix');
        await host.page.click('#ctl-people');
        await host.page.waitForSelector('#waiters button[data-act="admit"]');
        const badge = await text(host.page, '#ctl-people .ctl-badge');
        ok(badge === '1', 'people button badge should count the waiter: ' + badge);
        await shot(host, 'a-host-waiter-panel.png');
        await shot(g1, 'a-guest-waiting.png');
        const t = Date.now();
        await host.page.click('#waiters button[data-act="admit"]');
        await waitPhase(g1, 'live', 20000);
        const tLive = Date.now() - t;
        // Never connected before the connection really is: tiles go through Connecting first.
        await waitConnected(host.page, 1);
        await waitConnected(g1.page, 1);
        const tConn = Date.now() - t;
        ok(!/waiting/.test(await host.page.title()), 'title prefix should clear after admit');
        const hostFlow = await assertFlow(host.page, { peers: 1, label: 'host' });
        const guestFlow = await assertFlow(g1.page, { peers: 1, label: 'guest' });
        const ht = await tiles(host.page);
        ok(ht.some((x) => x.name === 'Thandi Mokoena' && x.state === 'connected'), 'host sees Thandi connected');
        await host.page.click('#side-close');
        await sleep(600);
        await shot(host, 'a-1to1-host.png'); await shot(g1, 'a-1to1-guest.png');
        return `admit->live ${tLive} ms, admit->both connected ${tConn} ms; host sees frames ${hostFlow[0].frames}, video ${hostFlow[0].vBytes} B, audio ${hostFlow[0].aBytes} B, energy ${hostFlow[0].energy.toFixed(2)}; guest sees frames ${guestFlow[0].frames}, energy ${guestFlow[0].energy.toFixed(2)}; route ${hostFlow[0].route}`;
    });

    await scenario('(b) third participant (phone) via waiting room; full mesh', async () => {
        await guestSignIn(g2, key);
        await openRoom(g2, key);
        await g2.page.waitForSelector('#btn-join:not([disabled])', { timeout: 30000 });
        await sleep(1500);
        await shot(g2, 'm-phone-lobby.png');
        await g2.page.click('#btn-join');
        await waitPhase(g2, 'waiting');
        await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
        const t = Date.now();
        await host.page.click('#banner button:has-text("Admit")');
        await waitPhase(g2, 'live', 20000);
        await waitConnected(host.page, 2); await waitConnected(g1.page, 2); await waitConnected(g2.page, 2);
        const tConn = Date.now() - t;
        const f = [await assertFlow(host.page, { peers: 2, label: 'host' }), await assertFlow(g1.page, { peers: 2, label: 'g1' }), await assertFlow(g2.page, { peers: 2, label: 'g2' })];
        const ht = await tiles(host.page);
        ok(ht.length === 3, 'host grid should have 3 tiles, has ' + ht.length);
        await sleep(800);
        await shot(host, 'b-3tiles-desktop.png'); await shot(g2, 'm-phone-call-3.png');
        const sizes = await g2.page.evaluate(() => [...document.querySelectorAll('#controls .ctl, #controls .btn')].map((b) => { const r = b.getBoundingClientRect(); return [b.id, Math.round(r.width), Math.round(r.height)]; }));
        const small = sizes.filter(([, w, h]) => w < 44 || h < 44);
        ok(small.length === 0, 'phone controls below 44px: ' + JSON.stringify(small));
        const overflow = await g2.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        ok(!overflow, 'phone page scrolls horizontally');
        // The side panel is a full-height sheet on a phone; every button in it is a proper touch target.
        await g2.page.click('#ctl-people');
        await g2.page.waitForSelector('#side:not([hidden])');
        await sleep(400);
        const sheet = await g2.page.evaluate(() => { const r = document.getElementById('side').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height), innerWidth, innerHeight]; });
        ok(sheet[0] === sheet[2], 'side panel should be a full-width sheet on a phone: ' + sheet);
        const panelSmall = await g2.page.evaluate(() => [...document.querySelectorAll('#side button, #controls button')].filter((b) => b.offsetParent && (b.getBoundingClientRect().height < 44 || b.getBoundingClientRect().width < 44)).map((b) => [b.id || b.textContent.trim(), Math.round(b.getBoundingClientRect().width), Math.round(b.getBoundingClientRect().height)]));
        ok(panelSmall.length === 0, 'small touch targets with the panel open: ' + JSON.stringify(panelSmall));
        await shot(g2, 'm-phone-people-sheet.png');
        await g2.page.keyboard.press('Escape');
        const total = f.map((x) => x.map((p) => `${p.frames}f/${(p.vBytes / 1000) | 0}kB/e${p.energy.toFixed(1)}`).join(' ')).join(' | ');
        return `3-way mesh connected in ${tConn} ms after admit; per-peer remote video frames/bytes/audio energy: ${total}`;
    });

    await scenario('(c) camera off/on and mic mute/unmute reflected on the other side', async () => {
        // Camera off on the host.
        await host.page.click('#ctl-cam');
        await waitTile(g1.page, (t) => t.some((x) => x.name === 'Naledi Dlamini' && x.cam === 'off' && x.camOff), 'host camera off on guest tile', 15000);
        ok((await host.page.getAttribute('#ctl-cam', 'aria-pressed')) === 'false', 'host cam button should be unpressed');
        const live = await host.page.evaluate(() => (window.__tracks || []).filter((t) => t.kind === 'video' && t.readyState === 'live').length);
        // Camera on again.
        await host.page.click('#ctl-cam');
        await waitTile(g1.page, (t) => t.some((x) => x.name === 'Naledi Dlamini' && x.cam === 'on' && !x.camOff), 'host camera on again on guest tile', 20000);
        const before = (await rtcSnapshot(g1.page)).map((p) => p.video?.frames || 0);
        await sleep(2500);
        const after = (await rtcSnapshot(g1.page)).map((p) => p.video?.frames || 0);
        ok(after.some((f, i) => f > before[i]), 'video frames should resume after camera back on');
        // Mic mute: the audio really goes quiet for the listener, then comes back.
        const energyOf = async (p) => (await rtcSnapshot(p.page)).map((x) => x.audio?.energy || 0);
        const e0 = await energyOf(g1); await sleep(5000); const e1 = await energyOf(g1);
        const growthOn = Math.max(...e1.map((v, i) => v - e0[i]));
        await host.page.click('#ctl-mic');
        await waitTile(g1.page, (t) => t.some((x) => x.name === 'Naledi Dlamini' && x.micOff), 'host mic off icon on guest tile', 15000);
        await sleep(1200);
        const m0 = await energyOf(g1); await sleep(5000); const m1 = await energyOf(g1);
        // Both of g1's inbound audio streams: only the host's is muted, so compare against the host's connection only.
        const hostIdx = await g1.page.evaluate(async () => { const out = []; for (const pc of window.__pcs) { if (pc.signalingState !== 'closed' && pc.getTransceivers().length >= 2) out.push(pc.connectionState); } return out.length; });
        const growthMuted = Math.min(...m1.map((v, i) => v - m0[i]));
        ok(growthOn > 0.02, `baseline audio energy should be growing while unmuted (${growthOn.toFixed(3)})`);
        ok(growthMuted < 0.001, `muted host audio should add no energy (min growth ${growthMuted.toFixed(4)} vs ${growthOn.toFixed(3)} on)`);
        ok((await host.page.getAttribute('#ctl-mic', 'aria-pressed')) === 'false', 'host mic button should be unpressed');
        await host.page.click('#ctl-mic');
        await waitTile(g1.page, (t) => t.some((x) => x.name === 'Naledi Dlamini' && !x.micOff), 'host mic back on', 15000);
        const u0 = await energyOf(g1); await sleep(5000); const u1 = await energyOf(g1);
        const growthBack = Math.max(...u1.map((v, i) => v - u0[i]));
        ok(growthBack > 0.02, 'audio energy should grow again after unmute: ' + growthBack);
        return `cam off->tile camOff, on->frames resume; remote audio energy per 5 s window: unmuted ${growthOn.toFixed(2)}, muted ${growthMuted.toFixed(4)}, unmuted again ${growthBack.toFixed(2)}`;
    });

    await scenario('(d) device switch with replaceTrack (no renegotiation)', async () => {
        const devs = await host.page.evaluate(async () => { const d = await navigator.mediaDevices.enumerateDevices(); return d.map((x) => [x.kind, x.deviceId, x.label]); });
        await host.page.click('#ctl-devices summary');
        await host.page.waitForSelector('#dev-mic');
        const mics = await host.page.$$eval('#dev-mic option', (o) => o.map((x) => [x.value, x.textContent]));
        const cams = await host.page.$$eval('#dev-cam option', (o) => o.map((x) => [x.value, x.textContent]));
        const sdpBefore = await host.page.evaluate(() => window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2).map((p) => p.localDescription.sdp.length + ':' + p.signalingState));
        const trackBefore = await host.page.evaluate(() => window.__engageRoom.media.audioTrack.id);
        const camBefore = await host.page.evaluate(() => window.__engageRoom.media.videoTrack.id);
        let switchedMic = 'only one microphone exists, re-selected the same one';
        if (mics.length > 1) {
            const current = await host.page.$eval('#dev-mic', (s) => s.value);
            const other = mics.find(([v]) => v !== current)[0];
            await host.page.selectOption('#dev-mic', other);
            switchedMic = `switched to ${mics.find(([v]) => v === other)[1]}`;
        } else {
            await host.page.evaluate(() => window.__engageRoom.media.switchMic(window.__engageRoom.media.micId));
        }
        if (cams.length > 1) {
            const current = await host.page.$eval('#dev-cam', (s) => s.value);
            await host.page.selectOption('#dev-cam', cams.find(([v]) => v !== current)[0]);
        } else {
            await host.page.evaluate(() => window.__engageRoom.media.switchCamera(window.__engageRoom.media.camId));
        }
        await sleep(2500);
        const trackAfter = await host.page.evaluate(() => window.__engageRoom.media.audioTrack.id);
        const camAfter = await host.page.evaluate(() => window.__engageRoom.media.videoTrack.id);
        ok(trackAfter !== trackBefore, 'microphone track should have been replaced');
        ok(camAfter !== camBefore, 'camera track should have been replaced');
        const senders = await host.page.evaluate(() => window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2).flatMap((p) => p.getSenders().map((s) => s.track && s.track.id)));
        ok(senders.includes(trackAfter) && senders.includes(camAfter), 'senders should carry the new tracks');
        const sdpAfter = await host.page.evaluate(() => window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2).map((p) => p.localDescription.sdp.length + ':' + p.signalingState));
        ok(JSON.stringify(sdpBefore) === JSON.stringify(sdpAfter), 'no renegotiation expected, local description changed: ' + sdpBefore + ' -> ' + sdpAfter);
        await host.page.keyboard.press('Escape');
        const f = await assertFlow(g1.page, { peers: 2, label: 'g1 after switch' });
        const speaker = await host.page.$('#dev-spk');
        return `${switchedMic}; devices seen: ${devs.length} (${mics.length} mic, ${cams.length} camera); sender tracks replaced, local SDP unchanged; guest still receives (frames ${f[0].frames}); speaker select ${speaker ? 'shown (setSinkId supported)' : 'hidden'}`;
    });

    await scenario('(e) screen share start / banner / one sharer / stop via browser stop', async () => {
        // Guest 1 shares a real captured screen, the host and the phone see a large screen tile.
        await g1.page.click('#ctl-share');
        await waitTile(host.page, (t) => t.some((x) => x.kind === 'screen'), 'screen tile on host', 20000);
        const hostBanner = await text(host.page, '#banner');
        ok(/Thandi Mokoena is sharing their screen/.test(hostBanner), 'host banner: ' + hostBanner);
        const layout = await host.page.getAttribute('#grid', 'data-mode');
        ok(layout === 'focus', 'grid should switch to focus layout, is ' + layout);
        const hostShareDisabled = await host.page.$eval('#ctl-share', (b) => b.disabled);
        ok(hostShareDisabled, 'host share button should be disabled while someone else shares');
        const ht = await tiles(host.page);
        const screen = ht.find((x) => x.kind === 'screen');
        ok(screen.focus, 'screen tile should be the large one');
        await sleep(2500);
        const snap = await rtcSnapshot(host.page);
        const screenFrames = Math.max(...snap.map((p) => p.screen?.frames || 0));
        ok(screenFrames > 0, 'host should decode screen frames, got ' + screenFrames);
        await shot(host, 'e-screenshare-desktop.png'); await shot(g2, 'e-screenshare-phone.png');
        const mine = await text(g1.page, '#banner');
        ok(/You are sharing your screen/.test(mine), 'sharer banner: ' + mine);
        // The browser's own "Stop sharing" ends the track: stop it and fire 'ended' as the browser does.
        await g1.page.evaluate(() => {
            if (window.__fakeScreen) return window.__fakeScreen.stop();
            const t = [...window.__tracks].reverse().find((x) => x.kind === 'video' && x.readyState === 'live' && /screen|window|tab|web-contents/i.test(x.label));
            t.stop(); t.dispatchEvent(new Event('ended'));
        });
        await waitTile(host.page, (t) => !t.some((x) => x.kind === 'screen'), 'screen tile gone', 15000);
        await host.page.waitForFunction(() => !/sharing their screen/.test(document.getElementById('banner').innerText), null, { timeout: 15000 });
        ok(!(await host.page.$eval('#ctl-share', (b) => b.disabled)), 'host share button should be enabled again');
        // Share again (transceiver reuse) and stop with the toolbar button.
        await g1.page.click('#ctl-share');
        await waitTile(host.page, (t) => t.some((x) => x.kind === 'screen'), 'screen tile again', 20000);
        await g1.page.click('#ctl-share');
        await waitTile(host.page, (t) => !t.some((x) => x.kind === 'screen'), 'screen tile gone after toolbar stop', 15000);
        return `screen frames decoded on host: ${screenFrames}; large tile + strip layout; host's share disabled meanwhile; stop via browser stop (simulated 'ended' on the real capture track) and via toolbar both clean up`;
    });

    await scenario('(f) offline 8 s (signalling) and ICE flap: recovers without reload', async () => {
        await g2.page.evaluate(() => { window.__marker = 'same-page'; });
        const before = await rtcSnapshot(g2.page);
        const bytes0 = before.map((p) => p.video?.bytes || 0);
        const t0 = Date.now();
        await g2.ctx.setOffline(true);
        await g2.page.waitForFunction(() => document.getElementById('room-status').textContent === 'Reconnecting', null, { timeout: 15000 });
        const tShown = Date.now() - t0;
        const netText = await text(g2.page, '#room-net');
        ok(/Offline|Reconnecting/.test(netText), 'room-net text should say so: ' + netText);
        await shot(g2, 'f-phone-reconnecting.png');
        await sleep(Math.max(0, 8000 - (Date.now() - t0)));
        const tBack = Date.now();
        await g2.ctx.setOffline(false);
        await g2.page.waitForFunction(() => document.getElementById('room-status').textContent === 'Live', null, { timeout: 30000 });
        const tLive = Date.now() - tBack;
        const marker = await g2.page.evaluate(() => window.__marker);
        ok(marker === 'same-page', 'page must not have reloaded');
        await waitConnected(g2.page, 2, 30000);
        const flow = await assertFlow(g2.page, { peers: 2, label: 'g2 after offline' });
        // ICE flap on the host's link to g1: the connection reports disconnected, then failed, then really is fine again.
        const r0 = await host.page.evaluate(() => { window.__restarts = 0; const o = RTCPeerConnection.prototype.restartIce; RTCPeerConnection.prototype.restartIce = function () { window.__restarts++; return o.call(this); }; return 0; });
        const ufrag0 = await host.page.evaluate(() => window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2).map((p) => /a=ice-ufrag:(\S+)/.exec(p.remoteDescription.sdp)[1]));
        await host.page.evaluate(() => {
            const pcs = window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2);
            const pc = pcs[0];
            window.__flapped = pc;
            Object.defineProperty(pc, 'connectionState', { configurable: true, get: () => 'disconnected' });
            pc.dispatchEvent(new Event('connectionstatechange'));
        });
        await host.page.waitForFunction(() => [...document.querySelectorAll('#grid .tile')].some((t) => /Connection lost, trying to reconnect/.test(t.querySelector('.tile-status')?.innerText || '')), null, { timeout: 8000 });
        await host.page.waitForFunction(() => window.__restarts >= 1, null, { timeout: 12000 });
        await shot(host, 'f-host-tile-lost.png');
        await host.page.evaluate(() => { delete window.__flapped.connectionState; window.__flapped.dispatchEvent(new Event('connectionstatechange')); });
        await host.page.waitForFunction(() => ![...document.querySelectorAll('#grid .tile')].some((t) => /Connection lost/.test(t.querySelector('.tile-status')?.innerText || '')), null, { timeout: 15000 });
        const readU = () => host.page.evaluate(() => window.__pcs.filter((p) => p.signalingState !== 'closed' && p.getTransceivers().length >= 2).map((p) => /a=ice-ufrag:(\S+)/.exec(p.remoteDescription.sdp)[1]));
        let ufrag1 = await readU();
        for (let i = 0; i < 40 && JSON.stringify(ufrag0) === JSON.stringify(ufrag1); i++) { await sleep(250); ufrag1 = await readU(); }
        ok(JSON.stringify(ufrag0) !== JSON.stringify(ufrag1), 'ICE credentials should have changed after the restart: ' + ufrag0 + ' vs ' + ufrag1);
        const restarts = await host.page.evaluate(() => window.__restarts);
        await assertFlow(host.page, { peers: 2, label: 'host after flap' });
        return `HTTP offline 8 s: "Reconnecting" shown after ${tShown} ms, back to Live ${tLive} ms after going online, same page, media flowing (frames ${flow[0].frames}); simulated ICE flap: tile "Connection lost, trying to reconnect", restartIce x${restarts}, new ICE credentials negotiated, link healthy again`;
    });

    await scenario('(g) killed tab: others drop the tile in about 20 s, no ghost', async () => {
        // A crash does not say goodbye: crash the renderer (no pagehide, no leave request).
        const cdp = await g2.ctx.newCDPSession(g2.page);
        const t0 = Date.now();
        cdp.send('Page.crash').catch(() => {}); // never resolves: the renderer is gone
        await sleep(300);
        await waitTile(host.page, (t) => !t.some((x) => /Pieter/.test(x.name)), 'ghost tile removed on host', 40000);
        const tGone = Date.now() - t0;
        await waitTile(g1.page, (t) => !t.some((x) => /Pieter/.test(x.name)), 'ghost tile removed on guest 1', 20000);
        const people = await text(host.page, '#people');
        ok(/Pieter/.test(people) ? /Not responding/.test(people) : true, 'people list: ' + people.replace(/\n/g, ' | '));
        ok((await tiles(host.page)).length === 2, 'host should have 2 tiles');
        await sleep(1500);
        await shot(host, 'g-after-kill.png');
        return `tile gone ${(tGone / 1000).toFixed(1)} s after the kill (presence expiry 20 s + poll); people list marks them: ${/Not responding/.test(people) ? 'Not responding' : 'removed'}`;
    });

    await scenario('(g2) closing a tab politely: tile goes quickly', async () => {
        const gx = await newPerson(browser, { name: 'Guest 1', email: ensureGuest(1).email });
        await guestSignIn(gx, key);
        await enter(gx, key, 'waiting');
        await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
        await host.page.click('#banner button:has-text("Admit")');
        await waitPhase(gx, 'live', 20000);
        await waitTile(host.page, (t) => t.some((x) => x.name === 'Guest 1' && x.state === 'connected'), 'Guest 1 connected', 40000);
        const t0 = Date.now();
        await gx.page.close({ runBeforeUnload: true });
        await waitTile(host.page, (t) => !t.some((x) => x.name === 'Guest 1'), 'tile gone after polite close', 20000);
        return `tile gone ${((Date.now() - t0) / 1000).toFixed(1)} s after a normal close (keepalive leave on pagehide)`;
    });

    await scenario('(h) host lock: a new joiner is refused', async () => {
        await host.page.click('#ctl-lock');
        await host.page.waitForFunction(() => document.getElementById('ctl-lock').getAttribute('aria-pressed') === 'true');
        const gl = await newPerson(browser, { name: 'Guest 2', email: ensureGuest(2).email });
        await guestSignIn(gl, key);
        await enter(gl, key, 'ended');
        const heading = await text(gl.page, '#notice-h');
        ok(/locked/i.test(heading), 'refused joiner should be told the meeting is locked: ' + heading);
        const hasWayOut = await gl.page.$('#notice-actions a, #notice-actions button');
        ok(!!hasWayOut, 'notice needs a way out');
        await shot(gl, 'h-locked-notice.png');
        await host.page.click('#ctl-lock');
        await host.page.waitForFunction(() => document.getElementById('ctl-lock').getAttribute('aria-pressed') === 'false');
        return `joiner sees "${heading}" with a way out; lock button aria-pressed toggles`;
    });

    await scenario('(h2) host denies a person at the door', async () => {
        const gd = await newPerson(browser, { name: 'Guest 4', email: ensureGuest(4).email });
        await guestSignIn(gd, key);
        await enter(gd, key, 'waiting');
        await host.page.waitForSelector('#banner button:has-text("Deny")', { timeout: 15000 });
        await host.page.click('#banner button:has-text("Deny")');
        await waitPhase(gd, 'ended', 20000);
        const heading = await text(gd.page, '#notice-h');
        ok(/did not let you in/i.test(heading), 'denied person sees: ' + heading);
        return `denied person sees "${heading}"`;
    });

    await scenario('(j) host mute command', async () => {
        ok((await g1.page.getAttribute('#ctl-mic', 'aria-pressed')) === 'true', 'guest mic should start on');
        await host.page.click('#ctl-people');
        await host.page.waitForSelector('#people button[data-act="mute"]');
        await host.page.click('#people button[data-act="mute"][data-name="Thandi Mokoena"]');
        await g1.page.waitForFunction(() => document.getElementById('ctl-mic').getAttribute('aria-pressed') === 'false', null, { timeout: 15000 });
        await g1.page.waitForFunction(() => /The host muted you/.test(document.getElementById('banner').innerText), null, { timeout: 5000 });
        await waitTile(host.page, (t) => t.some((x) => x.name === 'Thandi Mokoena' && x.micOff), 'host sees Thandi muted', 15000);
        await g1.page.click('#ctl-mic');
        await g1.page.waitForFunction(() => document.getElementById('ctl-mic').getAttribute('aria-pressed') === 'true');
        return 'guest muted by the host with a visible message, can unmute again, host tile shows mic off meanwhile';
    });

    await scenario('(i) host removes a person: they are out and cannot re-enter', async () => {
        await host.page.click('#people button[data-act="remove"][data-name="Thandi Mokoena"]');
        await waitPhase(g1, 'ended', 20000);
        const heading = await text(g1.page, '#notice-h');
        ok(/removed you/i.test(heading), 'removed person sees: ' + heading);
        const freed = await g1.page.evaluate(() => ({ pcs: window.__pcs.filter((p) => p.signalingState !== 'closed').length, tracks: window.__tracks.filter((t) => t.readyState === 'live').length, ctx: window.__ctxs.filter((c) => c.state !== 'closed').length }));
        ok(freed.pcs === 0 && freed.tracks === 0 && freed.ctx === 0, 'everything should be released after removal: ' + JSON.stringify(freed));
        // Cannot come back: reload and press Join.
        await openRoom(g1, key);
        await g1.page.waitForSelector('#btn-join:not([disabled])');
        await g1.page.click('#btn-join');
        await g1.page.waitForFunction(() => /removed/i.test(document.getElementById('notice-h')?.textContent || ''), null, { timeout: 15000 });
        await waitTile(host.page, (t) => !t.some((x) => x.name === 'Thandi Mokoena'), 'removed tile gone on host', 15000);
        return `removed person told "${heading}", all peer connections/tracks/audio contexts released (${JSON.stringify(freed)}), re-enter refused`;
    });

    await scenario('(k) host ends the meeting: everyone leaves', async () => {
        // Bring back a second person so "everyone" is more than one.
        const gy = await newPerson(browser, { name: 'Guest 3', email: ensureGuest(3).email });
        await guestSignIn(gy, key);
        await enter(gy, key, 'waiting');
        await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
        await host.page.click('#banner button:has-text("Admit")');
        await waitPhase(gy, 'live', 20000);
        const t0 = Date.now();
        await host.page.click('#ctl-end');
        await waitPhase(gy, 'ended', 20000);
        const tSeen = Date.now() - t0;
        const heading = await text(gy.page, '#notice-h');
        ok(/host ended the meeting/i.test(heading), 'others see: ' + heading);
        const freed = await gy.page.evaluate(() => ({ pcs: window.__pcs.filter((p) => p.signalingState !== 'closed').length, tracks: window.__tracks.filter((t) => t.readyState === 'live').length, ctx: window.__ctxs.filter((c) => c.state !== 'closed').length }));
        ok(freed.pcs === 0 && freed.tracks === 0 && freed.ctx === 0, 'everything should be released: ' + JSON.stringify(freed));
        await gy.page.waitForURL((u) => !/\/meetings\//.test(u.pathname), { timeout: 15000 });
        const dest = new URL(gy.page.url()).pathname;
        await host.page.waitForURL((u) => !/\/meetings\//.test(u.pathname), { timeout: 15000 });
        noErrors([host, g1, g2], 'call chain');
        return `others saw "${heading}" ${tSeen} ms after End; sent on to ${dest}; host sent to ${new URL(host.page.url()).pathname}; resources released`;
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// (l) the seventh person
// ─────────────────────────────────────────────────────────────────────────────
async function fullRoom(browser) {
    await scenario('(l) six in the call (full mesh), seventh refused as full', async () => {
        const host = await newPerson(browser, { name: 'Host', kind: 'host', viewport: { width: 1280, height: 800 } });
        await login(host);
        const key = await createMeeting(host, 'E2E full room');
        await enter(host, key, 'live');
        const emails = ['thandi@karoofresh.example', 'pieter@karoofresh.example', ...[1, 2, 3, 4, 5].map((n) => ensureGuest(10 + n).email)];
        const guests = [];
        for (let i = 0; i < 6; i++) {
            const g = await newPerson(browser, { name: `Guest ${i + 1}`, email: emails[i], viewport: { width: 960, height: 600 } });
            await guestSignIn(g, key);
            await enter(g, key, 'waiting');
            guests.push(g);
        }
        // Admit five (the host plus five = six). The sixth guest stays at the door and tries once the room is full.
        await host.page.click('#ctl-people');
        await host.page.waitForFunction(() => document.querySelectorAll('#waiters button[data-act="admit"]').length === 6, null, { timeout: 60000 });
        for (let i = 0; i < 5; i++) {
            await host.page.locator('#waiters button[data-act="admit"]').first().click();
            await sleep(900);
        }
        for (const g of guests) {
            const ph = await g.page.evaluate(() => document.body.dataset.state);
            if (ph === 'waiting') await g.page.waitForFunction(() => document.body.dataset.state !== 'waiting', null, { timeout: 20000 }).catch(() => {});
        }
        const phases = await Promise.all(guests.map((g) => g.page.evaluate(() => document.body.dataset.state)));
        const live = guests.filter((g, i) => phases[i] === 'live');
        ok(live.length === 5, `expected 5 guests live, phases: ${phases}`);
        for (const p of [host, ...live]) await waitConnected(p.page, 5, 90000);
        const flows = [];
        for (const p of [host, live[0], live[4]]) flows.push((await assertFlow(p.page, { peers: 5, label: p.name })).length);
        const tl = await tiles(host.page);
        ok(tl.length === 6, 'host should see 6 tiles, saw ' + tl.length);
        await sleep(1000);
        await shot(host, 'l-6tiles-desktop.png');
        // The sixth guest, still at the door, is admitted now that the room is full: join must refuse.
        const last = guests[phases.indexOf('waiting')];
        await host.page.waitForSelector('#waiters button[data-act="admit"]');
        await host.page.click('#waiters button[data-act="admit"]');
        await last.page.waitForFunction(() => document.body.dataset.state === 'ended', null, { timeout: 25000 });
        const heading = await text(last.page, '#notice-h');
        const body = await text(last.page, '#notice-text');
        ok(/full/i.test(heading), 'seventh person should be told the meeting is full: ' + heading);
        await shot(last, 'l-full-notice.png');
        noErrors([host, ...guests], 'six-person room');
        return `6 browsers meshed (each with 5 connected peers, remote frames/bytes growing on 3 sampled), 7th told "${heading}" / "${body}"`;
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// (n) permissions: no fake UI, so the browser really denies
// ─────────────────────────────────────────────────────────────────────────────
async function permissions() {
    const args = CHROME_ARGS.filter((a) => !a.includes('fake-ui'));
    const browser = await chromium.launch({ channel: 'chrome', headless: true, args, ignoreDefaultArgs: ['--mute-audio'] });
    try {
        const host = await newPerson(browser, { name: 'Host', kind: 'host', permissions: ['camera', 'microphone'] });
        await login(host);
        const key = await createMeeting(host, 'E2E permissions');
        await enter(host, key, 'live');

        await scenario('(n1) camera denied: audio-only join works with a clear message', async () => {
            const g = await newPerson(browser, { name: 'NoCam', email: 'thandi@karoofresh.example', permissions: ['microphone'] });
            await guestSignIn(g, key);
            await openRoom(g, key);
            await g.page.waitForSelector('#btn-join:not([disabled])');
            await g.page.waitForFunction(() => /camera/i.test(document.getElementById('lobby-msg').textContent) && !/Looking for/.test(document.getElementById('lobby-msg').textContent), null, { timeout: 15000 });
            const msg = await text(g.page, '#lobby-msg');
            const checks = await text(g.page, '#lobby-checks');
            ok(/camera is blocked/i.test(msg) && /audio only/i.test(msg), 'lobby message: ' + msg);
            ok(/Camera is blocked/.test(checks) && /Microphone found/.test(checks), 'checks list: ' + checks.replace(/\n/g, ' '));
            ok(await g.page.$('#lobby-retry'), 'there should be a Try again control');
            await shot(g, 'n-camera-denied-lobby.png');
            await g.page.click('#btn-join');
            await waitPhase(g, 'waiting');
            await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
            await host.page.click('#banner button:has-text("Admit")');
            await waitPhase(g, 'live', 20000);
            await waitConnected(host.page, 1); await waitConnected(g.page, 1);
            const hostSees = await tiles(host.page);
            const t = hostSees.find((x) => x.name === 'Thandi Mokoena');
            ok(t && t.cam === 'off', 'host sees an initials tile for the audio-only person');
            const flowHost = await assertFlow(host.page, { peers: 1, video: false, audio: true, label: 'host hears audio-only guest' });
            const own = await tiles(g.page);
            await shot(g, 'n-camera-denied-call.png');
            await g.page.close();
            await waitTile(host.page, (tt) => tt.length === 1, 'guest gone', 20000);
            return `message: "${msg.slice(0, 90)}..."; joined audio-only; host hears them (audio bytes ${flowHost[0].aBytes}, energy ${flowHost[0].energy.toFixed(2)}); own tile note "${own.find((x) => x.key === 'me').cam}"`;
        });

        await scenario('(n2) camera and microphone denied: still joins as a viewer', async () => {
            const g = await newPerson(browser, { name: 'Viewer', email: 'pieter@karoofresh.example', permissions: [] });
            await guestSignIn(g, key);
            await openRoom(g, key);
            await g.page.waitForSelector('#btn-join:not([disabled])');
            await g.page.waitForFunction(() => /viewer/i.test(document.getElementById('lobby-msg').textContent), null, { timeout: 15000 });
            const msg = await text(g.page, '#lobby-msg');
            ok(/blocked/i.test(msg) && /how|allow/i.test(msg) || /Allow/.test(msg), 'message should explain how to allow access: ' + msg);
            await shot(g, 'n-both-denied-lobby.png');
            await g.page.click('#btn-join');
            await waitPhase(g, 'waiting');
            await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
            await host.page.click('#banner button:has-text("Admit")');
            await waitPhase(g, 'live', 20000);
            await waitConnected(host.page, 1); await waitConnected(g.page, 1);
            // The viewer sees and hears the host.
            const flow = await assertFlow(g.page, { peers: 1, label: 'viewer receives' });
            const micDisabled = await g.page.getAttribute('#ctl-mic', 'aria-label');
            await shot(g, 'n-viewer-call.png');
            await g.page.close();
            return `message: "${msg.slice(0, 100)}..."; viewer joined and receives host video (frames ${flow[0].frames}) and audio; mic button label "${micDisabled}"`;
        });

        await scenario('(n3) microphone denied only: listen-only message', async () => {
            const g = await newPerson(browser, { name: 'NoMic', email: 'thandi@karoofresh.example', permissions: ['camera'] });
            await guestSignIn(g, key);
            await openRoom(g, key);
            await g.page.waitForSelector('#btn-join:not([disabled])');
            await g.page.waitForFunction(() => /microphone/i.test(document.getElementById('lobby-msg').textContent) && !/Looking for/.test(document.getElementById('lobby-msg').textContent), null, { timeout: 15000 });
            const msg = await text(g.page, '#lobby-msg');
            ok(/listen/i.test(msg), 'message: ' + msg);
            await g.page.close();
            return `message: "${msg.slice(0, 80)}..."`;
        });
        noErrors([host], 'permissions host');
    } finally {
        await closeAll();
        await browser.close();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Misc: keyboard, focus, light/dark, reduced motion, 1/2 tiles, spotlight
// ─────────────────────────────────────────────────────────────────────────────
async function misc(browser) {
    const host = await newPerson(browser, { name: 'Host', kind: 'host' });
    const g1 = await newPerson(browser, { name: 'Thandi', email: 'thandi@karoofresh.example', colorScheme: 'dark' });
    await login(host);
    const key = await createMeeting(host, 'E2E misc');

    await scenario('(o1) lobby: focus on Join, light and dark themes, 1-tile call screenshot', async () => {
        await openRoom(host, key);
        await host.page.waitForSelector('#btn-join:not([disabled])');
        await sleep(2500);
        const focused = await host.page.evaluate(() => document.activeElement?.id);
        ok(focused === 'btn-join', 'Join should have focus in the lobby, has ' + focused);
        await shot(host, 'o-lobby-light.png');
        await host.page.emulateMedia({ colorScheme: 'dark' });
        await sleep(300);
        await shot(host, 'o-lobby-dark.png');
        await host.page.emulateMedia({ colorScheme: 'light' });
        const meterBefore = await host.page.$eval('#lobby-level', (e) => e.style.transform);
        let moved = false;
        for (let i = 0; i < 40 && !moved; i++) { await sleep(150); const now = await host.page.$eval('#lobby-level', (e) => e.style.transform); if (now !== meterBefore && now !== 'scaleX(0.000)') moved = true; }
        ok(moved, 'mic level meter should move with the fake microphone');
        const ctxBefore = await host.page.evaluate(() => window.__ctxs.map((c) => c.state));
        await host.page.click('#btn-join');
        await waitPhase(host, 'live');
        await sleep(800);
        const ctxAfter = await host.page.evaluate(() => window.__ctxs.map((c) => c.state));
        ok(ctxAfter[0] === 'closed', 'the lobby audio context should be closed after joining: ' + ctxAfter);
        await shot(host, 'o-1tile.png');
        return `meter moved; lobby AudioContext states before join ${ctxBefore}, after ${ctxAfter} (first is the lobby one)`;
    });

    await scenario('(o2) keyboard shortcuts, panel focus, tabs, spotlight, reduced motion', async () => {
        const { page } = host;
        await page.click('body', { position: { x: 5, y: 300 } });
        await page.keyboard.press('m');
        await page.waitForFunction(() => document.getElementById('ctl-mic').getAttribute('aria-pressed') === 'false');
        await page.keyboard.press('m');
        await page.waitForFunction(() => document.getElementById('ctl-mic').getAttribute('aria-pressed') === 'true');
        await page.keyboard.press('v');
        await page.waitForFunction(() => document.getElementById('ctl-cam').getAttribute('aria-pressed') === 'false');
        await page.keyboard.press('v');
        await page.waitForFunction(() => document.getElementById('ctl-cam').getAttribute('aria-pressed') === 'true', null, { timeout: 10000 });
        // Typing in a field must not trigger shortcuts.
        await page.click('#ctl-people');
        await page.waitForFunction(() => !document.getElementById('side').hidden);
        const focusIn = await page.evaluate(() => document.getElementById('side').contains(document.activeElement));
        ok(focusIn, 'focus should move into the panel when it opens');
        await page.evaluate(() => { const i = document.createElement('input'); i.id = 'probe'; document.getElementById('side').append(i); i.focus(); });
        await page.keyboard.type('mvs');
        ok((await page.getAttribute('#ctl-mic', 'aria-pressed')) === 'true', 'typing m in an input must not mute');
        await page.evaluate(() => document.getElementById('probe').remove());
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => document.getElementById('side').hidden);
        const back = await page.evaluate(() => document.activeElement?.id);
        ok(back === 'ctl-people', 'focus should return to the people button, is ' + back);
        const tabs = await page.$$eval('#side [role=tab]', (t) => t.map((x) => x.id));
        // Agreement / Sign tabs: lazy iframes.
        await page.click('#ctl-people');
        let lazy = 'no Agreement tab for this engagement';
        if (tabs.includes('tab-agreement')) {
            const srcBefore = await page.$eval('#frame-agreement', (f) => f.getAttribute('src'));
            ok(!srcBefore, 'agreement iframe should not load before it is opened');
            await page.click('#tab-agreement');
            await page.waitForFunction(() => !!document.getElementById('frame-agreement').getAttribute('src'));
            await page.waitForTimeout(1500);
            lazy = 'Agreement iframe empty until opened, then loaded';
            await shot(host, 'o-agreement-tab.png');
        }
        await page.keyboard.press('Escape');
        // Spotlight with Enter on the tile.
        await page.focus('#grid .tile[data-key="me"] .tile-hit');
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelector('#grid .tile[data-key="me"] .tile-hit').getAttribute('aria-pressed') === 'true');
        await page.keyboard.press('Enter');
        // Reduced motion: no speaking ring, no transitions.
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const rm = await page.evaluate(() => { const t = document.querySelector('#grid .tile'); const c = getComputedStyle(t); return { transition: c.transitionDuration, dot: c.outlineStyle }; });
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        // Focus ring visible on the dark stage.
        await page.focus('#ctl-share');
        await page.keyboard.press('Tab');
        await page.keyboard.press('Shift+Tab');
        const ring = await page.evaluate(() => { const c = getComputedStyle(document.activeElement); return `${c.outlineStyle} ${c.outlineWidth} ${c.outlineColor}`; });
        ok(/solid 2px/.test(ring), 'focus ring on the dark stage: ' + ring);
        // Live regions exist.
        const live = await page.$$eval('[aria-live]', (e) => e.map((x) => x.id || x.className));
        return `shortcuts m/v work and are ignored while typing; focus moves into panel and back; ${lazy}; Enter spotlights; reduced-motion transition ${rm.transition}; focus ring ${ring}; live regions ${live.join(',')}`;
    });

    await scenario('(o3) two people, dark OS theme lobby, 2-tile screenshots', async () => {
        await guestSignIn(g1, key);
        await enter(g1, key, 'waiting');
        await host.page.waitForSelector('#banner button:has-text("Admit")', { timeout: 15000 });
        await host.page.click('#banner button:has-text("Admit")');
        await waitPhase(g1, 'live', 20000);
        await waitConnected(host.page, 1);
        await sleep(1500);
        await shot(host, 'o-2tiles.png');
        // Spotlight the guest on the host: focus layout with a strip.
        await host.page.click('#grid .tile[data-key^="p"] .tile-hit');
        await sleep(500);
        const mode = await host.page.getAttribute('#grid', 'data-mode');
        ok(mode === 'focus', 'spotlight should switch to focus layout');
        await shot(host, 'o-spotlight.png');
        await host.page.click('#grid .tile[data-key^="p"] .tile-hit');
        return 'screenshots taken; spotlight toggles focus layout';
    });

    await scenario('(s) hidden tab: polling slows to a heartbeat when stable, speeds up on return', async () => {
        const { page } = host;
        await sleep(22000); // let the "recent roster change" window (20 s) pass so the call counts as stable
        const times = [];
        await page.route('**/meetings/*/poll*', (r) => { times.push(Date.now()); r.continue(); });
        await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
        await sleep(18000);
        const gaps = times.slice(1).map((t, i) => t - times[i]);
        const slow = gaps.slice(1); // the first one may have been scheduled before the tab was hidden
        ok(times.length >= 3, 'heartbeats must continue while hidden, saw ' + times.length + ' polls in 18 s');
        ok(slow.every((g) => g >= 4500 && g <= 9000), 'hidden and stable should poll about every 5 s, gaps: ' + gaps);
        ok(Math.max(...gaps) < 18000, 'never anywhere near the 20 s presence limit');
        await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); });
        await sleep(1500);
        await page.unroute('**/meetings/*/poll*');
        const people = await text(page, '#people');
        ok(/Thandi/.test(people) && !/Not responding/.test(people), 'still counted as present: ' + people.replace(/\n/g, ' '));
        return `${times.length} polls in 18 s hidden, gaps ${gaps.map((g) => (g / 1000).toFixed(1)).join(' ')} s (visible-stable is 2.5 s, busy is 1 s); still present afterwards`;
    });

    await scenario('(r) hostile network: 429, garbage and 500 responses on the poll', async () => {
        const { page } = host;
        const seen = [];
        let mode = 'throttle';
        let n = 0;
        await page.route('**/meetings/*/poll*', async (route) => {
            seen.push({ t: Date.now(), mode });
            if (mode === 'throttle' && n++ < 3) return route.fulfill({ status: 429, headers: { 'Retry-After': '2', 'content-type': 'application/json' }, body: '{"message":"Too many"}' });
            if (mode === 'garbage' && n++ < 3) return route.fulfill({ status: 200, headers: { 'content-type': 'text/html' }, body: '<html>proxy error</html>' });
            if (mode === 'fivehundred' && n++ < 4) return route.fulfill({ status: 500, body: 'boom' });
            return route.continue();
        });
        const tiles0 = (await tiles(page)).length;
        await page.waitForFunction(() => window.__engageRoom && true);
        await sleep(16000);
        const throttled = seen.filter((s) => s.mode === 'throttle');
        const gaps = throttled.slice(1, 4).map((s, i) => s.t - throttled[i].t);
        ok(gaps.length >= 2 && gaps.every((g) => g >= 4500), 'after a 429 the next poll should wait 5 s or more: gaps ' + gaps);
        const statusAfter429 = await text(page, '#room-status');
        ok(statusAfter429 === 'Live', '429 alone should not say Reconnecting: ' + statusAfter429);
        mode = 'garbage'; n = 0;
        await sleep(9000);
        ok((await tiles(page)).length === tiles0, 'garbage poll answers must not wipe the call (tiles ' + tiles0 + ' -> ' + (await tiles(page)).length + ')');
        mode = 'fivehundred'; n = 0;
        let sawReconnecting = false;
        for (let i = 0; i < 40 && !sawReconnecting; i++) { await sleep(500); sawReconnecting = (await text(page, '#room-status')) === 'Reconnecting'; }
        ok(sawReconnecting, '5xx answers should show Reconnecting');
        await page.waitForFunction(() => document.getElementById('room-status').textContent === 'Live', null, { timeout: 40000 });
        await page.unroute('**/meetings/*/poll*');
        ok((await tiles(page)).length === tiles0, 'tiles intact after recovery');
        return `429: next polls ${gaps.map((g) => (g / 1000).toFixed(1) + ' s').join(', ')} apart, status stayed Live; garbage 200 ignored (call intact); 500s -> Reconnecting -> Live again`;
    });

    await scenario('(q) expired session (419) says reload', async () => {
        // The dev server does not enforce CSRF on these routes, so the 419 is injected at the network layer.
        await g1.page.route('**/meetings/*/state', (r) => r.fulfill({ status: 419, contentType: 'application/json', body: '{"message":"CSRF token mismatch."}' }));
        await g1.page.click('#ctl-mic');
        await waitPhase(g1, 'ended', 20000);
        const heading = await text(g1.page, '#notice-h');
        const body = await text(g1.page, '#notice-text');
        ok(/session expired/i.test(heading) && /reload/i.test(body), 'expired notice: ' + heading + ' / ' + body);
        const reload = await g1.page.$('#notice-actions button');
        ok(!!reload, 'a Reload button should be offered');
        return `"${heading}" / "${body.slice(0, 60)}..." with a Reload button`;
    });

    await scenario('(p) second window displaces the first (409), first window stops and says so', async () => {
        const p2 = await host.ctx.newPage();
        p2.on('console', (m) => { if (m.type() === 'error') host.errors.push('p2 ' + m.text()); });
        await p2.goto(`/meetings/${key}`);
        await p2.waitForSelector('#btn-join:not([disabled])', { timeout: 30000 });
        await p2.click('#btn-join');
        await p2.waitForFunction(() => document.body.dataset.state === 'live', null, { timeout: 20000 });
        await waitPhase(host, 'ended', 20000);
        const heading = await text(host.page, '#notice-h');
        ok(/another window/i.test(heading), 'first window should say so: ' + heading);
        const freed = await host.page.evaluate(() => ({ pcs: window.__pcs.filter((p) => p.signalingState !== 'closed').length, tracks: window.__tracks.filter((t) => t.readyState === 'live').length }));
        ok(freed.pcs === 0 && freed.tracks === 0, 'first window should release everything: ' + JSON.stringify(freed));
        await p2.close();
        return `first window: "${heading}", all connections and tracks released (${JSON.stringify(freed)}); second window is the live one`;
    });
    noErrors([host, g1], 'misc', /status of (403|409|419|429|500|502)|ERR_INTERNET_DISCONNECTED/);
}

const browser = await launch();
try {
    if (run('call')) await callChain(browser);
    if (run('full')) await fullRoom(browser);
    if (run('misc')) await misc(browser);
} catch (e) {
    console.error('Harness failure:', e);
    results.push({ name: 'harness', ok: false, error: String(e && e.stack || e) });
} finally {
    await closeAll();
    await browser.close();
}
if (run('perms')) {
    try { await permissions(); } catch (e) { console.error('perms harness failure', e); results.push({ name: 'perms harness', ok: false, error: String(e && e.stack || e) }); }
}
console.log('\n=== SUMMARY ===');
report(results);
const failed = results.filter((r) => !r.ok).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
