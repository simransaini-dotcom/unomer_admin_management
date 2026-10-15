<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const STORE_FILE = DATA_DIR . '/store.php';
const BACKUP_FILE = DATA_DIR . '/store.bak.php';
const ATTEMPTS_FILE = DATA_DIR . '/attempts.php';
const LOCK_FILE = DATA_DIR . '/store.lock';
const GUARD = "<?php http_response_code(403); exit; ?>\n";
const SESSION_IDLE = 7200;
const MAX_ATTEMPTS = 5;
const LOCK_SECONDS = 900;
const PALETTE_SIZE = 8;
const MAX_BODY = 2097152;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg): never
{
    respond($code, ['ok' => false, 'error' => $msg]);
}

/* ---------- Sessions & authentication ---------- */

function start_session(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('tfm_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $path,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function config(): ?array
{
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) {
        return null;
    }
    if (!is_readable($file)) {
        fail(500, 'config.php is not readable by the web server. Run: sudo chgrp www-data config.php && sudo chmod 640 config.php');
    }
    $cfg = require $file;
    return (is_array($cfg) && isset($cfg['admin_id'], $cfg['password_hash'])) ? $cfg : null;
}

function is_admin(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['admin'] ?? false) !== true) {
        return false;
    }
    if (time() - (int)($_SESSION['last'] ?? 0) > SESSION_IDLE) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['last'] = time();
    return true;
}

function require_admin(): void
{
    if (!is_admin()) {
        fail(401, 'Admin login required');
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        fail(403, 'Invalid or missing security token');
    }
}

function check_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $host = (string)parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $full = strtolower($host . ($port ? ':' . $port : ''));
    if (!hash_equals(strtolower($_SERVER['HTTP_HOST'] ?? ''), $full)) {
        fail(403, 'Cross-origin request blocked');
    }
}

function session_payload(array $state): array
{
    $admin = is_admin();
    $p = [
        'ok' => true,
        'role' => $admin ? 'admin' : 'viewer',
        'configured' => config() !== null,
        'state' => $state,
    ];
    if ($admin) {
        $p['adminId'] = $_SESSION['admin_id'];
        $p['csrf'] = $_SESSION['csrf'];
    }
    return $p;
}

/* ---------- File storage (guarded, locked, atomic) ---------- */

function locked(callable $fn)
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0770, true) && !is_dir(DATA_DIR)) {
        fail(500, 'Data folder is missing or not writable on the server');
    }
    $h = @fopen(LOCK_FILE, 'c');
    if (!$h) {
        fail(500, 'Data folder is not writable by the web server. Check its permissions.');
    }
    flock($h, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function read_json_file(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || strncmp($raw, GUARD, strlen(GUARD)) !== 0) {
        return null;
    }
    $d = json_decode(substr($raw, strlen(GUARD)), true);
    return is_array($d) ? $d : null;
}

function write_json_file(string $file, array $data): void
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (@file_put_contents($tmp, GUARD . $json) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        fail(500, 'Could not save data on the server');
    }
}

function default_categories(): array
{
    return [
        ['id' => 'cat_late', 'name' => 'Late Penalty', 'kind' => 'in', 'fixed' => true, 'amount' => 120, 'afterTime' => '09:00', 'color' => 0],
        ['id' => 'cat_meeting', 'name' => 'Meeting Penalty', 'kind' => 'in', 'fixed' => false, 'amount' => 0, 'afterTime' => '', 'color' => 1],
        ['id' => 'cat_appraisal', 'name' => 'Appraisal Contribution', 'kind' => 'in', 'fixed' => false, 'amount' => 0, 'afterTime' => '', 'color' => 2],
        ['id' => 'cat_party_in', 'name' => 'Party Contribution', 'kind' => 'in', 'fixed' => false, 'amount' => 0, 'afterTime' => '', 'color' => 3],
        ['id' => 'cat_other', 'name' => 'Other Penalty', 'kind' => 'in', 'fixed' => false, 'amount' => 0, 'afterTime' => '', 'color' => 4],
        ['id' => 'cat_party', 'name' => 'Party Expense', 'kind' => 'out', 'fixed' => false, 'amount' => 0, 'afterTime' => '', 'color' => 5],
    ];
}

