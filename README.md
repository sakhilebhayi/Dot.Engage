<div align="center">

<img src="public/images/dot_engage.png" alt="Dot.Engage" width="220" />

<br /><br />

**Share contracts, chat, and get them signed — live on a call or async.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-infodot-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `engage.infodot.app`

</div>

---

## What is Dot.Engage?

Dot.Engage is a business contract-sharing, real-time chat, and video-call document-signing platform in the InfoDot ecosystem. A team uploads a contract, shares it with colleagues or a client, discusses it over real-time chat, and gets it signed — either from a standalone signature pad or live during a video call, with the signature captured the moment it happens.

## Core Features

- Contract upload, versioning, and team-scoped sharing
- Canvas-based e-signature capture (`signature_pad`), stored on a private disk and streamed back only to authorized viewers
- Real-time team chat via Laravel Reverb, with unread badges and file attachments
- Video calls (Daily.co, with a Reverb-signalling fallback when no Daily.co key is configured) with in-call document viewing and in-call signing
- Signed-contract PDF generation and email delivery
- Real-time notifications (contract shared/signed, message received, video session invite)
- Ecosystem SSO from the InfoDot hub via a single-use `PersonalAccessToken` handoff

## Domain Models

- **Contract** — uploaded document with status (`draft`/`pending`/`signed`), versioning, and expiry
- **ContractSignature** / **VideoSessionSignature** — a captured e-signature, either standalone or during a live call
- **Conversation** / **Message** — team-scoped real-time chat, 1:1 or group
- **VideoSession** — a call, optionally linked to a contract for live signing

There is no Client/Proposal/Session-scheduling/CRM layer in this repository — an earlier draft of this README described that as the product; it did not match the actual code and has been corrected here. See [`wiki.md`](wiki.md) for the full, code-verified breakdown.

## Tech Stack

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 13, PHP 8.3+ | `composer.json` requires `^8.3` |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS | |
| Database | PostgreSQL, `DB_DATABASE=infodot` | Shared instance across the ecosystem |
| Realtime | Laravel Reverb | Wired to real broadcast events (contract signed, message sent, video session started/ended, in-call signature requests) |
| Video | Daily.co (`@daily-co/daily-js`), Reverb-signalling fallback | Degrades gracefully with no `DAILY_API_KEY` configured |
| Documents | Spatie MediaLibrary, `barryvdh/laravel-dompdf` | Signed-contract PDF generation |
| Auth | Laravel Sanctum (InfoDot SSO) | |
| Queue | Database driver (`QUEUE_CONNECTION=database`) | |
| Storage | Local/Flysystem, private disks for contracts/signatures/attachments | AWS credentials are present in `.env.example` but S3 is not the configured default disk |

No AI integration, no search service (Scout/Meilisearch), and no Redis/Horizon queue exist in this repository — all three appeared in a previous draft of this README without corresponding code and have been removed.

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Engage.git
cd Dot.Engage
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance (`DB_DATABASE=infodot`) and `APP_URL=https://engage.infodot.app`. Users authenticated through InfoDot gain access automatically via a one-time Sanctum token handoff at `/auth/ecosystem`.

## Ecosystem

**Dot.Engage** is part of the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map, and see [`wiki.md`](wiki.md) for this platform's own knowledge base, including a security-scan and ethical-engagement writeup.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
