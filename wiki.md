---
title: Dot.Engage — Platform Wiki
version: 0.1.0
status: draft
owners: [Engage Platform Lead]
platform-id: dot-engage
last-review: 2026-08-02
---

# Dot.Engage

Purpose: this is Dot.Engage's own knowledge home — owned and maintained by the Dot.Engage team. It describes what this platform actually is, as implemented, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-engage.md)

---

## 1. What Dot.Engage Is

Dot.Engage is a business contract-sharing, real-time chat, and video-call document-signing platform for the InfoDot ecosystem. Teams upload contracts, share them with team members, negotiate and confirm terms over real-time chat or a live video call, and capture legally-relevant e-signatures either from a standalone signature pad or directly inside an active call. It is a Laravel 13 / Jetstream 5 / Livewire 3 team-scoped application — not a CRM, not a scheduling tool, and not an AI product, despite what an earlier draft README claimed.

**Note on the platform name and ecosystem registry entry:** Dot.Brain's platform registry describes Dot.Engage with a `campaign` icon, suggesting a marketing/engagement-campaign platform. That description does not match the actual codebase. Every model, migration, controller, policy, event, job, and test in this repository is about **contracts, e-signatures, chat, and video calls** — a client-facing document workflow tool, closer in spirit to a lightweight DocuSign + Slack + Zoom for service-business/team-to-client agreements. This wiki describes the platform as it is actually built; the registry entry and icon should be corrected to match (flagged in §8).

**Status:** this is a substantially built application — 9 domain models, 6 policies, 18 Livewire components, 7 broadcast events with listeners, 5 notifications, 4 queued jobs, 3 mailables, 3 artisan commands, and 20+ feature/unit tests already exist. The core loop (upload contract → share → sign, either async or live on a video call) is implemented end-to-end. Treat §8 (Roadmap) as what's still ahead; everything else in this document reflects what is actually in the repo today, verified by reading the code — not by trusting `tasklist.md` or the previous README, both of which were checked against the code as part of this pass (see §9).

## 2. Architecture

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 13, PHP 8.3+ (`composer.json` requires `^8.3`) | Jetstream 5.5 + Fortify for auth/teams |
| UI | Livewire 3.6, Alpine.js 3 (CDN), Tailwind (CDN in `layouts/app.blade.php`, Vite-built `resources/css/app.css` elsewhere) | Server-rendered; two coexisting layout styles — see §8 |
| Database | PostgreSQL (`config/database.php` default `pgsql`), `DB_DATABASE=infodot` in `.env.example` | Shared instance across the InfoDot ecosystem — confirmed matching convention |
| Auth | Laravel Sanctum + `App\Http\Controllers\Auth\EcosystemAuthController` | SSO handoff from the InfoDot hub at `/auth/ecosystem` — verified against the same contract used elsewhere in the ecosystem (single-use `PersonalAccessToken` with the `ecosystem:read` ability, deleted after use, expiry checked, `Auth::login()` then redirect to `dashboard`) |
| Realtime | Laravel Reverb 1.10 | Wired to real broadcast events (see §5) — this is further along than most ecosystem platforms, which have Reverb configured but unused |
| Video | Daily.co (`@daily-co/daily-js`, `App\Services\DailyCoService`) with a Reverb-token fallback (`VideoTokenController`) | `DailyCoService::isConfigured()` returns false with an empty `DAILY_API_KEY`, and the UI/controllers degrade gracefully to the Reverb-only signalling path |
| Documents | Spatie MediaLibrary, `barryvdh/laravel-dompdf` | PDF generation for signed contracts (`GenerateSignedContractPdf` job, `resources/views/pdf/signed-contract.blade.php`) |
| E-signatures | `signature_pad` (canvas, client-side) + `App\Http\Controllers\Api\SignatureController` / `App\Livewire\Contracts\SignaturePad` / `App\Livewire\Video\InCallSignaturePad` | Base64 PNG canvas data decoded server-side and stored on a private `signatures` disk |
| Queue | Database driver (`.env.example`: `QUEUE_CONNECTION=database`) | README previously claimed Redis/Horizon — not present in `composer.json` |
| Search | None | README previously claimed Scout/Meilisearch — no such dependency exists |
| AI | None | README previously claimed "Anthropic Claude (`claude-sonnet-4-6`)" — no AI service, no `services.anthropic` config key, no Anthropic-related code anywhere in the repo |

