﻿# Frequently Asked Questions

Common questions about Connect24 Hotspot Manager.

---

## General

### Is Connect24 free?

Yes â€” free for personal use on a single MikroTik router. Commercial
use across multiple routers or as a hosted service requires a Pro
license. See [LICENSE](../LICENSE) for the full terms.

### Does it need a database?

No. Connect24 is fully stateless. All data lives on the MikroTik
router. The only persistent state is the `.env` file on the server and
the browser session cookie.

This means:

- No migrations to run
- No backup of a database
- Nothing to lose if the server dies â€” just redeploy and reconnect

### Can I run it on shared hosting?

Yes, as long as the host:

- Runs PHP 8.0 or newer
- Allows outbound HTTPS connections to your router's IP
- Supports `mod_rewrite` or equivalent for URL routing

cPanel, Plesk, DirectAdmin, and most shared hosts work fine.

### Does it work with RouterOS 6?

Yes â€” RouterOS 6.48 and newer. Some features may behave slightly
differently than on 7.x. See [router-setup.md](router-setup.md) for
the compatibility table.

### Is there a hosted version?

Not yet. Connect24 is self-hosted only at the moment. A managed
hosting tier is on the roadmap.

### Where does the name come from?

The "24" in Connect24 is a nod to "24/7" â€” always-on connectivity.
The full name is "Connect24 Hotspot Manager."

---

## Router & networking

### My router is on a private IP (192.168.x.x). Can Connect24 reach it?

Only if the Connect24 server can reach that IP. Three options:

1. **Run Connect24 on the same LAN as the router** (recommended). If
   the server and router are on the same network, everything just works.

2. **Set up a VPN tunnel** (WireGuard or OpenVPN) between the server
   and the router. See the WireGuard section in
   [router-setup.md](router-setup.md).

3. **Port-forward `www-ssl` (port 443) from the router's WAN to its
   LAN IP** â€” **not recommended** unless locked down by source IP.

### Can I manage multiple routers from one Connect24 install?

Not from the UI yet â€” you change routers by editing `ROUTER_HOST` in
`.env`. **Per-batch server selection** within a single router is
already supported (see the Server dropdown on the Generate page).

Multi-router management is planned for v1.2.

### Does Connect24 work behind Cloudflare?

You can put Cloudflare in front of the **Connect24 web UI** (the
pages users see), but not in front of the **RouterOS API** â€”
Cloudflare doesn't proxy raw JSON REST calls to arbitrary ports.

If the router is on your LAN, the server reaches it directly. If the
router is remote, use a VPN tunnel rather than exposing it publicly.

### Can I use a domain name instead of an IP for the router?

Yes. In `.env`:

```env
ROUTER_HOST=router.mynetwork.local
```

As long as the Connect24 server can resolve the name, it works.

### Does Connect24 support IPv6?

Yes, if your router's REST API is reachable over IPv6. Set
`ROUTER_HOST` to an IPv6 address enclosed in brackets:

```env
ROUTER_HOST=[2001:db8::1]
```

---

## Vouchers

### How do I generate vouchers?

Open the **Generate** page, pick a quick preset or fill in the form,
and click Generate. Vouchers are created on the router immediately and
listed on the Generate page for printing or exporting.

### Can I generate more than 100 at once?

No â€” the UI caps each batch at 100. This prevents RouterOS from
timing out under a large burst of API calls. For more, run the
generator multiple times.

### Where are vouchers stored?

On the router, in `/ip/hotspot/user`. Connect24 does not keep its own
copy â€” it always reads live from the router.

### Can I edit a voucher after creating it?

Not yet. You can delete and recreate it. Edit support (rename,
change profile, extend uptime) is planned for v1.1.

### What's the difference between "unused", "active", "almost full",
### "heavy user", and "expired"?

| Status | Meaning |
|---|---|
| **Unused** | 0 bytes used AND 0 uptime â€” never connected |
| **Active** | Has been used, still within limits |
| **Almost Full** | â‰¥80% of uptime limit consumed |
| **Heavy User** | â‰¥500 MB of data consumed |
| **Expired** | Disabled, or uptime limit reached |

The four statuses **Active**, **Unused**, **Almost Full**, and
**Expired** appear on the summary cards. **Heavy User** is available
as a tab filter.

### What does the price tag do?

The price tag is a free-form label stored in the voucher's `comment`
field on RouterOS. It shows up on printed cards and CSV exports. It
has no effect on billing â€” Connect24 doesn't process payments.

### Can I customize the voucher card that prints?

