# Security Model

How Connect24 protects your router, your credentials, and your users.

---

## Threat model

Connect24 assumes:

- The **Connect24 server** is trusted.
- The **browser** is trusted.
- The **network between server and router** may be untrusted.
- The **network between browser and server** may be untrusted.
- Anyone with filesystem access to the Connect24 server can read the router credentials.

It does **not** attempt to defend against:

- A compromised Connect24 server (game over — see above).
- A malicious administrator.
- Physical access to the router.

---

## Authentication

- Admin credentials live in `.env` as `ADMIN_USER` and `ADMIN_PASS`.
- On login, the server sets a signed session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`).
- Sessions expire after `SESSION_LIFETIME` seconds (default 3600).
- Every request validates the session server-side before touching the router.
- Failed requests return `401` and the frontend redirects to the login page.

**Best practices:**

- Use a password manager to generate `ADMIN_PASS` (32+ random chars).
- Rotate `ADMIN_PASS` every 90 days.
- Never reuse your RouterOS admin password for Connect24.

---

## CSRF protection

All state-changing requests (create voucher, delete profile, kick
session) are `POST` requests that require:

1. A valid session cookie.
2. A `Content-Type: application/json` header.

The `Content-Type` requirement alone blocks classic form-based CSRF
attacks. A CSRF token is **not** currently used, but is on the
roadmap for v1.1.

**Why this is acceptable for now:**

- Connect24 is meant to run on a private network or behind a VPN.
- All cookies are `SameSite=Lax`, which blocks cross-site POSTs.
- The app has no third-party embeds that could exfiltrate data.

If you expose Connect24 to the public internet, consider adding a
reverse-proxy-level CSRF