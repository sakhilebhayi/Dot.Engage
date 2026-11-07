// Lobby connection check. Gathers ICE candidates on a throwaway connection with the same servers the call will use
// and counts what came back. It reports only what it measured.

export async function gatherCandidates(iceServers, timeoutMs = 4500) {
    const result = { host: 0, srflx: 0, relay: 0, prflx: 0, errors: [], elapsed: 0, timedOut: false, failed: null };
    if (typeof RTCPeerConnection === 'undefined') {
        result.failed = 'This browser cannot make video calls.';
        return result;
    }
    const started = performance.now();
    let pc;
    try {
        pc = new RTCPeerConnection({ iceServers });
    } catch (e) {
        result.failed = 'The connection servers could not be used.';
        return result;
    }

    await new Promise((resolve) => {
        const finish = (timedOut) => { result.timedOut = timedOut; resolve(); };
        const timer = setTimeout(() => finish(true), timeoutMs);
        pc.onicecandidate = (event) => {
            if (!event.candidate) { clearTimeout(timer); finish(false); return; }
            const type = event.candidate.type || (/ typ (\w+)/.exec(event.candidate.candidate) || [])[1];
            if (type && type in result) result[type] += 1;
        };
        pc.onicecandidateerror = (event) => {
            result.errors.push({ url: event.url || '', code: event.errorCode, text: event.errorText || '' });
        };
        try {
            pc.createDataChannel('probe');
            pc.createOffer().then((offer) => pc.setLocalDescription(offer)).catch(() => { clearTimeout(timer); result.failed = 'Could not start the connection check.'; resolve(); });
        } catch (e) {
            clearTimeout(timer);
            result.failed = 'Could not start the connection check.';
            resolve();
        }
    });

    result.elapsed = Math.round(performance.now() - started);
    try { pc.onicecandidate = null; pc.onicecandidateerror = null; pc.close(); } catch (e) { /* closed */ }
    return result;
}

/** Plain-words verdicts for the lobby list. */
export function describe(result, { relayConfigured }) {
    if (result.failed) {
        return { direct: { tone: 'warn', text: result.failed }, relay: { tone: 'warn', text: 'Not checked' } };
    }
    let direct;
    if (result.srflx > 0) {
        direct = { tone: 'ok', text: `Direct connection possible (${result.srflx} public address${result.srflx === 1 ? '' : 'es'} found in ${(result.elapsed / 1000).toFixed(1)} s)` };
    } else if (result.host > 0) {
        direct = { tone: 'warn', text: result.timedOut || result.errors.length
            ? 'Only local addresses found: the public server did not answer, so you may only reach people on this network'
            : 'Only local addresses found: you may only reach people on this network' };
    } else {
        direct = { tone: 'warn', text: 'No network addresses found: the browser may be blocking connections' };
    }

    let relay;
    if (result.relay > 0) relay = { tone: 'ok', text: `Relay available (${result.relay} relay address${result.relay === 1 ? '' : 'es'} found)` };
    else if (relayConfigured) relay = { tone: 'warn', text: 'A relay is set up but did not answer from here' };
    else relay = { tone: 'warn', text: 'No relay configured: strict networks may not connect' };
    return { direct, relay };
}
