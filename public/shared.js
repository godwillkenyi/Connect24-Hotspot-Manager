/* ============================================================
 *  Connect24 Hotspot Manager
 *  shared.js — v1.0.0
 *
 *  Global runtime shared by every page:
 *    - CSRF token cache + fetch wrapper
 *    - Session guard (redirects to index.html if not signed in)
 *    - appApi helpers (format, classify, escape, fetch, etc.)
 *    - Layout wiring (menu button, overlay, sidebar collapse)
 *    - Live pill
 *    - Hook system (onAppReady, onVouchersLoaded, onRefreshRequested)
 *    - Auto-refresh poll for vouchers or custom pages
 *    - Tier 1: footer year, API status, toast close, ⌘K palette,
 *              global keyboard shortcuts
 *    - Tier 3: theme manager, onboarding, error boundary, focus trap
 * ============================================================ */
(function () {
    "use strict";

    /* ============================================================
     *  CONFIG
     * ============================================================ */
    const API_BASE      = "../api.php";
    const LOGIN_PATH    = "../index.html";
    const POLL_INTERVAL = 5000;   // ms — vouchers poll
    const APP_VERSION   = "1.0.0";
    const APP_NAME      = "Connect24";

    /* ============================================================
     *  CSRF — global token cache + fetch wrapper
     *
     *  The server returns a token from ?action=session and expects
     *  it back as X-CSRF-Token on every POST. We cache it here and
     *  auto-attach it via a fetch wrapper so no caller has to know
     *  about it.
     * ============================================================ */
    let __csrfToken = "";

    function setCsrfToken(t) { __csrfToken = String(t || ""); }
    function getCsrfToken()  { return __csrfToken; }

    // Replace window.fetch with one that auto-attaches the CSRF
    // header on every non-GET/HEAD request to our own API.
    const __origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        init = init || {};
        const url = typeof input === "string"
            ? input
            : (input && input.url) || "";
        const method = String(init.method || "GET").toUpperCase();

        const isOurApi = url.indexOf("api.php") !== -1;

        if (isOurApi && method !== "GET" && method !== "HEAD" && __csrfToken) {
            init.headers = Object.assign({}, init.headers || {}, {
                "X-CSRF-Token": __csrfToken
            });
        }
        return __origFetch(input, init);
    };

    /* ============================================================
     *  DOM HELPERS
     * ============================================================ */
    const $   = (id) => document.getElementById(id);
    const $$  = (sel) => document.querySelectorAll(sel);
    const qsa = (sel) => Array.from(document.querySelectorAll(sel));

    const escapeHtml = (s) =>
        String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");

    /* ============================================================
     *  FORMATTERS
     * ============================================================ */

    // Format bytes → "1.2 MB", "850 KB", etc.
    function formatBytes(bytes) {
        const n = Number(bytes) || 0;
        if (n < 1024)                 return n + " B";
        if (n < 1024 * 1024)          return (n / 1024).toFixed(1) + " KB";
        if (n < 1024 * 1024 * 1024)   return (n / (1024 * 1024)).toFixed(2) + " MB";
        return (n / (1024 * 1024 * 1024)).toFixed(2) + " GB";
    }

    // Short uptime — "2h 14m", "45m", "12s"
    function shortUptime(str) {
        if (!str) return "0s";
        const s = String(str).trim();
        if (s === "0s" || s === "") return "0s";

        const regex = /(\d+)([wdhms])/g;
        let match;
        let w = 0, d = 0, h = 0, m = 0, sec = 0;
        while ((match = regex.exec(s)) !== null) {
            const v = parseInt(match[1], 10);
            const u = match[2];
            if (u === "w") w = v;
            if (u === "d") d = v;
            if (u === "h") h = v;
            if (u === "m") m = v;
            if (u === "s") sec = v;
        }
        if (w > 0) return `${w}w ${d}d`;
        if (d > 0) return `${d}d ${h}h`;
        if (h > 0) return `${h}h ${m}m`;
        if (m > 0) return `${m}m`;
        return `${sec}s`;
    }

    // Long uptime for voucher cards — "2 Hours", "45 Minutes"
    function formatUptime(str) {
        if (!str) return "Unlimited";
        const s = String(str).trim();
        const m = s.match(/^(\d+)\s*([a-zA-Z]+)$/);
        if (!m) return s;

        const val  = parseInt(m[1], 10);
        const unit = m[2].toLowerCase();
        const map = {
            s:  "Second",
            m:  "Minute",
            h:  "Hour",
            d:  "Day",
            w:  "Week",
            mo: "Month"
        };
        const word = map[unit] || unit;
        return `${val} ${word}${val === 1 ? "" : "s"}`;
    }

    // "1h30m" → 5400
    function uptimeToSeconds(str) {
        if (!str) return 0;
        const s = String(str).trim();
        if (s === "0s" || s === "") return 0;
        const regex = /(\d+)([wdhms])/g;
        let total = 0, match;
        while ((match = regex.exec(s)) !== null) {
            const v = parseInt(match[1], 10);
            const u = match[2];
            const mult = { s: 1, m: 60, h: 3600, d: 86400, w: 604800 }[u] || 0;
            total += v * mult;
        }
        return total;
    }

    // "2h" → 7200 (limit format)
    function uptimeLimitToSeconds(str) {
        if (!str) return 0;
        const s = String(str).trim();
        const m = s.match(/^(\d+)\s*([a-zA-Z]+)$/);
        if (!m) return 0;
        const val = parseInt(m[1], 10);
        const rawUnit = m[2];
        const unit = rawUnit.toLowerCase();
        let key;
        if (unit === "mo" || rawUnit === "M") key = "mo";
        else if (unit === "m") key = "m";
        else key = unit.charAt(0);
        const mult = { s: 1, m: 60, h: 3600, d: 86400, w: 604800, mo: 2592000 }[key] || 0;
        return val * mult;
    }

    // Sum bytes-in + bytes-out
    function bytesOf(v) {
        return (Number(v["bytes-in"])  || 0)
             + (Number(v["bytes-out"]) || 0);
    }

    /* ============================================================
     *  STATUS CLASSIFIER
     *  Active / unused / expired / almost / heavy
     * ============================================================ */
    function classify(v) {
        if (!v) return "expired";

        const disabled = v.disabled === "true" || v.disabled === true;

        const usedSec  = uptimeToSeconds(v.uptime || "0s");
        const limitSec = uptimeLimitToSeconds(v["limit-uptime"] || "");
        const bytes    = bytesOf(v);

        // Never used
        if (!disabled && usedSec === 0 && bytes === 0 && limitSec > 0) {
            return "unused";
        }

        // Expired — disabled, or uptime consumed
        if (disabled) return "expired";
        if (limitSec > 0 && usedSec >= limitSec) return "expired";

        // Almost / heavy
        if (limitSec > 0) {
            const pct = (usedSec / limitSec) * 100;
            if (pct >= 80) return "almost";
        }

        const mb = bytes / (1024 * 1024);
        if (mb >= 500) return "heavy";

        return "active";
    }

    /* ============================================================
     *  COPY / CLIPBOARD
     * ============================================================ */
    async function copyToClipboard(text) {
        const value = String(text == null ? "" : text);
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                toast("ok", "Copied", value.length > 40
                    ? value.slice(0, 40) + "…"
                    : value, 2200);
                return true;
            }
        } catch (_) { /* fall through */ }

        // Fallback for non-secure contexts
        try {
            const ta = document.createElement("textarea");
            ta.value = value;
            ta.setAttribute("readonly", "");
            ta.style.position = "fixed";
            ta.style.opacity  = "0";
            ta.style.pointerEvents = "none";
            document.body.appendChild(ta);
            ta.select();
            document.execCommand("copy");
            document.body.removeChild(ta);
            toast("ok", "Copied", value.length > 40
                ? value.slice(0, 40) + "…"
                : value, 2200);
            return true;
        } catch (err) {
            toast("error", "Copy failed", "Your browser blocked clipboard access.", 4000);
            return false;
        }
    }

    /* ============================================================
     *  TOAST
     * ============================================================ */
    function toastHost() {
        let host = document.getElementById("toastHost");
        if (!host) {
            host = document.createElement("div");
            host.id = "toastHost";
            host.style.cssText = `
                position: fixed;
                right: 18px;
                bottom: 18px;
                z-index: 300;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
            `;
            document.body.appendChild(host);
        }
        return host;
    }

    function toast(kind, title, message, ttl = 4000) {
        const host = toastHost();
        const el = document.createElement("div");
        el.className = "d-toast " + (kind || "info");
        el.style.pointerEvents = "auto";
        el.setAttribute("role", kind === "error" ? "alert" : "status");
        el.innerHTML = `
            <div style="flex:1; min-width:0;">
                <div style="font-weight:700; color:var(--ink); font-size:13px; margin-bottom:2px;">
                    ${escapeHtml(title || "")}
                </div>
                ${message ? `<div style="color:var(--ink-2); font-size:12.5px; line-height:1.45;">${escapeHtml(message)}</div>` : ""}
            </div>
        `;
        host.appendChild(el);

        if (ttl > 0) {
            setTimeout(() => {
                if (!el.parentNode) return;
                el.style.transition = "opacity 0.18s ease, transform 0.18s ease";
                el.style.opacity = "0";
                el.style.transform = "translateY(6px)";
                setTimeout(() => el.remove(), 200);
            }, ttl);
        }

        return el;
    }

    /* ============================================================
     *  PRINT / CSV EXPORT
     * ============================================================ */
    function printVoucherSheet(vouchers) {
        if (!Array.isArray(vouchers) || vouchers.length === 0) {
            toast("warn", "Nothing to print", "Select at least one voucher.");
            return;
        }

        const w = window.open("", "_blank", "width=900,height=1000");
        if (!w) {
            toast("error", "Print blocked", "Allow pop-ups for this site to print.");
            return;
        }

        const cardsHtml = vouchers.map((v) => {
            const code    = escapeHtml(v.name || "");
            const uptime  = escapeHtml(formatUptime(v["limit-uptime"] || ""));
            const price   = escapeHtml((v.comment || "").trim() || "—");
            return `
                <div class="card">
                    <div class="code">${code}</div>
                    <div class="meta">
                        <div><span>Duration:</span> <b>${uptime}</b></div>
                        <div><span>Price:</span> <b>${price}</b></div>
                    </div>
                    <div class="brand">C O N N E C T 2 4 · W I F I H O T S P O T</div>
                </div>
            `;
        }).join("");

        w.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Connect24 Vouchers</title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        margin: 24px;
        color: #111;
    }
    h1 { font-size: 18px; margin: 0 0 16px; }
    .grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }
    .card {
        border: 1.5px dashed #333;
        border-radius: 8px;
        padding: 14px 12px;
        break-inside: avoid;
    }
    .code {
        font-family: "SF Mono", Consolas, monospace;
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.02em;
        margin-bottom: 8px;
    }
    .meta {
        font-size: 12px;
        line-height: 1.6;
        margin-bottom: 10px;
    }
    .meta span { color: #666; }
    .brand {
        font-size: 9px;
        letter-spacing: 0.12em;
        color: #555;
        text-align: center;
        padding-top: 8px;
        border-top: 1px solid #ddd;
    }
    @media print {
        body { margin: 8mm; }
        .grid { gap: 8mm; }
    }
</style>
</head>
<body>
    <h1>Connect24 Vouchers — ${vouchers.length} total</h1>
    <div class="grid">${cardsHtml}</div>
    <script>window.onload = () => setTimeout(() => window.print(), 300);<\/script>
</body>
</html>`);
        w.document.close();
    }

    function exportCsv(vouchers) {
        if (!Array.isArray(vouchers) || vouchers.length === 0) {
            toast("warn", "Nothing to export", "Select at least one voucher.");
            return;
        }

        const headers = ["Code", "Profile", "Uptime", "Limit", "Data", "Price", "Server", "Status"];
        const rows = vouchers.map((v) => [
            v.name || "",
            v.profile || "",
            v.uptime || "",
            v["limit-uptime"] || "",
            formatBytes(bytesOf(v)),
            v.comment || "",
            v.server || "",
            classify(v)
        ]);

        const csv = [headers, ...rows]
            .map((row) => row.map((cell) => {
                const s = String(cell == null ? "" : cell);
                return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
            }).join(","))
            .join("\n");

        const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement("a");
        const stamp = new Date().toISOString().slice(0, 10);
        a.href = url;
        a.download = `connect24-vouchers-${stamp}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        toast("ok", "Exported", `${vouchers.length} voucher(s) → CSV`, 2500);
    }

    /* ============================================================
     *  API — VOUCHERS
     * ============================================================ */
    async function loadVouchers(opts = {}) {
        const { silent = false, manual = false } = opts;
        const btn = $("refreshBtn");
        if (manual && btn) {
            btn.classList.add("loading");
            btn.disabled = true;
        }
        try {
            const res = await fetch(API_BASE + "?action=vouchers", {
                credentials: "same-origin",
                cache: "no-store"
            });
            if (res.status === 401) {
                window.location.replace(LOGIN_PATH);
                return;
            }
            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.message || ("HTTP " + res.status));
            }
            const vouchers = Array.isArray(data.vouchers) ? data.vouchers : [];
            if (typeof window.onVouchersLoaded === "function") {
                window.onVouchersLoaded(vouchers);
            }
            if (manual) {
                toast("ok", "Refreshed", vouchers.length + " voucher(s) loaded.", 2200);
            }
        } catch (err) {
            if (typeof window.onVouchersError === "function") {
                window.onVouchersError(err);
            }
            if (manual || !silent) {
                toast("error", "Failed to load vouchers", err.message || "Unknown error.");
            }
        } finally {
            if (manual && btn) {
                btn.classList.remove("loading");
                btn.disabled = false;
            }
        }
    }

    /* ============================================================
     *  LAYOUT — menu button, overlay, live pill
     * ============================================================ */
    function wireLayout() {
        const menuBtn = $("menuBtn");
        const sidebar = $("sidebar");
        const overlay = $("overlay");

        if (menuBtn && sidebar && overlay) {
            const open  = () => {
                sidebar.classList.add("on");
                overlay.classList.add("on");
            };
            const close = () => {
                sidebar.classList.remove("on");
                overlay.classList.remove("on");
            };
            menuBtn.addEventListener("click", () => {
                sidebar.classList.contains("on") ? close() : open();
            });
            overlay.addEventListener("click", close);
            sidebar.querySelectorAll(".d-nav-link").forEach((a) => {
                a.addEventListener("click", () => {
                    if (window.innerWidth <= 900) close();
                });
            });
        }

        const livePill = $("livePill");
        if (livePill) livePill.classList.add("on");
    }

    /* ============================================================
     *  POLL — vouchers (default) or custom via flag
     * ============================================================ */
    let pollTimer = null;

    function startPolling() {
        if (window.__autoRefreshUsesCustom) return; // page handles its own
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(() => {
            if (document.hidden) return;
            loadVouchers({ silent: true });
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function wireRefreshButton() {
        const btn = $("refreshBtn");
        if (!btn) return;
        btn.addEventListener("click", () => {
            if (window.__autoRefreshUsesCustom &&
                typeof window.onRefreshRequested === "function") {
                window.onRefreshRequested({ silent: false, manual: true });
                return;
            }
            loadVouchers({ manual: true });
        });
    }

    /* ============================================================
     *  SESSION GUARD
     *
     *  Also captures the CSRF token returned by ?action=session
     *  and stashes it for the fetch wrapper to use.
     * ============================================================ */
    async function checkSession() {
        try {
            const res = await fetch(API_BASE + "?action=session", {
                credentials: "same-origin",
                cache: "no-store"
            });
            if (res.status === 401) {
                window.location.replace(LOGIN_PATH);
                return false;
            }
            if (!res.ok) return true;

            let data = null;
            try { data = await res.json(); } catch (_) { return true; }

            if (data && data.success === false && data.authenticated === false) {
                window.location.replace(LOGIN_PATH);
                return false;
            }

            // Store CSRF token FIRST so any subsequent fetch carries it
            if (data && data.csrf) {
                setCsrfToken(data.csrf);
            }

            if (data && data.user) {
                const nameEl = $("userName");
                const avEl   = $("userAvatar");
                if (nameEl && data.user.name) nameEl.textContent = data.user.name;
                if (avEl && data.user.name) {
                    avEl.textContent = String(data.user.name).charAt(0).toUpperCase();
                }
            }
            return true;
        } catch (_) {
            return true;
        }
    }

    /* ============================================================
     *  BOOT
     * ============================================================ */
    async function boot() {
        wireLayout();
        wireRefreshButton();

        const ok = await checkSession();
        if (!ok) return;

        // Kick off the page's own ready hook
        if (typeof window.onAppReady === "function") {
            try {
                window.onAppReady();
            } catch (err) {
                console.error("[Connect24] onAppReady threw:", err);
            }
        }

        // FIRST LOAD
        // Default pages: fetch vouchers. Custom pages (sessions, profiles,
        // settings, traffic): hand off to their onRefreshRequested hook so
        // the table loads immediately instead of waiting for a manual click.
        if (!window.__autoRefreshUsesCustom) {
            loadVouchers({ silent: true });
        } else if (typeof window.onRefreshRequested === "function") {
            try {
                window.onRefreshRequested({ silent: true, manual: false });
            } catch (err) {
                console.error("[Connect24] onRefreshRequested threw:", err);
            }
        }

        // Start the shared poll (no-op on custom pages)
        startPolling();

        // Pause polling when tab is hidden
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) {
                stopPolling();
            } else {
                startPolling();
                if (!window.__autoRefreshUsesCustom) {
                    loadVouchers({ silent: true });
                }
            }
        });
    }

    /* ============================================================
     *  EXPORT appApi
     * ============================================================ */
    window.appApi = {
        $, $$, qsa,
        escapeHtml,

        getCsrfToken,
        setCsrfToken,

        formatBytes,
        shortUptime,
        formatUptime,
        uptimeToSeconds,
        uptimeLimitToSeconds,
        bytesOf,

        classify,

        copyToClipboard,
        toast,

        printVoucherSheet,
        exportCsv,

        loadVouchers
    };

    /* ============================================================
     *  RUN — DOM ready
     * ============================================================ */
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();


