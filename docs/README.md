# Connect24 Documentation

Everything you need to install, configure, and use Connect24 Hotspot
Manager.

---

## Getting started

Start here if you're new to Connect24.

| Guide | What it covers |
|---|---|
| **[Installation](install.md)** | Full setup walkthrough â€” requirements, web server config, first login |
| **[Router setup](router-setup.md)** | Enable the RouterOS REST API, create the least-privilege user |

---

## Reference

| Guide | What it covers |
|---|---|
| **[FAQ](faq.md)** | 50+ answers â€” general, router, vouchers, sessions, profiles, security, troubleshooting |
| **[Security](security.md)** | Threat model, hardening guide, production checklist |

---

## Screenshots

All screenshots are captured from **demo mode** (`?demo=1`) so they
don't expose real hotspot data.

### Dashboard

![Dashboard](screenshots/dashboard.png)

Real-time overview â€” stat cards, live bandwidth, charts, and recent
vouchers.

### Vouchers

![Vouchers](screenshots/vouchers.png)

Full voucher lifecycle â€” six tabs, live search, bulk actions, and a
usage analysis chart.

### Generate

![Generate](screenshots/generate.png)

Batch creation with quick presets, printable cards, and CSV export.

### Sessions

![Sessions](screenshots/sessions.png)

Live user monitoring â€” IP, MAC, uptime, and per-session disconnect.

### Profiles

![Profiles](screenshots/profiles.png)

Hotspot user profiles â€” rate limits, shared users, session timeouts.

### Settings

![Settings](screenshots/settings.png)

Connection status, system info, hotspot defaults, and the danger
zone.

---

## Quick links

- **[Back to main README](../README.md)** â€” project overview and
  quick start
- **[Contributing guide](../CONTRIBUTING.md)** â€” how to report bugs,
  request features, and submit PRs
- **[Changelog](../CHANGELOG.md)** â€” version history
- **[License](../LICENSE)** â€” commercial terms

---

## Getting help

| Channel | Use for |
|---|---|
| [GitHub Issues](https://github.com/Goken-byte/connect24-hotspot-manager/issues) | Bug reports and feature requests |
| [GitHub Discussions](https://github.com/Goken-byte/connect24-hotspot-manager/discussions) | Questions and ideas |
| **testapps065@gmail.com** | General enquiries |
| **testapps065@gmail.com** | Security disclosures (do **not** use the issue tracker) |
| **testapps065@gmail.com** | Pro and White-Label licensing |

---

## Documentation conventions

Throughout these guides:

- **`ROUTER_IP`** â€” your MikroTik router's IP address, e.g.
  `192.168.88.1`
- **`PASS`** â€” the API password you set for the `connect24` user
- **`CONNECT24_SERVER_IP`** â€” the IP of the machine running Connect24
- **`.env`** â€” the environment file at the root of the repo (never
  committed)
- **`config.json`** â€” local router config (gitignored, contains
  credentials)
- **Winbox** â€” MikroTik's GUI management tool
- **RouterOS** â€” MikroTik's operating system
- **REST API** â€” the HTTP+JSON interface Connect24 uses to talk to
  the router

Code blocks use the following conventions:

```bash
# Shell commands (Linux/macOS)