Yes â€” edit the CSS inside the `printVoucherSheet()` function in
`shared.js`. The template is self-contained.

### Can I import vouchers from a CSV?

Not currently. It's a frequently requested feature and is on the
roadmap.

### How do I bulk delete unused vouchers?

Two ways:

1. Go to **Vouchers** â†’ click the **Unused** tab â†’ select all â†’
   click **Delete**.
2. Go to **Settings** â†’ **Danger Zone** â†’ **Purge unused**.

Both do the same thing.

---

## Sessions

### Why doesn't the session list match Winbox?

Connect24 reads `/ip/hotspot/active` â€” the same list Winbox shows on
the **Hotspot â†’ Active** tab. If they differ, click **Refresh**.

The list is polled every 5 seconds automatically.

### Can I disconnect a user without deleting their voucher?

Yes â€” use the disconnect button in the Sessions table. The voucher
stays intact; the session is dropped. The user can reconnect if they
have time or bytes remaining.

### What happens if I click "Disconnect all"?

Every active session is dropped. Vouchers are not deleted â€” users can
reconnect if they still have time or bytes remaining. This is useful
before rebooting the router or changing firewall rules.

### Can I see historical sessions (who connected last week)?

Not currently. Connect24 shows only **active** sessions. Historical
logging would require a database and is not planned for v1.x.

### Does Connect24 show Wi-Fi signal strength or client device info?

Not currently. RouterOS exposes some of this, but it varies by
hardware. It's a possible v2.0 feature.

### How often does the session list update?

Every 5 seconds. You can change the polling interval in **Settings**
â†’ **Hotspot Defaults** â†’ **Live refresh interval**.

---

## Profiles

### What is a "profile"?

A profile in RouterOS defines the limits for hotspot users: rate
limit, session timeout, shared users, etc. Vouchers reference a
profile by name.

For example, a "2hours" profile might have:

- Session timeout: `2h`
- Rate limit: `4M/4M`
- Shared users: `1`

Any voucher assigned to that profile inherits those limits.

### Why do I only see some profiles?

Connect24 hides RouterOS **system profiles** â€” `default`, `none`, and
empty names â€” because they're not meant for user-created vouchers.
Only profiles you create yourself appear on the Profiles page and in
the Generate dropdown.

### Can I edit an existing profile?

Yes â€” but you have to do it on the router directly via Winbox or
SSH. Connect24 currently supports create and delete only. Edit support
is planned for v1.1.

### What does "shared users" mean?

The number of simultaneous devices that can use a single voucher. If
`shared-users` is 1, only one device at a time can use the voucher.
If it's 3, up to three devices can share it.

### What happens if I delete a profile that has vouchers?

The vouchers remain but become **orphaned** â€” they'll still exist on
the router, but they won't inherit any profile limits. In practice,
RouterOS usually falls back to `default` behaviour.

Connect24 warns you before deleting. If you're unsure, reassign the
vouchers to another profile first (currently manual, on the router).

### Can I use rate limits like "4M/4M"?

Yes. That's standard RouterOS syntax: `<upload>/<download>`. Examples:

- `2M/2M` â€” 2 Mbps up and down
- `1M/4M` â€” 1 Mbps up, 4 Mbps down
- `512k/2M` â€” asymmetric
- `10M` â€” 10 Mbps both ways (shorthand)

---

## Settings

### Where are my defaults stored?

In your browser's **localStorage** under the key
`c24.settings.defaults`. This means:

- Defaults are per-browser, not per-account
- Clearing your browser data resets them
- They don't sync between devices

A future version will store them server-side.

### What does the "Live refresh interval" control?

How often the Dashboard's **Live Data** card updates. Minimum 2
seconds, maximum 60.

Note: the Sessions page polls every 5s regardless â€” that's fixed for
now.

### Can I change the polling intervals?

Yes â€” edit the constants in `shared.js`:

```js
const POLL_INTERVAL = 5000;  // vouchers poll
```

And in each page's `<script>` block for page-specific polls.

### What does the "Purge unused" button do?

Deletes every voucher with **0 bytes AND 0 uptime**. Vouchers that
were generated but never used.

It shows a confirmation dialog first, and reports how many were
deleted and how many failed.

### What does "Kick all" do?

Disconnects every active session immediately. Same as "Disconnect
all" on the Sessions page.

Useful before:

- Rebooting the router
- Applying firewall rules
- Running firmware updates

### Can I back up my settings?

