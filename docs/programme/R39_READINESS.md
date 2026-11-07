# R-39 Dot.Engage: readiness report

Brief: `requirements/dot-engage-transformation.md`. Plan and decisions: `R39_PLAN.md`. Written 2026-11-07.

## What Dot.Engage is now

A business engagement platform. The engagement is the central object. Businesses meet, discuss, negotiate, approve and sign inside one record whose state (15 states) is derived from facts, never set by hand.

| Area | What was built |
|---|---|
| Engagements | Parties, internal and external participants, roles (owner, admin, manager, reviewer, participant, external) plus capabilities (approve, sign, host). One access class decides every permission server-side. |
| Outside parties | No account needed. Emailed token, then a one-time code (10 minutes, 5 tries), then a session limited to one engagement. InfoDot stays the identity gateway for people with accounts. |
| Documents | Every version carries a SHA-256. Sharing with outside parties is an explicit act. A document locks when a signing request is sent. |
| Negotiation | Notes anchored on the page, proposed changes, accept and reject (never your own), amendment schedule appended as a new version, approvals, readiness checklist. |
| Signing | Placed fields, sequential or parallel signers, drawn, typed or uploaded signatures, consent text hash, evidence JSON sealed with Ed25519, optional RFC 3161 timestamp, public hash check at `/verify`. Own PDF engine with incremental updates: the original bytes stay an exact prefix. |
| Meetings | Native WebRTC mesh (up to 6), waiting room, lock, remove, mute, end for everyone, screen share, camera and microphone lobby with permission handling, reconnect with ICE restart. No recording of any kind. |
| Audit | Hash-chained per engagement, database triggers refuse UPDATE and DELETE, verified nightly. Events carry the request IP and user agent. |
| Design | "The Margin": the contract in the middle, notes hanging in the margin on leader lines. Wax red is reserved for refusal and the seal. Light and dark, self-hosted fonts, enforced self-only CSP. |

The old contracts and video code is removed. Old routes redirect, and `/sign/{signer}` answers 410 with a plain "replaced" page. A backfill migration turns every existing contract into an engagement (rehearsed on a populated pre-R-39 database).

## Evidence

| Check | Result |
|---|---|
| PHPUnit | 460 tests, 8110 assertions, 8 skipped (environment), on PHP 8.5 locally and PHP 8.4 in CI |
| Own PDF engine | 110 tests, visual checks in pdf.js |
| Full business flow in a real browser (`tests/e2e/engagement`) | Join by code, note, proposed change, accept, revised version, approvals, prepare, send, three signing methods, completion, public verify, a one-byte tamper rejected, audit chain intact (46 events). ALL PASSED |
| Quality sweep (`qa.e2e.mjs`) | Every real page, light and dark, 390, 768 and 1440 wide: no console or CSP errors, no axe violations, no horizontal overflow. Clean |
| Meeting suite (`tests/e2e/meeting`) | 23 of 23 checks in real Chrome with a fake camera and microphone, media proven from `getStats()`. The `call` group (13 checks) was re-run after the last server change |
| Impeccable detector | Only the Livewire-injected glow style, a known false positive |
| CI | Quality gate green (frontend build, PHP tests, Pint) |

Security tests cover cross-engagement access, the outside-party session boundary, forged and replayed tokens, code brute force, document download without share, signing by the wrong person, a second signing attempt, host-only meeting commands and audit tampering.

## Not verified, and why

- **TURN relay.** No TURN server exists in the dev setup, so relay behaviour was never exercised. Two people behind strict firewalls may fail to connect until `ENGAGE_TURN_*` is set.
- **Browsers.** Meetings were tested in Chrome only. Safari and Firefox are untested. Phone layouts were tested with Chrome emulation, not real phones.
- **Real network loss.** Playwright can cut HTTP, not UDP media. ICE restart was driven by a simulated state on a real connection.
- **Mesh size.** Six people is the limit. Uplink load is heavy and bitrate is capped by participant count.
- **Daily.co replacement.** The old integration is gone. Nothing in the live environment was compared against it.
- **Legal status of signatures.** The platform provides a basic electronic signature with evidence. It is not an advanced or qualified signature and does not claim to be. The wording on `/how-signing-works`, the terms, policy and cookies pages was rewritten for this product and needs a lawyer.
- **Timestamping.** No timestamp authority is configured. Without `ENGAGE_TSA_URL` the evidence has no third-party time proof, and the certificate says so.
- **Dot.Files.** Not used for storage here. Documents stay in this app's own storage.
- **Accredited signing provider.** None is integrated. No fake integration is shown.

## Owner list

1. Choose and set a TURN service (`ENGAGE_TURN_URLS` with either `ENGAGE_TURN_SECRET` or a fixed username and credential).
2. Choose a timestamp authority and set `ENGAGE_TSA_URL`, if third-party time proof is wanted.
3. Legal review of the signing explanation, terms, policy and cookies pages, and of the 7 year retention default (`ENGAGE_RETENTION_YEARS`).
4. Decide whether to set `ENGAGE_SEAL_SEED`. Without it the seal key derives from `APP_KEY`; rotating `APP_KEY` would stop old seals verifying with the new key.
5. Host facts: cron present for `schedule:run`, disk quota for stored documents, SMTP for `no-reply` mail.
6. Dot.Files secrets, only if storage is later moved there.

## Post-deploy checks

1. `/up`, `/`, `/how-signing-works`, `/verify`, `/login` answer 200; a font and a pdf.js font answer 200.
2. Response headers: enforced CSP, Permissions-Policy allowing camera, microphone and display capture for self.
3. Migrations ran, including the backfill: every old contract is an engagement, versions shifted by one, original is v1.
4. `php artisan engage:verify-audit` reports an intact chain.
5. The scheduler runs `engage:housekeeping`, `engage:finalise-pending`, `engage:meetings-clean`.
6. Old URLs: `/contracts` and `/video` redirect, `/sign/{signer}` answers 410.
7. Sign in with a real account and open one existing engagement.
