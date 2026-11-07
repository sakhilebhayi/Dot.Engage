// Does headless Chrome give us a real getDisplayMedia stream with the test flags? Prints the answer. Used to decide
// whether scenario (e) may use the real thing or must use the canvas fake.
import { launch, newPerson, login, createMeeting, openRoom, closeAll } from './lib.mjs';
const browser = await launch();
try {
  const p = await newPerson(browser, { name: 'x', kind: 'host' });
  await login(p);
  await p.page.goto('/login');
  const r = await p.page.evaluate(async () => {
    try {
      const s = await Promise.race([navigator.mediaDevices.getDisplayMedia({ video: true }), new Promise((_, rej) => setTimeout(() => rej(new Error('timeout 8s')), 8000))]);
      const t = s.getVideoTracks()[0];
      const out = { ok: true, label: t.label, settings: t.getSettings() };
      t.stop();
      return out;
    } catch (e) { return { ok: false, name: e.name, message: e.message }; }
  });
  console.log(JSON.stringify(r));
} finally { await closeAll(); await browser.close(); }