Copy the `.env` file from the server. That's the only persistent
configuration Connect24 has. Everything else lives on the router.

---

## Security

### Where are my router credentials stored?

In a `.env` file on the Connect24 server. Never in the repository,
never in the browser, never sent to third parties.

The `.env` file should have permissions `600` so only the web server
user can read it.

### Is Connect24 safe to expose to the internet?

Only over HTTPS, and only if you:

- Use a strong `SESSION_SECRET` (64 random chars)
- Set a strong `ADMIN_PASS` (32+ random chars)
- Lock down `www-ssl` on the router to the Connect24 server's IP
- Keep PHP and your web server updated
- Consider rate-limiting `/api.php`

For maximum safety, run Connect24 on a private LAN and access it
through a VPN. See [security.md](security.md) for the full threat
model.

### Does Connect24 have multi-factor authentication?

Not yet. It's on the roadmap for v1.2.

### Does Connect24 log anything?

By default, no. Only PHP errors go to your web server's error log.
Connect24 does not write access logs, audit logs, or usage data.

Your web server (Apache, Nginx, Caddy) will log HTTP requests to
Connect24, as it does for any site. Check your server's log rotation
policy if that matters to you.

### Can I use Connect24 with a read-only API user?

Partially. Browsing vouchers, sessions, and profiles works fine. But
generating vouchers, deleting, and disconnecting sessions will fail
with a permission error. Use `read,write,api,rest-api` for full
functionality.

### What happens if someone steals my router's API password?

They can create and delete vouchers and profiles, and disconnect
sessions â€” the same things Connect24 can do. They **cannot**:

- Reboot the router
- Change firewall rules
- Read Wi-Fi passwords
- Access SSH
- Access the full system

This is why Connect24 uses a least-privilege API user instead of
your main admin account. See the policy breakdown in
[router-setup.md](router-setup.md).

---

## Troubleshooting

### "Session expired" â€” I get logged out every few minutes

Your `SESSION_SECRET` may be missing or changing on every request.
Check that:

1. `.env` exists and is readable by the web server
2. `SESSION_SECRET` is set to a fixed string (not empty)
3. Your PHP session storage directory is writable

### Vouchers load, but the page is slow

The router's CPU may be maxed. Try:

1. Reducing the polling interval in **Settings**
2. Checking `/system/resource print` on the router
3. Upgrading the router firmware
4. Reducing the number of active vouchers

### Charts don't render

Charts use inline SVG, which every modern browser supports. If you see
blank areas, open the browser console â€” usually it's a JavaScript
error from a modified file. Hard-refresh (Ctrl+Shift+R) to clear
stale assets.

### Print doesn't open a new window

Your browser is blocking pop-ups. Allow pop-ups for the Connect24
domain in your browser's site settings.

### "Failed to load" after clicking Refresh

Open the browser console â€” you'll see the real error. Common causes:

- Router unreachable (network issue)
- Session expired (401) â€” reload the page
- Firewall blocking the Connect24 server
- Wrong `ROUTER_HOST` in `.env`

### My vouchers show the wrong profile

Check that the profile name in `.env` matches exactly what's on the
router. RouterOS is case-sensitive for profile names.

If the profile doesn't exist on the router, the voucher is created
with the `default` profile.

### I accidentally deleted the wrong vouchers

Connect24 doesn't have an undo. The vouchers are permanently removed
from the router.

To prevent this:

- Enable RouterOS logging: `/system logging add topics=hotspot,account`
- Take periodic router backups: `/system backup save`
- Export vouchers to CSV before bulk operations

### The dark mode toggle doesn't stick

Check that your browser allows `localStorage`. Some privacy-focused
browsers or extensions block it. Connect24 falls back to the OS
theme setting if storage is unavailable.

---

## Still stuck?

- ðŸ› **Bug reports:** [GitHub Issues](https://github.com/godwillkenyi/Connect24-Hotspot-Manager/issues)
- ðŸ’¬ **Questions:** [GitHub Discussions](https://github.com/godwillkenyi/Connect24-Hotspot-Manager/discussions)
- ðŸ“§ **Email:** testapps065@gmail.com
- ðŸ”’ **Security issues:** testapps065@gmail.com (do **not** use the
  issue tracker for security disclosures)

When reporting a bug, please include:

- Connect24 version (shown in the topbar)
- RouterOS version (`/system/resource print`)
- Browser and operating system
- Steps to reproduce
- Console errors (DevTools â†’ Console)
- What you expected vs what happened

Do **not** include your `.env`, router IP, or credentials in any
public report.