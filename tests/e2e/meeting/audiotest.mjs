import { launch, newPerson, closeAll } from './lib.mjs';
const browser = await launch();
try {
  const p = await newPerson(browser, { name: 'x' });
  await p.page.goto('/login');
  for (const proc of [true, false]) {
    const res = await p.page.evaluate(async (proc) => {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: proc, noiseSuppression: proc, autoGainControl: proc } });
      const ctx = new AudioContext(); const src = ctx.createMediaStreamSource(stream); const an = ctx.createAnalyser(); an.fftSize = 512; src.connect(an);
      const buf = new Float32Array(512); let max = 0; const hist = [];
      const t0 = performance.now();
      while (performance.now() - t0 < 6000) { an.getFloatTimeDomainData(buf); let s = 0; for (const v of buf) s += v*v; const r = Math.sqrt(s/512); max = Math.max(max, r); if (r>0.01) hist.push(Math.round(performance.now()-t0)); await new Promise(r=>setTimeout(r,50)); }
      stream.getTracks().forEach(t=>t.stop()); ctx.close();
      return { proc, max, loudSamples: hist.length, first: hist.slice(0,3) };
    }, proc);
    console.log(JSON.stringify(res));
  }
} finally { await closeAll(); await browser.close(); }
