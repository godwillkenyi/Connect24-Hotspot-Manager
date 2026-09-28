# Connect24 Documentation

Everything you need to install, configure, and use Connect24 Hotspot
Manager.

---

## Getting started

Start here if you're new to Connect24.

| Guide | What it covers |
|---|---|
| **[Installation](install.md)** | Full setup walkthrough — requirements, web server config, first login |
| **[Router setup](router-setup.md)** | Enable the RouterOS REST API, create the least-privilege user |

---

## Reference

| Guide | What it covers |
|---|---|
| **[FAQ](faq.md)** | 50+ answers — general, router, vouchers, sessions, profiles, security, troubleshooting |
| **[Security](security.md)** | Threat model, hardening guide, production checklist |

---

## Screenshots

All screenshots are captured from **demo mode** (`?demo=1`) so they
don't expose real hotspot data.

### Dashboard

![Dashboard](screenshots/dashboard.png)

Real-time overview — stat cards, live bandwidth, charts, and recent
vouchers.

### Vouchers

![Vouchers](screenshots/vouchers.png)

Full voucher lifecycle — six tabs, live search, bulk actions, and a
usage analysis chart.

### Generate

![Generate](screenshots/generate.png)

Batch creation with quick presets, printable cards, and CSV export.

### Sessions

![Sessions](screenshots/sessions.png)

Live user monitoring — IP, MAC, uptime, and per-session disconnect.

### Profiles

![Profiles](screenshots/profiles.png)

Hotspot user profiles — rate limits, shared users, session timeouts.

### Settings

![Settings](screenshots/settings.png)

Connection status, system info, hotspot defaults, and the danger
zone.

---

## Quick links

- **[Back to main README](../README.md)** — project overview and
  quick start
- **[Contributing guide](../CONTRIBUTING.md)** — how to report bugs,
  request features, and submit PRs
- **[Changelog](../CHANGELOG.md)** — version history
- **[License](../LICENSE)** — commercial terms

---

## Getting help

| Channel | Use for |
|---|---|
| [GitHub Issues](https://github.com/your-username/connect24/issues) | Bug reports and feature requests |
| [GitHub Discussions](https://github.com/your-username/connect24/discussions) | Questions and ideas |
| **hello@connect24.app** | General enquiries |
| **security@connect24.app** | Security disclosures (do **not** use the issue tracker) |
| **sales@connect24.app** | Pro and White-Label licensing |

---

## Documentation conventions

Throughout these guides:

- **`ROUTER_IP`** — your MikroTik router's IP address, e.g.
  `192.168.88.1`
- **`PASS`** — the API password you set for the `connect24` user
- **`CONNECT24_SERVER_IP`** — the IP of the machine running Connect24
- **`.env`** — the environment file at the root of the repo (never
  committed)
- **Winbox** — MikroTik's GUI management tool
- **RouterOS** — MikroTik's operating system
- **REST API** — the HTTP+JSON interface Connect24 uses to talk to
  the router

Code blocks use the following conventions:

```bash
# Shell commands (Linux/macOS)