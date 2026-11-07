---
title: Dot.Engage — Platform Wiki
version: 1.0.0
status: current
owners: [Engage Platform Lead]
platform-id: dot-engage
last-review: 2026-11-07
---

# Dot.Engage

This is Dot.Engage's own knowledge home: what the platform is as implemented, and how it connects to the Dot Ecosystem. Dot.Brain reads it and never edits it. The earlier history of this file (contract list, chat, Daily.co calls, 2026-08) is in git; it described a product that no longer exists.

> Dot.Brain's ingested view: https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-engage.md

## 1. What it is

Dot.Engage is where two or more businesses reach an agreement: **Engage → Discuss → Negotiate → Approve → Sign → Complete**. The central object is the engagement. People outside the owning business take part without an account (emailed link plus one-time code). Meetings, notes, proposed changes, approvals and signing all happen inside the engagement, and an append-only history records them.

It is a Laravel 13 / Jetstream / Livewire application on SQLite with a database queue and cron, sign-in through InfoDot. The design system is "The Margin" (`DESIGN.md`). The decisions and what was found in the old code are in `docs/programme/R39_PLAN.md`; what is verified and what is not is in `docs/programme/R39_READINESS.md`.

## 2. Domain entities

| Entity | Meaning |
|---|---|
| Engagement | Reference `ENG-YYYY-NNNNN`, title, kind, owner, derived state, expiry, agreed version |
| EngagementParty / EngagementParticipant | The businesses and the people (role plus approve/sign/host capabilities; outside people hold a hashed link token and a hashed one-time code) |
| Contract / ContractVersion | A document and every file it ever had, each with a SHA-256; the first document of any pre-existing contract |
| DocumentComment / Amendment / EngagementApproval | Notes on a page region, proposed text changes, approvals per version |
| SigningEnvelope / SigningSigner / SigningField | A signing request, who signs in what order, where; the evidence record, seal and final files live on the envelope |
| Meeting / MeetingAttendee / MeetingSignal | A meeting, who is in the room (heartbeat presence), and short-lived connection messages |
| EngagementAuditEvent | Hash-chained, append-only, refused for UPDATE and DELETE by database triggers |
| Conversation / Message | Team chat (unchanged, internal) |

Legacy tables `contract_signatures` and `contract_external_signers` hold signatures made before evidence records existed; they are read, never written.

## 3. Events and notifications

Every action writes an `engagement.*`, `participant.*`, `document.*`, `comment.*`, `amendment.*`, `approval.*`, `meeting.*` or `signing.*` audit event with actor, time, subject and (for team members and outside people) network address and browser. People are told by one `EngagementNotice` (mail, plus the in-app tray for team members). Mail names the engagement and what happened and never carries clause text.

## 4. Security posture

Permissions are decided server-side by `EngagementAccess` (roles plus capabilities, outside parties limited to one engagement and to documents shared with them). Invitation tokens are stored as hashes and can be revoked; codes lock after five tries. Signing is transactional and idempotent; the signed file starts with the exact agreed bytes. Content Security Policy is enforced (self only; kill switch `SECURITY_CSP_REPORT_ONLY`). Throttle keys are per route. Uploads are content-sniffed and kept on private disks. A business with signed agreements cannot be deleted inside the retention period.

## 5. Ethical engagement check

The platform optimises for finishing an agreement correctly, not for time on site: no feeds, no streaks, no engagement metrics shown to users, notifications limited to what needs action (chatty ones are throttled). Meetings are not recorded and nothing is transcribed. Outside parties are told in plain words what is stored about them.

## 6. Connecting to Dot.Brain

Registered as `dot-engage`. Natural aggregates if a Knowledge Pack is ever published: time from invitation to signature, share of engagements completed, reasons for decline (categories only). Contract content, names and messages never leave this application. No publishing code exists yet.

## 7. Open items

See `docs/programme/R39_READINESS.md` (owner items: TURN relay for meetings, timestamp authority choice, legal review of the signing wording, Dot.Files integration secrets, accredited signing provider for documents that need more than a basic signature).

## Change log

| Version | Date | Change |
|---|---|---|
| 1.0.0 | 2026-11-07 | Rewritten after the R-39 transformation: engagements, controlled negotiation, evidence-backed signing, native meetings, new design system. |