/* ============================================================
 *  TIER 1 — COMMERCIAL POLISH
 *  Footer year · API status pill · Toast close · ⌘K · Shortcuts
 * ============================================================ */
(function tierOne() {
    "use strict";

    /* ------------------------------------------------------------
     *  FOOTER — dynamic year
     * ------------------------------------------------------------ */
    (function footerYear() {
        const el = document.getElementById("footerYear");
        if (el) el.textContent = new Date().getFullYear();
    })();

    /* ------------------------------------------------------------
     *  FOOTER — API status pill
     * ------------------------------------------------------------ */
    (function footerApiStatus() {
        const dot  = document.getElementById("footerApiStatus");
        const text = document.getElementById("footerApiText");
        if (!dot && !text) return;

        async function ping() {
            try {
                const res = await fetch("../api.php?action=status", {
                    credentials: "same-origin",
                    cache: "no-store"
                });
                const ok = res.status >= 200 && res.status < 500;

                if (dot) {
                    dot.classList.toggle("on", ok);
                    dot.classList.toggle("off", !ok);
                }
                if (text) text.textContent = ok ? "API: Connected" : "API: Offline";
            } catch (_) {
                if (dot) {
                    dot.classList.remove("on");
                    dot.classList.add("off");
                }
                if (text) text.textContent = "API: Offline";
            }
        }

        ping();
        setInterval(ping, 30000);
        document.addEventListener("visibilitychange", () => {
            if (!document.hidden) ping();
        });
    })();

    /* ------------------------------------------------------------
     *  TOAST — add close button to every new toast
     * ------------------------------------------------------------ */
    (function toastClose() {
        if (!window.appApi || typeof window.appApi.toast !== "function") return;

        const original = window.appApi.toast;

        window.appApi.toast = function (kind, title, message, ttl) {
            const el = original.apply(this, arguments);
            if (!el || el.querySelector(".d-toast-close")) return el;

            const btn = document.createElement("button");
            btn.className = "d-toast-close";
            btn.setAttribute("aria-label", "Dismiss notification");
            btn.textContent = "×";
            btn.addEventListener("click", () => {
                el.style.transition = "opacity 0.16s ease, transform 0.16s ease";
                el.style.opacity = "0";
                el.style.transform = "translateY(6px)";
                setTimeout(() => el.remove(), 180);
            });
            el.appendChild(btn);

            return el;
        };
    })();

    /* ------------------------------------------------------------
     *  COMMAND PALETTE (⌘K / Ctrl+K)
     * ------------------------------------------------------------ */
    (function cmdk() {
        const cmdk = document.getElementById("cmdk");
        if (!cmdk) return;

        const input   = document.getElementById("cmdkInput");
        const results = document.getElementById("cmdkResults");
        let items  = [];
        let active = 0;

        const COMMANDS = [
            { label: "Dashboard",         hint: "Overview",       href: "dashboard.html" },
            { label: "Vouchers",          hint: "Manage",         href: "vouchers.html" },
            { label: "Generate vouchers", hint: "Create batch",   href: "generate.html" },
            { label: "Sessions",          hint: "Active users",   href: "sessions.html" },
            { label: "Traffic",           hint: "Insight",        href: "traffic.html" },
            { label: "Profiles",          hint: "Hotspot config", href: "profiles.html" },
            { label: "Settings",          hint: "System",         href: "settings.html" },
        ];

        function render(q) {
            const query = String(q || "").trim().toLowerCase();
            items = COMMANDS.filter((c) =>
                !query ||
                c.label.toLowerCase().includes(query) ||
                c.hint.toLowerCase().includes(query)
            );
            active = 0;

            if (items.length === 0) {
                results.innerHTML = `<div style="padding:24px; text-align:center; color:var(--ink-3); font-size:13px;">No matches</div>`;
                return;
            }

            results.innerHTML = items.map((c, i) => `
                <div class="d-cmdk-item ${i === active ? "active" : ""}" data-href="${c.href}">
                    <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                    <span>${c.label}</span>
                    <span style="margin-left:auto; font-size:11.5px; color:var(--ink-3);">${c.hint}</span>
                </div>
            `).join("");
        }

        function open() {
            cmdk.hidden = false;
            if (input) input.value = "";
            render("");
            if (input) input.focus();
        }

        function close() {
            cmdk.hidden = true;
        }

        function move(d) {
            if (items.length === 0) return;
            active = Math.max(0, Math.min(items.length - 1, active + d));
            Array.from(results.children).forEach((el, i) =>
                el.classList.toggle("active", i === active)
            );
            if (results.children[active]) {
                results.children[active].scrollIntoView({ block: "nearest" });
            }
        }

        function go() {
            const item = items[active];
            if (item) window.location.href = item.href;
        }

        document.addEventListener("keydown", (e) => {
            const isOpen = !cmdk.hidden;

            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
                e.preventDefault();
                isOpen ? close() : open();
                return;
            }

            if (!isOpen) return;

            if (e.key === "Escape")         { e.preventDefault(); close(); }
            else if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
            else if (e.key === "ArrowUp")   { e.preventDefault(); move(-1); }
            else if (e.key === "Enter")     { e.preventDefault(); go(); }
        });

        if (input) input.addEventListener("input", (e) => render(e.target.value));

        results.addEventListener("click", (e) => {
            const row = e.target.closest(".d-cmdk-item");
            if (row) window.location.href = row.dataset.href;
        });

        const closer = cmdk.querySelector("[data-cmdk-close]");
        if (closer) closer.addEventListener("click", close);

        const trigger = document.getElementById("cmdkTrigger");
        if (trigger) trigger.addEventListener("click", open);
    })();

    /* ------------------------------------------------------------
     *  GLOBAL KEYBOARD SHORTCUTS
     * ------------------------------------------------------------ */
    (function keyboardShortcuts() {
        const map = {
            d: "dashboard.html",
            v: "vouchers.html",
            s: "sessions.html",
            t: "traffic.html",
            p: "profiles.html",
            g: "generate.html",
            ",": "settings.html"
        };
        let pending = null;
        let timer = null;

        document.addEventListener("keydown", (e) => {
            const tag = (e.target && e.target.tagName) || "";
            if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
            if (e.metaKey || e.ctrlKey || e.altKey) return;

            if (e.key === "g") {
                pending = "g";
                clearTimeout(timer);
                timer = setTimeout(() => { pending = null; }, 900);
                return;
            }

            if (pending === "g" && map[e.key]) {
                e.preventDefault();
                const href = map[e.key];
                pending = null;
                clearTimeout(timer);
                window.location.href = href;
            }
        });
    })();
})();


