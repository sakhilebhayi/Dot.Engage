# R-39 Dot.Engage: findings, decisions and plan

Brief: `requirements/dot-engage-transformation.md`. Written 2026-11-07 after reading the whole repository at `a82bfa2`. The detailed security findings were kept off this public repository; this file records what was wrong in general terms and what was decided.

## What was found (by module)

| Module | Condition | Root cause |
|---|---|---|
| Signing | Functional but unsuitable | One signature image for the whole document, no fields or positions. "Fully signed" was a count of signatures against every team member who may create records plus every external signer, not a record of who must sign. No transaction or lock, so two signers finishing together could both trigger completion or neither. No idempotency. Any `data:image` string was stored under a `.png` name. The "signed" PDF was a summary page, not the agreed document, and carried no hash. Links were signed URLs: not revocable, not tied to an identity, replayable until expiry. |
| Video | Broken in practice | The only working path needed a paid third-party key that was not configured; without it the page showed a local camera preview and a sentence promising peer-to-peer video that did not exist. Outside parties could not join at all (team members only). The participant list showed every team member as present. No waiting room, no host controls, no reconnection. |
| Contracts | Partially implemented | Upload accepted Word files that could not be previewed or signed; no versions of what was agreed; no comments tied to a clause; no amendment process; no approvals; no readiness check. |
| Engagements | Missing | The product had contracts, chat and calls side by side, not a shared object that held them. |
| Notifications, audit | Partial | A tray existed; nothing recorded who did what with evidential value. |
| Design | Generic | Purple accent, Syne and Inter from a font CDN, a radial bloom behind the logo, glass panels, identical rounded cards, Tailwind from a CDN-era stylesheet. |
| Platform | Sound foundation | InfoDot sign-in, tenant scoping, CI and deploy pipeline, health probes, security headers. Kept. |

## Decisions

1. **The engagement is the root object.** Documents, people, discussion, notes, changes, approvals, meetings, signing requests and the audit trail all hang off it. Every existing contract became the first document of an engagement (backfill migration); old addresses redirect.
2. **State is derived, not set.** `EngagementStateMachine` works the 15 lifecycle states out from recorded facts and writes a `state_changed` audit event whenever it moves.
3. **Outside parties need no account.** An emailed link (token stored only as a hash) shows who invited them and nothing else; a one-time code sent to the address opens a session limited to that one engagement. Links can be revoked or replaced; codes lock after five tries.
4. **Permissions live in one class**, `EngagementAccess`, used by every controller. Roles give base abilities; approve, sign and host are capabilities added to a person. Team members get abilities from their Jetstream role.
5. **Documents reach outside parties only when shared.** Every version carries its SHA-256. A new version clears approvals and agreement. Once a signing request is sent the document is locked.
6. **Amendments never edit the document.** A proposal is reviewed by someone else; accepted proposals become a new version made of the previous PDF plus a generated schedule of agreed amendments (incremental PDF update).
7. **A PDF engine written for the job.** Signatures are drawn onto the original with an incremental update, so the original bytes are an exact prefix and their hash stays provable. It reads xref tables, xref streams and object streams, which is what Word and most generators produce. PDFs the engine cannot read are still accepted; their signatures go into the certificate only, and the uploader is told.
8. **Evidence.** Per completed request: a canonical JSON evidence record (document hash, every signer with identity method, consent, time, address and device, audit head, final file hash), sealed with Ed25519 by the platform, optionally timestamped by an RFC 3161 authority when one is configured, plus a certificate of completion appended to the signed PDF. The seal is the platform's own attestation, not a qualified signature, and says so everywhere it appears.
9. **Signing is atomic and repeatable.** Envelope and signer rows are locked, the client's completion key makes a repeat harmless, a different key after signing is refused, finalising runs under a lease and can be retried by the queue and by `engage:finalise-pending`.
10. **Audit trail.** Hash-chained per engagement, refused for UPDATE and DELETE by database triggers, verified nightly; the signing evidence includes the chain's head.
11. **Meetings are native WebRTC between browsers** (mesh, up to six), signalled by short polling over HTTP because the host is shared cPanel with no websocket server, daemon or SFU. Presence is a heartbeat; a browser that stops reporting is shown as lost. A TURN relay is configured by environment (`ENGAGE_TURN_*`); without one a minority of strict networks cannot connect, and the lobby says so. The Daily.co integration was removed: it could not be verified and was never configured.
12. **Design: "The Margin".** The contract sits in the middle, everything said about a clause hangs in the margin on a leader line. Light and dark. Self-hosted fonts, no CDN, no gradients or cards. See `DESIGN.md`.
13. **Dot.Files is not used for storage in this release.** Its integration secrets are held back until every platform is done; documents stay on this app's private disk with hashes, which is what the verification relies on. Recorded as an owner item.

## Phases and where they landed

All six phases are built and merged to `main`; see `R39_READINESS.md` for evidence, what was not done and what the owner has to supply.
