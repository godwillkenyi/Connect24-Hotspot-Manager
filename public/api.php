<?php
/**
 * api.php — Backend for MikroTik voucher management
 *
 * Actions:
 *   POST  api.php?action=login             -> authenticate admin (verifies against the router)
 *   POST  api.php?action=logout            -> destroy session
 *   GET   api.php?action=session           -> { success, loggedIn, authenticated, user }
 *   GET   api.php?action=status            -> { success, loggedIn, routeros }
 *   GET   api.php?action=get-config        -> { host, user, port, timeout }
 *   POST  api.php?action=save-config       -> writes config.json
 *   POST  api.php?action=test-connection   -> opens API connection, returns router info
 *   GET   api.php?action=vouchers          -> list hotspot users (voucher-filtered by default)
 *   POST  api.php?action=create-voucher    -> create voucher(s)
 *   POST  api.php?action=delete-voucher    -> delete one voucher by name
 *   GET   api.php?action=sessions          -> list active hotspot sessions (voucher-filtered)
 *   POST  api.php?action=disconnect-session-> kick one active session
 *   GET   api.php?action=profiles          -> list hotspot profile names only
 *   GET   api.php?action=profile-details   -> list hotspot profiles with full details
 *   POST  api.php?action=create-profile    -> create a hotspot user profile
 *   POST  api.php?action=delete-profile    -> delete a hotspot user profile by name
 *   GET   api.php?action=servers           -> list hotspot servers
 *
 *  Voucher filtering:
 *    The `vouchers` and `sessions` actions only return users whose
 *    name starts with a known voucher prefix (default `C24-`).
 *    Pass `?all=1` to include every hotspot user, or
 *    `?prefix=C24-,VIP-` to override the prefix list.
 */

declare(strict_types=1);

/* ============================================================
 *  SESSION — must be configured before session_start()
 * ============================================================ */
ini_set('session.use_strict_mode', '0');   // accept client-provided session id
ini_set('session.use_only_cookies', '1');  // never put session id in URLs
ini_set('session.cookie_httponly', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',      // one cookie for the whole site
    'domain'   => '',
    'secure'   => false,    // http on localhost
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

/* ============================================================
 *  ROUTEROS CLIENT — optional Composer dependency
 *
 *  If vendor/autoload.php exists, we load the real client.
 *  If not, the app still boots — router actions fail with a
 *  clear message instead of killing the whole file.
 * ============================================================ */
$__routeros_available = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $__routeros_available = class_exists('RouterOS\\Client');
}

use RouterOS\Client;
use RouterOS\Query;

/* ============================================================
 *  VOUCHER PREFIX FILTER
 * ============================================================ */
const DEFAULT_VOUCHER_PREFIXES = ['C24-'];

/* ============================================================
 *  CONFIG STORAGE
 * ============================================================ */
const CONFIG_FILE = __DIR__ . '/config.json';

const DEFAULT_CONFIG = [
    'host'    => '192.168.88.1',
    'user'    => 'admin',
    'pass'    => '',
    'port'    => 8728,
    'timeout' => 10,
];

function loadConfig(): array {
    if (!file_exists(CONFIG_FILE)) {
        return DEFAULT_CONFIG;
    }
    $raw = @file_get_contents(CONFIG_FILE);
    $cfg = json_decode($raw ?: '{}', true);
    if (!is_array($cfg)) {
        return DEFAULT_CONFIG;
    }
    return array_merge(DEFAULT_CONFIG, $cfg);
}

function saveConfig(array $cfg): bool {
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return @file_put_contents(CONFIG_FILE, $json, LOCK_EX) !== false;
}

/* ============================================================
 *  HELPERS
 * ============================================================ */
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireAuth(): void {
    if (empty($_SESSION['logged_in'])) {
        respond(['success' => false, 'message' => 'Not authenticated.'], 401);
    }
}

function routerClient(array $cfg = null): Client {
    if (!class_exists('RouterOS\\Client')) {
        throw new \RuntimeException(
            'RouterOS client not installed. Run: composer require evilfreelancer/routeros-api-php'
        );
    }
    $cfg = $cfg ?? loadConfig();
    return new Client([
        'host'    => $cfg['host'],
        'user'    => $cfg['user'],
        'pass'    => $cfg['pass'],
        'port'    => (int)$cfg['port'],
        'timeout' => (int)$cfg['timeout'],
    ]);
}

