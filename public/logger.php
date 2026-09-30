<?php
/**
 * logger.php — Domain-level traffic aggregation for Connect24
 *
 * Reads DNS cache + optional firewall SNI logs from the router,
 * extracts hostnames, and returns aggregated counters. Never stores
 * raw URLs, never inspects payloads, never sees HTTPS contents.
 *
 * Also handles snapshot persistence for the Traffic page:
 *   .cache/top-domains.txt
 *   .cache/top-talkers.txt
 *   .cache/behavior-flags.txt
 */

declare(strict_types=1);

/* ============================================================
 *  BLOCKLIST — public hosts-format lists
 * ============================================================ */
const BLOCKLISTS = [
    'adult' => [
        'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/porn/hosts',
    ],
    'malware' => [
        'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/fakenews-gambling-porn/hosts',
        'https://urlhaus.abuse.ch/downloads/hostfile/',
    ],
    'social' => [
        'https://raw.githubusercontent.com/StevenBlack/hosts/master/alternates/social/hosts',
    ],
];

/* ============================================================
 *  DOMAIN CATEGORIES
 *
 *  Keyword-based classification. Matches against the registrable
 *  domain. First match wins, so put specific categories before
 *  generic ones.
 * ============================================================ */
const DOMAIN_CATEGORIES = [
    'Streaming' => [
        'googlevideo.com', 'youtube.com', 'ytimg.com', 'netflix.com', 'nflxvideo.net',
        'nflximg.net', 'hulu.com', 'disneyplus.com', 'dssott.com', 'primevideo.com',
        'aiv-cdn.net', 'twitch.tv', 'ttvnw.net', 'spotify.com', 'scdn.co',
        'soundcloud.com', 'vimeo.com', 'dailymotion.com', 'music.apple.com',
        'podcasts.apple.com', 'hbomax.com', 'max.com', 'peacocktv.com',
        'paramountplus.com', 'crunchyroll.com', 'plex.tv', 'plex.direct',
    ],
    'Social' => [
        'facebook.com', 'fbcdn.net', 'instagram.com', 'cdninstagram.com',
        'twitter.com', 'x.com', 'twimg.com', 'tiktok.com', 'tiktokcdn.com',
        'tiktokv.com', 'musical.ly', 'bytedance.com', 'snapchat.com', 'sc-cdn.net',
        'reddit.com', 'redd.it', 'redditmedia.com', 'pinterest.com', 'pinimg.com',
        'linkedin.com', 'licdn.com', 'discord.com', 'discordapp.com', 'discord.gg',
        'telegram.org', 't.me', 'whatsapp.com', 'whatsapp.net', 'signal.org',
        'mastodon.social', 'threads.net', 'bereal.com', 'bere.al',
    ],
    'Ads & Tracking' => [
        'doubleclick.net', 'googleadservices.com', 'googlesyndication.com',
        'google-analytics.com', 'googletagmanager.com', 'googletagservices.com',
        'adnxs.com', 'adsrvr.org', 'criteo.com', 'criteo.net', 'taboola.com',
        'outbrain.com', 'scorecardresearch.com', 'quantserve.com', 'moatads.com',
        'pubmatic.com', 'rubiconproject.com', 'openx.net', 'casalemedia.com',
        'amazon-adsystem.com', 'adsafeprotected.com', 'branch.io', 'adjust.com',
        'appsflyer.com', 'mixpanel.com', 'segment.io', 'segment.com',
        'amplitude.com', 'hotjar.com', 'fullstory.com', 'smartlook.com',
        'bugsnag.com', 'sentry.io', 'newrelic.com', 'datadoghq.com',
    ],
    'Updates' => [
        'microsoft.com', 'windowsupdate.com', 'msftconnecttest.com',
        'msftncsi.com', 'windows.com', 'live.com', 'msn.com', 'bing.com',
        'office.com', 'office365.com', 'office.net', 'sharepoint.com',
        'onedrive.com', 'azure.com', 'azureedge.net', 'azurefd.net',
        'trafficmanager.net', 'edgekey.net', 'edgesuite.net', 'akamaiedge.net',
        'akamai.net', 'akamaitechnologies.com', 'llnwd.net', 'apple.com',
        'icloud.com', 'mzstatic.com', 'cdn-apple.com', 'apple-cloudkit.com',
        'googleapis.com', 'gstatic.com', 'googleusercontent.com',
        'google.com', 'gvt1.com', 'gvt2.com', 'gvt3.com',
        'ubuntu.com', 'canonical.com', 'debian.org', 'fedoraproject.org',
        'archlinux.org', 'mozilla.org', 'mozilla.net', 'firefox.com',
    ],
    'Messaging' => [
        'slack.com', 'slack-edge.com', 'slack-msgs.com', 'teams.microsoft.com',
        'teams.live.com', 'zoom.us', 'zoomgov.com', 'webex.com', 'gotomeeting.com',
        'meet.google.com', 'hangouts.google.com', 'duo.google.com',
        'skype.com', 'skypeassets.com', 'viber.com', 'line.me', 'line-apps.com',
        'kakao.com', 'kakaocdn.net', 'wechat.com', 'weixin.qq.com',
    ],
    'Cloud & Dev' => [
        'github.com', 'githubusercontent.com', 'githubassets.com',
        'gitlab.com', 'bitbucket.org', 'atlassian.com', 'atlassian.net',
        'docker.com', 'docker.io', 'dockerhub.com', 'npmjs.com', 'npmjs.org',
        'pypi.org', 'python.org', 'nodejs.org', 'rubygems.org', 'crates.io',
        'stackoverflow.com', 'stackexchange.com', 'serverfault.com',
        'heroku.com', 'herokuapp.com', 'vercel.com', 'vercel.app',
        'netlify.com', 'netlify.app', 'cloudflare.com', 'cloudflare-dns.com',
        'amazonaws.com', 'awsstatic.com', 'cloudfront.net', 'digitalocean.com',
        'linode.com', 'vultr.com', 'hetzner.com', 'ovh.com', 'ovh.net',
        'namecheap.com', 'godaddy.com', 'letsencrypt.org', 'lencr.org',
        'digicert.com', 'sectigo.com', 'comodoca.com', 'globalsign.com',
    ],
    'Shopping' => [
        'amazon.com', 'amazon.co.uk', 'amazon.de', 'amazon.in', 'media-amazon.com',
        'ssl-images-amazon.com', 'ebay.com', 'ebaystatic.com', 'etsy.com',
        'etsystatic.com', 'alibaba.com', 'aliexpress.com', 'alicdn.com',
        'alipay.com', 'walmart.com', 'target.com', 'bestbuy.com', 'shopify.com',
        'myshopify.com', 'shopifycdn.com', 'stripe.com', 'stripe.network',
        'paypal.com', 'paypalobjects.com', 'square.com', 'squareup.com',
        'jumia.com', 'konga.com', 'jiji.ng',
    ],
    'News' => [
        'cnn.com', 'bbc.com', 'bbc.co.uk', 'nytimes.com', 'washingtonpost.com',
        'theguardian.com', 'reuters.com', 'apnews.com', 'aljazeera.com',
        'bloomberg.com', 'wsj.com', 'ft.com', 'economist.com',
        'npr.org', 'pbs.org', 'foxnews.com', 'nbcnews.com', 'abcnews.go.com',
        'cbsnews.com', 'huffpost.com', 'vice.com', 'buzzfeed.com',
    ],
];