function load_store(): array
{
    if (!is_file(STORE_FILE)) {
        return ['employees' => [], 'categories' => default_categories(), 'entries' => []];
    }
    $d = read_json_file(STORE_FILE);
    if ($d === null) {
        fail(500, 'Data file is unreadable. Restore data/store.bak.php if needed.');
    }
    foreach (['employees', 'categories', 'entries'] as $k) {
        if (!isset($d[$k]) || !is_array($d[$k])) {
            $d[$k] = [];
        }
    }
    return $d;
}

function save_store(array $s): void
{
    if (is_file(STORE_FILE)) {
        @copy(STORE_FILE, BACKUP_FILE);
    }
    write_json_file(STORE_FILE, [
        'employees' => array_values($s['employees']),
        'categories' => array_values($s['categories']),
        'entries' => array_values($s['entries']),
    ]);
}

/* ---------- Login throttling ---------- */

function attempts_check(string $ip): void
{
    $a = read_json_file(ATTEMPTS_FILE) ?? [];
    $until = (int)($a[$ip]['lockUntil'] ?? 0);
    if ($until > time()) {
        fail(429, 'Too many failed attempts. Try again in ' . (int)ceil(($until - time()) / 60) . ' minute(s).');
    }
}

function attempts_record(string $ip, bool $ok): void
{
    locked(function () use ($ip, $ok) {
        $a = read_json_file(ATTEMPTS_FILE) ?? [];
        $now = time();
        foreach ($a as $k => $v) {
            if ((int)($v['lockUntil'] ?? 0) < $now && (int)($v['first'] ?? 0) < $now - LOCK_SECONDS) {
                unset($a[$k]);
            }
        }
        if ($ok) {
            unset($a[$ip]);
        } else {
            $r = $a[$ip] ?? ['count' => 0, 'first' => $now, 'lockUntil' => 0];
            if ((int)$r['lockUntil'] < $now && (int)$r['first'] < $now - LOCK_SECONDS) {
                $r = ['count' => 0, 'first' => $now, 'lockUntil' => 0];
            }
            $r['count']++;
            if ($r['count'] >= MAX_ATTEMPTS) {
                $r = ['count' => 0, 'first' => $now, 'lockUntil' => $now + LOCK_SECONDS];
            }
            $a[$ip] = $r;
        }
        write_json_file(ATTEMPTS_FILE, $a);
    });
}

/* ---------- Validation helpers ---------- */

function new_id(): string
{
    return bin2hex(random_bytes(6));
}

function valid_id($v): bool
{
    return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) === 1;
}

function clean_text($v, int $max, bool $required = true): string
{
    $v = is_scalar($v) ? (string)$v : '';
    $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
    if ($required && $v === '') {
        fail(400, 'A required field is empty');
    }
    if (mb_strlen($v) > $max) {
        fail(400, "Text is too long (max $max characters)");
    }
    return $v;
}

function clean_date($v): string
{
    if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $v;
    }
    fail(400, 'Invalid date');
}

function clean_time($v): string
{
    if ($v === '' || $v === null) {
        return '';
    }
    if (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
        return $v;
    }
    fail(400, 'Invalid time');
}

function clean_amount($v): int
{
    if (is_string($v) && preg_match('/^\d+$/', $v)) {
        $v = (int)$v;
    }
    if (is_float($v) && floor($v) === $v) {
        $v = (int)$v;
    }
    if (!is_int($v) || $v < 1 || $v > 10000000) {
        fail(400, 'Amount must be a whole number between 1 and 10,000,000');
    }
    return $v;
}

function find_index(array $list, $id): int
{
    foreach ($list as $i => $item) {
        if (($item['id'] ?? null) === $id) {
            return (int)$i;
        }
    }
    return -1;
}

function read_body(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        fail(415, 'Content-Type must be application/json');
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if ($raw === false || strlen($raw) > MAX_BODY) {
        fail(413, 'Request is too large');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        fail(400, 'Invalid JSON body');
    }
    return $body;
}