Team/user scoping runs through Jetstream's `Team` model (multi-tenant by team). `Contract`, `Conversation`, and `VideoSession` all carry `team_id`; `Message` and signatures are scoped transitively through their parent `Conversation`/`Contract`/`VideoSession`.

## 3. Domain Entities (as implemented)

Source: `database/migrations/2026_04_15_11053*` and `app/Models/`.

| Model | Table | Purpose |
|---|---|---|
| `Contract` | `contracts` | Uploaded document — title, description, private `file_path`, status (`draft`/`pending`/`signed`), expiry, soft-deletable |
| `ContractSignature` | `contract_signatures` | One team member's signature on a contract — image path, IP, signed timestamp |
| `ContractVersion` | `contract_versions` | Version history entry for a re-uploaded contract file |
| `Conversation` | `conversations` | Chat thread — 1:1 or group, team-scoped, soft-deletable |
| `ConversationParticipant` | `conversation_participants` | Pivot — user's membership + `last_read_at` per conversation |
| `Message` | `messages` | Chat message — text or file, optionally linked to a `Contract`, soft-deletable |
| `MessageAttachment` | `message_attachments` | File attached to a message |
| `VideoSession` | `video_sessions` | A call — UUID `room_id`, status (`waiting`/`active`/`ended`), optionally linked to a `Contract` for in-call signing |
| `VideoSessionSignature` | `video_session_signatures` | A signature captured live during a video call |

`Team`, `TeamInvitation`, `Membership`, `User` are the standard Jetstream Teams models, unmodified beyond the usual `dispatchesEvents`/fillable customization.

## 4. Events Emitted

Unlike most ecosystem platforms at this stage, these are **real, wired events** — each implements `ShouldBroadcast`, has a defined private channel, and is actually dispatched from application code (not just declared).

