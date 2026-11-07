import { launch, newPerson, closeAll } from './lib.mjs';
const browser = await launch();
try {
  const p = await newPerson(browser, { name: 'x' });
  await p.page.goto('/login');
  for (const [proc, attach] of [[true,false],[false,false],[false,true],[true,true]]) {
    const res = await p.page.evaluate(async ([proc, attach]) => {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: proc, noiseSuppression: proc, autoGainControl: proc } });
      const a = new RTCPeerConnection(), b = new RTCPeerConnection();
      a.onicecandidate = e => e.candidate && b.addIceCandidate(e.candidate); b.onicecandidate = e => e.candidate && a.addIceCandidate(e.candidate);
      stream.getTracks().forEach(t => a.addTrack(t, stream));
      let remote; const got = new Promise(r => b.ontrack = e => { remote = e.streams[0]; r(); });
      await a.setLocalDescription(); await b.setRemoteDescription(a.localDescription); await b.setLocalDescription(); await a.setRemoteDescription(b.localDescription);
      await got;
      let el; if (attach) { el = new Audio(); el.srcObject = remote; await el.play().catch(e=>console.log('play fail', e.message)); }
      await new Promise(r => setTimeout(r, 4000));
      const st = await b.getStats(); let rec;
      st.forEach(s => { if (s.type === 'inbound-rtp') rec = { bytes: s.bytesReceived, energy: s.totalAudioEnergy, level: s.audioLevel, samples: s.totalSamplesReceived, concealed: s.concealedSamples }; });
      a.close(); b.close(); stream.getTracks().forEach(t=>t.stop());
      return { proc, attach, rec };
    }, [proc, attach]);
    console.log(JSON.stringify(res));
  }
} finally { await closeAll(); await browser.close(); }
