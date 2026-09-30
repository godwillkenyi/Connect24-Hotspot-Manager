<?php
/**
 * api.php — Backend for MikroTik voucher management
 *
 * Security:
 *   - CSRF: every POST requires X-CSRF-Token matching ?action=session
 *   - Rate limit: 5 failed logins per IP per 15 minutes (50 for localhost)
 *   - Audit: sensitive actions append to .cache/audit.txt
 *   - Validation: every action declares its field rules up front
 */

declare(strict_types=1);

/* ============================================================
 *  SESSION
 * ============================================================ */
ini_set('session.use_strict_mode', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
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
 *  ROUTEROS CLIENT
 * ============================================================ */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use RouterOS\Client;
use RouterOS\Query;

/* ============================================================
 *  CONSTANTS
 * ============================================================ */
const DEFAULT_VOUCHER_PREFIXES = ['C24-'];
const CONFIG_FILE              = __DIR__ . '/config.json';
const BLOCKLIST_MANIFEST       = __DIR__ . '/.cache/blocklist-applied.json';
const CSRF_SECRET_FILE         = __DIR__ . '/.cache/csrf-secret.txt';
const AUDIT_LOG_FILE           = __DIR__ . '/.cache/audit.txt';
const AUDIT_LOG_MAX_LINES      = 5000;
const LOGIN_ATTEMPTS_FILE      = __DIR__ . '/.cache/login-attempts.txt';

/* Login rate limit: failures per IP within a sliding window.
 * Localhost gets a higher ceiling so dev work isn't interrupted,
 * but the mechanism is still live — you can prove it works by
 * failing 51 times, and see the block in audit.txt. */
const LOGIN_MAX_FAILURES           = 5;    // real clients
const LOGIN_MAX_FAILURES_LOCALHOST = 5;   // ::1, 127.0.0.1
const LOGIN_WINDOW_SECONDS         = 900;  // 15 minutes

const HEAVY_DOWNLOAD_BYTES   = 524288000;
const TORRENT_LIKE_BYTES     = 1073741824;
const MULTI_DEVICE_THRESHOLD = 3;

const DEFAULT_CONFIG = [
    'host'    => '192.168.88.1',
    'user'    => 'admin',
    'pass'    => '',
    'port'    => 8728,
    'timeout' => 10,
];

/* ============================================================
 *  INPUT VALIDATION
 * ============================================================ */
function validate(array $rules, array $data): array {
    $values = [];
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        $ruleList = array_map('trim', explode('|', $ruleString));
        $isRequired = in_array('required', $ruleList, true);

        $raw = $data[$field] ?? null;
        $missing = ($raw === null || $raw === '');

        if ($missing) {
            if ($isRequired) {
                $errors[] = ['field' => $field, 'message' => "Field '{$field}' is required."];
            } else {
                $values[$field] = '';
            }
            continue;
        }

        $type = 'string';
        foreach ($ruleList as $r) {
            if ($r === 'int')    { $type = 'int';    break; }
            if ($r === 'string') { $type = 'string'; break; }
        }

        if ($type === 'int') {
            if (is_bool($raw)) {
                $errors[] = ['field' => $field, 'message' => "Field '{$field}' must be an integer."];
                continue;
            }
            if (is_string($raw) && !preg_match('/^-?\d+$/', $raw)) {
                $errors[] = ['field' => $field, 'message' => "Field '{$field}' must be an integer."];
                continue;
            }
            $val = (int)$raw;
        } else {
            if (is_array($raw)) {
                $errors[] = ['field' => $field, 'message' => "Field '{$field}' must be a string."];
                continue;
            }
            $val = (string)$raw;
        }

        foreach ($ruleList as $r) {
            if ($r === '' || in_array($r, ['required', 'optional', 'string', 'int'], true)) {
                continue;
            }

            if (strpos($r, 'min:') === 0) {
                $n = (int)substr($r, 4);
                $actual = ($type === 'int') ? $val : mb_strlen($val);
                if ($actual < $n) {
                    $label = ($type === 'int') ? "at least {$n}" : "at least {$n} characters";
                    $errors[] = ['field' => $field, 'message' => "Field '{$field}' must be {$label}."];
                }
                continue;
            }

            if (strpos($r, 'max:') === 0) {
                $n = (int)substr($r, 4);
                $actual = ($type === 'int') ? $val : mb_strlen($val);
                if ($actual > $n) {
                    $label = ($type === 'int') ? "at most {$n}" : "at most {$n} characters";
                    $errors[] = ['field' => $field, 'message' => "Field '{$field}' must be {$label}."];
                }
                continue;
            }

            if (strpos($r, 'in:') === 0) {
                $allowed = array_map('trim', explode(',', substr($r, 3)));
                if (!in_array((string)$val, $allowed, true)) {
                    $errors[] = [
                        'field'   => $field,
                        'message' => "Field '{$field}' must be one of: " . implode(', ', $allowed) . ".",
                    ];
                }
                continue;
            }

            if (strpos($r, 'regex:') === 0) {
                $pattern = substr($r, 6);
                $check = (@preg_match($pattern, '') !== false)
                    ? $pattern
                    : '/' . str_replace('/', '\/', $pattern) . '/';
                if (@preg_match($check, (string)$val) !== 1) {
                    $errors[] = ['field' => $field, 'message' => "Field '{$field}' has an invalid format."];
                }
                continue;
            }
        }

        $values[$field] = $val;
    }

    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }
    return ['ok' => true, 'values' => $values];
}

