# Installation Guide

Step-by-step instructions for installing Connect24 Hotspot Manager on
your own server.

---

## Requirements

| Component | Minimum | Recommended |
|---|---|---|
| PHP | 8.0 | 8.2+ |
| Web server | Apache, Nginx, Caddy, or PHP built-in | Nginx or Caddy |
| Database | None | None |
| MikroTik RouterOS | 6.48 | 7.x |
| Browser | Chrome 100+, Firefox 100+, Safari 15+ | Latest |

Connect24 has **zero runtime dependencies**. No npm, no Composer, no
build step. If your server can run PHP, it can run Connect24.

---

## 1. Get the code

### Option A — Git clone

```bash
git clone https://github.com/your-username/connect24.git
cd connect24
```

### Option B — Download the ZIP

Download the latest release from
<https://github.com/your-username/connect24/releases>, unzip it, and
`cd` into the folder.

---

## 2. Configure the environment

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` and set at minimum:

```env
# Router
ROUTER_HOST=192.168.88.1
ROUTER_USER=connect24
ROUTER_PASS=your-secure-password
ROUTER_PORT=443
ROUTER_TLS=true

# App
APP_URL=https://connect24.example.com
APP_ENV=production
APP_DEBUG=false

# Security
SESSION_SECRET=your-64-char-random-string
ADMIN_USER=admin
ADMIN_PASS=change-this-immediately
```

Generate a secure `SESSION_SECRET`:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Then lock down the file:

```bash
chmod 600 .env
chown www-data:www-data .env   # adjust to your web server user
```

---

## 3. Point your web server at `public/`

### Apache

```apache
<VirtualHost *:443>
    ServerName connect24.example.com
    DocumentRoot /var/www/connect24/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/connect24.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/connect24.example.com/privkey.pem

    <Directory /var/www/connect24/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name connect24.example.com;

    root /var/www/connect24/public;
    index index.html index.php;

    ssl_certificate     /etc/letsencrypt/live/connect24.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/connect24.example.com/privkey.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block dotfiles and .env
    location ~ /\. {
        deny all;
    }

    error_page 404 /404.html;
    error_page 500 502 503 504 /500.html;
    location = /404.html { internal; }
    location = /500.html { internal; }
}
```

### Caddy (simplest — automatic HTTPS)

```
connect24.example.com {
    root * /var/www/connect24/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    encode gzip

    @php {
        path *.php
    }
    header @php Cache-Control "no-store"

    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
    }

    handle_errors {
        @404 expression {err.status_code} == 404
        rewrite @404 /404.html
        file_server
    }
}
```

Caddy handles Let's Encrypt certificates automatically. If you're new
to self-hosting, start with Caddy.

---

## 4. Quick local test (no web server needed)

```bash
php -S localhost:8080 -t public
```

Then open <http://localhost:8080>.

This is the fastest way to see the app running before you commit to a
full web server setup.

---

## 5. Enable the RouterOS REST API

See [router-setup.md](router-setup.md) for the full walkthrough. In
short, on your router:

```
/user group add name=connect24 policy=read,write,api,rest-api
/user add name=connect24 group=connect24 password=your-secure-password
/ip service enable www-ssl
/ip service set www-ssl certificate=<your-cert>
```

---

## 6. First login

1. Open your Connect24 URL in a browser.
2. Sign in with the `ADMIN_USER` / `ADMIN_PASS` from `.env`.
3. **Immediately change the admin password.** The Settings page
   prompts you on first login.
4. Create your first profile on the **Profiles** page.
5. Generate a test batch on the **Generate** page.
6. Print or export the batch to verify the router connection works.

---

## 7. Optional — try demo mode first

Before connecting a real router, you can preview the whole app with
sample data. Add `?demo=1` to any page:

```
http://localhost:8080/pages/dashboard.html?demo=1
```

Everything works — stats, charts, voucher generation, session list,
profiles — but nothing touches a real router. A blue banner appears at
the top to remind you that you're in demo mode.

This is the fastest way to:

- Take screenshots for the README
- Show the app to a client
- Learn the interface before going live

---

## 8. Optional — install as a PWA

Connect24 ships with a web app manifest. In Chrome or Edge:

1. Open the app in your browser.
2. Look for the install icon in the address bar.
3. Click **Install**.

The app then opens in its own window and can be added to your dock
or Start menu.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Blank white page | Missing `.env` or `SESSION_SECRET` | Check the browser console for a 401 |
| "Failed to load vouchers" | Router unreachable | `curl -k -u connect24:pass https://ROUTER_HOST/rest/ip/hotspot/user` |
| "Connection refused" | `www-ssl` service disabled | On the router: `/ip service enable www-ssl` |
| SSL certificate warning | Self-signed cert | Set `ROUTER_TLS=false` in `.env` **only on a private LAN** |
| Vouchers appear but won't print | Pop-ups blocked | Allow pop-ups for your domain |
| Login redirects in a loop | Cookies blocked | Ensure `APP_URL` matches the actual URL |
| 500 error on load | PHP version too old | Run `php -v` — needs 8.0+ |

---

## Updating

```bash
cd /var/www/connect24
git pull
```

No migrations. No rebuilds. Just hard-refresh the browser.

If you upgraded across a major version, check `CHANGELOG.md` for any
`.env` changes you need to make.

---

## Uninstalling

1. Delete the folder:

```bash
rm -rf /var/www/connect24
```

2. Revoke the RouterOS API user:

```
/user remove [find name=connect24]
/user group remove [find name=connect24]
```

There is no database and no state stored anywhere else.

---

## Production checklist

Before going live, verify:

- [ ] HTTPS enabled with a valid certificate
- [ ] `SESSION_SECRET` is 64 random characters
- [ ] `ADMIN_PASS` is 32+ random characters
- [ ] `.env` has permissions `600`
- [ ] `.env` is excluded from any backups you make publicly
- [ ] Router API locked to the Connect24 server's IP
- [ ] Router API user has only `read,write,api,rest-api`
- [ ] `robots.txt` blocks indexing of `/pages/`
- [ ] Automatic backups of `.env` are stored securely

---

## Getting help

- 📖 [Router setup guide](router-setup.md)
- ❓ [FAQ](faq.md)
- 🔒 [Security model](security.md)
- 🐛 [GitHub Issues](https://github.com/your-username/connect24/issues)