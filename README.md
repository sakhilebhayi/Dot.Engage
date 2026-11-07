<div align="center">

<img src="public/images/dot_engage.png" alt="Dot.Engage" width="220" />

<br /><br />

**Meet. Agree. Sign. Move business forward.**

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![SQLite](https://img.shields.io/badge/SQLite-production-003B57?style=flat-square&logo=sqlite&logoColor=white)

Part of the [InfoDot ecosystem](https://github.com/sakhileb/InfoDot) · `engage.infodot.co.za`

</div>

---

## What it is

Dot.Engage is where two or more businesses reach an agreement: invite the other party, talk the contract through (in the discussion or in a meeting with the document on screen), change it in a controlled way, approve it, and sign it, in one place. The central object is the **engagement**. Its state is worked out from what has actually happened, never typed in.

**Engage → Discuss → Negotiate → Approve → Sign → Complete**

| | |
|---|---|
| **Engagements** | Reference, parties, people, documents, discussion, notes, proposed changes, approvals, meetings, signing and an append-only history, in one workspace. 15 lifecycle states derived by `EngagementStateMachine`. |
| **Outside parties** | No account. An emailed link shows who invited them; a one-time code to the same address opens a session limited to that engagement. Links can be revoked or replaced. |
| **Negotiation** | Notes anchored to a rectangle on a page, with the quoted text. Proposed changes (replace, add, remove) reviewed by someone other than the author; accepted ones become a new version made of the previous PDF plus a schedule of agreed amendments. Version compare. Approvals per version. A readiness checklist gates signing. |
| **Signing** | Fields placed on the page (signature, initials, date, text, tick box), sequential or parallel, drawn/typed/uploaded signatures, consent, reminders, reassignment, decline, cancel, expiry. Atomic and idempotent. The finished file is the original with signatures drawn on and a certificate appended, an evidence record sealed by the platform, and a public check page. |
| **Meetings** | WebRTC between browsers (up to six) with a waiting room, host controls, device selection, screen sharing, reconnection and honest presence. The agreement and the signing sheet open beside the call. Signalled over HTTP polling: no websocket server, daemon or SFU is needed. |
| **Evidence** | SHA-256 of every version; hash-chained audit log refused for UPDATE/DELETE by database triggers; Ed25519 seal over the evidence record; optional RFC 3161 timestamp; verification at `/verify`. |

What it does **not** do: it provides a *basic* electronic signature (email-confirmed identity). It is not an advanced or qualified signature and says so. No meeting is recorded. Dot.Files is not used for storage in this release. See `/how-signing-works` and `docs/programme/R39_READINESS.md`.

## Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13, PHP 8.4, Jetstream teams, InfoDot sign-in (`/auth/ecosystem`) |
| Interface | Blade, Livewire 3 (chat, notifications, account pages), vanilla JS modules built by Vite for the document viewer, signing and meetings, pdf.js 6 |
| Design | "The Margin", see [`DESIGN.md`](DESIGN.md). Self-hosted Public Sans, Literata, IBM Plex Mono, Caveat. No CDN. |
| PDF | `app/Services/Pdf`: a reader and incremental writer for xref tables, xref streams and object streams. No external PDF library or binary. DomPDF generates the certificate and schedules. |
| Database | SQLite in production (WAL, immediate transactions); MySQL works. |
| Queue and schedule | Database queue, cron every minute (`schedule:run`, `queue:work`). `engage:housekeeping`, `engage:finalise-pending`, `engage:meetings-clean`, `engage:verify-audit`. |
| Security | Enforced CSP (self only), per-route throttle keys, server-side `EngagementAccess` on every endpoint, private disks, content-sniffed uploads. |

## Layout of the code

- `app/Services/Engage/` the domain: `EngagementService`, `EngagementStateMachine`, `EngagementAccess`, `ParticipantAccess`, `DocumentService`, `NegotiationService`, `AmendmentSchedule`, `SigningService`, `SigningReadiness`, `EnvelopeFinaliser`, `EvidenceSeal`, `Rfc3161Client`, `MeetingService`, `IceConfig`, `AuditTrail`.
- `app/Http/Controllers/Engage/` thin controllers; `routes/engage.php`.
- `resources/js/viewer`, `resources/js/signing`, `resources/js/meeting` the browser modules.
- `docs/programme/` the brief, the plan and decisions (`R39_PLAN.md`), the readiness report and the owner's list (`R39_READINESS.md`).

## Run it

```bash
cp .env.example .env && composer install && npm install && npm run build
php artisan key:generate && touch database/database.sqlite && php artisan migrate
php artisan db:seed --class=EngageDemoSeeder   # local only: owner@demo.test / password, one engagement with a document
php artisan serve
```

Tests: `php artisan test` (PHPUnit, SQLite in memory). Browser end-to-end tests live in `tests/e2e/` and are described in the README files next to them; they need Playwright and a running copy of the app.

Configuration is in `config/engage.php` and `.env.example` (invitation lifetime, retention years, signing deadlines, timestamp authority, seal key, TURN relay for meetings).

## Deploying

Manual only: `gh workflow run deploy.yml -f confirm=deploy`. It gates on CI, builds the frontend on the runner, backs up the host, runs migrations (including the one that turns every existing contract into an engagement), mirrors fonts and reader files into the docroot, installs the cron lines and smoke-checks the live site. See [`docs/deployment-cpanel.md`](docs/deployment-cpanel.md).

## License

MIT