/* ============================================================
 *  DOMAIN EXTRACTION
 * ============================================================ */
function extractHostname(string $line): ?string {
    $line = trim($line);
    if ($line === '') return null;

    if (preg_match('/SNI[:\s]+([a-z0-9.\-]+\.[a-z]{2,})/i', $line, $m)) {
        return strtolower(rtrim($m[1], '.'));
    }

    if (preg_match('/DNS\s+query[:\s]+([a-z0-9.\-]+\.[a-z]{2,})/i', $line, $m)) {
        return strtolower(rtrim($m[1], '.'));
    }

    if (preg_match('/^([a-z0-9][a-z0-9.\-]*\.[a-z]{2,})\.?\s/i', $line, $m)) {
        $host = strtolower(rtrim($m[1], '.'));
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return $host;
        }
    }

    return null;
}

function registrableDomain(string $host): string {
    $host = strtolower(trim($host));
    $parts = explode('.', $host);
    if (count($parts) <= 2) return $host;

    $multi = [
        'co.uk', 'org.uk', 'gov.uk', 'ac.uk',
        'co.za', 'co.ke', 'co.tz', 'co.ug',
        'com.au', 'com.br', 'com.mx',
        'co.jp', 'co.kr', 'co.in', 'co.nz',
    ];
    $lastTwo = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    if (in_array($lastTwo, $multi, true) && count($parts) >= 3) {
        return $parts[count($parts) - 3] . '.' . $lastTwo;
    }

    return $lastTwo;
}

