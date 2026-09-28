# MikroTik Router Setup

How to enable the REST API and create the least-privilege user that
Connect24 needs.

---

## Prerequisites

- RouterOS **6.48 or newer** — the REST API was added in 6.48
- RouterOS **7.x** recommended for full feature support
- Admin access to the router (Winbox, SSH, or the web UI)
- The router's IP address reachable from the Connect24 server

---

## 1. Enable the REST API (HTTPS)

The REST API is served through the `www-ssl` service. Enable it and
attach a certificate.

### If you already have a certificate

```
/ip service enable www-ssl
/ip service set www-ssl port=443
```

### If you need a certificate (self-signed)

Fine for a private LAN:

```
/certificate add name=connect24-cert common-name=connect24 days-valid=3650 key-size=2048
/certificate sign connect24-cert
/ip service set www-ssl certificate=connect24-cert
/ip service enable www-ssl
```

### If you need a Let's Encrypt certificate

For public-facing routers, use the DNS challenge method. See the
[MikroTik certificate documentation](https://help.mikrotik.com/docs/display/ROS/Certificates).

---

## 2. Create the API user group

Connect24 only needs read/write access to hotspot users, profiles, and
active sessions. It does **not** need full admin.

```
/user group add name=connect24 policy=read,write,api,rest-api
```

**Policy breakdown:**

| Policy | Why Connect24 needs it |
|---|---|
| `read` | List vouchers, profiles, active sessions |
| `write` | Create and delete vouchers and profiles, disconnect sessions |
| `api` | Access the legacy API for a few calls that don't have a REST endpoint |
| `rest-api` | Access the REST API that Connect24 uses for everything else |

**Do not grant** `policy`, `test`, `sniff`, `romon`, `sensitive`,
`ftp`, `password`, `reboot`, `ssh`, or `telnet`. Connect24 never needs
them, and granting them makes a compromised server far more dangerous.

---

## 3. Create the API user

```
/user add name=connect24 group=connect24 password=YOUR_SECURE_PASSWORD
```

Use a long, random password — ideally 32+ characters. Store it only in
`.env` on the Connect24 server. Never commit it.

To generate a strong password locally:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

---

## 4. Test the connection

From the Connect24 server:

```bash
curl -k -u connect24:YOUR_SECURE_PASSWORD \
  https://ROUTER_IP/rest/ip/hotspot/user
```

Expected result: a JSON array (possibly empty if no vouchers exist
yet). Something like:

```json
[]
```

Or, if you already have vouchers:

```json
[
  {
    ".id": "*1",
    "name": "C24-1H-3A9F12",
    "profile": "1hour",
    "uptime": "48m",
    "limit-uptime": "1h"
  }
]
```

**Errors and what they mean:**

| Result | Meaning |
|---|---|
| Empty array `[]` | Success — no vouchers yet |
| `401 Unauthorized` | Wrong username or password |
| `403 Forbidden` | User is missing a policy |
| `Connection refused` | `www-ssl` service is disabled or on a different port |
| `SSL certificate problem` | Self-signed cert — use `-k` to skip verification in `curl`, or install a valid cert |

---

## 5. Verify all three operations

Connect24 performs three kinds of API calls. Test each one:

```bash
# 1. List hotspot users (vouchers)
curl -k -u connect24:PASS https://ROUTER_IP/rest/ip/hotspot/user

# 2. List hotspot user profiles
curl -k -u connect24:PASS https://ROUTER_IP/rest/ip/hotspot/user/profile

# 3. List active hotspot sessions
curl -k -u connect24:PASS https://ROUTER_IP/rest/ip/hotspot/active
```

All three should return `[]` or a JSON array — never a 403.

If any of them fails, the user is missing a policy. Fix it with:

```
/user group set connect24 policy=read,write,api,rest-api
```

---

## 6. Optional — lock down by IP

Restrict the API user to only accept connections from the Connect24
server's IP address:

```
/ip firewall filter add chain=input \
    protocol=tcp dst-port=443 \
    src-address=!CONNECT24_SERVER_IP \
    action=drop \
    comment="Block www-ssl from everywhere except Connect24"
```

Replace `CONNECT24_SERVER_IP` with the actual IP (e.g. `192.168.88.10`).

**⚠️ Warning:** this can lock you out if you get the IP wrong. Always
test from a separate SSH session before closing the one you're using.

---

## 7. Firewall best practices

If your router is exposed to the internet:

- Only open `www-ssl` (port 443). Never open `www` (port 80).
- Whitelist the Connect24 server IP as shown above.
- Use a strong password — 32+ random characters.
- Rotate the password every 90 days.
- Consider a **WireGuard tunnel** instead of exposing the API at all.
  Connect24 works fine over a VPN — it just needs to reach the API.

### WireGuard tunnel (recommended for remote routers)

On the router:

```
/interface wireguard add name=wg-connect24 listen-port=51820
/interface wireguard peers add \
    interface=wg-connect24 \
    public-key="<server-public-key>" \
    allowed-address=10.10.10.2/32
/ip address add address=10.10.10.1/24 interface=wg-connect24
```

Then in `.env` on the server:

```env
ROUTER_HOST=10.10.10.1
```

Traffic never leaves the encrypted tunnel. This is the safest setup
for routers on remote networks.

---

## 8. Multiple hotspot servers (RouterOS 7+)

If your router runs more than one hotspot server instance (e.g.
`hotspot1`, `hotspot2`, `guest-wifi`), Connect24's Generate page lets
you pick which server each batch targets. No extra router config is
needed — just create the servers normally in RouterOS:

```
/ip hotspot add name=hotspot1 interface=ether2 address-pool=dhcp_pool1
/ip hotspot add name=hotspot2 interface=ether3 address-pool=dhcp_pool2
```

Connect24 auto-detects them via `/rest/ip/hotspot`.

---

## 9. RouterOS version compatibility

| RouterOS | Status | Notes |
|---|---|---|
| 6.48 – 6.49 | ✅ Supported | REST API is fully functional but some fields are named differently |
| 7.0 – 7.5 | ✅ Supported | Full support |
| 7.6+ | ✅ Recommended | Best performance and stability |
| 6.47 and older | ❌ Not supported | No REST API |

Check your version with:

```
/system/resource print
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `401 Unauthorized` | Wrong username or password | Reset: `/user set connect24 password=NEWPASS` |
| `403 Forbidden` | User's group missing a policy | `/user group set connect24 policy=read,write,api,rest-api` |
| `Connection refused` | `www-ssl` disabled or wrong port | `/ip service print` and check `www-ssl` |
| `SSL certificate problem` | Self-signed cert | Either install a proper cert, or set `ROUTER_TLS=false` in `.env` (private LAN only) |
| API returns `[]` but Winbox shows users | Wrong table | Check `/ip/hotspot/user` — not `/ppp/secret` |
| Requests time out | Firewall blocking | Check `/ip firewall filter print` for rules blocking port 443 |
| Slow response | Router CPU maxed | Check `/system/resource print` — upgrade firmware if possible |
| Vouchers won't print | User missing `read` policy | Rare, but verify the group has all four policies |

---

## Removing Connect24's access

When you no longer need Connect24:

```
/user remove [find name=connect24]
/user group remove [find name=connect24]
```

Optionally remove the certificate:

```
/certificate remove [find name=connect24-cert]
```

Optionally disable the API service if nothing else uses it:

```
/ip service disable www-ssl
```

That's it — no other state remains on the router.

---

## See also

- [Installation guide](install.md)
- [FAQ](faq.md)
- [Security model](security.md)