/* ---------- Request handling ---------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (isset($_COOKIE['tfm_sid'])) {
    start_session();
}

if ($method === 'GET') {
    if (($_GET['action'] ?? 'state') !== 'state') {
        fail(405, 'Use POST for this action');
    }
    respond(200, session_payload(load_store()));
}

if ($method !== 'POST') {
    fail(405, 'Method not allowed');
}

check_origin();
$body = read_body();
$action = is_string($body['action'] ?? null) ? $body['action'] : '';

if ($action === 'login') {
    $cfg = config();
    if ($cfg === null) {
        fail(503, 'Admin account is not set up yet. Run "php setup_admin.php" on the server.');
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    attempts_check($ip);
    $id = is_string($body['id'] ?? null) ? trim($body['id']) : '';
    $pw = is_string($body['password'] ?? null) ? $body['password'] : '';
    $okPw = password_verify($pw, (string)$cfg['password_hash']);
    $okId = hash_equals((string)$cfg['admin_id'], $id);
    if (!($okId && $okPw)) {
        attempts_record($ip, false);
        usleep(400000);
        fail(401, 'Invalid ID or password');
    }
    attempts_record($ip, true);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_session();
    }
    session_regenerate_id(true);
    $_SESSION = [
        'admin' => true,
        'admin_id' => (string)$cfg['admin_id'],
        'csrf' => bin2hex(random_bytes(32)),
        'last' => time(),
    ];
    respond(200, session_payload(load_store()));
}

if ($action === 'logout') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $p['path'],
            'secure' => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
    respond(200, session_payload(load_store()));
}

/* Everything below modifies data: admin only, with CSRF protection. */
require_admin();