/* ============================================================
 *  TIER 3 — POLISH
 *  Theme toggle · Onboarding · Error boundary · Focus trap
 * ============================================================ */
(function tierThree() {
    "use strict";

    /* ------------------------------------------------------------
     *  THEME MANAGER — dark / light / system
     * ------------------------------------------------------------ */
    (function themeManager() {
        const THEME_KEY = "c24.theme";
        const root = document.documentElement;

        // Apply saved theme immediately (before paint)
        const saved = localStorage.getItem(THEME_KEY);
        if (saved === "dark" || saved === "light") {
            root.setAttribute("data-theme", saved);
        }

        function current() {
            const explicit = root.getAttribute("data-theme");
            if (explicit) return explicit;
            return window.matchMedia("(prefers-color-scheme: dark)").matches
                ? "dark" : "light";
        }

        function apply(theme) {
            if (theme === "system") {
                root.removeAttribute("data-theme");
                localStorage.removeItem(THEME_KEY);
            } else {
                root.setAttribute("data-theme", theme);
                localStorage.setItem(THEME_KEY, theme);
            }
            updateButton();
        }

        function toggle() {
            apply(current() === "dark" ? "light" : "dark");
        }

        function updateButton() {
            const btn = document.getElementById("themeBtn");
            if (!btn) return;
            const c = current();
            btn.setAttribute("aria-label",
                c === "dark" ? "Switch to light mode" : "Switch to dark mode");
            btn.setAttribute("title",
                c === "dark" ? "Light mode" : "Dark mode");
        }

        // Wire the toggle button
        document.addEventListener("DOMContentLoaded", () => {
            const btn = document.getElementById("themeBtn");
            if (btn) btn.addEventListener("click", toggle);
            updateButton();
        });

        // React to OS theme changes
        window.matchMedia("(prefers-color-scheme: dark)")
            .addEventListener("change", () => {
                if (!root.hasAttribute("data-theme")) updateButton();
            });

        // Expose for the command palette + settings page
        window.__c24Theme = { get: current, set: apply, toggle };
    })();

    /* ------------------------------------------------------------
     *  ONBOARDING — first-run modal
     *  Shows once per browser. Stores a flag in localStorage.
     * ------------------------------------------------------------ */
    (function onboarding() {
        const KEY = "c24.onboarded.v1";
        if (localStorage.getItem(KEY)) return;
        if (!document.getElementById("sidebar")) return; // skip on login page

        document.addEventListener("DOMContentLoaded", () => {
            const STEPS = [
                {
                    title: "Welcome to Connect24",
                    text: "Your hotspot control centre. Everything happens " +
                          "in real time — no reloads, no waiting.",
                    icon: "M12 2 4 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-3z",
                },
                {
                    title: "Connect your router",
                    text: "Head to <strong>Settings</strong> to configure " +
                          "the RouterOS API endpoint. Demo mode is on if you " +
                          "just want to explore.",
                    icon: "M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3a4.237 4.237 0 0 0-6 0zm-4-4 2 2a7.074 7.074 0 0 1 10 0l2-2C15.14 9.14 8.87 9.14 5 13z",
                },
                {
                    title: "Generate your first batch",
                    text: "The <strong>Generate</strong> page builds vouchers " +
                          "in one click. Print, export to CSV, or copy them all.",
                    icon: "M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z",
                },
                {
                    title: "Pro tip — press ⌘K",
                    text: "The command palette jumps anywhere instantly. Try " +
                          "<kbd style='font:inherit;font-size:11px;padding:1px 5px;border:1px solid var(--line);border-radius:4px;background:var(--cream-2)'>g</kbd> then " +
                          "<kbd style='font:inherit;font-size:11px;padding:1px 5px;border:1px solid var(--line);border-radius:4px;background:var(--cream-2)'>t</kbd> to open Traffic.",
                    icon: "M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5z",
                },
            ];

            let step = 0;

            const modal = document.createElement("div");
            modal.className = "d-onb";
            modal.id = "onbModal";
            modal.setAttribute("role", "dialog");
            modal.setAttribute("aria-modal", "true");
            modal.setAttribute("aria-labelledby", "onbTitle");

            modal.innerHTML = `
                <div class="d-onb-backdrop" data-onb-close></div>
                <div class="d-onb-panel">
                    <div class="d-onb-head">
                        <div class="d-onb-mark">24</div>
                        <h2 class="d-onb-title" id="onbTitle">Welcome to Connect24</h2>
                        <p class="d-onb-sub">A quick 30-second tour to get you started.</p>
                    </div>
                    <div class="d-onb-body" id="onbBody"></div>
                    <div class="d-onb-dots" id="onbDots"></div>
                    <div class="d-onb-foot">
                        <button type="button" class="d-onb-skip" id="onbSkip">Skip tour</button>
                        <button type="button" class="d-onb-next" id="onbNext">
                            <span id="onbNextLabel">Next</span>
                            <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            const bodyEl = document.getElementById("onbBody");
            const dotsEl = document.getElementById("onbDots");
            const nextBtn = document.getElementById("onbNext");
            const nextLabel = document.getElementById("onbNextLabel");

            function render() {
                bodyEl.innerHTML = STEPS.map((s, i) => `
                    <div class="d-onb-step ${i === step ? "on" : ""}">
                        <div class="d-onb-step-icon">
                            <svg viewBox="0 0 24 24"><path d="${s.icon}"/></svg>
                        </div>
                        <div class="d-onb-step-body">
                            <div class="d-onb-step-title">${s.title}</div>
                            <div class="d-onb-step-text">${s.text}</div>
                        </div>
                    </div>
                `).join("");

                dotsEl.innerHTML = STEPS.map((_, i) =>
                    `<span class="d-onb-dot ${i === step ? "on" : ""}"></span>`
                ).join("");

                nextLabel.textContent = step === STEPS.length - 1 ? "Get started" : "Next";
            }

            function close() {
                localStorage.setItem(KEY, "1");
                modal.style.transition = "opacity 0.18s ease";
                modal.style.opacity = "0";
                setTimeout(() => modal.remove(), 200);
            }

            nextBtn.addEventListener("click", () => {
                if (step === STEPS.length - 1) return close();
                step++;
                render();
            });

            document.getElementById("onbSkip").addEventListener("click", close);
            modal.querySelector("[data-onb-close]").addEventListener("click", close);

            document.addEventListener("keydown", function esc(e) {
                if (e.key === "Escape" && modal.parentNode) {
                    close();
                    document.removeEventListener("keydown", esc);
                }
            });

            // Focus the primary button for keyboard users
            setTimeout(() => nextBtn.focus(), 100);

            render();
        });
    })();

    /* ------------------------------------------------------------
     *  ERROR BOUNDARY — global uncaught error handler
     * ------------------------------------------------------------ */
    (function errorBoundary() {
        let shown = false;

        function show(msg) {
            if (shown) return;
            shown = true;

            const el = document.createElement("div");
            el.className = "d-fatal";
            el.setAttribute("role", "alert");
            el.innerHTML = `
                <div class="d-fatal-box">
                    <div class="d-fatal-icon">
                        <svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                    </div>
                    <h2>Something went wrong</h2>
                    <p>Connect24 hit an unexpected error. Your data on the
                       router is safe — just reload the page to continue.</p>
                    <div class="d-fatal-actions">
                        <button class="d-btn big primary" onclick="location.reload()">
                            Reload page
                        </button>
                        <a class="d-btn big" href="dashboard.html">
                            Go to Dashboard
                        </a>
                    </div>
                    ${msg ? `<div class="d-fatal-detail">${String(msg).replace(/</g, "&lt;")}</div>` : ""}
                </div>
            `;
            document.body.appendChild(el);
        }

        window.addEventListener("error", (e) => {
            // Ignore resource load errors (missing images, favicons)
            if (e.target && e.target !== window && e.target.tagName) return;
            show(e.message || "Unknown error");
        });

        window.addEventListener("unhandledrejection", (e) => {
            const reason = e.reason && e.reason.message
                ? e.reason.message
                : String(e.reason || "");
            // Ignore 401 redirects triggered on purpose
            if (reason.indexOf("401") !== -1) return;
            // Ignore CSRF failures — they're handled by the UI
            if (reason.indexOf("403") !== -1) return;
            show(reason);
        });
    })();

    /* ------------------------------------------------------------
     *  FOCUS TRAP — keeps Tab inside a modal
     *  Used by both the command palette and the onboarding modal.
     * ------------------------------------------------------------ */
    (function focusTraps() {
        function trapFor(container) {
            if (!container) return () => {};
            const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
            return function onKey(e) {
                if (e.key !== "Tab") return;
                const nodes = Array.from(container.querySelectorAll(FOCUSABLE));
                if (nodes.length === 0) return;
                const first = nodes[0];
                const last  = nodes[nodes.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            };
        }

        // Attach to the command palette when it opens
        const cmdk = document.getElementById("cmdk");
        if (cmdk) {
            const handler = trapFor(cmdk.querySelector(".d-cmdk-panel"));
            const observer = new MutationObserver(() => {
                if (cmdk.hidden) document.removeEventListener("keydown", handler);
                else document.addEventListener("keydown", handler);
            });
            observer.observe(cmdk, { attributes: true, attributeFilter: ["hidden"] });
        }
    })();

    /* ------------------------------------------------------------
     *  ARIA SWEEP — label icon-only buttons that have a title
     *  Automatically mirrors title → aria-label so screen readers
     *  announce the right thing without me touching every page.
     * ------------------------------------------------------------ */
    (function ariaSweep() {
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll("button[title]:not([aria-label])")
                .forEach((b) => b.setAttribute("aria-label", b.getAttribute("title")));
            document.querySelectorAll("a[title]:not([aria-label])")
                .forEach((a) => a.setAttribute("aria-label", a.getAttribute("title")));
        });
    })();
})();