function validateOrFail(array $rules, array $data): array {
    $result = validate($rules, $data);
    if (!$result['ok']) {
        respond([
            'success' => false,
            'message' => $result['errors'][0]['message'] ?? 'Validation failed.',
            'errors'  => $result['errors'],
        ], 400);
    }
    return $result['values'];
}

/* ============================================================
 *  LOGIN RATE LIMITING
 *
 *  File format — one line per failed attempt, tab-separated:
 *     <unix-timestamp> <TAB> <ip>
 * ============================================================ */
function readLoginAttempts(): array {
    if (!file_exists(LOGIN_ATTEMPTS_FILE)) return [];
    $lines = @file(LOGIN_ATTEMPTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $out = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) < 2) continue;
        $ts = (int)$parts[0];
        $ip = trim($parts[1]);
        if ($ts > 0 && $ip !== '') {
            $out[] = ['ts' => $ts, 'ip' => $ip];
        }
    }
    return $out;
}

function writeLoginAttempts(array $entries): void {
    $dir = dirname(LOGIN_ATTEMPTS_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $lines = [];
    foreach ($entries as $e) {
        $lines[] = $e['ts'] . "\t" . $e['ip'];
    }
    $tmp = LOGIN_ATTEMPTS_FILE . '.tmp';
    if (@file_put_contents($tmp, implode("\n", $lines) . (count($lines) ? "\n" : ""), LOCK_EX) !== false) {
        @rename($tmp, LOGIN_ATTEMPTS_FILE);
    }
}

function loginAttemptsRecent(string $ip): array {
    $now     = time();
    $cutoff  = $now - LOGIN_WINDOW_SECONDS;
    $entries = readLoginAttempts();
    $recent  = [];
    $keep    = [];
    foreach ($entries as $e) {
        if ($e['ts'] < $cutoff) continue;
        $keep[] = $e;
        if ($e['ip'] === $ip) $recent[] = $e['ts'];
    }
    if (count($keep) !== count($entries)) {
        writeLoginAttempts($keep);
    }
    return $recent;
}

function recordLoginFailure(string $ip): void {
    $now     = time();
    $cutoff  = $now - LOGIN_WINDOW_SECONDS;
    $entries = readLoginAttempts();
    $keep    = [];
    foreach ($entries as $e) {
        if ($e['ts'] >= $cutoff) $keep[] = $e;
    }
    $keep[] = ['ts' => $now, 'ip' => $ip];
    writeLoginAttempts($keep);
}

function clearLoginFailures(string $ip): void {
    $entries = readLoginAttempts();
    $keep    = [];
    foreach ($entries as $e) {
        if ($e['ip'] !== $ip) $keep[] = $e;
    }
    writeLoginAttempts($keep);
}

/**
 * Returns:
 *   ['allowed' => bool, 'retryAfter' => int, 'count' => int, 'max' => int]
 */
function checkLoginRate(string $ip, int $max = LOGIN_MAX_FAILURES): array {
    $recent = loginAttemptsRecent($ip);
    $count  = count($recent);
    if ($count < $max) {
        return ['allowed' => true, 'retryAfter' => 0, 'count' => $count, 'max' => $max];
    }
    $oldest = min($recent);
    $retryAfter = max(1, ($oldest + LOGIN_WINDOW_SECONDS) - time());
    return ['allowed' => false, 'retryAfter' => $retryAfter, 'count' => $count, 'max' => $max];
}

/* ============================================================
 *  CSRF
 * ============================================================ */
function csrfSecret(): string {
    $dir = dirname(CSRF_SECRET_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    if (file_exists(CSRF_SECRET_FILE)) {
        $raw = @file_get_contents(CSRF_SECRET_FILE);
        if (is_string($raw) && strlen($raw) >= 32) {
            return trim($raw);
        }
    }
    $secret = bin2hex(random_bytes(32));
    @file_put_contents(CSRF_SECRET_FILE, $secret, LOCK_EX);
    return $secret;
}

function csrfToken(): string {
    return hash_hmac('sha256', session_id(), csrfSecret());
}

function csrfFromRequest(): string {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    $body = readJsonBody();
    if (!empty($body['csrf'])) return (string)$body['csrf'];
    if (!empty($_POST['csrf'])) return (string)$_POST['csrf'];
    return '';
}

function verifyCsrf(): void {
    if (empty($_SESSION['logged_in'])) {
        respond(['success' => false, 'message' => 'Not authenticated.'], 401);
    }
    $sent = csrfFromRequest();
    $good = csrfToken();
    if ($sent === '' || !hash_equals($good, $sent)) {
        respond(['success' => false, 'message' => 'CSRF token missing or invalid.'], 403);
    }
}

/* ============================================================
 *  AUDIT LOG
 * ============================================================ */
function clientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function audit(string $action, string $target = '', string $detail = ''): void {
    $dir = dirname(AUDIT_LOG_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $user = (string)($_SESSION['admin_user'] ?? 'anonymous');
    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\t%s\n",
        date('Y-m-d H:i:s'),
        str_replace(["\t", "\n"], ' ', $user),
        str_replace(["\t", "\n"], ' ', $action),
        str_replace(["\t", "\n"], ' ', $target),
        str_replace(["\t", "\n"], ' ', $detail),
        clientIp()
    );
    @file_put_contents(AUDIT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);

    $size = @filesize(AUDIT_LOG_FILE);
    if ($size !== false && $size > 500_000) {
        $lines = @file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines && count($lines) > AUDIT_LOG_MAX_LINES) {
            $keep = array_slice($lines, -intdiv(AUDIT_LOG_MAX_LINES, 2));
            @file_put_contents(AUDIT_LOG_FILE, implode("\n", $keep) . "\n", LOCK_EX);
        }
    }
}

function readAuditLog(int $limit = 100): array {
    if (!file_exists(AUDIT_LOG_FILE)) return [];
    $lines = @file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $lines = array_slice($lines, -$limit);
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $parts = explode("\t", $line);
        $out[] = [
            'time'   => $parts[0] ?? '',
            'user'   => $parts[1] ?? '',
            'action' => $parts[2] ?? '',
            'target' => $parts[3] ?? '',
            'detail' => $parts[4] ?? '',
            'ip'     => $parts[5] ?? '',
        ];
    }
    return $out;
}

