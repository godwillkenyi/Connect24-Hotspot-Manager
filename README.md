<div align="center">

<img src="public/favicon-192.png" alt="Connect24" width="96" height="96">

# Connect24 Hotspot Manager

**MikroTik Hotspot Billing & Voucher Platform**

A self-hosted admin panel for MikroTik hotspot operators. Generate voucher
batches, track live sessions, manage user profiles, and purge expired
vouchers — all from one clean, keyboard-first dashboard.

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](CHANGELOG.md)
[![License](https://img.shields.io/badge/license-Commercial-orange.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777BB4.svg)](https://www.php.net/)
[![RouterOS](https://img.shields.io/badge/RouterOS-%3E%3D6.48-2E7D32.svg)](https://mikrotik.com/)
[![Status](https://img.shields.io/badge/status-stable-success.svg)]()
[![Lint](https://github.com/your-username/connect24/actions/workflows/lint.yml/badge.svg)](https://github.com/your-username/connect24/actions/workflows/lint.yml)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

[Features](#features) · [Screenshots](#screenshots) · [Quick start](#quick-start) · [Docs](docs/) · [Demo](#demo-mode) · [License](#license)

</div>

---

## What is Connect24?

Connect24 is a **zero-dependency** admin panel for MikroTik hotspot
operators. It talks directly to the RouterOS REST API — no database, no
build step, no npm, no Composer. Point PHP at the `public/` folder and
you're running.

It replaces the daily pain of Winbox for hotspot management:

- **Voucher generation** — one click, any quantity, any profile
- **Live dashboard** — real-time stats, charts, session bytes
- **Session control** — see who's online, kick individuals or everyone
- **Profile management** — rate limits, timeouts, shared users
- **Print & export** — printable cards and CSV in one click

Built for hotspot operators who want a modern interface without
sacrificing the reliability of RouterOS underneath.

---

## Screenshots

### Dashboard — real-time overview

![Dashboard](docs/screenshots/dashboard.png)

### Vouchers — manage, filter, export

![Vouchers](docs/screenshots/vouchers.png)

### Generate — batch creation with live preview

![Generate](docs/screenshots/generate.png)

### Sessions — live user monitoring

![Sessions](docs/screenshots/sessions.png)

### Profiles — hotspot configuration

![Profiles](docs/screenshots/profiles.png)

### Settings — system info and danger zone

![Settings](docs/screenshots/settings.png)

---

## Features

### 🎟️ Voucher management

- Generate batches of 1–100 vouchers in a single click
- Custom prefix, profile, uptime, price tag, and server
- Print as branded cards (3-up layout, print-ready)
- Export any selection to CSV
- Bulk delete, bulk print, bulk export
- Copy individual codes with one click
- Six filtered views: All · Active · Unused · Expired · Almost Full · Heavy Users
- Live search across code, profile, and price
- Sortable by code, uptime, or data usage

### 📊 Live dashboard

- Animated stat cards (total / active / unused / expired)
- Real-time bandwidth tracking (bytes-in + bytes-out)
- Bar chart — vouchers by profile
- Donut chart — status breakdown
- Recent vouchers table
- Auto-refreshes every 2–3 seconds

### 👥 Session monitoring

- Live list of every connected user
- IP address, MAC, uptime, and data usage per session
- Per-session disconnect with confirmation
- Disconnect-all for firmware updates or firewall changes
- Search by user, IP, or MAC
- 6-way sort (uptime, bytes, user — both directions)
- Live stat cards (active count, total data, longest session, top user)

### ⚙️ Profile management

- Create profiles with rate limit, shared users, and session timeout
- System profiles (`default`, `none`) automatically hidden
- Delete profiles with confirmation
- Dropdown integration on the Generate page

### 🎨 Design

- **Zero dependencies** — pure vanilla JS + PHP, no framework
- **Dark mode** — automatic OS detection + manual toggle
- **Keyboard-first** — ⌘K command palette, `g`-shortcuts, full tab nav
- **Responsive** — mobile sidebar, breakpoint-aware charts
- **Accessible** — `:focus-visible` rings, `aria-label` on every icon button
- **PWA-ready** — installable as a standalone app
- **Branded error pages** — 404 and 500 look intentional, not broken

---

## Quick start

### 1. Get the code

```bash
git clone https://github.com/your-username/connect24.git
cd connect24