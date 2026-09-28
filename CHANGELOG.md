# Changelog

All notable changes to **Connect24 Hotspot Manager** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Planned
- Dark mode
- PWA support (installable app)
- Reseller accounts
- Billing integration (Stripe)
- Multi-tenant architecture

---

## [1.0.0] — 2025-01-15

### Added
- **Dashboard** — real-time overview of vouchers, sessions, and bandwidth
  - Animated stat cards (total / active / unused / expired vouchers)
  - Live session data counter
  - Vouchers-by-profile bar chart
  - Status breakdown donut chart
  - Recent vouchers table
- **Vouchers** — full voucher lifecycle management
  - 6 tabs: All, Active, Unused, Expired, Almost Full, Heavy Users
  - Live search across code, profile, and price
  - Sortable by code, uptime, or data usage
  - Bulk select with print / export / delete actions
  - Per-row copy, print, and delete
  - Data usage analysis chart (top 20 by MB or uptime %)
- **Generate** — batch voucher creation
  - Quick presets (10× 1h, 2h, 3h)
  - Custom prefix, profile, uptime, server, and price tag
  - Printable voucher cards with brand footer
  - CSV export and copy-all
- **Sessions** — live user monitoring
  - Real-time session table with IP, MAC, uptime, and data usage
  - Per-session disconnect
  - Bulk disconnect all
  - Search and multi-column sort
  - Live stat cards (active, total data, longest, top user)
- **Profiles** — hotspot user profile management
  - Create profiles with rate limit, shared users, and timeout
  - System profiles (`default`, `none`, empty) automatically hidden
  - Delete user profiles
- **Settings** — system configuration
  - Connection status with live router identity and version
  - Hotspot defaults (prefix, uptime, profile, count, price, refresh)
  - System information panel with live counts
  - Danger zone: purge unused, purge expired, kick all sessions
- **Global**
  - Live nav badges (vouchers, sessions, profiles) on every page
  - Consistent design system via `shared.css`
  - Hook-based app lifecycle (`onAppReady`, `onVouchersLoaded`)
  - Toast notifications with semantic colors
  - Responsive layout down to mobile
  - Keyboard-first navigation

### Security
- Session-based authentication with CSRF protection
- Router credentials stored in `.env`, never in the repository
- Separate API user with least-privilege permissions

---

## Version History

| Version | Date | Status |
|---|---|---|
| 1.0.0 | 2025-01-15 | Stable |