/* ============================================================
 *  MANIFEST + CONFIG STORAGE
 * ============================================================ */
function loadManifest(): array {
    if (!file_exists(BLOCKLIST_MANIFEST)) return [];
    $raw = @file_get_contents(BLOCKLIST_MANIFEST);
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function saveManifest(array $m): void {
    $dir = dirname(BLOCKLIST_MANIFEST);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(
        BLOCKLIST_MANIFEST,
        json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function loadConfig(): array {
    if (!file_exists(CONFIG_FILE)) return DEFAULT_CONFIG;
    $raw = @file_get_contents(CONFIG_FILE);
    $cfg = json_decode($raw ?: '{}', true);
    if (!is_array($cfg)) return DEFAULT_CONFIG;
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
    static $cached = null;
    if ($cached !== null) return $cached;

    $raw = file_get_contents('php://input');
    if (!$raw) { $cached = []; return $cached; }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
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

function requestedPrefixes(): ?array {
    if (isset($_GET['all']) && $_GET['all'] === '1') return null;
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

function commentMatchesCategory(string $comment, string $category): bool {
    $c = strtolower(trim($comment));
    $needle = 'c24-block:' . strtolower($category);
    return strpos($c, $needle) !== false;
}

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

function formatUptimeHuman(int $sec): string {
    if ($sec <= 0) return "0s";
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    $s = $sec % 60;
    if ($h > 0) return "{$h}h {$m}m";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

/* ============================================================
 *  DISPATCH
 * ============================================================ */
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    /* --------------------------------------------------------
     *  LOGIN — rate limited
     *  Localhost: 50 failures allowed before lockout.
     *  Everyone else: 5 failures.
     * -------------------------------------------------------- */
    case 'login':
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $ip = clientIp();
        $isLocalhost = in_array($ip, ['::1', '127.0.0.1', 'localhost'], true);
        $maxFailures = $isLocalhost ? LOGIN_MAX_FAILURES_LOCALHOST : LOGIN_MAX_FAILURES;

        $rate = checkLoginRate($ip, $maxFailures);
        if (!$rate['allowed']) {
            $mins = max(1, (int)ceil($rate['retryAfter'] / 60));
            header('Retry-After: ' . $rate['retryAfter']);
            audit('login-blocked', '', "count={$rate['count']} max={$rate['max']} retryAfter={$rate['retryAfter']}s");
            respond([
                'success'    => false,
                'message'    => "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . ".",
                'retryAfter' => $rate['retryAfter'],
            ], 429);
        }

        $body = readJsonBody();
        $v = validateOrFail([
            'username' => 'required|string|min:1|max:60',
            'password' => 'required|string|min:1|max:200',
        ], $body);

        $user = $v['username'];
        $pass = $v['password'];

        $adminUser = getenv('ADMIN_USER') ?: '';
        $adminPass = getenv('ADMIN_PASS') ?: '';
        $localOk = false;
        if ($adminUser !== '' && $adminPass !== '') {
            $localOk = ($user === $adminUser) && hash_equals($adminPass, $pass);
        }

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

        if (!$localOk && !$routerOk) {
            // Record the failure for every IP, including localhost
            recordLoginFailure($ip);

            $after = checkLoginRate($ip, $maxFailures);
            $remaining = max(0, $maxFailures - $after['count']);

            audit('login-failed', $user, $routerError ? 'router unreachable' : 'invalid credentials');

            $msg = 'Invalid credentials or router unreachable.';
            if ($remaining > 0 && $remaining <= 3) {
                $msg .= " {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining.";
            }

            respond(['success' => false, 'message' => $msg], 401);
        }

        // Success — clear this IP's failures
        clearLoginFailures($ip);

        session_regenerate_id(true);
        $_SESSION['logged_in']  = true;
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_time'] = time();
        $_SESSION['csrf']       = csrfToken();

        audit('login', $user, 'routerOk=' . ($routerOk ? '1' : '0'));

        session_write_close();

        respond([
            'success'  => true,
            'message'  => 'Login successful.',
            'routerOk' => $routerOk,
            'csrf'     => $_SESSION['csrf'] ?? csrfToken(),
        ]);
        break;

    /* --------------------------------------------------------
     *  LOGOUT
     * -------------------------------------------------------- */
    case 'logout':
        if (!empty($_SESSION['logged_in'])) {
            audit('logout', $_SESSION['admin_user'] ?? '', '');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        respond(['success' => true]);
        break;

    /* --------------------------------------------------------
     *  SESSION CHECK
     * -------------------------------------------------------- */
    case 'session':
        $isAuth = !empty($_SESSION['logged_in']);
        if ($isAuth && empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = csrfToken();
        }
        respond([
            'success'       => true,
            'loggedIn'      => $isAuth,
            'authenticated' => $isAuth,
            'user'          => $isAuth && !empty($_SESSION['admin_user'])
                ? ['name' => $_SESSION['admin_user'], 'role' => 'administrator']
                : null,
            'csrf'          => $isAuth ? ($_SESSION['csrf'] ?? csrfToken()) : '',
        ]);
        break;

    /* --------------------------------------------------------
     *  STATUS
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
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'host'    => 'optional|string|max:255',
            'user'    => 'optional|string|max:60',
            'pass'    => 'optional|string|max:200',
            'port'    => 'optional|int|min:1|max:65535',
            'timeout' => 'optional|int|min:1|max:60',
        ], $body);

        $cfg  = loadConfig();
        $changes = [];

        if (isset($body['host']) && $v['host'] !== '') {
            if ($v['host'] !== $cfg['host']) $changes[] = "host={$v['host']}";
            $cfg['host'] = $v['host'];
        }
        if (isset($body['user']) && $v['user'] !== '') {
            if ($v['user'] !== $cfg['user']) $changes[] = "user={$v['user']}";
            $cfg['user'] = $v['user'];
        }
        if (isset($body['pass']) && $v['pass'] !== '') {
            $cfg['pass'] = $v['pass'];
            $changes[] = 'password=***';
        }
        if (isset($body['port']) && $v['port'] !== '') {
            if ((int)$v['port'] !== (int)$cfg['port']) $changes[] = "port={$v['port']}";
            $cfg['port'] = (int)$v['port'];
        }
        if (isset($body['timeout']) && $v['timeout'] !== '') {
            if ((int)$v['timeout'] !== (int)$cfg['timeout']) $changes[] = "timeout={$v['timeout']}";
            $cfg['timeout'] = (int)$v['timeout'];
        }

        if ($cfg['host'] === '') respond(['success' => false, 'message' => 'Host cannot be empty.'], 400);
        if ($cfg['user'] === '') respond(['success' => false, 'message' => 'Username cannot be empty.'], 400);
        if (!saveConfig($cfg)) {
            respond(['success' => false, 'message' => 'Could not write config.json.'], 500);
        }

        audit('save-config', '', $changes ? implode(', ', $changes) : 'no changes');
        respond(['success' => true, 'message' => 'Configuration saved.']);
        break;

    /* --------------------------------------------------------
     *  TEST CONNECTION
     * -------------------------------------------------------- */
    case 'test-connection':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'host'    => 'optional|string|max:255',
            'user'    => 'optional|string|max:60',
            'pass'    => 'optional|string|max:200',
            'port'    => 'optional|int|min:1|max:65535',
            'timeout' => 'optional|int|min:1|max:60',
        ], $body);

        $cfg  = loadConfig();
        if (isset($body['host']) && $v['host'] !== '') $cfg['host'] = $v['host'];
        if (isset($body['user']) && $v['user'] !== '') $cfg['user'] = $v['user'];
        if (isset($body['pass']) && $v['pass'] !== '') $cfg['pass'] = $v['pass'];
        if (isset($body['port']) && $v['port'] !== '') $cfg['port'] = (int)$v['port'];
        if (isset($body['timeout']) && $v['timeout'] !== '') $cfg['timeout'] = (int)$v['timeout'];

        try {
            $client = routerClient($cfg);
            $identity = $client->query(new Query('/system/identity/print'))->read();
            $resource = $client->query(new Query('/system/resource/print'))->read();
            $users    = $client->query(new Query('/ip/hotspot/user/print'))->read();
            audit('test-connection', $cfg['host'], 'ok');
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
            audit('test-connection', $cfg['host'], 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()], 500);
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
            respond(['success' => false, 'message' => 'Failed to read vouchers: ' . $e->getMessage()], 500);
        }
        break;

    /* --------------------------------------------------------
     *  CREATE VOUCHER(S)
     * -------------------------------------------------------- */
    case 'create-voucher':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'count'        => 'optional|int|min:1|max:100',
            'profile'      => 'optional|string|max:40',
            'prefix'       => 'optional|string|max:12',
            'limit-uptime' => 'optional|string|max:20',
            'comment'      => 'optional|string|max:80',
            'server'       => 'optional|string|max:60',
        ], $body);

        $count   = (int)($v['count'] ?? 1);
        if ($count < 1) $count = 1;
        $profile = $v['profile']  !== '' ? $v['profile']  : 'default';
        $prefix  = $v['prefix']   !== '' ? $v['prefix']   : 'C24-';
        $uptime  = $v['limit-uptime'] !== '' ? $v['limit-uptime'] : '1d';
        $comment = $v['comment'];
        $server  = $v['server'];

        $created = [];
        try {
            $client = routerClient();
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
                    if ($server !== '') $q->equal('server', $server);
                    if ($comment !== '') $q->equal('comment', $comment);
                    $client->query($q)->read();
                    $created[] = $code;
                    $existing[$code] = true;
                } catch (\Throwable $e) {
                    error_log('create-voucher item failed: ' . $e->getMessage());
                }
            }
            audit('create-voucher', $profile, sprintf(
                "requested=%d created=%d prefix=%s uptime=%s",
                $count, count($created), $prefix, $uptime
            ));
            respond(['success' => true, 'created' => $created, 'requested' => $count]);
        } catch (\Throwable $e) {
            error_log('create-voucher error: ' . $e->getMessage());
            audit('create-voucher', $profile, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => 'Failed to create vouchers: ' . $e->getMessage()], 500);
        }
        break;

    /* --------------------------------------------------------
     *  DELETE VOUCHER
     * -------------------------------------------------------- */
    case 'delete-voucher':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'name' => 'required|string|min:1|max:60',
        ], $body);
        $name = $v['name'];

        try {
            $client = routerClient();
            $users  = $client->query(new Query('/ip/hotspot/user/print'))->read();
            $target = null;
            foreach ($users as $u) {
                if (($u['name'] ?? '') === $name) { $target = $u['.id'] ?? null; break; }
            }
            if (!$target) {
                audit('delete-voucher', $name, 'not found');
                respond(['success' => false, 'message' => 'Voucher not found.'], 404);
            }
            $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $target))->read();
            audit('delete-voucher', $name, 'deleted');
            respond(['success' => true, 'message' => 'Voucher deleted.', 'name' => $name]);
        } catch (\Throwable $e) {
            error_log('delete-voucher error: ' . $e->getMessage());
            audit('delete-voucher', $name, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => 'Failed to delete voucher.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  LIST SESSIONS
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
     *  DISCONNECT SESSION
     * -------------------------------------------------------- */
    case 'disconnect-session':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'id'   => 'required|string|min:1|max:60',
            'user' => 'optional|string|max:60',
        ], $body);
        $id   = $v['id'];
        $user = $v['user'];

        try {
            $client = routerClient();
            $client->query((new Query('/ip/hotspot/active/remove'))->equal('.id', $id))->read();
            audit('disconnect-session', $user ?: $id, 'kicked');
            respond(['success' => true, 'message' => 'Session disconnected.']);
        } catch (\Throwable $e) {
            error_log('disconnect-session error: ' . $e->getMessage());
            audit('disconnect-session', $user ?: $id, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => 'Failed to disconnect session.'], 500);
        }
        break;

    /* --------------------------------------------------------
     *  PROFILES
     * -------------------------------------------------------- */
    case 'profiles':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            $names = [];
            foreach ($rows as $row) { if (!empty($row['name'])) $names[] = $row['name']; }
            sort($names);
            respond(['success' => true, 'profiles' => $names]);
        } catch (\Throwable $e) {
            error_log('profiles error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to read profiles.', 'profiles' => ['default']], 200);
        }
        break;

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

    case 'create-profile':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'name'            => 'required|string|min:1|max:40',
            'rate-limit'      => 'optional|string|max:40',
            'shared-users'    => 'optional|int|min:1|max:100',
            'session-timeout' => 'optional|string|max:20',
            'comment'         => 'optional|string|max:80',
        ], $body);

        $name = $v['name'];

        try {
            $client = routerClient();
            $existing = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            foreach ($existing as $p) {
                if (($p['name'] ?? '') === $name) {
                    audit('create-profile', $name, 'already exists');
                    respond(['success' => false, 'message' => "A profile named \"$name\" already exists."], 409);
                }
            }
            $q = (new Query('/ip/hotspot/user/profile/add'))->equal('name', $name);
            if ($v['rate-limit'] !== '')      $q->equal('rate-limit', $v['rate-limit']);
            if ($v['shared-users'] !== '')    $q->equal('shared-users', (string)$v['shared-users']);
            if ($v['session-timeout'] !== '') $q->equal('session-timeout', $v['session-timeout']);
            if ($v['comment'] !== '')         $q->equal('comment', $v['comment']);
            $reply = $client->query($q)->read();
            foreach ($reply as $item) {
                if (isset($item['message'])) throw new \RuntimeException($item['message']);
            }
            audit('create-profile', $name, 'created');
            respond(['success' => true, 'message' => 'Profile created.']);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            error_log('create-profile error: ' . $msg);
            $friendly = $msg;
            if (stripos($msg, 'already have') !== false || stripos($msg, 'already exists') !== false) {
                $friendly = "A profile named \"$name\" already exists.";
            } elseif (stripos($msg, 'no permission') !== false || stripos($msg, 'not allowed') !== false) {
                $friendly = "Your API user doesn't have permission to create profiles.";
            } elseif (stripos($msg, 'invalid') !== false) {
                $friendly = "Router rejected a field value: $msg";
            }
            audit('create-profile', $name, 'failed: ' . substr($msg, 0, 100));
            respond(['success' => false, 'message' => $friendly], 500);
        }
        break;

    case 'delete-profile':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'name' => 'required|string|min:1|max:40',
        ], $body);
        $name = $v['name'];

        try {
            $client = routerClient();
            $rows = $client->query(new Query('/ip/hotspot/user/profile/print'))->read();
            $target = null;
            foreach ($rows as $r) {
                if (($r['name'] ?? '') === $name) { $target = $r['.id'] ?? null; break; }
            }
            if (!$target) {
                audit('delete-profile', $name, 'not found');
                respond(['success' => false, 'message' => 'Profile not found.'], 404);
            }
            $client->query((new Query('/ip/hotspot/user/profile/remove'))->equal('.id', $target))->read();
            audit('delete-profile', $name, 'deleted');
            respond(['success' => true, 'message' => 'Profile deleted.']);
        } catch (\Throwable $e) {
            error_log('delete-profile error: ' . $e->getMessage());
            audit('delete-profile', $name, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => 'Failed to delete profile.'], 500);
        }
        break;

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
            respond(['success' => false, 'message' => 'Failed to read servers.', 'servers' => []], 200);
        }
        break;

    /* --------------------------------------------------------
     *  TOP DOMAINS
     * -------------------------------------------------------- */
    case 'top-domains':
        requireAuth();
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        try {
            require_once __DIR__ . '/logger.php';
            $client = routerClient();
            $lines = [];
            try {
                $logs = $client->query(
                    (new Query('/log/print'))->where('topics', 'dns')
                )->read();
                foreach ($logs as $entry) {
                    if (!empty($entry['message'])) $lines[] = $entry['message'];
                }
            } catch (\Throwable $e) {
                error_log('top-domains: /log read failed: ' . $e->getMessage());
            }
            try {
                $cache = $client->query(new Query('/ip/dns/cache/print'))->read();
                foreach ($cache as $entry) {
                    if (!empty($entry['name'])) {
                        $lines[] = $entry['name'] . '  ' . ($entry['data'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
                error_log('top-domains: /ip/dns/cache read failed: ' . $e->getMessage());
            }
            $domains = aggregateDomains($lines);
            $domains = array_slice($domains, 0, $limit);
            foreach ($domains as &$d) {
                $d['category'] = categorizeDomain($d['domain']);
            }
            unset($d);
            $snapshotRows = [];
            foreach ($domains as $d) {
                $snapshotRows[] = [
                    'domain'    => $d['domain'],
                    'category'  => $d['category'],
                    'hits'      => $d['hits'],
                    'subdomains'=> implode(', ', array_slice($d['subdomains'] ?? [], 0, 3)),
                ];
            }
            writeSnapshot(
                'top-domains.txt',
                ['domain', 'category', 'hits', 'subdomains'],
                $snapshotRows
            );
            respond([
                'success'   => true,
                'domains'   => $domains,
                'sampled'   => count($lines),
                'source'    => count($lines) > 0 ? 'dns-log+cache' : 'cache-only',
            ]);
        } catch (\Throwable $e) {
            error_log('top-domains error: ' . $e->getMessage());
            respond([
                'success' => false,
                'message' => 'Failed to read DNS data: ' . $e->getMessage(),
                'domains' => [],
            ], 500);
        }
        break;

    /* --------------------------------------------------------
     *  ANOMALIES
     * -------------------------------------------------------- */
    case 'anomalies':
        requireAuth();
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/active/print'))->read();
            $out    = array_map('normalizeSession', $rows);
            $byUser = [];
            foreach ($out as $s) {
                $u = (string)($s['user'] ?? '');
                if ($u === '') continue;
                if (!isset($byUser[$u])) {
                    $byUser[$u] = [
                        'user' => $u, 'macs' => [], 'ips' => [],
                        'sessions' => 0, 'bytes' => 0, 'flags' => [],
                    ];
                }
                $byUser[$u]['sessions']++;
                $byUser[$u]['bytes'] += (int)($s['bytes-in'] ?? 0) + (int)($s['bytes-out'] ?? 0);
                if (!empty($s['mac']))     $byUser[$u]['macs'][$s['mac']] = true;
                if (!empty($s['address'])) $byUser[$u]['ips'][$s['address']] = true;
            }
            $flags = [];
            foreach ($byUser as $u => $info) {
                $macCount = count($info['macs']);
                $ipCount  = count($info['ips']);
                $userFlags = [];
                if ($macCount >= 3) $userFlags[] = 'multi-mac';
                if ($ipCount  >= 3) $userFlags[] = 'multi-ip';
                if ($info['sessions'] >= 2 && $macCount >= 2) $userFlags[] = 'rapid-reconnect';
                if ($info['bytes'] >= 1024 * 1024 * 1024)     $userFlags[] = 'data-spike';
                if (!empty($userFlags)) {
                    $flags[] = [
                        'user' => $u, 'flags' => $userFlags,
                        'macs' => array_keys($info['macs']),
                        'ips'  => array_keys($info['ips']),
                        'sessions' => $info['sessions'],
                        'bytes' => $info['bytes'],
                    ];
                }
            }
            usort($flags, fn($a, $b) => count($b['flags']) <=> count($a['flags']));
            respond(['success' => true, 'anomalies' => $flags, 'scanned' => count($out)]);
        } catch (\Throwable $e) {
            error_log('anomalies error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to scan sessions.', 'anomalies' => []], 500);
        }
        break;

    /* --------------------------------------------------------
     *  TOP TALKERS
     * -------------------------------------------------------- */
    case 'top-talkers':
        requireAuth();
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 25)));
        try {
            require_once __DIR__ . '/logger.php';
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/active/print'))->read();
            $out    = array_map('normalizeSession', $rows);
            $byUser = [];
            foreach ($out as $s) {
                $u = (string)($s['user'] ?? '');
                if ($u === '') continue;
                if (!isset($byUser[$u])) {
                    $byUser[$u] = [
                        'user' => $u, 'sessions' => 0, 'bytes' => 0,
                        'uptime_sec' => 0, 'ips' => [],
                    ];
                }
                $byUser[$u]['sessions']++;
                $byUser[$u]['bytes'] += (int)($s['bytes-in'] ?? 0) + (int)($s['bytes-out'] ?? 0);
                $byUser[$u]['uptime_sec'] = max(
                    $byUser[$u]['uptime_sec'],
                    uptimeStrToSeconds((string)($s['uptime'] ?? '0s'))
                );
                if (!empty($s['address'])) $byUser[$u]['ips'][] = $s['address'];
            }
            $list = array_values($byUser);
            usort($list, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
            $list = array_slice($list, 0, $limit);
            foreach ($list as &$t) {
                $t['rate_bps'] = $t['uptime_sec'] > 0 ? (int)round($t['bytes'] / $t['uptime_sec']) : 0;
                $t['uptime_human'] = formatUptimeHuman($t['uptime_sec']);
            }
            unset($t);
            $snapshotRows = [];
            foreach ($list as $t) {
                $snapshotRows[] = [
                    'voucher' => $t['user'], 'bytes' => $t['bytes'],
                    'sessions' => $t['sessions'], 'uptime' => $t['uptime_human'],
                    'rate_bps' => $t['rate_bps'], 'ips' => count($t['ips']),
                ];
            }
            writeSnapshot(
                'top-talkers.txt',
                ['voucher', 'bytes', 'sessions', 'uptime', 'rate_bps', 'ips'],
                $snapshotRows
            );
            respond([
                'success' => true, 'talkers' => $list,
                'scanned' => count($out), 'total' => count($byUser),
            ]);
        } catch (\Throwable $e) {
            error_log('top-talkers error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to read sessions.', 'talkers' => []], 500);
        }
        break;

    /* --------------------------------------------------------
     *  BEHAVIOR FLAGS
     * -------------------------------------------------------- */
    case 'behavior-flags':
        requireAuth();
        try {
            require_once __DIR__ . '/logger.php';
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/hotspot/active/print'))->read();
            $out    = array_map('normalizeSession', $rows);
            $byUser = [];
            foreach ($out as $s) {
                $u = (string)($s['user'] ?? '');
                if ($u === '') continue;
                if (!isset($byUser[$u])) {
                    $byUser[$u] = [
                        'user' => $u, 'sessions' => 0, 'bytes' => 0,
                        'uptime_sec' => 0, 'ips' => [], 'macs' => [], 'flags' => [],
                    ];
                }
                $byUser[$u]['sessions']++;
                $byUser[$u]['bytes'] += (int)($s['bytes-in'] ?? 0) + (int)($s['bytes-out'] ?? 0);
                $byUser[$u]['uptime_sec'] = max(
                    $byUser[$u]['uptime_sec'],
                    uptimeStrToSeconds((string)($s['uptime'] ?? '0s'))
                );
                if (!empty($s['address'])) $byUser[$u]['ips'][$s['address']] = true;
                if (!empty($s['mac']))     $byUser[$u]['macs'][$s['mac']]    = true;
            }
            $flagged = [];
            foreach ($byUser as $u => $info) {
                $flags = [];
                $ipCount = count($info['ips']);
                if ($info['bytes'] >= TORRENT_LIKE_BYTES)       $flags[] = 'torrent-like';
                elseif ($info['bytes'] >= HEAVY_DOWNLOAD_BYTES) $flags[] = 'heavy-download';
                if ($ipCount >= MULTI_DEVICE_THRESHOLD)         $flags[] = 'shared-device';
                if (!empty($flags)) {
                    $flagged[] = [
                        'user' => $u, 'flags' => $flags, 'bytes' => $info['bytes'],
                        'sessions' => $info['sessions'], 'ip_count' => $ipCount,
                        'mac_count' => count($info['macs']),
                        'uptime_sec' => $info['uptime_sec'],
                        'uptime_human' => formatUptimeHuman($info['uptime_sec']),
                    ];
                }
            }
            usort($flagged, function ($a, $b) {
                $c = count($b['flags']) <=> count($a['flags']);
                return $c !== 0 ? $c : ($b['bytes'] <=> $a['bytes']);
            });
            $snapshotRows = [];
            foreach ($flagged as $f) {
                $snapshotRows[] = [
                    'voucher' => $f['user'], 'flags' => implode(',', $f['flags']),
                    'bytes' => $f['bytes'], 'sessions' => $f['sessions'],
                    'ip_count' => $f['ip_count'], 'mac_count' => $f['mac_count'],
                    'uptime' => $f['uptime_human'],
                ];
            }
            writeSnapshot(
                'behavior-flags.txt',
                ['voucher', 'flags', 'bytes', 'sessions', 'ip_count', 'mac_count', 'uptime'],
                $snapshotRows
            );
            respond([
                'success' => true, 'flagged' => $flagged,
                'scanned' => count($out), 'total' => count($byUser),
            ]);
        } catch (\Throwable $e) {
            error_log('behavior-flags error: ' . $e->getMessage());
            respond(['success' => false, 'message' => 'Failed to scan sessions.', 'flagged' => []], 500);
        }
        break;

    /* --------------------------------------------------------
     *  BLOCKLIST STATUS
     * -------------------------------------------------------- */
    case 'blocklist-status':
        requireAuth();
        require_once __DIR__ . '/logger.php';
        $manifest = loadManifest();
        $liveCounts = ['adult' => 0, 'malware' => 0, 'social' => 0];
        $routerOk = false;
        try {
            $client = routerClient();
            $rows   = $client->query(new Query('/ip/dns/static/print'))->read();
            $routerOk = true;
            foreach ($rows as $r) {
                $comment = (string)($r['comment'] ?? '');
                foreach (['adult', 'malware', 'social'] as $cat) {
                    if (commentMatchesCategory($comment, $cat)) $liveCounts[$cat]++;
                }
            }
        } catch (\Throwable $e) {
            error_log('blocklist-status: router read failed: ' . $e->getMessage());
        }
        $out = [];
        foreach (['adult', 'malware', 'social'] as $cat) {
            $entries = fetchBlocklist($cat);
            $manifestCount = (int)($manifest[$cat]['count'] ?? 0);
            $liveCount = $liveCounts[$cat];
            $appliedCount = max($manifestCount, $liveCount);
            $out[] = [
                'category' => $cat,
                'entries' => count($entries),
                'cached' => file_exists(__DIR__ . '/.cache/blocklist-' . $cat . '.txt'),
                'applied' => $appliedCount > 0,
                'appliedCount' => $appliedCount,
                'manifestCount' => $manifestCount,
                'liveCount' => $liveCount,
                'routerOk' => $routerOk,
            ];
        }
        respond(['success' => true, 'categories' => $out]);
        break;

    /* --------------------------------------------------------
     *  APPLY BLOCKLIST
     * -------------------------------------------------------- */
    case 'apply-blocklist':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'category' => 'required|string|in:adult,malware,social',
        ], $body);
        $category = $v['category'];

        try {
            require_once __DIR__ . '/logger.php';
            $entries = fetchBlocklist($category);
            if (empty($entries)) {
                respond(['success' => false, 'message' => 'Blocklist is empty.'], 500);
            }
            $entries = array_slice($entries, 0, 500);
            $client = routerClient();

            $existing = $client->query(new Query('/ip/dns/static/print'))->read();
            $removed = 0;
            foreach ($existing as $r) {
                $comment = (string)($r['comment'] ?? '');
                if (commentMatchesCategory($comment, $category) && !empty($r['.id'])) {
                    try {
                        $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $r['.id']))->read();
                        $removed++;
                    } catch (\Throwable $e) {
                        error_log("apply-blocklist: failed to remove {$r['.id']}: " . $e->getMessage());
                    }
                }
            }

            $added = 0;
            $names = [];
            foreach ($entries as $host) {
                try {
                    $client->query(
                        (new Query('/ip/dns/static/add'))
                            ->equal('name', $host)
                            ->equal('address', '0.0.0.0')
                            ->equal('comment', "C24-BLOCK:$category")
                    )->read();
                    $names[] = $host;
                    $added++;
                } catch (\Throwable $e) { continue; }
            }

            $manifest = loadManifest();
            $manifest[$category] = [
                'count'     => $added,
                'names'     => $names,
                'appliedAt' => time(),
            ];
            saveManifest($manifest);

            audit('apply-blocklist', $category, "applied=$added removed=$removed");
            respond([
                'success' => true,
                'message' => "Applied $added DNS rules for '$category' ($removed old rules removed).",
                'applied' => $added, 'removed' => $removed, 'category' => $category,
            ]);
        } catch (\Throwable $e) {
            error_log('apply-blocklist error: ' . $e->getMessage());
            audit('apply-blocklist', $category, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;

    /* --------------------------------------------------------
     *  CLEAR BLOCKLIST
     * -------------------------------------------------------- */
    case 'clear-blocklist':
        verifyCsrf();
        if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required.'], 405);

        $body = readJsonBody();
        $v = validateOrFail([
            'category' => 'required|string|in:adult,malware,social',
        ], $body);
        $category = $v['category'];

        try {
            $client = routerClient();
            $existing = $client->query(new Query('/ip/dns/static/print'))->read();

            $manifest = loadManifest();
            $manifestNames = [];
            if (!empty($manifest[$category]['names']) && is_array($manifest[$category]['names'])) {
                foreach ($manifest[$category]['names'] as $n) {
                    $manifestNames[strtolower((string)$n)] = true;
                }
            }

            $removed = 0;
            foreach ($existing as $r) {
                if (empty($r['.id'])) continue;
                $comment = (string)($r['comment'] ?? '');
                $name    = strtolower((string)($r['name'] ?? ''));
                $addr    = (string)($r['address'] ?? '');
                $byComment  = commentMatchesCategory($comment, $category);
                $byManifest = isset($manifestNames[$name]) && $addr === '0.0.0.0';
                if ($byComment || $byManifest) {
                    try {
                        $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $r['.id']))->read();
                        $removed++;
                    } catch (\Throwable $e) {
                        error_log("clear-blocklist: failed to remove {$r['.id']}: " . $e->getMessage());
                    }
                }
            }

            $manifest = loadManifest();
            unset($manifest[$category]);
            saveManifest($manifest);

            audit('clear-blocklist', $category, "removed=$removed");
            respond([
                'success' => true,
                'message' => "Removed $removed rules.",
                'removed' => $removed, 'category' => $category,
            ]);
        } catch (\Throwable $e) {
            error_log('clear-blocklist error: ' . $e->getMessage());
            audit('clear-blocklist', $category, 'failed: ' . substr($e->getMessage(), 0, 100));
            respond(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;

    /* --------------------------------------------------------
     *  AUDIT LOG
     * -------------------------------------------------------- */
    case 'audit-log':
        requireAuth();
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        respond([
            'success' => true,
            'entries' => readAuditLog($limit),
        ]);
        break;

    default:
        respond(['success' => false, 'message' => 'Unknown action.'], 404);
}