$result = locked(function () use ($action, $body) {
    $s = load_store();

    switch ($action) {
        case 'employee_add':
            $name = clean_text($body['name'] ?? '', 60);
            foreach ($s['employees'] as $e) {
                if (mb_strtolower($e['name']) === mb_strtolower($name)) {
                    fail(409, 'Employee already exists');
                }
            }
            $s['employees'][] = ['id' => new_id(), 'name' => $name];
            break;

        case 'employee_remove':
            if (find_index($s['employees'], $body['id'] ?? null) < 0) {
                fail(404, 'Employee not found');
            }
            $id = $body['id'];
            $s['employees'] = array_values(array_filter($s['employees'], fn($e) => $e['id'] !== $id));
            $s['entries'] = array_values(array_filter($s['entries'], fn($e) => $e['empId'] !== $id));
            break;

        case 'category_save':
            $id = $body['id'] ?? null;
            $name = clean_text($body['name'] ?? '', 40);
            $fixed = ($body['fixed'] ?? false) === true;
            $amount = $fixed ? clean_amount($body['amount'] ?? 0) : 0;
            foreach ($s['categories'] as $c) {
                if ($c['id'] !== $id && mb_strtolower($c['name']) === mb_strtolower($name)) {
                    fail(409, 'A category with this name already exists');
                }
            }
            if (is_string($id) && $id !== '') {
                $i = find_index($s['categories'], $id);
                if ($i < 0) {
                    fail(404, 'Category not found');
                }
                $kind = $s['categories'][$i]['kind'];
                $s['categories'][$i]['name'] = $name;
                $s['categories'][$i]['fixed'] = $fixed;
                $s['categories'][$i]['amount'] = $amount;
                $s['categories'][$i]['afterTime'] = $kind === 'in' ? clean_time($body['afterTime'] ?? '') : '';
            } else {
                $kind = $body['kind'] ?? '';
                if (!in_array($kind, ['in', 'out'], true)) {
                    fail(400, 'Invalid category type');
                }
                $s['categories'][] = [
                    'id' => new_id(),
                    'name' => $name,
                    'kind' => $kind,
                    'fixed' => $fixed,
                    'amount' => $amount,
                    'afterTime' => $kind === 'in' ? clean_time($body['afterTime'] ?? '') : '',
                    'color' => count($s['categories']) % PALETTE_SIZE,
                ];
            }
            break;

        case 'category_delete':
            $id = $body['id'] ?? null;
            if (find_index($s['categories'], $id) < 0) {
                fail(404, 'Category not found');
            }
            foreach ($s['entries'] as $e) {
                if ($e['catId'] === $id) {
                    fail(409, 'Transactions use this category. Delete them first.');
                }
            }
            $s['categories'] = array_values(array_filter($s['categories'], fn($c) => $c['id'] !== $id));
            break;

        case 'entry_add':
            $ci = find_index($s['categories'], $body['catId'] ?? null);
            if ($ci < 0) {
                fail(400, 'Unknown category');
            }
            if (find_index($s['employees'], $body['empId'] ?? null) < 0) {
                fail(400, 'Unknown employee');
            }
            $cat = $s['categories'][$ci];
            $date = clean_date($body['date'] ?? null);
            $amount = !empty($cat['fixed']) ? (int)$cat['amount'] : clean_amount($body['amount'] ?? null);
            $time = '';
            if ($cat['afterTime'] !== '') {
                $time = clean_time($body['time'] ?? '');
                if ($time === '' || strcmp($time, $cat['afterTime']) <= 0) {
                    fail(400, 'Arrival time must be after ' . $cat['afterTime']);
                }
            }
            $s['entries'][] = [
                'id' => new_id(),
                'catId' => $cat['id'],
                'kind' => $cat['kind'],
                'empId' => $body['empId'],
                'date' => $date,
                'time' => $time,
                'amount' => $amount,
                'note' => clean_text($body['note'] ?? '', 200, false),
                'created' => (int)round(microtime(true) * 1000),
            ];
            break;

        case 'entry_delete':
            $id = $body['id'] ?? null;
            if (find_index($s['entries'], $id) < 0) {
                fail(404, 'Record not found');
            }
            $s['entries'] = array_values(array_filter($s['entries'], fn($e) => $e['id'] !== $id));
            break;

        case 'import':
            if (count($s['employees']) > 0 || count($s['entries']) > 0) {
                fail(409, 'The server already has data. Import is only allowed into an empty store.');
            }
            $emps = [];
            foreach (array_slice((array)($body['employees'] ?? []), 0, 500) as $e) {
                if (is_array($e) && valid_id($e['id'] ?? null)) {
                    $emps[$e['id']] = ['id' => $e['id'], 'name' => clean_text($e['name'] ?? '', 60)];
                }
            }
            $cats = [];
            foreach (array_slice((array)($body['categories'] ?? []), 0, 100) as $c) {
                if (!is_array($c) || !valid_id($c['id'] ?? null) || !in_array($c['kind'] ?? '', ['in', 'out'], true)) {
                    continue;
                }
                $fx = ($c['fixed'] ?? false) === true;
                $cats[$c['id']] = [
                    'id' => $c['id'],
                    'name' => clean_text($c['name'] ?? '', 40),
                    'kind' => $c['kind'],
                    'fixed' => $fx,
                    'amount' => $fx ? clean_amount($c['amount'] ?? 0) : 0,
                    'afterTime' => $c['kind'] === 'in' ? clean_time($c['afterTime'] ?? '') : '',
                    'color' => ((int)($c['color'] ?? 0)) % PALETTE_SIZE,
                ];
            }
            if ($cats) {
                $s['categories'] = array_values($cats);
            } else {
                foreach ($s['categories'] as $c) {
                    $cats[$c['id']] = $c;
                }
            }
            $entries = [];
            foreach (array_slice((array)($body['entries'] ?? []), 0, 5000) as $e) {
                if (!is_array($e) || !isset($cats[$e['catId'] ?? ''], $emps[$e['empId'] ?? ''])) {
                    continue;
                }
                $entries[] = [
                    'id' => valid_id($e['id'] ?? null) ? $e['id'] : new_id(),
                    'catId' => $e['catId'],
                    'kind' => $cats[$e['catId']]['kind'],
                    'empId' => $e['empId'],
                    'date' => clean_date($e['date'] ?? null),
                    'time' => clean_time($e['time'] ?? ''),
                    'amount' => clean_amount($e['amount'] ?? null),
                    'note' => clean_text($e['note'] ?? '', 200, false),
                    'created' => (int)($e['created'] ?? 0),
                ];
            }
            $s['employees'] = array_values($emps);
            $s['entries'] = $entries;
            break;

        default:
            fail(400, 'Unknown action');
    }

    save_store($s);
    return $s;
});

respond(200, session_payload($result));
