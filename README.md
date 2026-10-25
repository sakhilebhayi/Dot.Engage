<div align="center">

<img src="public/images/dot_engage.png" alt="Dot.Engage" width="220" />

<br /><br />

**Share contracts, chat, and get them signed — live on a call or async.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![SQLite](https://img.shields.io/badge/SQLite-production-003B57?style=flat-square&logo=sqlite&logoColor=white)

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
| Framework | Laravel 13, PHP 8.4 | `composer.json` requires `^8.4`, platform pinned to 8.4.1 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS 4 (Vite) | Two CSS entries, one for the guest pages and one for the signed-in app; Alpine is the copy bundled with Livewire. See "Tailwind CSS 4" below |
| Database | SQLite (`DB_CONNECTION=sqlite`) | One file, `database/database.sqlite`, on the cPanel host. WAL, 5 s busy timeout and `IMMEDIATE` transactions are set in `config/database.php`. PostgreSQL is not used in production |
| Realtime | Laravel Reverb, **off in production** | Production has no websocket server (`BROADCAST_CONNECTION=null`): broadcast events are discarded, the browser opens no websocket, and chat polls every 5 s instead. Live push needs a Reverb server, `BROADCAST_CONNECTION=reverb`, the `REVERB_*` values and a rebuild |
| Video | Daily.co (`@daily-co/daily-js`), Reverb-signalling fallback | Degrades gracefully with no `DAILY_API_KEY` configured |
| Documents | Spatie MediaLibrary, `barryvdh/laravel-dompdf` | Signed-contract PDF generation |
| Auth | Laravel Sanctum (InfoDot SSO) | |
| Queue | Database driver (`QUEUE_CONNECTION=database`) | |
| Storage | Local/Flysystem, private disks for contracts/signatures/attachments | AWS credentials are present in `.env.example` but S3 is not the configured default disk |

No AI integration, no search service (Scout/Meilisearch), and no Redis/Horizon queue exist in this repository — all three appeared in a previous draft of this README without corresponding code and have been removed.

## Tailwind CSS 4

Tailwind is v4, built by `@tailwindcss/vite` (`vite.config.js`). There is no `tailwind.config.js`, `postcss.config.js` or autoprefixer: everything that used to be config lives in `resources/css/app.css`.

- **Two entries, because the two sets of pages were built differently.** `resources/css/app.css` is for the guest pages: `layouts/guest.blade.php` (login, register, password reset, terms, privacy, cookies, external signing), `welcome.blade.php` and the `errors/*` pages. `resources/css/signed-in.css` is for the signed-in app (`layouts/app.blade.php`), which used to load the Tailwind Play CDN script (v3) and a second Alpine from unpkg at run time and now loads the built stylesheet like everything else. Both come from the same `npm run build`, which the deploy runs on the runner. The signed-contract PDF (`pdf/signed-contract.blade.php`, dompdf) and the mails (Markdown mail with `vendor/mail/html/themes/default.css`) are self-contained and never load either.
- **Why the signed-in entry is not `app.css`.** The CDN pages had Tailwind's preflight OFF and no forms or typography plugin, and their own `<style>` block is the reset. `signed-in.css` therefore imports only the theme and the utilities (no preflight, no plugins), keeps the utilities out of a cascade layer and is linked after the layout's inline `<style>` (the order the CDN's late-appended `<style>` produced), restores v3's `space-*` margins (the browser's own margins on `<h3>`/`<p>` still count without preflight), and defaults `--tw-border-style` to each element's browser default (v3 without preflight never set a border style, so `border` drew nothing on a `<div>`). Read the header of the file before changing it.
- **Livewire ships Alpine.** `@livewireScripts` loads Alpine (with its plugins) and starts it; never add another copy. The CDN layout did, and every Livewire response then failed in `morph` (`window.Alpine.cloneNode is not a function`), so the page only changed after a reload. `welcome.blade.php` has no Livewire and still loads Alpine 3.10.2 from unpkg.
- `app.css`: `@import 'tailwindcss' source(none)` plus four `@source` lines (the old `content` globs, nothing wider: automatic detection would scan `app/`, `docs/` and `tests/`), `@plugin` for forms and typography.
- **Every v4 default that moved a pixel is put back**, each with a comment in `app.css`: border colour (gray-200, not `currentColor`), pointer cursor on buttons, placeholder colour, `hover:` on touch screens, `ring` (3px blue-500/50), the `shadow`/`rounded`/`blur` scale renames, the transparent `outline-none`, the v3 `transition` property lists, the browser's own padding on `th`/`td`/`option`/the file button, `ltr:`/`rtl:` needing an explicit `dir`, the v3 `font-sans` (Figtree first) and `font-mono` stacks (declared `@theme inline`, because the pages define their own `--font-mono`), the v3 palette hex values (`tailwind-v3-palette.css`; v4 moved to oklch) and the v3 colours of `prose`. The rule of the migration is NO visual change: fix a difference in `app.css`, never by redesigning.
- Things v4 does differently that a view can trip over, not compensated: a responsive text size (`sm:text-4xl`) no longer resets an earlier `leading-*` (the welcome headings carry an explicit `sm:leading-*` for that); `space-x-*`/`space-y-*` and `divide-*` put the margin or border on every child but the LAST instead of every child but the first (a hidden last child leaves a trailing gap; `signed-in.css` restores the v3 `space-*` rules, `app.css` does not); `bg-opacity-*` and `ring-opacity-*` no longer exist (use `bg-black/5`); the important marker goes last (`grid!`); `scale-*`, `translate-*` and `rotate-*` set their own properties, not `transform`.
- `@tailwindcss/typography` still pins a flagged `postcss-selector-parser`; `package.json` `overrides` pins it to ^7.1.6 so `npm audit --audit-level=moderate` is clean. Leave it until the plugin itself moves.
- Browser floor: Chrome 111, Safari 16.4, Firefox 128 (cascade layers, `@property`, `color-mix`).

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Engage.git
cd Dot.Engage
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

> **Database:** `DB_CONNECTION=sqlite` is the default. Create the file first (`touch database/database.sqlite`), then `php artisan migrate`. Production is SQLite too; PostgreSQL is not used in production.
>
> **Ecosystem SSO:** Users authenticated through InfoDot gain access via a one-time Sanctum token handoff at `/auth/ecosystem`.

## Ecosystem

**Dot.Engage** is part of the InfoDot ecosystem, connected via Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map, and see [`wiki.md`](wiki.md) for this platform's own knowledge base, including a security-scan and ethical-engagement writeup.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