function toInt($v): int {
    if (is_int($v)) return $v;
    if (is_string($v) && $v !== '') return (int)$v;
    return 0;
}

/**
 * Returns the list of prefixes to filter by, honoring the
 * `prefix` query param and falling back to the default.
 * Returns null if the caller requested `all=1` (no filtering).
 */
function requestedPrefixes(): ?array {
    if (isset($_GET['all']) && $_GET['all'] === '1') {
        return null;
    }
    if (!empty($_GET['prefix'])) {
        $raw = (string)$_GET['prefix'];
        $list = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn($p) => $p !== ''
        ));
        if (!empty($list)) return $list;
    }
    return DEFAULT_VOUCHER_PREFIXES;
}

function matchesPrefix(string $name, array $prefixes): bool {
    foreach ($prefixes as $p) {
        if ($p === '') continue;
        if (stripos($name, $p) === 0) return true;
    }
    return false;
}

/**
 * Normalize a hotspot user record from RouterOS.
 */
function normalizeVoucher(array $u): array {
    $comment  = $u['comment'] ?? '';
    $disabled = ($u['disabled'] ?? 'false') === 'true';

    $exhausted = $disabled && stripos(ltrim($comment), 'EXHAUSTED') === 0;

    $uptimeSec = uptimeStrToSeconds($u['uptime'] ?? '0s');
    $limitSec  = uptimeStrToSeconds($u['limit-uptime'] ?? '');

    return [
        'id'              => $u['.id']         ?? '',
        'name'            => $u['name']        ?? '',
        'password'        => $u['password']    ?? '',
        'profile'         => $u['profile']     ?? 'default',
        'server'          => $u['server']      ?? '',
        'uptime'          => $u['uptime']      ?? '0s',
        'uptime-sec'      => $uptimeSec,
        'bytes-in'        => toInt($u['bytes-in']  ?? 0),
        'bytes-out'       => toInt($u['bytes-out'] ?? 0),
        'limit-uptime'    => $u['limit-uptime']    ?? '',
        'limit-uptime-sec'=> $limitSec,
        'limit-bytes-in'  => $u['limit-bytes-in']  ?? '',
        'limit-bytes-out' => $u['limit-bytes-out'] ?? '',
        'comment'         => $comment,
        'disabled'        => $disabled,
        'exhausted'       => $exhausted,
    ];
}

/**
 * Normalize an active session record.
 */
function normalizeSession(array $s): array {
    return [
        'id'        => $s['.id']         ?? '',
        'user'      => $s['user']        ?? '',
        'address'   => $s['address']     ?? '',
        'mac'       => $s['mac-address'] ?? '',
        'uptime'    => $s['uptime']      ?? '0s',
        'bytes-in'  => toInt($s['bytes-in']  ?? 0),
        'bytes-out' => toInt($s['bytes-out'] ?? 0),
        'login-by'  => $s['login-by']    ?? '',
    ];
}

/**
 * Parse a RouterOS duration string ("1w2d3h4m5s") into seconds.
 */
function uptimeStrToSeconds(string $str): int {
    $s = trim($str);
    if ($s === '' || $s === '0s') return 0;

    if (preg_match_all('/(\d+)([wdhms])/', $s, $m, PREG_SET_ORDER)) {
        $total = 0;
        foreach ($m as $match) {
            $val  = (int)$match[1];
            $unit = $match[2];
            $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$unit] ?? 0;
            $total += $val * $mult;
        }
        return $total;
    }

    if (preg_match('/^(\d+)\s*([a-zA-Z]+)$/', $s, $m)) {
        $val = (int)$m[1];
        $rawUnit = $m[2];
        $unit = strtolower($rawUnit);
        $key = $unit;
        if ($unit === "mo" || $rawUnit === "M") $key = "mo";
        elseif ($unit === "m") $key = "m";
        else $key = $unit[0];
        $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'mo' => 2592000][$key] ?? 0;
        return $val * $mult;
    }

    return 0;
}