/* ============================================================
 *  CATEGORIZE — keyword match against known domains
 * ============================================================ */
function categorizeDomain(string $domain): string {
    $d = strtolower($domain);
    foreach (DOMAIN_CATEGORIES as $category => $needles) {
        foreach ($needles as $needle) {
            // Suffix match: youtube.com matches www.youtube.com and youtube.com
            // but not youtube.com.evil.net
            if ($d === $needle || substr($d, -strlen('.' . $needle)) === '.' . $needle) {
                return $category;
            }
        }
    }
    return 'Other';
}

/* ============================================================
 *  AGGREGATE
 * ============================================================ */
function aggregateDomains(array $lines): array {
    $counts = [];
    foreach ($lines as $line) {
        $host = extractHostname((string)$line);
        if ($host === null) continue;
        $root = registrableDomain($host);
        if ($root === '' || strlen($root) < 4) continue;
        if (!isset($counts[$root])) {
            $counts[$root] = ['domain' => $root, 'hits' => 0, 'subdomains' => []];
        }
        $counts[$root]['hits']++;
        if (!in_array($host, $counts[$root]['subdomains'], true) && count($counts[$root]['subdomains']) < 5) {
            $counts[$root]['subdomains'][] = $host;
        }
    }
    usort($counts, fn($a, $b) => $b['hits'] <=> $a['hits']);
    return array_values($counts);
}

/* ============================================================
 *  SNAPSHOT STORAGE
 *
 *  Flat text files in .cache/. Simple, human-readable, no DB.
 *  Each write is atomic via temp file + rename.
 * ============================================================ */
const CACHE_DIR = __DIR__ . '/.cache';

function cachePath(string $name): string {
    return CACHE_DIR . '/' . $name;
}

/**
 * Write an array of associative rows to a .txt file.
 *   - header row is included
 *   - tab-separated (safe in any text editor, easy to grep)
 *   - atomic via tmp+rename
 */
function writeSnapshot(string $filename, array $headers, array $rows): bool {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);

    $path = cachePath($filename);
    $tmp  = $path . '.tmp';

    $lines = [];
    $lines[] = implode("\t", $headers);
    foreach ($rows as $row) {
        $cells = [];
        foreach ($headers as $h) {
            $v = $row[$h] ?? '';
            // Collapse tabs/newlines in values so the file stays parseable
            $cells[] = str_replace(["\t", "\n", "\r"], ' ', (string)$v);
        }
        $lines[] = implode("\t", $cells);
    }

    $ok = @file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX) !== false;
    if ($ok) @rename($tmp, $path);
    return $ok;
}

/**
 * Read a snapshot file back as an array of rows (header→value).
 */
function readSnapshot(string $filename): array {
    $path = cachePath($filename);
    if (!file_exists($path)) return [];

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return [];

    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    if (count($lines) < 2) return [];

    $headers = explode("\t", array_shift($lines));
    $out = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cells = explode("\t", $line);
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = $cells[$i] ?? '';
        }
        $out[] = $row;
    }
    return $out;
}

/* ============================================================
 *  BLOCKLIST (unchanged)
 * ============================================================ */
function fetchBlocklist(string $category): array {
    if (!isset(BLOCKLISTS[$category])) return [];

    $cacheDir = __DIR__ . '/.cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $cacheFile = $cacheDir . '/blocklist-' . $category . '.txt';
    $cacheAge  = file_exists($cacheFile) ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;

    if ($cacheAge > 86400) {
        $allHosts = [];
        foreach (BLOCKLISTS[$category] as $url) {
            $raw = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 15, 'user_agent' => 'Connect24/1.0']
            ]));
            if ($raw === false) continue;

            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $parts = preg_split('/\s+/', $line);
                if (count($parts) < 2) continue;
                $host = strtolower($parts[1]);
                if ($host === 'localhost' || $host === 'localhost.localdomain') continue;
                if ($host === '0.0.0.0' || $host === '127.0.0.1') continue;
                $allHosts[$host] = true;
            }
        }
        if (!empty($allHosts)) {
            @file_put_contents($cacheFile, implode("\n", array_keys($allHosts)), LOCK_EX);
        }
    }

    if (!file_exists($cacheFile)) return [];
    $lines = @file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $lines ?: [];
}