| Event | Channel | Triggered from | Listener |
|---|---|---|---|
| `MessageSent` | `conversation.{conversationId}` | `MessageComposer::send()` | `SendMessageNotification` |
| `ContractShared` | `user.{sharedWith.id}` | **Never dispatched.** `ShareModal::share()` sends `ContractSharedNotification` directly via `Notification::send()` — the `ContractShared` event/listener pair exists but nothing in `app/` calls `ContractShared::dispatch()` — flagged in §8 | `NotifyContractShared` (registered but effectively dead code) |
| `ContractSigned` | `team.{contract.team_id}` | `SignaturePad::saveSignature()`, `Api\SignatureController` — dispatched once all team members have signed | `NotifyContractSigned` |
| `VideoSessionStarted` | `team.{session.team_id}` | Declared with a listener (`LogVideoSession`) but **not found dispatched anywhere in `app/Livewire` or `app/Http`** — flagged in §8 | — |
| `VideoSessionEnded` | `team.{session.team_id}` | `SessionRoom::endSession()` | `LogVideoSession` |
| `SignatureRequestedDuringCall` | `team.{session.team_id}` | `InCallSignaturePad::sign()` | — (no listener registered; notification exists but isn't wired to this event — see §8) |

`routes/channels.php` should be consulted for the private-channel authorization callbacks backing these broadcasts (not modified in this pass, per the "don't touch Jetstream/teams core" boundary — but this file is channel authorization, not team/auth structure, and is worth a follow-up read).

## 5. Security Posture — This Pass

A bounded IDOR/authorization scan (per [Dot.Brain 02-Engineering-Loop.md](../Dot.Brain/os/02-Engineering-Loop.md) §5.6) found and fixed the following in this repo. All policies (`ContractPolicy`, `ConversationPolicy`, `MessagePolicy`, `VideoSessionPolicy`, `TeamPolicy`) were already correctly team-scoped and consistently applied in the majority of controllers/Livewire components — the gaps were narrow and are listed below with what changed:

1. **Dashboard cross-tenant data leak (`routes/web.php`).** The most recent commit before this pass (`5dae85f`, "fix: update routes/web.php imports and dashboard query") added dashboard stats and recent-activity queries with **no team scoping** — `Contract::count()`, `Conversation::whereNotNull(...)->count()`, etc. queried *every team's* data and displayed it on *every* team's dashboard. This was a real, live cross-tenant information disclosure, not a hypothetical — every team using the app would have seen aggregate counts and recent contracts/conversations belonging to other teams. Fixed by scoping every dashboard query to `Auth::user()->currentTeam->id`.
2. **`App\Livewire\Contracts\VersionHistory`** — looked up `ContractVersion` records by `contract_id` with no `mount()` and no policy check at all. Added a `mount()` that authorizes `view` on the parent `Contract` before rendering, matching the pattern already used by `ContractViewer`.
3. **`App\Livewire\Video\InCallDocumentViewer`** — rendered a `Contract` (including its signatures) by ID with no authorization check. Added the same `mount()` + `authorize('view', ...)` pattern.
4. **`App\Livewire\Video\ParticipantList`** — listed all team members of a `VideoSession` by ID with no authorization check. Added `mount()` + `authorize('view', ...)`.

All four fixes reuse the exact `$this->authorize(...)` + policy pattern already established elsewhere in this codebase (e.g. `ContractViewer`, `SessionRoom`) — no new authorization pattern was introduced. `ContractPdfController`, `SignatureController`, `VideoTokenController`, and `VideoJoinUrlController` (the API layer) were already correctly gated with `Gate::authorize(...)` and needed no changes.

**Not fixed, flagged for follow-up:** `routes/web.php`'s page-view routes (`/contracts/{contract}`, `/chat/{conversation}`, `/video/{room}`) resolve the model via route-model-binding with no `Gate::authorize` call of their own — but every one of them renders a Blade view whose embedded Livewire component (`ContractViewer`, `ConversationThread`, `SessionRoom`) performs the real authorization check in `mount()`. This is a workable pattern (Livewire 3 signs/validates component state server-side between requests) but relies on every current and future page route continuing to delegate authorization to its embedded component — a single new page-view route or Livewire component that skips this convention would silently reopen the same class of gap found in items 2–4 above. Recommend either a route-level `Gate::authorize` as defense-in-depth, or a project convention doc making the "authorize in `mount()`" rule explicit for all new Livewire components.

## 6. Ethical Engagement Check

Manifesto principle 3 states: never optimize for addiction or screen time. Given this platform is literally named "Dot.Engage," this check matters more here than almost anywhere else in the ecosystem.

**Result: clean.** A dedicated scan (`grep` across `app/` and `resources/` for streak/badge/gamification/points/reward/leaderboard/login-bonus patterns) found no dark-pattern engagement mechanics. What exists instead:

- `UnreadBadge` — a plain unread-message counter, the same pattern used for email inboxes everywhere; not a gamified streak or variable-reward mechanic.
- `NotificationBell` / `NotificationTray` — standard database-notification UI, no artificial urgency, no manufactured scarcity.
- No points, XP, levels, streaks, daily-login rewards, leaderboards, or infinite-scroll feeds anywhere in the codebase.

The platform's actual "engagement" is a business-workflow loop (contract → share → discuss → sign), not an attention-capture loop. The name "Dot.Engage" appears to be a marketing/ecosystem-branding artifact rather than a description of dark-pattern mechanics — worth flagging to whoever owns platform naming (§8), since it invites exactly the wrong inference about what this platform optimizes for.

## 7. Connecting to Dot.Brain

Dot.Engage is registered in Dot.Brain's platform map as `dot-engage`. Dot.Brain's ingested view of this platform is maintained at [`platforms/dot-engage.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-engage.md) — that document is Dot.Brain's proposed target-state framing (entities, events, cross-platform relationships) and this wiki is the ground truth of what actually ships. As noted in §1, Dot.Brain's registry currently frames this platform with a `campaign` icon suggesting marketing/engagement-campaign functionality; the real domain (contracts, e-signatures, chat, video) should be reconciled into that ingested view.

This repository has no Knowledge Pack publishing code yet. If/when it exists, natural payload candidates given the real domain are:

| Payload type | Would contain |
|---|---|
| `observation` | Aggregated contract-signing throughput, average time-to-sign, chat/video engagement volume — never individual contract content or message bodies |
| `insight` | Patterns in how often video calls convert to same-session signing vs. async signing |
| `outcome` | Whether a Dot.Brain recommendation (e.g., "nudge pending signers") measurably reduced time-to-sign |
| `incident` | Signature/PDF-generation failures, Reverb/Daily.co outages |

Given that this platform stores signature images and contract documents, any aggregation published outward should default to at least as strict an anonymity floor as Dot.Billing proposes for money-movement data (n≥50), and contract/message content itself should never leave this repository's boundary. No aggregation or publishing code exists yet, so this is a design requirement, not an enforced behavior.

## 8. Roadmap / Open Questions

- [ ] Reconcile Dot.Brain's `campaign` icon / marketing-platform framing for `dot-engage` with the platform's actual contract/chat/video-signing domain (§1, §7)
- [ ] `VideoSessionStarted` event is declared and has a listener (`LogVideoSession`) but is never dispatched anywhere in the codebase — either wire it up (likely in `SessionLauncher::create()` / `SessionRoom::mount()`) or remove the dead listener registration
- [ ] `ContractShared` event is declared with a listener (`NotifyContractShared`) but is never dispatched — `ShareModal::share()` sends `ContractSharedNotification` directly instead, bypassing the event entirely. Either dispatch the event from `ShareModal` (letting `NotifyContractShared` own the notification-sending) or remove the unused event/listener pair — right now both paths exist and only one runs
- [ ] `SignatureRequestedDuringCall` is dispatched from `InCallSignaturePad::sign()` but has no registered listener, even though `SignatureRequestedNotification` exists — wire the notification to the event
- [ ] Two coexisting Blade layout systems: `layouts/app.blade.php` (Tailwind via CDN `<script>`, custom design-system CSS) and `layouts/guest.blade.php` (Vite-built `resources/css/app.css`, Jetstream defaults) — worth converging on one build pipeline
- [ ] `routes/channels.php` authorization callbacks for the four private channels used by real-time broadcasts were not audited in this pass — recommended follow-up given how much of this platform depends on Reverb channel authorization matching the same team/participant rules enforced elsewhere
- [ ] Defense-in-depth: consider adding `Gate::authorize` at the route level for `/contracts/{contract}`, `/chat/{conversation}`, `/video/{room}` rather than relying solely on the embedded Livewire component's `mount()` (see §5)
- [ ] README's claims of Redis/Horizon queueing, Laravel Scout/Meilisearch search, and an Anthropic Claude AI integration were all found to be aspirational/fabricated relative to `composer.json` and `config/services.php` — corrected in this pass (see repo README)
- [ ] `tasklist.md` at the repo root is a build log, not living documentation — every item is checked off but it describes a CRM-adjacent framing ("client relationship and engagement platform") in its README companion that doesn't match its own body text ("contract-sharing, real-time chat, and video-call document-signing platform," which is accurate); consider retiring `tasklist.md` or clearly marking it historical

## 9. Verification Notes (this pass)

- SSO contract (`EcosystemAuthController`) read in full and matches the token-based, single-use, ability-gated (`ecosystem:read`) handoff pattern used elsewhere in the ecosystem.
- `DB_DATABASE=infodot` confirmed in `.env.example`, matching the shared-database convention.
- Git log's `5dae85f` "fix: update routes/web.php imports and dashboard query" was verified against the actual diff — it added dashboard queries but did **not** scope them to the current team, so despite the "fix" label the commit shipped a cross-tenant data leak, now corrected (§5.1).
- `dot_engage.png` was already wired into `application-logo.blade.php` and `authentication-card-logo.blade.php` (auth pages), but had no favicon. Generated `apple-touch-icon.png` (180px), `favicon-32x32.png`, `favicon-16x16.png` via `sips` and added `<link>` tags to both `layouts/app.blade.php` and `layouts/guest.blade.php`, matching the exact convention already used in Dot.Billing.
- This pass was written and reviewed, not executed — no PHP/Composer/PostgreSQL/Docker available in this environment. See [Dot.Brain 02-Engineering-Loop.md](../Dot.Brain/os/02-Engineering-Loop.md) §2.

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.1.0 | 2026-08-02 | Engage Platform Lead | Initial platform-owned wiki. Verified SSO contract and DB naming, fixed a real cross-tenant dashboard data leak left by the prior "fix" commit, added missing Policy-based authorization to three Livewire components (`VersionHistory`, `InCallDocumentViewer`, `ParticipantList`), wired the real logo into favicons across both layouts, ran the ethical-engagement check (clean), and corrected the README's fabricated tech-stack claims (AI, search, queue). |

## Open Questions
- Should `dot_engage.png` (2362×2362 source) be re-exported as an optimized web asset (currently ~195KB served as-is for the nav/auth logo `<img>` tags with only CSS `h-16`/`h-20` sizing, no `srcset`)? Not addressed this pass — cosmetic/perf, not correctness.
- Who owns reconciling the Dot.Brain registry's `campaign` icon with this platform's actual domain — Dot.Engage's own team (via this wiki) or a Dot.Brain registry correction? Per Manifesto principle 4, Dot.Brain proposes and platforms decide, but the icon mismatch originates on the Dot.Brain side, not here.