/* ============================================================
 *  ROUTER DISPATCH
 * ============================================================ */
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    /* --------------------------------------------------------
     *  LOGIN
     *
     *  The user and password entered here are used to talk to
     *  the router. If the router accepts them, they're saved
     *  to config.json and a session is created.
     *
     *  If the router is offline, login still succeeds as long
     *  as the local admin credentials match (see ADMIN_* below),
     *  so the app is usable for demo / offline work.
     * -------------------------------------------------------- */
    case 'login':
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $user = trim($body['username'] ?? '');
        $pass = (string)($body['password'] ?? '');

        if ($user === '' || $pass === '') {
            respond(['success' => false, 'message' => 'Missing username or password.'], 400);
        }

        // ----------------------------------------------------
        //  LOCAL ADMIN GATE
        //
        //  If you want a fixed pair of admin credentials that
        //  work even when the router is unreachable, set them
        //  here. Leave ADMIN_PASS empty to disable.
        // ----------------------------------------------------
        $adminUser = getenv('ADMIN_USER') ?: '';
        $adminPass = getenv('ADMIN_PASS') ?: '';

        $localOk = false;
        if ($adminUser !== '' && $adminPass !== '') {
            $localOk = ($user === $adminUser) && hash_equals($adminPass, $pass);
        }

        // ----------------------------------------------------
        //  ROUTER AUTH
        //
        //  Try to authenticate against the router. On success
        //  we save the credentials into config.json so future
        //  router calls use them.
        // ----------------------------------------------------
        $cfg        = loadConfig();
        $cfg['user'] = $user;
        $cfg['pass'] = $pass;

        $routerOk    = false;
        $routerError = null;

        try {
            $client = routerClient($cfg);
            $client->query(new Query('/system/identity/print'))->read();
            $routerOk = true;
            saveConfig($cfg);
        } catch (\Throwable $e) {
            $routerError = $e->getMessage();
            error_log('RouterOS login failed: ' . $routerError);
        }

        // ----------------------------------------------------
        //  DECIDE
        // ----------------------------------------------------
        if (!$localOk && !$routerOk) {
            respond([
                'success' => false,
                'message' => 'Invalid credentials or router unreachable.',
                // ── DEBUG: uncomment the next line temporarily to
                //    see the real router error. Remove before prod.
                // 'debug' => $routerError,
            ], 401);
        }

        // Log them in
        $_SESSION['logged_in']  = true;
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_time'] = time();

        // ─── Force session data to be written BEFORE responding ───
        //     Without this, PHP may not flush the session to disk
        //     before exit(), and the next request sees an empty session.
        session_write_close();

        respond([
            'success'  => true,
            'message'  => 'Login successful.',
            'routerOk' => $routerOk,
        ]);
        break;

    /* --------------------------------------------------------
     *  LOGOUT
     * -------------------------------------------------------- */
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }
        session_destroy();
        respond(['success' => true]);
        break;

    /* --------------------------------------------------------
     *  SESSION CHECK
     *  Returns the shape the frontend expects:
     *    { success, loggedIn, authenticated, user: { name, role } }
     * -------------------------------------------------------- */
    case 'session':
        $isAuth = !empty($_SESSION['logged_in']);
        respond([
            'success'       => true,
            'loggedIn'      => $isAuth,
            'authenticated' => $isAuth,
            'user'          => $isAuth && !empty($_SESSION['admin_user'])
                ? ['name' => $_SESSION['admin_user'], 'role' => 'administrator']
                : null,
        ]);
        break;

    /* --------------------------------------------------------
     *  STATUS — used by the footer API pill
     * -------------------------------------------------------- */
    case 'status':
        respond([
            'success'  => true,
            'loggedIn' => !empty($_SESSION['logged_in']),
            'routeros' => class_exists('RouterOS\\Client'),
        ]);
        break;

    /* --------------------------------------------------------
     *  GET CONFIG
     * -------------------------------------------------------- */
    case 'get-config':
        requireAuth();
        $cfg = loadConfig();
        respond([
            'success' => true,
            'config'  => [
                'host'        => $cfg['host'],
                'user'        => $cfg['user'],
                'port'        => (int)$cfg['port'],
                'timeout'     => (int)$cfg['timeout'],
                'hasPassword' => $cfg['pass'] !== '',
            ],
        ]);
        break;

    /* --------------------------------------------------------
     *  SAVE CONFIG
     * -------------------------------------------------------- */
    case 'save-config':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $cfg  = loadConfig();

        if (isset($body['host']))    $cfg['host']    = trim((string)$body['host']);
        if (isset($body['user']))    $cfg['user']    = trim((string)$body['user']);
        if (isset($body['pass']) && $body['pass'] !== '') {
            $cfg['pass'] = (string)$body['pass'];
        }
        if (isset($body['port']))    $cfg['port']    = max(1, min(65535, (int)$body['port']));
        if (isset($body['timeout'])) $cfg['timeout'] = max(1, min(60, (int)$body['timeout']));

        if ($cfg['host'] === '') respond(['success' => false, 'message' => 'Host cannot be empty.'], 400);
        if ($cfg['user'] === '') respond(['success' => false, 'message' => 'Username cannot be empty.'], 400);

        if (!saveConfig($cfg)) {
            respond(['success' => false, 'message' => 'Could not write config.json (check folder permissions).'], 500);
        }
        respond(['success' => true, 'message' => 'Configuration saved.']);
        break;

    /* --------------------------------------------------------
     *  TEST CONNECTION
     * -------------------------------------------------------- */
    case 'test-connection':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $cfg  = loadConfig();

        if (isset($body['host']))    $cfg['host']    = trim((string)$body['host']);
        if (isset($body['user']))    $cfg['user']    = trim((string)$body['user']);
        if (isset($body['pass']) && $body['pass'] !== '') {
            $cfg['pass'] = (string)$body['pass'];
        }
        if (isset($body['port']))    $cfg['port']    = (int)$body['port'];
        if (isset($body['timeout'])) $cfg['timeout'] = (int)$body['timeout'];

        try {
            $client = routerClient($cfg);

            $identity = $client->query(new Query('/system/identity/print'))->read();
            $resource = $client->query(new Query('/system/resource/print'))->read();
            $users    = $client->query(new Query('/ip/hotspot/user/print'))->read();

            respond([
                'success' => true,
                'message' => 'Connected successfully.',
                'info'    => [
                    'identity' => $identity[0]['name']    ?? '(unknown)',
                    'version'  => $resource[0]['version'] ?? '(unknown)',
                    'users'    => count($users),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('test-connection failed: ' . $e->getMessage());
            respond([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ], 500);
        }
        break;

    /* --------------------------------------------------------
     *  LIST VOUCHERS
     * -------------------------------------------------------- */
    case 'vouchers':
        requireAuth();
        try {
            $client = routerClient();
            $users  = $client->query(new Query('/ip/hotspot/user/print'))->read();
            $out    = array_map('normalizeVoucher', $users);

            $prefixes = requestedPrefixes();
            if ($prefixes !== null) {
                $out = array_values(array_filter($out, function ($v) use ($prefixes) {
                    return matchesPrefix((string)($v['name'] ?? ''), $prefixes);
                }));
            }

            respond(['success' => true, 'vouchers' => $out]);
        } catch (\Throwable $e) {
            error_log('vouchers error: ' . $e->getMessage());
            respond([
                'success' => false,
                'message' => 'Failed to read vouchers: ' . $e->getMessage(),
            ], 500);
        }
        break;

    /* --------------------------------------------------------
     *  CREATE VOUCHER(S)
     * -------------------------------------------------------- */
    case 'create-voucher':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body    = readJsonBody();
        $count   = max(1, min(100, (int)($body['count'] ?? 1)));
        $profile = trim((string)($body['profile'] ?? 'default'));
        $prefix  = trim((string)($body['prefix'] ?? 'C24-'));
        $uptime  = trim((string)($body['limit-uptime'] ?? '1d'));
        $comment = trim((string)($body['comment'] ?? ''));
        $server  = trim((string)($body['server'] ?? ''));

        if ($profile === '') $profile = 'default';
        if ($prefix === '')  $prefix  = 'C24-';
        if ($uptime === '')  $uptime  = '1d';

        $created = [];
        try {
            $client = routerClient();

            // Fetch existing names so we never duplicate a code
            $existing = [];
            $users = $client->query(new Query('/ip/hotspot/user/print'))->read();
            foreach ($users as $u) {
                if (!empty($u['name'])) $existing[$u['name']] = true;
            }

            $attempts = 0;
            $maxAttempts = $count * 10;

            while (count($created) < $count && $attempts < $maxAttempts) {
                $attempts++;
                $suffix = strtoupper(bin2hex(random_bytes(3)));
                $code = $prefix . $suffix;
                if (isset($existing[$code])) continue;

                try {
                    $q = (new Query('/ip/hotspot/user/add'))
                        ->equal('name', $code)
                        ->equal('password', $code)
                        ->equal('profile', $profile)
                        ->equal('limit-uptime', $uptime);

                    if ($server !== '') {
                        $q->equal('server', $server);
                    }
                    if ($comment !== '') {
                        $q->equal('comment', $comment);
                    }

                    $client->query($q)->read();
                    $created[] = $code;
                    $existing[$code] = true;
                } catch (\Throwable $e) {
                    error_log('create-voucher item failed: ' . $e->getMessage());
                }
            }

            respond([
                'success'   => true,
                'created'   => $created,
                'requested' => $count,
            ]);
        } catch (\Throwable $e) {
            error_log('create-voucher error: ' . $e->getMessage());
            respond([
                'success' => false,
                'message' => 'Failed to create vouchers: ' . $e->getMessage(),
            ], 500);
        }
        break;

    /* --------------------------------------------------------
     *  DELETE VOUCHER (single, by name)
     * -------------------------------------------------------- */
    case 'delete-voucher':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') respond(['success' => false, 'message' => 'Missing voucher name.'], 400);

        try {
            $client = routerClient();

            $users  = $client->query(new Query('/ip/hotspot/user/print'))->read();
            $target = null;
            foreach ($users as $u) {
                if (($u['name'] ?? '') === $name) {
                    $target = $u['.id'] ?? null;
                    break;
                }
            }
            if (!$target) {
                respond(['success' => false, 'message' => 'Voucher not found.'], 404);
            }

            $client->query(
                (new Query('/ip/hotspot/user/remove'))->equal('.id', $target)
            )->read();

            respond([
                'success' => true,
                'message' => 'Voucher deleted.',
                'name'    => $name,
            ]);
        } catch (\Throwable $e) {
            error_log('delete-voucher error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to delete voucher.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  LIST SESSIONS (active hotspot users)
     * -------------------------------------------------------- */
    case 'sessions':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/active/print'))->read();
            $out    = array_map('normalizeSession', $rows);

            $prefixes = requestedPrefixes();
            if ($prefixes !== null) {
                $out = array_values(array_filter($out, function ($s) use ($prefixes) {
                    return matchesPrefix((string)($s['user'] ?? ''), $prefixes);
                }));
            }

            respond(['success' => true, 'sessions' => $out]);
        } catch (\Throwable $e) {
            error_log('sessions error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to read sessions.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  DISCONNECT A SESSION (kick a user off the hotspot)
     * -------------------------------------------------------- */
    case 'disconnect-session':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $id   = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            respond(['success' => false, 'message' => 'Missing session id.'], 400);
        }

        try {
            $client = routerClient();

            $client->query(
                (new Query('/ip/hotspot/active/remove'))->equal('.id', $id)
            )->read();

            respond(['success' => true, 'message' => 'Session disconnected.']);
        } catch (\Throwable $e) {
            error_log('disconnect-session error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to disconnect session.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  LIST HOTSPOT PROFILE NAMES (used by generate.html)
     * -------------------------------------------------------- */
    case 'profiles':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();

            $names = [];
            foreach ($rows as $row) {
                if (!empty($row['name'])) {
                    $names[] = $row['name'];
                }
            }
            sort($names);

            respond(['success' => true, 'profiles' => $names]);
        } catch (\Throwable $e) {
            error_log('profiles error: ' . $e->getMessage());
            respond([
                'success'  => false,
                'message'  => 'Failed to read profiles.',
                'profiles' => ['default'],
            ], 200);
        }
        break;

    /* --------------------------------------------------------
     *  LIST HOTSPOT PROFILES — full details (used by profiles.html)
     * -------------------------------------------------------- */
    case 'profile-details':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id'              => $row['.id']             ?? '',
                    'name'            => $row['name']            ?? '',
                    'rate-limit'      => $row['rate-limit']      ?? '',
                    'shared-users'    => $row['shared-users']    ?? '1',
                    'session-timeout' => $row['session-timeout'] ?? '',
                    'comment'         => $row['comment']         ?? '',
                ];
            }
            usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

            respond(['success' => true, 'profiles' => $out]);
        } catch (\Throwable $e) {
            error_log('profile-details error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to read profiles.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  CREATE HOTSPOT PROFILE
     * -------------------------------------------------------- */
    case 'create-profile':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'message' => 'Profile name is required.'], 400);
        }

        try {
            $client = routerClient();

            $existing = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            foreach ($existing as $p) {
                if (($p['name'] ?? '') === $name) {
                    respond([
                        'success' => false,
                        'message' => "A profile named \"$name\" already exists.",
                    ], 409);
                }
            }

            $q = (new Query('/ip/hotspot/user/profile/add'))
                ->equal('name', $name);

            if (!empty($body['rate-limit'])) {
                $q->equal('rate-limit', (string)$body['rate-limit']);
            }
            if (!empty($body['shared-users'])) {
                $q->equal('shared-users', (string)$body['shared-users']);
            }
            if (!empty($body['session-timeout'])) {
                $q->equal('session-timeout', (string)$body['session-timeout']);
            }
            if (!empty($body['comment'])) {
                $q->equal('comment', (string)$body['comment']);
            }

            $reply = $client->query($q)->read();

            foreach ($reply as $item) {
                if (isset($item['message'])) {
                    throw new \RuntimeException($item['message']);
                }
            }

            respond(['success' => true, 'message' => 'Profile created.']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            error_log('create-profile error: ' . $msg);

            $friendly = $msg;
            if (stripos($msg, 'already have') !== false || stripos($msg, 'already exists') !== false) {
                $friendly = "A profile named \"$name\" already exists.";
            } elseif (stripos($msg, 'no permission') !== false || stripos($msg, 'not allowed') !== false) {
                $friendly = "Your API user doesn't have permission to create profiles. Add the 'write' policy to the user group.";
            } elseif (stripos($msg, 'invalid') !== false) {
                $friendly = "Router rejected a field value: $msg";
            }

            respond([
                'success' => false,
                'message' => $friendly,
            ], 500);
        }
        break;

    /* --------------------------------------------------------
     *  DELETE HOTSPOT PROFILE
     * -------------------------------------------------------- */
    case 'delete-profile':
        requireAuth();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') respond(['success' => false, 'message' => 'Missing profile name.'], 400);

        try {
            $client = routerClient();

            $rows = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            $target = null;
            foreach ($rows as $r) {
                if (($r['name'] ?? '') === $name) {
                    $target = $r['.id'] ?? null;
                    break;
                }
            }
            if (!$target) {
                respond(['success' => false, 'message' => 'Profile not found.'], 404);
            }

            $client->query(
                (new Query('/ip/hotspot/user/profile/remove'))->equal('.id', $target)
            )->read();

            respond(['success' => true, 'message' => 'Profile deleted.']);
        } catch (\Throwable $e) {
            error_log('delete-profile error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to delete profile.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  LIST HOTSPOT SERVERS (used by generate.html Server dropdown)
     * -------------------------------------------------------- */
    case 'servers':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/print'))->read();

            $out = [];
            foreach ($rows as $row) {
                if (empty($row['name'])) continue;
                $out[] = [
                    'id'        => $row['.id']       ?? '',
                    'name'      => $row['name']      ?? '',
                    'interface' => $row['interface'] ?? '',
                    'address'   => $row['address']   ?? '',
                ];
            }
            usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));

            respond(['success' => true, 'servers' => $out]);
        } catch (\Throwable $e) {
            error_log('servers error: ' . $e->getMessage());
            respond([
                'success' => false,
                'message' => 'Failed to read servers.',
                'servers' => [],
            ], 200);
        }
        break;

    /* --------------------------------------------------------
     *  UNKNOWN
     * -------------------------------------------------------- */
    default:
        respond(['success' => false, 'message' => 'Unknown action.'], 404);
}