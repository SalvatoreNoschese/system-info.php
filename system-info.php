<?php
declare(strict_types=1);
/**
 * system-info.php — single-file PHP server dashboard.
 *
 * Drop it next to your application, open it, read the report, then delete it.
 * No dependencies, no writes outside the temp directory, no outbound traffic.
 *
 * Requires PHP 8.0 or newer; older versions get a message, not a parse error.
 *
 * @Author: Salvatore Noschese (https://salvatorenoschese.it)
 * @Donate: https://donate.salvatorenoschese.it/
 * @License: MIT
 */

/** Version and home, printed in the footer and on every refusal screen. */
const APP_VERSION  = '1.0.0';
const APP_HOMEPAGE = 'https://github.com/SalvatoreNoschese/system-info.php';

/**
 * The two settings worth your attention; the rest is further down.
 *
 * ACCESS_PIN — the whole query string: system-info.php?12345
 *   '' refuses to serve, '0' opens to anyone with the URL, anything else is
 *   digits only, five or more, arbitrary: not 12345, not your door code.
 *
 * PIN_MEMORY_MINUTES — minutes a tab may reuse its PIN across reloads,
 *   counted from the last load. 0 asks every time.
 */
const ACCESS_PIN = '';
const PIN_MEMORY_MINUTES = 60;

// ---- advanced settings (rarely need to change) ----

/**
 * Filesystem paths to report disk usage for, one meter each. On a VPS or a
 * local machine, add the mounts you actually care about:
 *
 *     const DISK_PATHS = [__DIR__, '/var/log', '/mnt/backups'];
 *
 * On shared hosting there is no point: every path you name answers for the
 * same provider volume.
 */
const DISK_PATHS = [__DIR__];

/** Seconds between live metric updates. 0 turns the polling off entirely. */
const REFRESH_SECONDS = 5;

/**
 * Look up the nameservers of the domain this page is served on. This is the
 * only query the file sends off the machine, and the only one that can block:
 * a host that filters outbound DNS makes the resolver wait for its own
 * timeout. Set it to false there — the report loses one row and nothing else.
 */
const DNS_LOOKUPS = true;

/**
 * Failed-PIN throttling, per client IP. 0 switches it off and the script then
 * writes nothing at all — but a five-digit PIN becomes guessable in minutes.
 */
const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_SECONDS = 300;

/**
 * Full path to the failed-attempt counter. Empty means one file in the system
 * temp directory, where PHP already puts uploads and the machine cleans up on
 * its own schedule. Nothing is written to the web root.
 */
const RATE_LIMIT_PATH = '';

/**
 * Extra cache servers, on top of the ones found automatically. Any of these
 * forms works and the type is detected from the answer: '127.0.0.1:6379',
 * '/tmp/redis.sock', 'unix:/tmp/redis.sock', '/tmp/redis.sock:0'.
 */
const REDIS_SERVERS = [];
const MEMCACHED_SERVERS = [];

/** Sweep the well-known addresses below before giving up. */
const CACHE_AUTODETECT = true;

/**
 * Where Redis, Memcached and LSMCD normally listen. A missing socket file or a
 * closed local port fails instantly, so the sweep costs nothing when empty.
 */
const CACHE_CANDIDATES = [
    '127.0.0.1:6379',
    '127.0.0.1:6380',
    '127.0.0.1:11211',
    '[::1]:6379',
    '[::1]:11211',
    '/tmp/redis.sock',
    '/tmp/redis.socket',
    '/var/run/redis/redis.sock',
    '/var/run/redis/redis-server.sock',
    '/run/redis/redis.sock',
    '/run/redis/redis-server.sock',
    '/var/run/redis-server.sock',
    '/var/run/redis.sock',
    '/tmp/memcached.sock',
    '/var/run/memcached/memcached.sock',
    '/run/memcached/memcached.sock',
    '/tmp/lsmcd.sock',
    '/tmp/lsmcd/lsmcd.sock',
    '/var/run/lsmcd.sock',
];

/** Connect and read timeout for those probes, in seconds. */
const PROBE_TIMEOUT_SECONDS = 0.3;

/** PHP end-of-life dates, used by the version check. */
const EOL_TABLE_UPDATED = '2026-08-01';
const EOL_DATES = [
    '8.0' => '2023-11-26',
    '8.1' => '2025-12-31',
    '8.2' => '2026-12-31',
    '8.3' => '2027-12-31',
    '8.4' => '2028-12-31',
    '8.5' => '2029-12-31',
];

/** Extensions worth calling out, grouped the way you actually think about them. */
const EXTENSION_GROUPS = [
    'Runtime' => ['json', 'mbstring', 'iconv', 'intl', 'ctype', 'filter', 'session', 'pcre', 'spl'],
    'Database' => ['pdo', 'pdo_mysql', 'mysqli', 'pdo_pgsql', 'pgsql', 'pdo_sqlite', 'sqlite3', 'pdo_odbc'],
    'Cache' => ['opcache', 'apcu', 'redis', 'relay', 'memcached', 'memcache', 'igbinary', 'msgpack'],
    'Crypto' => ['openssl', 'sodium', 'hash', 'bcmath', 'gmp'],
    'Files & media' => ['fileinfo', 'zip', 'zlib', 'gd', 'imagick', 'exif'],
    'Network' => ['curl', 'sockets', 'ftp', 'ssh2', 'ldap', 'imap', 'soap', 'xml', 'dom', 'simplexml'],
    'System' => ['posix', 'pcntl', 'shmop', 'sysvshm', 'xdebug'],
];

/** Check PHP version (min: 8.0) */
if (PHP_VERSION_ID < 80000) {
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'system-info.php requires PHP 8.0 or newer. This server runs ' . PHP_VERSION . ".\n";
    exit;
}

/**
 * DOCUMENT_ROOT is not always reliable (some hosts, e.g. Altervista, leave it
 * empty or pointing somewhere unexpected). Correct it here, once, from
 * SCRIPT_FILENAME and SCRIPT_NAME — every check below still just reads
 * $_SERVER['DOCUMENT_ROOT'] as normal and gets the fixed value automatically.
 *
 * The derived value is only used when the one the server gave us is unusable:
 * empty, gone, or not an ancestor of this file. A working DOCUMENT_ROOT is
 * never overwritten — on an Alias, a userdir or a symlinked root the URL path
 * is not a suffix of the filesystem path, and guessing would be worse than
 * the value already there.
 */
$givenRoot = isset($_SERVER['DOCUMENT_ROOT'])
    ? rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/')
    : '';
$rootIsUsable = $givenRoot !== '' && @is_dir($givenRoot)
    && strpos(str_replace('\\', '/', __FILE__), $givenRoot . '/') === 0;

if ($rootIsUsable) {
    $_SERVER['DOCUMENT_ROOT'] = $givenRoot; // normalised: forward slashes, no trailing one
} elseif (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg'
    && isset($_SERVER['SCRIPT_FILENAME'], $_SERVER['SCRIPT_NAME'])) {
    $scriptFilename = str_replace('\\', '/', (string) $_SERVER['SCRIPT_FILENAME']);
    $scriptName = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    if ($scriptName !== '' && $scriptName[0] === '/'
        && strlen($scriptName) < strlen($scriptFilename)
        && substr($scriptFilename, -strlen($scriptName)) === $scriptName) {
        $derivedRoot = substr($scriptFilename, 0, -strlen($scriptName));
        if (@is_dir($derivedRoot)) {
            $_SERVER['DOCUMENT_ROOT'] = $derivedRoot;
        }
    }
    unset($scriptFilename, $scriptName, $derivedRoot);
}
unset($givenRoot, $rootIsUsable);

// ---- request context ----

$IS_CLI = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

/**
 * REMOTE_ADDR, unconditionally. This file makes no security decisions off
 * the visitor's IP beyond keying its own PIN attempt counter, and never sets
 * a cookie or opens a session — so there is nothing here that blindly
 * trusting X-Forwarded-For would actually gain you, and no way for this
 * single file to safely verify a request came through a given proxy rather
 * than directly. client_ip_state() reports what the forwarded chain implies,
 * for the checks below, without ever acting on it.
 */
function client_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
}

/**
 * Is REMOTE_ADDR actually the visitor, or a proxy standing in for them?
 * Some hosts rewrite REMOTE_ADDR to the real client before PHP ever runs
 * (common on shared hosting sitting behind Cloudflare or similar) — there,
 * REMOTE_ADDR already agrees with the forwarded chain's client entry.
 * Purely informational: the checks below report this, they don't act on it.
 * Returns:
 *   'resolved'    — X-Forwarded-For is present and matches REMOTE_ADDR
 *   'unresolved'  — X-Forwarded-For is present and differs from REMOTE_ADDR
 *   'unknown'     — no forwarded chain to compare against, or it's malformed
 */
function client_ip_state(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($remote === '' || $fwd === '') {
        return 'unknown';
    }
    $client = trim(explode(',', $fwd)[0]);
    if ($client === '' || filter_var($client, FILTER_VALIDATE_IP) === false) {
        return 'unknown';
    }
    return $client === $remote ? 'resolved' : 'unresolved';
}

/**
 * The PIN is the first query-string segment when it carries no "=" sign, so
 * "?12345" is a PIN and "?live=1" is not.
 */
function submitted_pin(): string
{
    $query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    if ($query === '') {
        return '';
    }
    $first = explode('&', $query)[0];
    if ($first === '' || strpos($first, '=') !== false) {
        return '';
    }
    return urldecode($first);
}

/** 'unset' until configured, 'open' when explicitly disabled, otherwise 'set' */
function pin_mode(): string
{
    if (ACCESS_PIN === '') {
        return 'unset';
    }
    if (ACCESS_PIN === '0') {
        return 'open';
    }
    return 'set';
}

function wants_live_payload(): bool
{
    return isset($_GET['live']);
}

// ---- rate limiting ----

/** The counter is a PHP file: requested over the web, it runs and prints nothing */
const RATE_LIMIT_STUB = "<?php exit; ?>";

function rate_limit_path(): ?string
{
    if (RATE_LIMIT_PATH !== '') {
        return RATE_LIMIT_PATH;
    }
    // Same directory PHP already uses for uploads and scratch files, with a name
    // derived from this file's own path so two installations never collide.
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'system-info-' . substr(hash('sha256', __FILE__), 0, 16) . '.php';
}

/** Runs $mutator against the attempt table under an exclusive lock */
function rate_limit_transaction(callable $mutator)
{
    $path = rate_limit_path();
    if ($path === null || @is_link($path)) {
        // A symlink here is somebody aiming our writes at a file of ours —
        // their own webroot's index.php, say. Never follow it.
        return null;
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }
    @chmod($path, 0600);
    // Shared temp directories: if this path was somebody else's file, or a
    // symlink into one, it is not ours to write to.
    if (function_exists('posix_geteuid')) {
        $stat = fstat($handle);
        if (is_array($stat) && isset($stat['uid']) && $stat['uid'] !== posix_geteuid()) {
            fclose($handle);
            return null;
        }
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    $raw = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 8192);
        if ($chunk === false) {
            break;
        }
        $raw .= $chunk;
    }
    // Everything up to the first newline is the PHP stub, not data.
    $newline = strpos($raw, "\n");
    $json = $newline === false ? $raw : substr($raw, $newline + 1);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $data = [];
    }

    $now = time();
    foreach ($data as $key => $entry) {
        if (!is_array($entry) || !isset($entry['first'], $entry['count'])
            || ($now - (int) $entry['first']) >= RATE_LIMIT_WINDOW_SECONDS) {
            unset($data[$key]);
        }
    }

    $pair = $mutator($data);
    $data = $pair[0];
    $return = $pair[1];

    $encoded = json_encode($data);
    if ($encoded !== false) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, RATE_LIMIT_STUB . "\n" . $encoded);
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $return;
}

/**
 * True when the counter path exists but is not usable: taken by another
 * account, or a symlink someone left there. Read-only, no writes.
 */
function rate_limit_conflict(): bool
{
    $path = rate_limit_path();
    if ($path === null) {
        return true;
    }
    if (@is_link($path)) {
        return true;
    }
    if (!@is_file($path)) {
        return false;
    }
    if (!@is_writable($path)) {
        return true;
    }
    if (function_exists('posix_geteuid')) {
        $owner = @fileowner($path);
        if ($owner !== false && $owner !== posix_geteuid()) {
            return true;
        }
    }
    return false;
}

/** Client IPs are stored hashed: the counter needs identity, not addresses */
function rate_limit_key(): string
{
    return substr(hash('sha256', client_ip() . '|' . __FILE__), 0, 24);
}

function rate_limit_enabled(): bool
{
    return RATE_LIMIT_MAX_ATTEMPTS > 0;
}

function rate_limit_attempts_exist(): bool
{
    $path = rate_limit_path();
    return $path !== null && is_file($path);
}

function rate_limit_is_locked(): bool
{
    if (!rate_limit_enabled() || !rate_limit_attempts_exist()) {
        return false;
    }
    $key = rate_limit_key();
    return (bool) rate_limit_transaction(static function (array $data) use ($key) {
        $entry = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : null;
        $locked = $entry !== null && (int) $entry['count'] >= RATE_LIMIT_MAX_ATTEMPTS;
        return [$data, $locked];
    });
}

function rate_limit_register_failure(): void
{
    if (!rate_limit_enabled()) {
        return;
    }
    $key = rate_limit_key();
    rate_limit_transaction(static function (array $data) use ($key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $data[$key]['count'] = (int) $data[$key]['count'] + 1;
        } else {
            $data[$key] = ['count' => 1, 'first' => time()];
        }
        return [$data, null];
    });
}

function rate_limit_clear(): void
{
    if (!rate_limit_enabled() || !rate_limit_attempts_exist()) {
        return;
    }
    $key = rate_limit_key();
    rate_limit_transaction(static function (array $data) use ($key) {
        unset($data[$key]);
        return [$data, null];
    });
}

// ---- response helpers ----

function send_hardening_headers(string $nonce = ''): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    // Inline style attributes drive the meters; scripts stay on the nonce.
    $csp = "default-src 'none'; style-src 'unsafe-inline'; connect-src 'self'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
    if ($nonce !== '') {
        $csp .= "; script-src 'nonce-" . $nonce . "'";
    }
    header('Content-Security-Policy: ' . $csp);
}

function request_nonce(): string
{
    try {
        return base64_encode(random_bytes(16));
    } catch (Throwable $exception) {
        return base64_encode(hash('sha256', uniqid('', true), true));
    }
}

/**
 * Says no and stops, with a screen that admits the page exists and refuses.
 * Hiding behind a 404 is tempting, but a bare one looks nothing like the 404
 * the rest of the site serves and gives the file away anyway.
 */
function send_denial(int $status, string $heading, string $message, bool $allowRetry): void
{
    if (headers_sent()) {
        exit;
    }

    $nonce = request_nonce();
    send_hardening_headers($nonce);
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($status);

    $safeHeading = h($heading);
    $safeMessage = '';
    foreach (preg_split('/\n+/', $message) as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $safeMessage .= '<p>' . h($paragraph) . '</p>';
        }
    }
    $safeNonce = h($nonce);
    $safeStatus = (string) $status;
    $safeHome = h(APP_HOMEPAGE);
    $safeVersion = h(APP_VERSION);

    $hint = '';
    if ($allowRetry && rate_limit_enabled()) {
        $hint = '<p class="hint">Attempts from this address are counted, and stop being '
            . 'accepted after ' . (int) RATE_LIMIT_MAX_ATTEMPTS . '.</p>';
    }

    $box = '';
    $script = '';
    if ($allowRetry) {
        $box = '<div class="row">'
            // A text field hidden by CSS rather than type=password, which password
            // managers treat as a credential to offer, save and fill. The script
            // below falls back to type=password if the browser lacks text-security.
            . '<input id="pin" type="text" class="masked" inputmode="numeric" pattern="[0-9]*" '
            . 'maxlength="16" autocomplete="off" spellcheck="false" aria-label="Access PIN" '
            . 'placeholder="PIN">'
            . '<button type="button" id="go">Open</button></div>';
        $script = '<script nonce="' . $safeNonce . '">'
            . '(function(){var i=document.getElementById("pin"),b=document.getElementById("go");'
            // Older engines ignore text-security and would show the PIN in clear.
            . 'if(!(window.CSS&&CSS.supports&&(CSS.supports("-webkit-text-security","disc")'
            . '||CSS.supports("text-security","disc")))){i.type="password";}'
            // One stored PIN, spent on one attempt: cleared before it is used, so a
            // stale one cannot bounce the browser between here and a 403 forever.
            // A stored PIN is only this tab's if the marker in window.name matches
            // the one it was filed under. A tab that merely inherited a copy of the
            // storage has no marker, and gets asked like anyone else.
            . 'var k="system-info-pin:"+location.pathname,saved=null,'
            . 'tag=String(window.name||"").indexOf("system-info:")===0'
            . '?String(window.name).slice(12):"";'
            . 'try{saved=sessionStorage.getItem(k);sessionStorage.removeItem(k);}catch(e){}'
            . 'var parts=saved?saved.split(":"):[];'
            // Right tab, still in date: marker, deadline, PIN, in that order.
            // replace(), not assignment: an attempt should not leave a history entry
            // with the PIN in it for the back button to hand out.
            . 'if(tag&&parts.length===3&&parts[0]===tag&&Number(parts[1])>Date.now()){'
            . 'location.replace(location.pathname+"?"+parts[2]);return;}'
            // A wrong PIN is still a PIN: take it out of the address bar too.
            . 'if(location.search){try{history.replaceState(null,"",location.pathname);}catch(e){}}'
            . 'i.addEventListener("input",function(){i.value=i.value.replace(/\D/g,"");});'
            // Older browsers do not resize for the keyboard: scroll the field up ourselves.
            . 'function reveal(){setTimeout(function(){i.scrollIntoView({block:"center"});},250);}'
            . 'i.addEventListener("focus",reveal);'
            . 'if(window.visualViewport){window.visualViewport.addEventListener("resize",reveal);}'
            . 'function go(){var v=i.value.replace(/\D/g,"");'
            . 'if(v){location.replace(location.pathname+"?"+v);}}'
            . 'b.addEventListener("click",go);'
            . 'i.addEventListener("keydown",function(e){if(e.key==="Enter"){go();}});'
            . 'i.focus();})();</script>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
<meta name="robots" content="noindex, nofollow">
<title>{$safeHeading}</title>
<style>
:root {color-scheme: light dark; --bg: #eef1f5; --card: #fff; --border: #dbe0e7; --text: #171a1f; --muted: #646c78; --accent: #0069a8;}
@media (prefers-color-scheme: dark) {:root {--bg: #0e1116; --card: #171b21; --border: #272c34; --text: #e5e8ec; --muted: #9aa2ad; --accent: #4fb0e6;}}
* {box-sizing: border-box;}
body {margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; background: var(--bg); color: var(--text); line-height: 1.5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;}
.panel {background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 26px 24px; max-width: 420px; width: 100%;}
h1 {margin: 0 0 10px; font-size: 1.2rem;}
.code {font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .85rem; font-weight: 400; color: var(--muted);}
p {margin: 0 0 8px; color: var(--muted); font-size: .92rem;}
p:last-of-type {margin-bottom: 0;}
.hint {margin-top: 12px; font-size: .8rem;}
.src {margin: 20px 0 0; font-size: .75rem;}
a {color: var(--accent);}
.row {display: flex; gap: 8px; margin-top: 18px;}
.row > * {height: 46px; padding: 0 14px; margin: 0; font: inherit; font-size: .95rem; line-height: 1; border-width: 1px; border-style: solid; border-radius: 8px;}
input {flex: 1; min-width: 0; color: var(--text); background: transparent; border-color: var(--border);}
.masked {-webkit-text-security: disc; text-security: disc; letter-spacing: .15em;}
/* Focus is shown on the border, not as a ring outside it: an offset outline
   adds 8px around the focused field and makes it look taller than the button. */
.row > *:focus-visible {outline: none; border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent);}
button {flex: none; cursor: pointer; color: #fff; background: var(--accent); border-color: var(--accent);}
</style>
</head>
<body>
<div class="panel">
    <h1>{$safeHeading} <span class="code">({$safeStatus})</span></h1>
    {$safeMessage}
    {$box}
    {$hint}
    <p class="src"><a href="{$safeHome}" target="_blank" rel="noopener noreferrer">system-info.php v{$safeVersion}</a></p>
</div>
{$script}
</body>
</html>

HTML;
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---- formatting ----

function human_bytes(?float $bytes): string
{
    if ($bytes === null || $bytes < 0) {
        return 'n/a';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i >= 2 ? 1 : 0, '.', '') . ' ' . $units[$i];
}

function human_duration(?float $seconds): string
{
    if ($seconds === null || $seconds < 0) {
        return 'n/a';
    }
    $total = (int) $seconds;
    $days = intdiv($total, 86400);
    $hours = intdiv($total % 86400, 3600);
    $minutes = intdiv($total % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $hours . 'h ' . $minutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
}

function ini_to_bytes(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if ($value === '-1') {
        return -1.0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($unit === 'g') {
        return $number * 1024 * 1024 * 1024;
    }
    if ($unit === 'm') {
        return $number * 1024 * 1024;
    }
    if ($unit === 'k') {
        return $number * 1024;
    }
    return $number;
}

function percent(?float $part, ?float $total): ?float
{
    if ($part === null || $total === null || $total <= 0) {
        return null;
    }
    return round(($part / $total) * 100, 1);
}

/**
 * Where a directive can be set: PHP_INI_SYSTEM ones are ignored in .user.ini
 * and .htaccess, which is why editing them there changes nothing.
 */
function ini_scope_hint(string $key): string
{
    static $access = null;
    if ($access === null) {
        $access = [];
        $all = @ini_get_all(null, true);
        if (is_array($all)) {
            foreach ($all as $name => $entry) {
                if (isset($entry['access'])) {
                    $access[$name] = (int) $entry['access'];
                }
            }
        }
    }
    if (!isset($access[$key])) {
        return '';
    }
    if (($access[$key] & 1) === 1) {
        return ' Changeable anywhere, including .user.ini and at runtime.';
    }
    if (($access[$key] & 2) === 2) {
        return ' Set it in .user.ini or .htaccess; ini_set() will not work.';
    }
    return ' Server-level only: it has to go in the php.ini your host actually loads,'
        . ' or the FPM pool. A per-directory .user.ini or .htaccess is ignored for it.';
}

function ini_flag(string $key): bool
{
    return filter_var(ini_get($key), FILTER_VALIDATE_BOOLEAN);
}

function ini_display(string $key, string $fallback = 'not set'): string
{
    $value = ini_get($key);
    return ($value === false || $value === '') ? $fallback : (string) $value;
}

/** Headers a proxy uses to say it terminated TLS on the visitor's behalf. */
function forwarded_https_headers(): array
{
    $found = [];

    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        if (strtolower(trim($parts[0])) === 'https') {
            $found[] = 'x-forwarded-proto';
        }
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $found[] = 'x-forwarded-ssl';
    }
    if (isset($_SERVER['HTTP_FRONT_END_HTTPS'])
        && strtolower((string) $_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') {
        $found[] = 'front-end-https';
    }
    if (isset($_SERVER['HTTP_X_URL_SCHEME'])
        && strtolower((string) $_SERVER['HTTP_X_URL_SCHEME']) === 'https') {
        $found[] = 'x-url-scheme';
    }
    if (isset($_SERVER['HTTP_CF_VISITOR'])
        && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
        $found[] = 'cf-visitor';
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])
        && (string) $_SERVER['HTTP_X_FORWARDED_PORT'] === '443') {
        $found[] = 'x-forwarded-port';
    }

    return $found;
}

/**
 * 'direct' TLS ends here; 'forwarded' a proxy in front says the visitor is on
 * HTTPS and this hop is not; 'no' nothing suggests encryption anywhere.
 */
function https_state(): string
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return 'direct';
    }
    if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return 'direct';
    }
    if (isset($_SERVER['REQUEST_SCHEME'])
        && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
        return 'direct';
    }
    return forwarded_https_headers() === [] ? 'no' : 'forwarded';
}


function https_label(): string
{
    $state = https_state();
    if ($state === 'direct') {
        return 'yes';
    }
    if ($state === 'forwarded') {
        return 'yes for the visitor, terminated at a proxy in front';
    }
    return 'no';
}

// ---- host collectors ----

function read_meminfo(): ?array
{
    if (!@is_readable('/proc/meminfo')) {
        return null;
    }
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }
    $info = [];
    foreach ($lines as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s*kB$/', $line, $match)) {
            $info[$match[1]] = (float) $match[2] * 1024;
        }
    }
    return $info !== [] ? $info : null;
}

function read_uptime(): ?float
{
    if (!@is_readable('/proc/uptime')) {
        return null;
    }
    $raw = @file_get_contents('/proc/uptime');
    if ($raw === false) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($raw));
    return isset($parts[0]) ? (float) $parts[0] : null;
}

function read_cpu(): array
{
    $result = ['cores' => null, 'model' => null];
    if (!@is_readable('/proc/cpuinfo')) {
        return $result;
    }
    $raw = @file_get_contents('/proc/cpuinfo');
    if ($raw === false) {
        return $result;
    }
    $cores = preg_match_all('/^processor\s*:/mi', $raw);
    if ($cores > 0) {
        $result['cores'] = $cores;
    }
    if (preg_match('/^model name\s*:\s*(.+)$/mi', $raw, $match)) {
        $result['model'] = trim($match[1]);
    } elseif (preg_match('/^Hardware\s*:\s*(.+)$/mi', $raw, $match)) {
        $result['model'] = trim($match[1]);
    }
    return $result;
}

/** Boards expose dozens of zones; only the hottest few are useful. */
function read_thermal_zones(): array
{
    $zones = [];
    $dirs = @glob('/sys/class/thermal/thermal_zone*');
    if (!$dirs) {
        return $zones;
    }
    foreach ($dirs as $dir) {
        $rawTemp = @file_get_contents($dir . '/temp');
        if ($rawTemp === false || !is_numeric(trim($rawTemp))) {
            continue;
        }
        $celsius = ((float) trim($rawTemp)) / 1000;
        if ($celsius <= 0 || $celsius > 200) {
            continue;
        }
        $label = @file_get_contents($dir . '/type');
        $zones[] = [
            'label' => $label === false ? basename($dir) : trim($label),
            'celsius' => round($celsius, 1),
        ];
    }
    usort($zones, static function (array $a, array $b) {
        return $b['celsius'] <=> $a['celsius'];
    });
    return array_slice($zones, 0, 4);
}

function read_disks(): array
{
    $disks = [];
    foreach (DISK_PATHS as $path) {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            $disks[] = [
                'path' => $path, 'used' => null, 'total' => null,
                'free' => null, 'percent' => null, 'reported' => false,
            ];
            continue;
        }
        $used = (float) $total - (float) $free;
        // Some containers report a quota with no usage figure at all.
        $reported = $used > 0;
        $disks[] = [
            'path' => $path,
            'used' => $reported ? $used : null,
            'total' => (float) $total,
            'free' => (float) $free,
            'percent' => $reported ? percent($used, (float) $total) : null,
            'reported' => $reported,
        ];
    }
    return $disks;
}

function get_load_average(): ?array
{
    if (!function_exists('sys_getloadavg')) {
        return null;
    }
    $load = sys_getloadavg();
    if (!$load) {
        return null;
    }
    return array_map(static function ($value) {
        return round((float) $value, 2);
    }, $load);
}

function process_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    $user = get_current_user();
    return $user !== '' ? $user : 'unknown';
}

// ---- server stack ----

function detect_stack(): array
{
    $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
    $sapi = PHP_SAPI;

    $family = 'unknown';
    if (stripos($software, 'litespeed') !== false || $sapi === 'litespeed') {
        $family = 'litespeed';
    } elseif (stripos($software, 'apache') !== false || function_exists('apache_get_version')) {
        $family = 'apache';
    } elseif (stripos($software, 'nginx') !== false) {
        $family = 'nginx';
    } elseif (stripos($software, 'caddy') !== false) {
        $family = 'caddy';
    } elseif (stripos($software, 'microsoft-iis') !== false) {
        $family = 'iis';
    } elseif ($sapi === 'cli-server') {
        $family = 'builtin';
    }

    if ($sapi === 'fpm-fcgi') {
        $handler = 'PHP-FPM (FastCGI)';
    } elseif ($sapi === 'cgi-fcgi') {
        $handler = 'FastCGI';
    } elseif ($sapi === 'apache2handler') {
        $handler = 'mod_php';
    } elseif ($sapi === 'litespeed') {
        $handler = 'LiteSpeed SAPI';
    } elseif ($sapi === 'cli-server') {
        $handler = 'built-in development server';
    } elseif ($sapi === 'cli' || $sapi === 'phpdbg') {
        $handler = 'command line';
    } else {
        $handler = $sapi;
    }

    $name = $software !== '' ? $software : 'not reported by the server';
    if ($software === '' && $family === 'unknown' && $sapi === 'fpm-fcgi') {
        // nginx and Caddy both forward requests without naming themselves
        // unless the pool is configured to pass SERVER_SOFTWARE.
        $name = 'not reported (FastCGI front end)';
    }

    $proxyHeaders = [];
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP',
        'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED'] as $header) {
        if (!empty($_SERVER[$header])) {
            $proxyHeaders[] = strtolower(str_replace(['HTTP_', '_'], ['', '-'], $header));
        }
    }

    $modules = null;
    if (function_exists('apache_get_modules')) {
        $modules = @apache_get_modules();
        if (!is_array($modules)) {
            $modules = null;
        }
    }

    return [
        'family' => $family,
        'name' => $name,
        'handler' => $handler,
        'sapi' => $sapi,
        'htaccess' => $family === 'apache' || $family === 'litespeed',
        'proxy_headers' => $proxyHeaders,
        'modules' => $modules,
    ];
}

function htaccess_label(array $stack): string
{
    if ($stack['htaccess']) {
        return 'honoured (' . ($stack['family'] === 'apache' ? 'Apache' : 'LiteSpeed') . ')';
    }
    if ($stack['family'] === 'nginx' || $stack['family'] === 'caddy' || $stack['family'] === 'iis') {
        return 'ignored — protect this file in the server config';
    }
    return 'unknown web server, assume ignored';
}

// ---- cache backends ----

function get_opcache(): array
{
    // extension_loaded('opcache') is unreliable — the module registers itself
    // as 'Zend OPcache'. Detect availability from the extension and the ini
    // flag first, since opcache_get_status() itself is a common candidate for
    // disable_functions on shared hosting: hosts disable the introspection
    // call while leaving the cache itself on.
    $loaded = extension_loaded('Zend OPcache') || extension_loaded('opcache');
    $result = [
        'available' => $loaded,
        'enabled' => $loaded && ini_flag('opcache.enable'),
        'status_disabled' => false,
        'jit' => null,
        // function_exists() already returns false for anything listed in
        // disable_functions, so this alone tells us whether a per-file
        // cache reset (opcache_invalidate) is something the host allows.
        'invalidate_available' => $loaded && function_exists('opcache_invalidate'),
    ];
    if (!$loaded) {
        return $result;
    }
    if (!function_exists('opcache_get_status')) {
        $result['status_disabled'] = true;
        return $result;
    }
    $status = @opcache_get_status(false);
    if ($status === false) {
        $result['status_disabled'] = true;
        return $result;
    }
    $result['enabled'] = !empty($status['opcache_enabled']);
    $result['jit'] = isset($status['jit']['enabled']) ? (bool) $status['jit']['enabled'] : null;

    $memory = isset($status['memory_usage']) ? $status['memory_usage'] : null;
    if (is_array($memory)) {
        $result['mem_used'] = (float) $memory['used_memory'];
        $result['mem_free'] = (float) $memory['free_memory'];
        $result['mem_wasted'] = (float) $memory['wasted_memory'];
        $result['mem_total'] = $result['mem_used'] + $result['mem_free'] + $result['mem_wasted'];
    }
    $stats = isset($status['opcache_statistics']) ? $status['opcache_statistics'] : null;
    if (is_array($stats)) {
        $result['hit_rate'] = round((float) ($stats['opcache_hit_rate'] ?? 0), 2);
        $result['scripts'] = $stats['num_cached_scripts'] ?? null;
        $result['keys'] = $stats['num_cached_keys'] ?? null;
        $result['max_keys'] = $stats['max_cached_keys'] ?? null;
        $result['restarts'] = (int) ($stats['oom_restarts'] ?? 0)
            + (int) ($stats['hash_restarts'] ?? 0)
            + (int) ($stats['manual_restarts'] ?? 0);
    }
    $result['cache_full'] = !empty($status['cache_full']);
    $result['percent'] = isset($result['mem_total'])
        ? percent($result['mem_used'], $result['mem_total'])
        : null;
    return $result;
}

function get_apcu(): array
{
    $result = ['available' => false];
    if (!function_exists('apcu_cache_info') || !ini_flag('apc.enabled')) {
        return $result;
    }
    $info = @apcu_cache_info(true);
    if (!is_array($info)) {
        return $result;
    }
    $sma = function_exists('apcu_sma_info') ? @apcu_sma_info(true) : null;
    $result['available'] = true;
    $result['version'] = phpversion('apcu') ?: null;
    $result['entries'] = $info['num_entries'] ?? null;
    $hits = (float) ($info['num_hits'] ?? 0);
    $misses = (float) ($info['num_misses'] ?? 0);
    $result['hit_rate'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 2) : null;
    if (is_array($sma) && isset($sma['seg_size'], $sma['avail_mem'])) {
        $result['mem_total'] = (float) $sma['seg_size'] * (float) ($sma['num_seg'] ?? 1);
        $result['mem_used'] = $result['mem_total'] - (float) $sma['avail_mem'];
    }
    $result['percent'] = isset($result['mem_total'])
        ? percent($result['mem_used'], $result['mem_total'])
        : null;
    return $result;
}

/**
 * Accepts what people write: host and port, a socket path, a unix: URL, or the
 * "/tmp/redis.sock:0" shape WordPress asks for. Returns "host:port" or
 * "unix:/path", or null if unusable.
 */
function normalize_cache_target(string $target): ?string
{
    $target = trim($target);
    if ($target === '') {
        return null;
    }
    if (strncmp($target, 'unix:', 5) === 0) {
        $target = '/' . ltrim(substr($target, 5), '/');
    }
    if ($target[0] === '/') {
        // A trailing :0 or :port means nothing to a socket file.
        return 'unix:' . preg_replace('/:\d+$/', '', $target);
    }
    $colon = strrpos($target, ':');
    if ($colon === false) {
        return null;
    }
    $host = substr($target, 0, $colon);
    $port = (int) substr($target, $colon + 1);
    if ($host === '' || $port <= 0) {
        return null;
    }
    return $host . ':' . $port;
}

function display_target(string $normalized): string
{
    return strncmp($normalized, 'unix:', 5) === 0 ? substr($normalized, 5) : $normalized;
}

/** Opens a TCP or unix-socket stream with a short timeout, or returns null. */
function open_probe_socket(string $normalized)
{
    $errno = 0;
    $errstr = '';
    if (strncmp($normalized, 'unix:', 5) === 0) {
        $path = substr($normalized, 5);
        // Checking the file first keeps the sweep instant on hosts without it.
        if (!@file_exists($path)) {
            return null;
        }
        $remote = 'unix://' . $path;
    } else {
        $remote = 'tcp://' . $normalized;
    }
    $handle = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        PROBE_TIMEOUT_SECONDS,
        STREAM_CLIENT_CONNECT
    );
    if ($handle === false) {
        return null;
    }
    $micro = (int) round(PROBE_TIMEOUT_SECONDS * 1000000);
    stream_set_timeout($handle, intdiv($micro, 1000000), $micro % 1000000);
    return $handle;
}

/** Reads the bulk reply to INFO and folds it into the shared shape. */
function read_redis_info($handle, string $header, array $server): array
{
    $server['type'] = 'redis';

    if ($header[0] === '-') {
        // NOAUTH and friends: the server is up, it just will not talk to us.
        $server['note'] = 'reachable, but INFO was refused: ' . ltrim($header, '-');
        return $server;
    }

    $length = (int) substr($header, 1);
    $payload = '';
    while ($length > 0 && strlen($payload) < $length) {
        $chunk = @fread($handle, min(8192, $length - strlen($payload)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $payload .= $chunk;
    }

    $info = [];
    foreach (preg_split('/\r?\n/', $payload) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $info[$parts[0]] = $parts[1];
        }
    }
    if ($info === []) {
        $server['note'] = 'reachable, but INFO returned nothing usable';
        return $server;
    }

    $used = isset($info['used_memory']) ? (float) $info['used_memory'] : null;
    $max = isset($info['maxmemory']) ? (float) $info['maxmemory'] : 0.0;
    $hits = (float) ($info['keyspace_hits'] ?? 0);
    $misses = (float) ($info['keyspace_misses'] ?? 0);

    $keys = 0;
    foreach ($info as $key => $value) {
        if (strncmp($key, 'db', 2) === 0 && preg_match('/keys=(\d+)/', (string) $value, $match)) {
            $keys += (int) $match[1];
        }
    }

    $server['version'] = isset($info['redis_version']) ? (string) $info['redis_version'] : null;
    $server['uptime'] = isset($info['uptime_in_seconds']) ? (float) $info['uptime_in_seconds'] : null;
    $server['clients'] = isset($info['connected_clients']) ? (int) $info['connected_clients'] : null;
    $server['mem_used'] = $used;
    $server['mem_total'] = $max > 0 ? $max : null;
    $server['percent'] = $max > 0 ? percent($used, $max) : null;
    $server['keys'] = $keys;
    $server['hit_rate'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 2) : null;
    $server['evicted'] = isset($info['evicted_keys']) ? (int) $info['evicted_keys'] : null;
    $server['persistence'] = isset($info['rdb_last_bgsave_status'])
        ? (string) $info['rdb_last_bgsave_status']
        : null;
    return $server;
}

/** Memcached and LSMCD speak the same plain-text stats protocol. */
function read_memcached_stats($handle, array $server): array
{
    $server['type'] = 'memcached';
    @fwrite($handle, "stats\r\n");

    $stats = [];
    $guard = 0;
    while ($guard < 400) {
        $guard++;
        $line = @fgets($handle);
        if ($line === false) {
            break;
        }
        $line = trim($line);
        if ($line === 'END' || $line === '') {
            break;
        }
        if (strncmp($line, 'STAT ', 5) === 0) {
            $parts = explode(' ', substr($line, 5), 2);
            if (count($parts) === 2) {
                $stats[$parts[0]] = $parts[1];
            }
        }
    }
    if ($stats === []) {
        $server['note'] = 'reachable, but stats returned nothing usable';
        return $server;
    }

    $used = isset($stats['bytes']) ? (float) $stats['bytes'] : null;
    $limit = isset($stats['limit_maxbytes']) ? (float) $stats['limit_maxbytes'] : null;
    $hits = (float) ($stats['get_hits'] ?? 0);
    $misses = (float) ($stats['get_misses'] ?? 0);

    $server['version'] = isset($stats['version']) ? (string) $stats['version'] : null;
    $server['uptime'] = isset($stats['uptime']) ? (float) $stats['uptime'] : null;
    $server['clients'] = isset($stats['curr_connections']) ? (int) $stats['curr_connections'] : null;
    $server['mem_used'] = $used;
    $server['mem_total'] = ($limit !== null && $limit > 0) ? $limit : null;
    $server['percent'] = ($limit !== null && $limit > 0) ? percent($used, $limit) : null;
    $server['keys'] = isset($stats['curr_items']) ? (int) $stats['curr_items'] : null;
    $server['hit_rate'] = ($hits + $misses) > 0 ? round($hits / ($hits + $misses) * 100, 2) : null;
    $server['evicted'] = isset($stats['evictions']) ? (int) $stats['evictions'] : null;
    return $server;
}

/**
 * One connection, one question: INFO as an inline command. Redis replies with a
 * bulk string or an auth error, Memcached replies ERROR to anything it does not
 * recognise — enough to tell the two apart without being told which is which.
 */
function probe_cache_server(string $normalized): ?array
{
    $handle = open_probe_socket($normalized);
    if ($handle === null) {
        return null;
    }
    @fwrite($handle, "INFO\r\n");
    $header = @fgets($handle);
    if ($header === false || trim($header) === '') {
        fclose($handle);
        return null;
    }
    $header = trim($header);

    $server = [
        'type' => 'unknown',
        'target' => display_target($normalized),
        'note' => null,
        'version' => null,
        'uptime' => null,
        'clients' => null,
        'mem_used' => null,
        'mem_total' => null,
        'percent' => null,
        'keys' => null,
        'hit_rate' => null,
        'evicted' => null,
        'persistence' => null,
    ];

    if ($header[0] === '$' || $header[0] === '-') {
        $server = read_redis_info($handle, $header, $server);
        fclose($handle);
        return $server;
    }
    if (strncmp($header, 'ERROR', 5) === 0 || strncmp($header, 'CLIENT_ERROR', 12) === 0
        || strncmp($header, 'SERVER_ERROR', 12) === 0) {
        $server = read_memcached_stats($handle, $server);
        @fwrite($handle, "quit\r\n");
        fclose($handle);
        return $server;
    }

    fclose($handle);
    $server['note'] = 'something is listening here, but it answered neither Redis nor Memcached';
    return $server;
}

/** Configured addresses first, then the well-known ones, without duplicates. */
function cache_targets(): array
{
    $targets = [];
    foreach (array_merge(REDIS_SERVERS, MEMCACHED_SERVERS) as $raw) {
        $normalized = normalize_cache_target((string) $raw);
        if ($normalized !== null) {
            $targets[$normalized] = true;
        }
    }
    if (CACHE_AUTODETECT) {
        foreach (CACHE_CANDIDATES as $raw) {
            $normalized = normalize_cache_target((string) $raw);
            if ($normalized !== null) {
                $targets[$normalized] = true;
            }
        }
    }
    return array_keys($targets);
}

/** Probe results are reused within a request: one connection per address, ever. */
function get_cache_servers(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $found = ['redis' => [], 'memcached' => [], 'unknown' => [], 'tried' => []];
    foreach (cache_targets() as $target) {
        $found['tried'][] = display_target($target);
        $server = probe_cache_server($target);
        if ($server === null) {
            continue;
        }
        $found[$server['type']][] = $server;
    }
    $cached = $found;
    return $cached;
}

// ---- checks ----

function check(string $level, string $label, string $detail): array
{
    return ['level' => $level, 'label' => $label, 'detail' => $detail];
}

function ini_bool_check(
    string $key,
    bool $expectedOn,
    string $badDetail,
    string $goodDetail,
    string $badLevel = 'warning'
): array {
    $isGood = ini_flag($key) === $expectedOn;
    return check(
        $isGood ? 'ok' : $badLevel,
        $key,
        $isGood ? $goodDetail : $badDetail . ini_scope_hint($key)
    );
}

function access_checks(): array
{
    $checks = [];

    if (pin_mode() === 'open') {
        $checks[] = check(
            'warning',
            'Page protection',
            'ACCESS_PIN is 0, so anyone who finds this URL reads your server configuration. That is a deliberate setting — put a real PIN back when this file is reachable from the internet.'
        );
    } else {
        $repeated = preg_match('/^(\d)\1*$/', ACCESS_PIN) === 1;
        $sequential = strpos('01234567890', ACCESS_PIN) !== false
            || strpos('09876543210', ACCESS_PIN) !== false;
        if ($repeated || $sequential) {
            $checks[] = check(
                'warning',
                'Page protection',
                'The PIN is a repeated or sequential run of digits — 12345 and its relatives are the first things anyone tries. Pick something arbitrary instead.'
            );
        } else {
            $checks[] = check(
                'ok',
                'Page protection',
                rate_limit_enabled()
                    ? 'A PIN is required and failed attempts are throttled.'
                    : 'A PIN is required.'
            );
        }

        if (!rate_limit_enabled()) {
            $checks[] = check(
                'warning',
                'Brute-force throttling',
                'Throttling is switched off, so the PIN can be guessed as fast as the server answers. Fine on a private network, not on a public one.'
            );
        } elseif (rate_limit_conflict()) {
            $checks[] = check(
                'warning',
                'Brute-force throttling',
                'Failed attempts are not being counted: the counter path in the temp directory is a symlink or belongs to another account, so it is being left alone. Point RATE_LIMIT_PATH at a file only this user can write.'
            );
        }
        if (https_state() === 'no') {
            $checks[] = check(
                'warning',
                'PIN in the clear',
                'The connection is not encrypted, so the PIN travels visibly in the URL and lands in every proxy log along the way.'
            );
        }
    }

    if (PHP_OS_FAMILY === 'Linux' && !@is_readable('/proc/meminfo')) {
        $checks[] = check(
            'info',
            'Host metrics',
            'RAM, swap, uptime and CPU details are unreadable because PHP cannot open /proc. That is normal on shared hosting, where open_basedir confines PHP to your own directory.'
        );
    }

    $checks[] = check(
        'info',
        'Leftover file',
        'Delete ' . basename(__FILE__) . ' when you are done: the safest diagnostic page is one that is no longer there.'
    );

    return $checks;
}

function stack_checks(array $stack): array
{
    $checks = [];

    if ($stack['family'] === 'nginx' || $stack['family'] === 'caddy') {
        $server = $stack['family'] === 'nginx' ? 'nginx' : 'Caddy';
        $checks[] = check(
            'info',
            'Access rules',
            $server . ' ignores .htaccess files. Restricting this page — or anything else under your web root — has to happen in the server config, not in a dropped file.'
        );
    } elseif ($stack['family'] === 'unknown') {
        $checks[] = check(
            'info',
            'Access rules',
            'The web server did not identify itself, so assume .htaccess files are ignored until you have checked the server config.'
        );
    }

    if ($stack['proxy_headers'] !== []) {
        $ipState = client_ip_state();
        if ($ipState === 'unresolved') {
            $checks[] = check(
                'warning',
                'Reverse proxy',
                'Forwarding headers are present (' . implode(', ', $stack['proxy_headers'])
                    . ') and the forwarded client address differs from REMOTE_ADDR, so PHP — and this file\'s own PIN rate limiter — sees the proxy, not the visitor.'
            );
        } elseif ($ipState === 'resolved') {
            $checks[] = check(
                'ok',
                'Reverse proxy',
                'Forwarding headers are present (' . implode(', ', $stack['proxy_headers'])
                    . '), and REMOTE_ADDR already matches the forwarded client address — this host resolves the real IP before PHP runs.'
            );
        } else {
            $checks[] = check(
                'info',
                'Reverse proxy',
                'Forwarding headers are present (' . implode(', ', $stack['proxy_headers'])
                    . '), but there is no X-Forwarded-For to compare against REMOTE_ADDR, so it is not clear whether PHP sees the real visitor.'
            );
        }
    }

    $serverAddress = server_ip();
    $publicAddress = public_ip();
    if ($serverAddress !== null && $publicAddress !== null && $serverAddress !== $publicAddress) {
        $checks[] = check(
            pin_mode() === 'open' ? 'warning' : 'info',
            'Origin address',
            'The domain resolves to ' . $publicAddress . ', but this machine answers on '
                . $serverAddress . ' — a CDN, a load balancer or a proxy sits in between.'
                . (pin_mode() === 'open'
                    ? ' This page prints both and asks for no PIN, so it hands out the address the proxy exists to hide.'
                    : '')
        );
    }

    $httpsState = https_state();
    if ($httpsState === 'no' && pin_mode() === 'open') {
        $checks[] = check(
            'warning',
            'HTTPS',
            'Nothing indicates this connection is encrypted, so everything on this page — and every credential your application handles — travels in the clear.'
        );
    } elseif ($httpsState === 'forwarded') {
        $checks[] = check(
            'ok',
            'HTTPS',
            'TLS terminates at a proxy in front of this server (' . implode(', ', forwarded_https_headers())
                . '), and the last hop to PHP is plain HTTP. That is how nearly every hosted setup works.'
        );
    }

    if ($stack['family'] === 'builtin') {
        $checks[] = check(
            'warning',
            'Development server',
            'This is the PHP built-in server. It is single-threaded and unhardened; do not use these numbers to judge production behaviour.'
        );
    }

    if (is_array($stack['modules']) && !in_array('mod_rewrite', $stack['modules'], true)) {
        $checks[] = check(
            'info',
            'mod_rewrite',
            'Apache is running without mod_rewrite, so front-controller routing and .htaccess rewrites will not work.'
        );
    }

    return $checks;
}

function php_checks(): array
{
    $checks = [];

    $checks[] = ini_bool_check(
        'display_errors',
        false,
        'PHP errors are printed into responses, which leaks paths, queries and sometimes credentials.',
        'Errors are not printed into responses.'
    );
    $checks[] = ini_bool_check(
        'log_errors',
        true,
        'Errors are not written to a log, so failures leave no trace to investigate.',
        'Errors are written to the error log.'
    );
    $checks[] = ini_bool_check(
        'expose_php',
        false,
        'The exact PHP version is advertised in the X-Powered-By header.',
        'The PHP version is not advertised in response headers.'
    );
    $checks[] = ini_bool_check(
        'session.cookie_httponly',
        true,
        'Session cookies are readable by JavaScript, so any XSS becomes a session takeover.',
        'Session cookies are hidden from JavaScript.'
    );
    $checks[] = ini_bool_check(
        'session.use_strict_mode',
        true,
        'PHP accepts session IDs it never issued, which allows session fixation.',
        'Session IDs invented by the client are rejected.'
    );

    $sameSite = (string) ini_get('session.cookie_samesite');
    $checks[] = check(
        in_array(strtolower($sameSite), ['lax', 'strict'], true) ? 'ok' : 'warning',
        'session.cookie_samesite',
        $sameSite === ''
            ? 'No SameSite policy on session cookies: they travel with cross-site requests, which is what CSRF needs.'
            : 'SameSite policy is ' . $sameSite . '.'
    );

    if (https_state() !== 'no') {
        $checks[] = ini_bool_check(
            'session.cookie_secure',
            true,
            'The site is served over HTTPS but session cookies may still be sent over plain HTTP.',
            'Session cookies are restricted to HTTPS.'
        );
    }

    $checks[] = ini_bool_check(
        'allow_url_fopen',
        false,
        'File functions can open remote URLs, which turns an unvalidated path into an SSRF.',
        'File functions cannot open remote URLs.'
    );
    $checks[] = ini_bool_check(
        'allow_url_include',
        false,
        'include and require can pull code from a remote URL. This is remote code execution waiting to happen.',
        'include and require cannot load remote URLs.',
        'error'
    );

    $openBasedir = ini_display('open_basedir', '');
    $checks[] = check(
        $openBasedir === '' ? 'info' : 'ok',
        'open_basedir',
        $openBasedir === ''
            ? 'Not set: PHP can read anywhere the web server user can. Setting it limits the blast radius of a file-read bug.'
            : 'PHP file access is confined to: ' . $openBasedir
    );

    $disabled = array_filter(array_map('trim', explode(',', ini_display('disable_functions', ''))));
    $risky = ['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen'];
    $stillEnabled = array_values(array_diff($risky, $disabled));
    $checks[] = check(
        'info',
        'disable_functions',
        $stillEnabled === []
            ? 'All the usual command-execution functions are disabled.'
            : 'Command execution is available through: ' . implode(', ', $stillEnabled)
                . '. Disable whatever your application does not use.'
    );

    $timezone = ini_display('date.timezone', '');
    $checks[] = check(
        $timezone === '' ? 'warning' : 'ok',
        'date.timezone',
        $timezone === ''
            ? 'No timezone configured: PHP falls back to UTC and date handling turns ambiguous.'
            : 'Timezone is ' . $timezone . '.'
    );

    $memoryLimit = ini_to_bytes(ini_display('memory_limit', ''));
    $uploadMax = ini_to_bytes(ini_display('upload_max_filesize', ''));
    $postMax = ini_to_bytes(ini_display('post_max_size', ''));
    if ($memoryLimit !== null && $memoryLimit > 0 && $uploadMax !== null && $uploadMax > $memoryLimit) {
        $checks[] = check(
            'warning',
            'memory_limit vs upload_max_filesize',
            'A maximum-size upload needs more memory than a script is allowed to use, so the largest uploads will fail.'
        );
    }
    if ($postMax !== null && $postMax > 0 && $uploadMax !== null && $uploadMax > $postMax) {
        $checks[] = check(
            'warning',
            'post_max_size vs upload_max_filesize',
            'upload_max_filesize is larger than post_max_size, so the bigger limit can never be reached.'
        );
    }

    $sessionPath = (string) ini_get('session.save_path');
    if ($sessionPath === '') {
        $sessionPath = sys_get_temp_dir();
    } elseif (strpos($sessionPath, ';') !== false) {
        $parts = explode(';', $sessionPath);
        $sessionPath = (string) end($parts);
    }
    if ($sessionPath !== '' && strpos($sessionPath, '://') === false) {
        $writable = @is_writable($sessionPath);
        $checks[] = check(
            $writable ? 'ok' : 'error',
            'Session storage',
            $writable
                ? 'Sessions are written to ' . $sessionPath . '.'
                : 'Session directory ' . $sessionPath . ' is not writable: logins will fail silently.'
        );
    }

    $tmp = ini_display('upload_tmp_dir', '');
    if ($tmp === '') {
        $tmp = sys_get_temp_dir();
    }
    $tmpWritable = @is_writable($tmp);
    $checks[] = check(
        $tmpWritable ? 'ok' : 'error',
        'Upload temp directory',
        $tmpWritable
            ? 'Uploads are buffered in ' . $tmp . '.'
            : 'Upload directory ' . $tmp . ' is not writable: file uploads will fail.'
    );

    if (extension_loaded('xdebug')) {
        $checks[] = check(
            'warning',
            'Xdebug',
            'Xdebug is loaded. It costs a large slice of performance and, when the remote debugger listens, it is a way into the server. Keep it off production.'
        );
    }

    return $checks;
}

function cache_checks(array $servers): array
{
    $checks = [];

    $opcache = get_opcache();
    if ($opcache['available'] && $opcache['enabled']) {
        if (!empty($opcache['status_disabled'])) {
            $checks[] = check('ok', 'OPcache', 'OPcache is active and caching bytecode, but this host has disabled opcache_get_status(), so no usage metrics are available.');
        } else {
            $detail = 'OPcache is active.';
            if (ini_flag('opcache.validate_timestamps')) {
                $detail .= ' Timestamp validation is on, which is right for development and costs performance in production.';
            }
            if (!empty($opcache['cache_full'])) {
                $checks[] = check('warning', 'OPcache', 'The opcode cache is full, so PHP keeps recompiling files it had to evict. Raise opcache.memory_consumption.');
            } else {
                $checks[] = check('ok', 'OPcache', $detail);
            }
        }
    } else {
        $checks[] = check('warning', 'OPcache', 'OPcache is not active. Every request recompiles your PHP from scratch.');
    }

    $apcu = get_apcu();
    $hasShared = $servers['redis'] !== [] || $servers['memcached'] !== [];
    if (!$hasShared && !$apcu['available']) {
        $checks[] = check(
            'info',
            'Data cache',
            'No data cache was found: no APCu, and nothing answered on the configured Redis or Memcached addresses. Applications that expect one will fall back to disk or the database.'
        );
    }

    foreach ($servers['redis'] as $server) {
        if ($server['note'] !== null) {
            $checks[] = check('info', 'Redis ' . $server['target'], $server['note'] . '.');
            continue;
        }
        if (isset($server['mem_total']) && $server['percent'] !== null && $server['percent'] > 90) {
            $checks[] = check('warning', 'Redis ' . $server['target'], 'Memory use is above 90% of maxmemory, so keys are about to be evicted.');
        }
        if (!empty($server['persistence']) && $server['persistence'] !== 'ok') {
            $checks[] = check('warning', 'Redis ' . $server['target'], 'The last background save did not succeed, so recent writes may not survive a restart.');
        }
    }

    foreach ($servers['memcached'] as $server) {
        if ($server['note'] !== null) {
            $checks[] = check('info', 'Memcached ' . $server['target'], $server['note'] . '.');
            continue;
        }
        if ($server['percent'] !== null && $server['percent'] > 90) {
            $checks[] = check('warning', 'Memcached ' . $server['target'], 'The slab memory is nearly full and items are being evicted early.');
        }
    }

    if ($servers['unknown'] !== []) {
        $targets = [];
        foreach ($servers['unknown'] as $server) {
            $targets[] = $server['target'];
        }
        $checks[] = check(
            'info',
            'Unidentified services',
            count($targets) > 2
                ? 'Every address checked accepts a connection but answers neither Redis nor Memcached ('
                    . implode(', ', $targets) . '). On shared hosting that usually means a firewall '
                    . 'accepting every connection, not that a cache is listening.'
                : 'Something is listening on ' . implode(' and ', $targets)
                    . ', but it answered neither Redis nor Memcached.'
        );
    }

    // A running cache PHP cannot talk to is a cache nobody is using.
    if ($servers['redis'] !== [] && !extension_loaded('redis') && !extension_loaded('relay')) {
        $checks[] = check(
            'warning',
            'Redis without a client',
            'Redis is answering, but neither the redis nor the relay extension is loaded, so PHP cannot use it. Install php-redis, or the server is running for nothing.'
        );
    }
    if ($servers['memcached'] !== [] && !extension_loaded('memcached') && !extension_loaded('memcache')) {
        $checks[] = check(
            'warning',
            'Memcached without a client',
            'Memcached is answering, but no memcached extension is loaded, so PHP cannot use it.'
        );
    }

    return $checks;
}

function exposure_check(): array
{
    $root = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    if ($root === '' || !@is_dir($root)) {
        return check('info', 'Sensitive files in the web root', 'DOCUMENT_ROOT is unknown, so this check was skipped.');
    }
    $root = rtrim($root, '/');
    $sensitive = [
        '.git/config' => 'a Git repository',
        '.env' => 'an environment file, usually full of secrets',
        '.env.local' => 'an environment file, usually full of secrets',
        '.env.production' => 'an environment file, usually full of secrets',
        'composer.lock' => 'a Composer lockfile that reveals exact dependency versions',
        'package-lock.json' => 'an npm lockfile that reveals exact dependency versions',
        'docker-compose.yml' => 'a Compose file, often carrying credentials',
        '.DS_Store' => 'a macOS index file that lists directory contents',
        'phpinfo.php' => 'a phpinfo dump',
        'adminer.php' => 'a database admin console',
    ];
    $found = [];
    foreach ($sensitive as $relative => $description) {
        if (@is_file($root . '/' . $relative)) {
            $found[] = $relative . ' (' . $description . ')';
        }
    }
    if ($found !== []) {
        return check(
            'error',
            'Sensitive files in the web root',
            'Present on disk under the web root — confirm none of these is downloadable: ' . implode(', ', $found) . '.'
        );
    }
    return check('ok', 'Sensitive files in the web root', 'None of the usual leaked files is sitting in the web root.');
}

function php_eol_check(): array
{
    $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $suffix = ' (EOL table last reviewed ' . EOL_TABLE_UPDATED . ')';
    if (!isset(EOL_DATES[$branch])) {
        return check(
            'info',
            'PHP version support',
            'Branch ' . $branch . ' is newer than this file, so its support window is unknown. Check php.net/supported-versions.php' . $suffix
        );
    }
    $eol = EOL_DATES[$branch];
    $daysLeft = (int) floor((strtotime($eol) - time()) / 86400);
    if ($daysLeft < 0) {
        return check(
            'error',
            'PHP version support',
            'PHP ' . $branch . ' stopped receiving security fixes on ' . $eol . '. Upgrading is the single highest-value change available here.' . $suffix
        );
    }
    if ($daysLeft < 180) {
        return check(
            'warning',
            'PHP version support',
            'PHP ' . $branch . ' reaches end of life on ' . $eol . ', in ' . $daysLeft . ' days. Plan the upgrade now.' . $suffix
        );
    }
    return check('ok', 'PHP version support', 'PHP ' . $branch . ' is supported until ' . $eol . '.' . $suffix);
}

function collect_checks(array $stack, array $servers): array
{
    $checks = array_merge(
        access_checks(),
        stack_checks($stack),
        php_checks(),
        cache_checks($servers),
        [exposure_check(), php_eol_check()]
    );
    $order = ['error' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
    usort($checks, static function (array $a, array $b) use ($order) {
        return $order[$a['level']] <=> $order[$b['level']];
    });
    return $checks;
}

function count_levels(array $checks): array
{
    $counts = ['error' => 0, 'warning' => 0, 'info' => 0, 'ok' => 0];
    foreach ($checks as $entry) {
        $counts[$entry['level']] = ($counts[$entry['level']] ?? 0) + 1;
    }
    return $counts;
}

// ---- server identity ----

/**
 * AlterVista numbers its machines one above the nameserver hostname they
 * answer on: ns685.altervista.org is the box their panel and their support
 * call server 686. Nothing in PHP reports that number — it is a naming
 * convention, so it is only appended when the hostname matches exactly, and
 * it is labelled as AlterVista's own rather than stated as a fact about the
 * system. If they ever change the offset, this is the line to delete.
 */
function hostname_label(string $hostname): string
{
    if (preg_match('/^ns(\d+)\.altervista\.org$/i', $hostname, $matches) !== 1) {
        return $hostname;
    }
    return $hostname . ' (AV-Server: ' . ((int) $matches[1] + 1) . ')';
}

/**
 * The domain this page answers on, which is not the machine's hostname:
 * shared hosting answers for hundreds of domains under one hostname.
 *
 * SERVER_NAME comes from the vhost, HTTP_HOST comes from the request — and
 * the request is the visitor's to write, so the vhost is preferred whenever
 * it looks like a real name rather than nginx's '_' or a bare 'localhost'.
 * Anything that is not a plausible hostname is dropped: this value decides
 * what gets looked up below.
 */
function served_host(): string
{
    $candidates = [
        strtolower(trim((string) ($_SERVER['SERVER_NAME'] ?? ''))),
        strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? ''))),
    ];
    foreach ($candidates as $index => $host) {
        if ($host === '') {
            continue;
        }
        if ($host[0] === '[') { // [::1]:8080
            $end = strpos($host, ']');
            $host = $end === false ? '' : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = substr($host, 0, (int) strrpos($host, ':'));
        }
        $host = rtrim($host, '.');
        if ($host === '' || preg_match('/^[a-z0-9.\-]+$/', $host) !== 1) {
            continue;
        }
        // The vhost name is only worth preferring when it names a domain.
        if ($index === 0 && (strpos($host, '.') === false || $host === 'localhost')) {
            continue;
        }
        return $host;
    }
    return '';
}

/**
 * The address the machine itself answers on. SERVER_ADDR is filled in by
 * Apache, nginx and LiteSpeed; PHP-FPM over a unix socket and a good deal of
 * shared hosting leave it out.
 *
 * The fallback resolves the machine's own hostname, which on shared hosting
 * is usually an alias for the loopback in /etc/hosts — and 127.0.0.1 is not
 * an answer to "where is this server", so it is thrown away rather than
 * printed. A private address is kept: on a VPS behind NAT that really is the
 * interface the server listens on.
 */
function server_ip(): ?string
{
    static $cached = false;
    static $value = null;
    if ($cached) {
        return $value;
    }
    $cached = true;

    $addr = trim((string) ($_SERVER['SERVER_ADDR'] ?? ''));
    if (usable_ip($addr)) {
        $value = $addr;
        return $value;
    }
    $hostname = gethostname();
    if ($hostname === false || !function_exists('gethostbyname')) {
        return $value;
    }
    $resolved = @gethostbyname($hostname);
    if ($resolved !== $hostname && usable_ip($resolved)) {
        $value = $resolved;
    }
    return $value;
}

/** A real address to report: parses as one, and is neither loopback nor 0.0.0.0 */
function usable_ip(string $address): bool
{
    if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    return filter_var(
        $address,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * What the rest of the internet gets when it looks up the domain this page is
 * served on — the address a firewall rule or an API allowlist would name.
 *
 * On shared hosting it is the only address there is: the machine knows itself
 * as the loopback and the public one belongs to the front-end. Behind a CDN
 * it is the CDN's edge, which is worth knowing too — and the report says so
 * by showing it next to the server's own address when the two differ.
 */
function public_ip(): ?string
{
    static $cached = false;
    static $value = null;
    if ($cached) {
        return $value;
    }
    $cached = true;

    if (!DNS_LOOKUPS || !function_exists('dns_get_record')) {
        return $value;
    }
    $host = served_host();
    if ($host === '') {
        return $value;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $value = usable_ip($host) ? $host : null;
        return $value;
    }

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
        return $value;
    }
    foreach ($records as $record) {
        $candidate = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        if (usable_ip($candidate)) {
            $value = $candidate;
            return $value;
        }
    }
    return $value;
}

/**
 * The nameservers answering for the served domain, walking up one label at a
 * time: NS records sit on the zone apex, so www.example.com has none of its
 * own and example.com does. Three steps covers every real domain and caps
 * how many queries a broken resolver can charge us for.
 */
function nameservers(): array
{
    static $cached = false;
    static $value = [];
    if ($cached) {
        return $value;
    }
    $cached = true;

    if (!DNS_LOOKUPS || !function_exists('dns_get_record')) {
        return $value;
    }
    $host = served_host();
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return $value;
    }

    $labels = explode('.', $host);
    for ($step = 0; $step < 3 && count($labels) >= 2; $step++) {
        $records = @dns_get_record(implode('.', $labels), DNS_NS);
        if (is_array($records)) {
            $names = [];
            foreach ($records as $record) {
                if (isset($record['target']) && $record['target'] !== '') {
                    $names[] = strtolower(rtrim((string) $record['target'], '.'));
                }
            }
            if ($names !== []) {
                $names = array_values(array_unique($names));
                sort($names);
                $value = $names;
                return $value;
            }
        }
        array_shift($labels);
    }
    return $value;
}

/**
 * There is no address to print: neither the web server nor DNS produced one.
 * Which of the two failed is the useful half of the answer.
 */
function address_unknown_reason(): string
{
    if (!DNS_LOOKUPS) {
        return 'not reported by the web server, and DNS_LOOKUPS is off';
    }
    if (!function_exists('dns_get_record')) {
        return 'not reported by the web server, and dns_get_record is disabled here';
    }
    if (served_host() === '') {
        return 'not reported by the web server, and there is no domain to resolve';
    }
    return 'not reported by the web server, and ' . served_host() . ' did not resolve';
}

/** One line for the report: the nameservers, or why there are none to show. */
function nameservers_label(): string
{
    $servers = nameservers();
    if ($servers !== []) {
        return implode(', ', $servers);
    }
    if (!DNS_LOOKUPS) {
        return 'not looked up (DNS_LOOKUPS is off)';
    }
    if (!function_exists('dns_get_record')) {
        return 'unknown — dns_get_record is disabled here';
    }
    return served_host() === '' ? 'unknown — no domain in the request' : 'no answer';
}

// ---- report assembly ----

function collect_environment(array $stack): array
{
    $hostname = gethostname();
    $servedHost = served_host();
    $environment = [
        'Hostname' => $hostname !== false ? hostname_label($hostname) : 'unknown',
        'Serving' => $servedHost !== '' ? $servedHost : 'unknown',
    ];

    // Two rows only when the two addresses actually disagree — which is the
    // case worth a second look, because it means something is in front of this
    // machine. Everywhere else one row says all there is to say.
    $serverAddress = server_ip();
    $publicAddress = public_ip();
    if ($serverAddress !== null && $publicAddress !== null && $serverAddress !== $publicAddress) {
        $environment['Server IP'] = $serverAddress;
        $environment['Public IP'] = $publicAddress . ' (' . $servedHost . ')';
    } else {
        $environment['Server IP'] = $serverAddress ?? $publicAddress ?? address_unknown_reason();
    }
    $environment['Nameservers'] = nameservers_label();

    return $environment + [
        'Operating system' => implode(' ', array_unique(array_filter([
            PHP_OS_FAMILY, php_uname('r'), php_uname('m'),
        ]))),
        'Web server' => $stack['name'],
        'PHP interface' => $stack['handler'] . ' (' . $stack['sapi'] . ')',
        'Runs as user' => process_user(),
        '.htaccess' => htaccess_label($stack),
        'Reverse proxy headers' => $stack['proxy_headers'] === []
            ? 'none'
            : implode(', ', $stack['proxy_headers']),
        'HTTPS' => https_label(),
        'Web root' => isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? (string) $_SERVER['DOCUMENT_ROOT']
            : 'unknown',
        'This file' => __FILE__,
        'Server time' => date('Y-m-d H:i:s T'),
    ];
}

function collect_php_config(): array
{
    $iniFile = php_ini_loaded_file();
    $scanned = php_ini_scanned_files();
    $scannedCount = 0;
    if (is_string($scanned) && trim($scanned) !== '') {
        $scannedCount = count(array_filter(array_map('trim', explode(',', $scanned))));
    }

    return [
        'PHP version' => PHP_VERSION,
        'Build' => PHP_OS_FAMILY . ' / ' . (PHP_INT_SIZE * 8) . '-bit'
            . (ZEND_THREAD_SAFE ? ' / thread safe' : ''),
        'Loaded php.ini' => $iniFile === false ? 'none' : $iniFile,
        'Extra .ini files' => (string) $scannedCount,
        'memory_limit' => ini_display('memory_limit'),
        'max_execution_time' => ini_display('max_execution_time') . 's',
        'upload_max_filesize' => ini_display('upload_max_filesize'),
        'post_max_size' => ini_display('post_max_size'),
        'max_file_uploads' => ini_display('max_file_uploads'),
        'max_input_vars' => ini_display('max_input_vars'),
        'date.timezone' => ini_display('date.timezone'),
        'error_reporting' => (string) ini_get('error_reporting'),
        'Temp directory' => sys_get_temp_dir(),
        'realpath cache' => human_bytes((float) realpath_cache_size())
            . ' / ' . ini_display('realpath_cache_size'),
        'This request used' => human_bytes((float) memory_get_peak_usage(true)) . ' of memory',
    ];
}

/** Everything that changes between two refreshes, and nothing else. */
function collect_metrics(): array
{
    $meminfo = read_meminfo();
    $memory = null;
    $swap = null;
    if ($meminfo !== null && isset($meminfo['MemTotal']) && $meminfo['MemTotal'] > 0) {
        $total = $meminfo['MemTotal'];
        $free = $meminfo['MemAvailable'] ?? $meminfo['MemFree'] ?? 0.0;
        $used = $total - $free;
        $memory = ['used' => $used, 'total' => $total, 'percent' => percent($used, $total)];
        if (isset($meminfo['SwapTotal']) && $meminfo['SwapTotal'] > 0) {
            $swapTotal = $meminfo['SwapTotal'];
            $swapUsed = $swapTotal - ($meminfo['SwapFree'] ?? 0.0);
            $swap = ['used' => $swapUsed, 'total' => $swapTotal, 'percent' => percent($swapUsed, $swapTotal)];
        }
    }

    $servers = get_cache_servers();

    return [
        'server_time' => date('Y-m-d H:i:s T'),
        'uptime_seconds' => read_uptime(),
        'load_average' => get_load_average(),
        'cpu' => read_cpu(),
        'thermal' => read_thermal_zones(),
        'memory' => $memory,
        'swap' => $swap,
        'disks' => read_disks(),
        'opcache' => get_opcache(),
        'apcu' => get_apcu(),
        'redis' => $servers['redis'],
        'memcached' => $servers['memcached'],
        'cache_other' => $servers['unknown'],
    ];
}

function collect_extensions(): array
{
    $loaded = get_loaded_extensions();
    usort($loaded, 'strcasecmp');
    return $loaded;
}

function build_report(): array
{
    $stack = detect_stack();
    $servers = get_cache_servers();

    return [
        'generator' => ['name' => 'system-info.php', 'version' => APP_VERSION],
        'generated_at' => date('c'),
        'environment' => collect_environment($stack),
        'php' => collect_php_config(),
        'metrics' => collect_metrics(),
        'checks' => collect_checks($stack, $servers),
        'extensions' => collect_extensions(),
    ];
}

function build_markdown(array $report): string
{
    $lines = [];
    $lines[] = '# Server report — ' . $report['environment']['Hostname'];
    $lines[] = '';
    $lines[] = 'Generated ' . $report['generated_at'] . ' by [system-info.php]('
        . APP_HOMEPAGE . ') v' . APP_VERSION . '.';
    $lines[] = '';

    $lines[] = '## Environment';
    foreach ($report['environment'] as $key => $value) {
        $lines[] = '- **' . $key . ':** ' . $value;
    }

    $lines[] = '';
    $lines[] = '## PHP';
    foreach ($report['php'] as $key => $value) {
        $lines[] = '- **' . $key . ':** ' . $value;
    }

    $metrics = $report['metrics'];
    $lines[] = '';
    $lines[] = '## Resources';
    if ($metrics['uptime_seconds'] !== null) {
        $lines[] = '- **Uptime:** ' . human_duration($metrics['uptime_seconds']);
    }
    if ($metrics['load_average'] !== null) {
        $lines[] = '- **Load average:** ' . implode(' / ', $metrics['load_average'])
            . ($metrics['cpu']['cores'] ? ' across ' . $metrics['cpu']['cores'] . ' cores' : '');
    }
    if ($metrics['cpu']['model'] !== null) {
        $lines[] = '- **CPU:** ' . $metrics['cpu']['model'];
    }
    if ($metrics['memory'] !== null) {
        $lines[] = '- **RAM:** ' . human_bytes($metrics['memory']['used']) . ' / '
            . human_bytes($metrics['memory']['total']) . ' (' . $metrics['memory']['percent'] . '%)';
    }
    if ($metrics['swap'] !== null) {
        $lines[] = '- **Swap:** ' . human_bytes($metrics['swap']['used']) . ' / '
            . human_bytes($metrics['swap']['total']);
    }
    foreach ($metrics['disks'] as $disk) {
        if ($disk['total'] === null) {
            continue;
        }
        $lines[] = '- **Disk ' . $disk['path'] . ':** '
            . ($disk['reported']
                ? human_bytes($disk['used']) . ' / ' . human_bytes($disk['total']) . ' (' . $disk['percent'] . '%)'
                : human_bytes($disk['total']) . ' total, usage not reported');
    }
    foreach ($metrics['thermal'] as $zone) {
        $lines[] = '- **Temperature ' . $zone['label'] . ':** ' . $zone['celsius'] . ' °C';
    }

    $lines[] = '';
    $lines[] = '## Caches';
    $opcache = $metrics['opcache'];
    if (!$opcache['available']) {
        $lines[] = '- **OPcache:** not installed';
    } else {
        $lines[] = '- **OPcache:** ' . ($opcache['enabled'] ? 'active' : 'installed but disabled')
            . (isset($opcache['mem_total'])
                ? ', ' . human_bytes($opcache['mem_used']) . ' / ' . human_bytes($opcache['mem_total'])
                : '')
            . (isset($opcache['hit_rate']) ? ', ' . $opcache['hit_rate'] . '% hit rate' : '')
            . (!empty($opcache['status_disabled']) ? ' (metrics unavailable, opcache_get_status() disabled by host)' : '')
            . (!$opcache['invalidate_available'] ? ' (opcache_invalidate() disabled by host)' : '');
    }
    $apcu = $metrics['apcu'];
    if ($apcu['available']) {
        $lines[] = '- **APCu:** ' . ($apcu['entries'] ?? '?') . ' entries'
            . ($apcu['hit_rate'] !== null ? ', ' . $apcu['hit_rate'] . '% hit rate' : '');
    }
    if ($metrics['cache_other'] !== []) {
        $otherTargets = [];
        foreach ($metrics['cache_other'] as $server) {
            $otherTargets[] = $server['target'];
        }
        $lines[] = '- **Unidentified services:** ' . implode(', ', $otherTargets)
            . ' answer neither Redis nor Memcached';
    }
    foreach (['redis' => 'Redis', 'memcached' => 'Memcached'] as $key => $label) {
        foreach ($metrics[$key] as $server) {
            if ($server['note'] !== null) {
                $lines[] = '- **' . $label . ' ' . $server['target'] . ':** ' . $server['note'];
                continue;
            }
            $lines[] = '- **' . $label . ' ' . $server['target'] . ':** v' . ($server['version'] ?? '?')
                . ', ' . human_bytes($server['mem_used'])
                . ($server['mem_total'] !== null ? ' / ' . human_bytes($server['mem_total']) : '')
                . ', ' . ($server['keys'] ?? 0) . ' keys'
                . ($server['hit_rate'] !== null ? ', ' . $server['hit_rate'] . '% hit rate' : '');
        }
    }

    $lines[] = '';
    $lines[] = '## Checks';
    foreach ($report['checks'] as $entry) {
        if ($entry['level'] === 'ok') {
            $icon = '[ok]';
        } elseif ($entry['level'] === 'warning') {
            $icon = '[warn]';
        } elseif ($entry['level'] === 'error') {
            $icon = '[fix]';
        } else {
            $icon = '[info]';
        }
        $lines[] = '- ' . $icon . ' **' . $entry['label'] . '** — ' . $entry['detail'];
    }

    $lines[] = '';
    $lines[] = '## Loaded extensions (' . count($report['extensions']) . ')';
    $lines[] = implode(', ', $report['extensions']);
    $lines[] = '';

    return implode("\n", $lines);
}

// ---- dispatch ----

/**
 * The floor, deliberately not in the settings block above: a minimum you can
 * turn down is not a minimum, and anyone who wants a longer PIN can simply
 * type one. A two-character PIN is theatre, and letters contradict the numeric
 * keypad the lock screen asks for. Refusing beats serving the page to whoever
 * tries "1".
 */
const MIN_PIN_LENGTH = 5;

if ($IS_CLI) {
    echo build_markdown(build_report());
    exit;
}

// Nobody publishes an open server report on purpose the first time: refusing
// until the choice is made is the only default that cannot go wrong.
if (pin_mode() === 'unset') {
    send_denial(
        403,
        'Setup required',
        'Open ' . basename(__FILE__) . ' and set ACCESS_PIN to ' . MIN_PIN_LENGTH
            . ' digits or more, then come back with the PIN as the query string: '
            . basename(__FILE__) . "?12345\n"
            . 'If this report is meant to be readable by anyone who knows the URL, set ACCESS_PIN to 0.',
        false
    );
}

if (pin_mode() === 'set') {
    $pinProblem = '';
    if (preg_match('/^\d+$/', ACCESS_PIN) !== 1) {
        $pinProblem = 'ACCESS_PIN must be digits only.';
    } elseif (strlen(ACCESS_PIN) < MIN_PIN_LENGTH) {
        $pinProblem = 'ACCESS_PIN is shorter than ' . MIN_PIN_LENGTH . ' digits.';
    }
    if ($pinProblem !== '') {
        send_denial(
            500,
            'Configuration error',
            $pinProblem . ' This page refuses to serve until it is fixed.' . "\n"
                . 'Edit the file and set ' . MIN_PIN_LENGTH . ' digits or more, or 0 for open access.',
            false
        );
    }

    if (rate_limit_is_locked()) {
        if (!headers_sent()) {
            header('Retry-After: ' . RATE_LIMIT_WINDOW_SECONDS);
        }
        send_denial(
            429,
            'Too many attempts',
            "Too many wrong PINs from this address.\nTry again in a few minutes.",
            false
        );
    }
    if (!hash_equals(ACCESS_PIN, submitted_pin())) {
        // An empty query string is somebody opening the URL, not guessing at it.
        // Counting it would let a crawler lock the owner out of their own page.
        if (submitted_pin() !== '') {
            rate_limit_register_failure();
        }
        send_denial(
            403,
            'This page is locked',
            submitted_pin() === ''
                ? 'A PIN is required to read this server report.'
                : 'That PIN is not correct.',
            true
        );
    }
    rate_limit_clear();
}

// The live endpoint answers the page's own polling: metrics only, no checks,
// no extension list, nothing that cannot change between two refreshes.
if (wants_live_payload()) {
    send_hardening_headers();
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_encode(
        ['metrics' => collect_metrics()],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    echo $payload === false ? '{"metrics":null}' : $payload;
    exit;
}

// ---- html dashboard ----

$nonce = request_nonce();
send_hardening_headers($nonce);
header('Content-Type: text/html; charset=utf-8');

$report = build_report();
$env = $report['environment'];
$phpConfig = $report['php'];
$metrics = $report['metrics'];
$checks = $report['checks'];
$extensions = $report['extensions'];
$counts = count_levels($checks);

$memory = $metrics['memory'];
$swap = $metrics['swap'];
$disks = $metrics['disks'];
$load = $metrics['load_average'];
$cpu = $metrics['cpu'];
$opcache = $metrics['opcache'];
$apcu = $metrics['apcu'];
$redisServers = $metrics['redis'];
$memcachedServers = $metrics['memcached'];
$otherServers = $metrics['cache_other'];
$cacheTried = get_cache_servers()['tried'];
$redisClient = extension_loaded('redis') || extension_loaded('relay');
$memcachedClient = extension_loaded('memcached') || extension_loaded('memcache');

$memoryPercent = $memory !== null ? $memory['percent'] : null;
$swapPercent = $swap !== null ? $swap['percent'] : null;
$readableDisks = [];
foreach ($disks as $diskIndex => $disk) {
    if ($disk['total'] !== null) {
        $readableDisks[$diskIndex] = $disk;
    }
}
// The disk path only earns a line when it says something the Host card has
// not already said: a second volume, or a path that is not the web root.
$webRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
$showDiskPaths = count($readableDisks) > 1;
foreach ($readableDisks as $disk) {
    if ($disk['path'] !== $webRoot) {
        $showDiskPaths = true;
    }
}

$hasResources = $memory !== null || $swap !== null || $load !== null
    || $readableDisks !== [] || $cpu['model'] !== null || $metrics['thermal'] !== [];

$loadPerCore = ($load !== null && !empty($cpu['cores']))
    ? min(100.0, round($load[0] / $cpu['cores'] * 100, 1))
    : null;

// Only what can be stated as a percentage of something: load needs a core
// count to divide by, and without one there is no scale to plot it against.
$trendSeries = [];
if ($loadPerCore !== null) {
    $trendSeries['cpu'] = '--cpu';
}
if ($memoryPercent !== null) {
    $trendSeries['ram'] = '--ram';
}

$exportMarkdown = build_markdown($report);
$exportJson = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$liveUrl = null;
if (REFRESH_SECONDS > 0) {
    $self = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    if ($self === '' || basename($self) !== basename(__FILE__)) {
        $self = basename(__FILE__);
    }
    $liveUrl = $self . '?' . (pin_mode() === 'set' ? rawurlencode(submitted_pin()) . '&' : '') . 'live=1';
}

$bootConfig = [
    'pinMemoryMs' => PIN_MEMORY_MINUTES * 60 * 1000,
    'liveUrl' => $liveUrl,
    'refreshSeconds' => REFRESH_SECONDS,
    'historyPoints' => 40,
    'seed' => [
        'ram' => $memoryPercent,
        'cpu' => $load !== null ? $load[0] : null,
    ],
];

/** Meters turn amber past 75% and red past 90%, whatever they are measuring. */
function level_color(?float $value, string $baseVar): string
{
    if ($value === null) {
        return 'var(' . $baseVar . ')';
    }
    if ($value > 90) {
        return 'var(--err)';
    }
    if ($value > 75) {
        return 'var(--warn)';
    }
    return 'var(' . $baseVar . ')';
}

function bar(string $id, ?float $value, string $baseVar): string
{
    $clamped = $value === null ? 0.0 : max(0.0, min(100.0, $value));
    return '<div class="bar-track"><div class="bar-fill" data-bar="' . h($id) . '" style="width:'
        . round($clamped, 1) . '%;background:' . level_color($value, $baseVar) . '"></div></div>';
}

/**
 * The right-hand side of a meter: the percentage first, because that is the
 * number anyone actually reads, then the absolute figures it came from.
 */
function meter_value(string $textField, string $text, ?float $percent, string $percentField): string
{
    $out = '<span class="meter-value">';
    if ($percent !== null) {
        $out .= '<b data-field="' . h($percentField) . '">'
            . number_format(max(0.0, min(100.0, $percent)), 1) . '%</b> ';
    }
    return $out . '<span data-field="' . h($textField) . '">' . h($text) . '</span></span>';
}

/**
 * One chart for every live series instead of one each. Both are percentages
 * on the same 0-100 scale, so the lines can be read against each other: RAM
 * climbing while load stays flat is a different problem from both climbing
 * together. Series with no percentage to plot are simply left out.
 */
function trend_chart(array $series): string
{
    if ($series === []) {
        return '';
    }
    $lines = '';
    foreach ($series as $id => $colourVar) {
        $lines .= '<polyline class="spark-line" data-spark="' . h($id)
            . '" style="stroke:var(' . h($colourVar) . ')" points=""/>';
    }
    // No legend: each line is the colour of the bar a few rows above it.
    return '<div class="trend"><svg class="spark" viewBox="0 0 300 40" preserveAspectRatio="none"'
        . ' role="img" aria-label="Recent usage, as a percentage">' . $lines . '</svg></div>';
}

/**
 * "76.5 MB / 256.0 MB" answers how much; the percentage answers how close to
 * full, which is the one you act on. Both, in that order, in one line.
 */
function usage_text(?float $percent, string $text): string
{
    return $percent === null
        ? $text
        : number_format(max(0.0, min(100.0, $percent)), 1) . '% · ' . $text;
}

function cache_row(string $label, string $value, string $field = ''): string
{
    return '<div class="kv"><dt>' . h($label) . '</dt><dd'
        . ($field !== '' ? ' data-field="' . h($field) . '"' : '') . '>' . h($value) . '</dd></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Server report — <?= h((string) $env['Hostname']) ?></title>
<script nonce="<?= h($nonce) ?>">
(function () {
    var saved = null;
    try { saved = localStorage.getItem('system-info-theme'); } catch (e) {}
    var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', saved || (dark ? 'dark' : 'light'));
})();
</script>
<style>
:root {--accent: #0069a8; --accent-2: #00477a; --ok: #237a41; --warn: #8a5d00; --err: #ad2019; --info: #41618f; --bg: #eef1f5; --card: #ffffff; --border: #dbe0e7; --text: #171a1f; --muted: #646c78; --track: #e6eaf0; --ram: #0069a8; --disk: #6b4ec4; --cache: #237a41; --cpu: #0f7d6f; --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; --radius: 12px;}
html[data-theme="dark"] {--accent: #4fb0e6; --accent-2: #0b5e91; --ok: #58bd7a; --warn: #dfae3e; --err: #e4675f; --info: #8fb2de; --bg: #0e1116; --card: #171b21; --border: #272c34; --text: #e5e8ec; --muted: #9aa2ad; --track: #232830; --ram: #4fb0e6; --disk: #b09bea; --cache: #58bd7a; --cpu: #3fc9b4; color-scheme: dark;}
html {color-scheme: light;}
* {box-sizing: border-box;}
html, body {max-width: 100%; overflow-x: hidden;}
body {margin: 0; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5;}
a {color: var(--accent);}
:focus-visible {outline: 2px solid var(--accent); outline-offset: 2px; border-radius: 4px;}
/* masthead */
.masthead {background: linear-gradient(135deg, var(--accent), var(--accent-2)); color: #fff; padding: 18px 20px 20px;}
.masthead-inner {max-width: 1180px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 14px; justify-content: space-between; align-items: flex-start;}
.title-row {display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%;}
.masthead h1 {margin: 0; font-size: 1.3rem; letter-spacing: -0.01em; font-weight: 650;}
.host-meta {margin: 0; font-family: var(--mono); font-size: .74rem; opacity: .72;}
.toolbar {display: flex; flex-wrap: wrap; gap: 8px; align-items: center;}
.toolbar button, .title-row button {background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.35); color: #fff; border-radius: 7px; padding: 6px 11px; font-size: .8rem; font-family: inherit; cursor: pointer; white-space: nowrap;}
.toolbar button:hover, .title-row button:hover {background: rgba(255,255,255,.26);}
/* summary */
.summary {max-width: 1180px; margin: 0 auto; padding: 14px 16px 0; display: flex; flex-wrap: wrap; gap: 10px;}
.pill {display: inline-flex; align-items: baseline; gap: 7px; background: var(--card); border: 1px solid var(--border); border-radius: 999px; padding: 6px 14px; font-size: .82rem;}
.pill b {font-family: var(--mono); font-size: .88rem; font-weight: 600;}
.pill.error {border-color: var(--err); color: var(--err);}
.pill.warning {border-color: var(--warn); color: var(--warn);}
.pill.ok {border-color: var(--ok); color: var(--ok);}
.dot-live {width: 7px; height: 7px; border-radius: 50%; background: var(--ok); align-self: center;}
.pill.paused .dot-live {background: var(--muted);}
/* layout */
/* Columns rather than a grid: grid rows are as tall as their tallest card,
   which leaves a short card sitting over a hole. Columns just pack. */
main {max-width: 1180px; margin: 0 auto; padding: 16px 16px 40px; column-width: 330px; column-gap: 16px;}
.card {background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; margin: 0 0 16px; break-inside: avoid; -webkit-column-break-inside: avoid;}
.card.wide {column-span: all;}
.card h2 {margin: 0 0 12px; font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); display: flex; align-items: center; flex-wrap: wrap; gap: 8px;}
.card h3 {margin: 18px 0 8px; font-size: .82rem; font-weight: 650; display: flex; align-items: center; flex-wrap: wrap; gap: 8px;}
.card h3:first-of-type {margin-top: 0;}
.card h3 code {font-family: var(--mono); font-size: .74rem; color: var(--muted); font-weight: 400;}
.badge {font-size: .67rem; font-weight: 700; letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; border: 1px solid; text-transform: none;}
.badge.ok {color: var(--ok); border-color: var(--ok);}
.badge.warning {color: var(--warn); border-color: var(--warn);}
.badge.error {color: var(--err); border-color: var(--err);}
.badge.muted {color: var(--muted); border-color: var(--border);}
.note {margin: 0; color: var(--muted); font-size: .84rem;}
.note-aside {font-size: .72rem; padding-top: 8px;}
/* key / value */
dl {margin: 0;}
.kv {display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; border-bottom: 1px solid var(--border);}
.kv:last-child {border-bottom: 0;}
.kv dt {color: var(--muted); font-size: .85rem; flex: none;}
.kv dd {margin: 0; font-family: var(--mono); font-size: .81rem; text-align: right; word-break: break-word;}
/* meters */
/* A fact that never changes, fenced off above the meters so nothing that sits
   still is mistaken for something that failed to update. */
.fixed-fact {display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; padding: 9px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);}
.fixed-fact span:first-child {font-size: .85rem; color: var(--muted);}
.fixed-fact span:last-child {font-family: var(--mono); font-size: .81rem; text-align: right;}
.meter {padding: 10px 0; border-bottom: 1px solid var(--border);}
.meter:last-child {border-bottom: 0;}
.meter-head {display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; align-items: baseline; margin-bottom: 6px;}
.meter-head > span:first-child {font-size: .85rem; color: var(--muted); word-break: break-all;}
.meter-value {font-family: var(--mono); font-size: .81rem; white-space: nowrap;}
.meter-value b {font-weight: 600;}
.meter-value span {color: var(--muted);}
.bar-track {height: 7px; border-radius: 4px; background: var(--track); overflow: hidden;}
.bar-fill {height: 100%; border-radius: 4px; transition: width .5s ease, background .5s ease;}
.trend {padding-top: 12px;}
.spark {width: 100%; height: 40px; display: block;}
.spark-line {fill: none; stroke: var(--accent); stroke-width: 1.5; vector-effect: non-scaling-stroke;}
/* checks */
.checks {list-style: none; margin: 0; padding: 0;}
.checks li {display: flex; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: .88rem;}
.checks li:last-child {border-bottom: 0;}
.dot {width: 9px; height: 9px; border-radius: 50%; flex: none; margin-top: 6px;}
.dot.ok {background: var(--ok);}
.dot.warning {background: var(--warn);}
.dot.error {background: var(--err);}
.dot.info {background: var(--info);}
.check-label {display: block; margin-bottom: 2px; font-family: var(--mono); font-size: .81rem; font-weight: 600;}
.check-detail {color: var(--muted);}
/* extensions */
.ext-groups {display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));}
.ext-group h3 {margin: 0 0 7px; font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; color: var(--muted);}
.ext-list {display: flex; flex-wrap: wrap; gap: 6px;}
.ext {font-family: var(--mono); font-size: .75rem; padding: 3px 9px; border-radius: 6px; border: 1px solid var(--border); color: var(--muted);}
.ext.on {color: var(--ok); border-color: var(--ok);}
.ext.off {text-decoration: line-through; opacity: .5;}
.ext.partial {color: var(--warn); border-color: var(--warn); text-decoration: underline dotted;}
details {margin-top: 14px;}
summary {cursor: pointer; font-size: .82rem; color: var(--accent);}
.all-ext {margin-top: 10px; font-family: var(--mono); font-size: .75rem; color: var(--muted); line-height: 1.9; word-break: break-word;}
/* footer */
footer {max-width: 1180px; margin: 0 auto; padding: 0 16px 36px; color: var(--muted); font-size: .78rem; text-align: center; display: flex; flex-direction: column; gap: 6px;}
.sr-only {position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap;}
@media (max-width: 640px) {
    .masthead {padding: 14px 14px 16px;}
    .masthead h1 {font-size: 1.08rem;}
    .masthead-inner {flex-direction: column; align-items: stretch;}
    .toolbar {width: 100%; gap: 6px;}
    .toolbar button, .title-row button {padding: 6px 9px; font-size: .74rem;}
    .summary {gap: 7px; padding: 12px 14px 0;}
    .pill {padding: 5px 10px; font-size: .76rem;}
    .pill.hide-narrow {display: none;}
    main {padding: 14px 14px 32px; columns: auto 1;}
    .card {padding: 14px 15px;}
    .kv {gap: 10px;}
}
@media (prefers-reduced-motion: reduce) {* {transition: none !important;}}
@media print {
    .masthead {background: none; color: #000;}
    .toolbar, .trend, .dot-live, .to-top {display: none;}
    main {column-count: 1;}
    .card {break-inside: avoid; margin-bottom: 12px; border-color: #ccc;}
}
</style>
</head>
<body>

<header class="masthead" id="top">
    <div class="masthead-inner">
        <div class="title-row">
            <h1>Server report</h1>
            <button type="button" id="toggle-theme" aria-label="Switch between the light and dark theme">Theme</button>
        </div>
        <p class="host-meta">PHP <?= h(PHP_VERSION) ?> &middot; <?= h((string) $env['PHP interface']) ?></p>
        <div class="toolbar">
            <button type="button" id="copy-md">Copy Markdown</button>
            <button type="button" id="copy-json">Copy JSON</button>
            <?php if ($liveUrl !== null): ?><button type="button" id="toggle-live">Pause updates</button><?php endif; ?>
        </div>
    </div>
</header>

<div class="summary">
    <a href="#anchor-check">
    <?php if ($counts['error'] > 0 && $counts['warning'] > 0): ?>
        <span class="pill error"><b><?= (int) $counts['error'] ?></b> to fix &middot; <b><?= (int) $counts['warning'] ?></b> to review</span>
    <?php elseif ($counts['error'] > 0): ?>
        <span class="pill error"><b><?= (int) $counts['error'] ?></b> to fix</span>
    <?php elseif ($counts['warning'] > 0): ?>
        <span class="pill warning"><b><?= (int) $counts['warning'] ?></b> to review</span>
    <?php else: ?>
        <span class="pill ok">Nothing to fix</span>
    <?php endif; ?>
    </a>
    <?php if ($metrics['uptime_seconds'] !== null): ?>
        <span class="pill">Uptime <b data-field="uptime"><?= h(human_duration($metrics['uptime_seconds'])) ?></b></span>
    <?php endif; ?>
    <span class="pill hide-narrow">Server time <b data-field="server-time"><?= h((string) $metrics['server_time']) ?></b></span>
    <?php if ($liveUrl !== null): ?>
        <span class="pill" id="live-pill"><span class="dot-live"></span><span data-live-label>Live</span></span>
    <?php endif; ?>
</div>

<main>

    <?php if ($hasResources): ?>
    <section class="card">
        <h2>Resources</h2>
        <?php if ($cpu['model'] !== null): ?>
        <div class="fixed-fact"><span>CPU</span><span><?= h($cpu['model']) ?></span></div>
        <?php endif; ?>
        <?php if ($load !== null): ?>
        <div class="meter">
            <div class="meter-head"><span>Load average<?= $cpu['cores'] ? ' &middot; ' . (int) $cpu['cores'] . ' cores' : '' ?></span><?= meter_value('load-text', implode(' / ', array_map('strval', $load)), $loadPerCore, 'load-pct') ?></div>
            <?php if ($loadPerCore !== null): ?><?= bar('cpu', $loadPerCore, '--cpu') ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($memory !== null): ?>
        <div class="meter">
            <div class="meter-head"><span>RAM</span><?= meter_value('ram-text', human_bytes($memory['used']) . ' / ' . human_bytes($memory['total']), $memoryPercent, 'ram-pct') ?></div>
            <?= bar('ram', $memoryPercent, '--ram') ?>
        </div>
        <?php endif; ?>
        <?php if ($swap !== null): ?>
        <div class="meter">
            <div class="meter-head"><span>Swap</span><?= meter_value('swap-text', human_bytes($swap['used']) . ' / ' . human_bytes($swap['total']), $swapPercent, 'swap-pct') ?></div>
            <?= bar('swap', $swapPercent, '--ram') ?>
        </div>
        <?php endif; ?>
        <?php foreach ($readableDisks as $index => $disk): ?>
        <div class="meter">
            <div class="meter-head"><span>Disk<?= $showDiskPaths ? ' ' . h($disk['path']) : '' ?></span><?= meter_value(
                'disk-' . (int) $index . '-text',
                $disk['reported']
                    ? human_bytes($disk['used']) . ' / ' . human_bytes($disk['total'])
                    : human_bytes($disk['total']) . ' total, usage not reported',
                $disk['reported'] ? $disk['percent'] : null,
                'disk-' . (int) $index . '-pct'
            ) ?></div>
            <?php if ($disk['reported']): ?>
            <?= bar('disk-' . $index, $disk['percent'], '--disk') ?>
            <?php endif; ?>
            <?php if ($index === array_key_last($readableDisks)): ?>
            <p class="note note-aside">Volume total — any account quota is invisible to PHP.</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?= $liveUrl !== null ? trend_chart($trendSeries) : '' ?>
        <?php if ($metrics['thermal'] !== []): ?>
        <dl>
            <?php foreach ($metrics['thermal'] as $zoneIndex => $zone): ?>
            <div class="kv"><dt><?= h($zone['label']) ?></dt><dd data-field="thermal-<?= (int) $zoneIndex ?>"><?= h((string) $zone['celsius']) ?> °C</dd></div>
            <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2>Host</h2>
        <dl>
            <?php foreach ($env as $key => $value): ?>
            <div class="kv"><dt><?= h((string) $key) ?></dt><dd<?= $key === 'Server time' ? ' data-field="server-time"' : '' ?>><?= h((string) $value) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section class="card">
        <h2>PHP</h2>
        <dl>
            <?php foreach ($phpConfig as $key => $value): ?>
            <div class="kv"><dt><?= h((string) $key) ?></dt><dd><?= h((string) $value) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section class="card wide">
        <h2>Caches</h2>

        <h3>OPcache<?php if (!$opcache['enabled']): ?>
            <span class="badge <?= $opcache['available'] ? 'warning' : 'muted' ?>"><?= $opcache['available'] ? 'installed, disabled' : 'not installed' ?></span>
        <?php endif; ?></h3>
        <?php if ($opcache['available']): ?>
        <dl>
            <?php if (!empty($opcache['status_disabled'])): ?>
            <?= cache_row('Status', 'active, metrics unavailable (opcache_get_status() disabled by host)') ?>
            <?php endif; ?>
            <?php if (isset($opcache['mem_total'])): ?>
            <?= cache_row('Memory', usage_text($opcache['percent'], human_bytes($opcache['mem_used']) . ' / ' . human_bytes($opcache['mem_total'])), 'opcache-mem') ?>
            <?php endif; ?>
            <?php if (isset($opcache['hit_rate'])): ?>
            <?= cache_row('Hit rate', $opcache['hit_rate'] . '%', 'opcache-hits') ?>
            <?php endif; ?>
            <?php if (isset($opcache['scripts'])): ?>
            <?= cache_row('Cached scripts', (string) $opcache['scripts'], 'opcache-scripts') ?>
            <?php endif; ?>
            <?php if (isset($opcache['keys'], $opcache['max_keys'])): ?>
            <?= cache_row('Keys', $opcache['keys'] . ' / ' . $opcache['max_keys']) ?>
            <?php endif; ?>
            <?php if (!$opcache['status_disabled']): ?>
            <?= cache_row('JIT', $opcache['jit'] === null ? 'n/a' : ($opcache['jit'] ? 'enabled' : 'disabled')) ?>
            <?= cache_row('Restarts', (string) (int) ($opcache['restarts'] ?? 0)) ?>
            <?php endif; ?>
            <?= cache_row('Per-file invalidate', $opcache['invalidate_available'] ? 'available' : 'disabled by host') ?>
        </dl>
        <?php else: ?>
        <p class="note">Without an opcode cache PHP recompiles every file on every request.</p>
        <?php endif; ?>

        <h3>APCu<?php if (!$apcu['available']): ?>
            <span class="badge <?= extension_loaded('apcu') ? 'warning' : 'muted' ?>"><?= extension_loaded('apcu') ? 'loaded, disabled' : 'not installed' ?></span>
        <?php endif; ?></h3>
        <?php if ($apcu['available']): ?>
        <dl>
            <?php if ($apcu['version'] !== null): ?>
            <?= cache_row('Version', $apcu['version']) ?>
            <?php endif; ?>
            <?php if (isset($apcu['mem_total'])): ?>
            <?= cache_row('Memory', usage_text($apcu['percent'], human_bytes($apcu['mem_used']) . ' / ' . human_bytes($apcu['mem_total'])), 'apcu-mem') ?>
            <?php endif; ?>
            <?= cache_row('Hit rate', $apcu['hit_rate'] === null ? 'n/a' : $apcu['hit_rate'] . '%', 'apcu-hits') ?>
            <?= cache_row('Entries', (string) ($apcu['entries'] ?? 'n/a'), 'apcu-entries') ?>
        </dl>
        <?php else: ?>
        <p class="note">No local key-value cache for this PHP process.</p>
        <?php endif; ?>

        <?php foreach ([
            ['label' => 'Redis', 'prefix' => 'redis', 'servers' => $redisServers, 'client' => $redisClient,
                'stored' => 'Keys', 'clients' => 'Clients', 'evicted' => 'Evicted keys'],
            ['label' => 'Memcached', 'prefix' => 'mc', 'servers' => $memcachedServers, 'client' => $memcachedClient,
                'stored' => 'Items', 'clients' => 'Connections', 'evicted' => 'Evictions'],
        ] as $group): ?>
        <?php foreach ($group['servers'] as $index => $server): ?>
        <?php $id = $group['prefix'] . '-' . $index; ?>
        <h3><?= h($group['label']) ?> <code><?= h($server['target']) ?></code>
            <?php if ($server['note'] !== null): ?><span class="badge warning">limited</span><?php endif; ?>
            <?php if (!$group['client']): ?><span class="badge warning">no PHP client</span><?php endif; ?>
        </h3>
        <?php if ($server['note'] !== null): ?>
        <p class="note"><?= h($server['note']) ?>.</p>
        <?php else: ?>
        <dl>
            <?= cache_row('Version', (string) ($server['version'] ?? 'n/a')) ?>
            <?= cache_row('Memory', usage_text($server['percent'] ?? null, human_bytes($server['mem_used']) . ($server['mem_total'] !== null
                ? ' / ' . human_bytes($server['mem_total'])
                : ($group['prefix'] === 'redis' ? ' (no maxmemory)' : ''))), $id . '-mem') ?>
            <?= cache_row('Hit rate', $server['hit_rate'] === null ? 'n/a' : $server['hit_rate'] . '%', $id . '-hits') ?>
            <?= cache_row($group['stored'], (string) ($server['keys'] ?? 0), $id . '-keys') ?>
            <?= cache_row($group['clients'], (string) ($server['clients'] ?? 'n/a'), $id . '-clients') ?>
            <?= cache_row('Uptime', human_duration($server['uptime'])) ?>
            <?= cache_row($group['evicted'], (string) ($server['evicted'] ?? 0), $id . '-evicted') ?>
        </dl>
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($group['servers'] === []): ?>
        <h3><?= h($group['label']) ?> <span class="badge muted">no server</span></h3>
        <p class="note"><?= $group['client']
            ? 'The PHP client is installed, but nothing answered on the ' . count($cacheTried) . ' addresses checked, so nothing is using it.'
            : 'No server answered on the ' . count($cacheTried) . ' addresses checked, and no PHP client is installed.' ?></p>
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($otherServers !== []): ?>
        <?php
        $otherTargets = [];
        foreach ($otherServers as $server) {
            $otherTargets[] = $server['target'];
        }
        ?>
        <h3>Unidentified services <span class="badge muted"><?= count($otherTargets) ?></span></h3>
        <p class="note">These accept a connection but answer neither Redis nor Memcached: <?= h(implode(', ', $otherTargets)) ?>.
        <?= count($otherTargets) > 2 ? 'When every address answers like this, it is normally a firewall accepting connections it never serves, not a cache.' : '' ?></p>
        <?php endif; ?>

        <?php if ($redisServers === [] || $memcachedServers === []): ?>
        <details>
            <summary>Addresses checked</summary>
            <div class="all-ext"><?= h(implode(' · ', $cacheTried)) ?></div>
        </details>
        <?php endif; ?>
    </section>

    <section class="card wide" id="anchor-check">
        <h2>Checks
            <?php if ($counts['error'] > 0): ?><span class="badge error"><?= (int) $counts['error'] ?> to fix</span><?php endif; ?>
            <?php if ($counts['warning'] > 0): ?><span class="badge warning"><?= (int) $counts['warning'] ?> to review</span><?php endif; ?>
            <?php if ($counts['error'] === 0 && $counts['warning'] === 0): ?><span class="badge ok">all clear</span><?php endif; ?>
        </h2>
        <ul class="checks">
            <?php foreach ($checks as $entry): ?>
            <li>
                <span class="dot <?= h($entry['level']) ?>"></span>
                <span><span class="check-label"><?= h($entry['label']) ?></span> <span class="check-detail"><?= h($entry['detail']) ?></span></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card wide">
        <h2>Extensions <span class="badge muted"><?= count($extensions) ?> loaded</span></h2>
        <div class="ext-groups">
            <?php foreach (EXTENSION_GROUPS as $groupName => $groupExtensions): ?>
            <div class="ext-group">
                <h3><?= h((string) $groupName) ?></h3>
                <div class="ext-list">
                    <?php foreach ($groupExtensions as $name): ?>
                    <?php
                    if ($name === 'opcache') {
                        $state = $opcache['enabled'] ? 'on' : ($opcache['available'] ? 'partial' : 'off');
                    } elseif ($name === 'apcu' && extension_loaded('apcu') && !$apcu['available']) {
                        $state = 'partial';
                    } else {
                        $state = extension_loaded($name) ? 'on' : 'off';
                    }
                    ?>
                    <span class="ext <?= h($state) ?>"<?= $state === 'partial' ? ' title="Loaded but not enabled"' : '' ?>><?= h($name) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <details>
            <summary>All <?= count($extensions) ?> loaded extensions</summary>
            <div class="all-ext"><?= h(implode(' · ', $extensions)) ?></div>
        </details>
    </section>

</main>

<footer>
    <div><a href="<?= h(APP_HOMEPAGE) ?>" target="_blank" rel="noopener noreferrer">system-info.php
        v<?= h(APP_VERSION) ?></a> · <a class="to-top" href="#top">top ^</a></div>
    <div>report generated <?= h(date('Y-m-d H:i:s T')) ?></div>
    <div>Delete this file from the server when you are done with it.</div>
</footer>

<textarea id="src-md" class="sr-only" readonly hidden><?= h($exportMarkdown) ?></textarea>
<textarea id="src-json" class="sr-only" readonly hidden><?= h((string) $exportJson) ?></textarea>

<script nonce="<?= h($nonce) ?>">
(function () {
    'use strict';

    var CONFIG = <?= json_encode($bootConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    /* The PIN is the query string, so it sits in the address bar for anyone
       standing behind you, in any screenshot, and in any URL copied from there.
       Once this page has rendered the PIN has done its job: it moves into the
       tab's own storage, where a reload can still find it and closing the tab
       throws it away, and the address bar is rewritten without it.

       Decided here rather than server-side: this page is only ever reached with
       the right PIN, so a query string on it is the PIN, and there is no flag to
       get wrong. Live updates hold their own copy of the URL. */
    if (location.search) {
        var submittedPin = location.search.slice(1).split('&')[0];
        if (CONFIG.pinMemoryMs > 0 && /^[0-9]+$/.test(submittedPin)) {
            /* Session storage alone is not enough: the spec has a tab inherit a copy
               of it from whichever tab opened it, so a new tab from a link would
               arrive already holding the PIN. window.name is the other half — it
               survives a reload of this tab and is empty in any tab that is not
               literally this one. The PIN is only handed back when the two agree.

               The marker is a throwaway token, never the PIN: window.name can be
               read by whatever page the tab visits next, and a meaningless string
               is all it should ever find. */
            try {
                var tabToken = Date.now().toString(36) + Math.random().toString(36).slice(2);
                window.name = 'system-info:' + tabToken;
                /* Deadline by the browser's own clock, which is the only one this
                   page has. A machine whose clock jumps forward simply asks again. */
                sessionStorage.setItem('system-info-pin:' + location.pathname,
                    tabToken + ':' + (Date.now() + CONFIG.pinMemoryMs) + ':' + submittedPin);
            } catch (e) {}
        }
        try { history.replaceState(null, '', location.pathname); } catch (e) {}
    }

    /* ---- theme ---- */
    var themeButton = document.getElementById('toggle-theme');

    function paintThemeButton() {
        var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'Dark' : 'Light';
        themeButton.textContent = 'Theme: ' + current;
    }

    themeButton.addEventListener('click', function () {
        var root = document.documentElement;
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('system-info-theme', next); } catch (e) {}
        paintThemeButton();
    });

    paintThemeButton();

    /* ---- copy buttons: the report is already in the page, hidden ---- */
    function copy(kind, button) {
        var field = document.getElementById('src-' + kind);
        var label = button.getAttribute('data-label') || button.textContent;
        button.setAttribute('data-label', label);

        function finish(ok) {
            button.textContent = ok ? 'Copied' : 'Copy failed';
            setTimeout(function () { button.textContent = label; }, 1600);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(
                function () { finish(true); },
                function () { finish(false); }
            );
            return;
        }
        field.removeAttribute('hidden');
        field.select();
        try { finish(document.execCommand('copy')); } catch (e) { finish(false); }
        field.setAttribute('hidden', '');
    }

    document.getElementById('copy-md').addEventListener('click', function () { copy('md', this); });
    document.getElementById('copy-json').addEventListener('click', function () { copy('json', this); });

    /* ---- live updates ---- */
    if (!CONFIG.liveUrl || !CONFIG.refreshSeconds) { return; }

    var pill = document.getElementById('live-pill');
    var pillLabel = pill.querySelector('[data-live-label]');
    var toggle = document.getElementById('toggle-live');
    var timer = null;
    var paused = false;
    var failures = 0;
    var trendHistory = {};

    Object.keys(CONFIG.seed || {}).forEach(function (key) {
        if (CONFIG.seed[key] !== null) { trendHistory[key] = [CONFIG.seed[key]]; }
    });

    function humanBytes(bytes) {
        if (bytes === null || bytes === undefined || bytes < 0) { return 'n/a'; }
        var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(i >= 2 ? 1 : 0) + ' ' + units[i];
    }

    function humanDuration(seconds) {
        if (seconds === null || seconds === undefined || seconds < 0) { return 'n/a'; }
        var total = Math.floor(seconds);
        var days = Math.floor(total / 86400);
        var hours = Math.floor((total % 86400) / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        if (days > 0) { return days + 'd ' + hours + 'h ' + minutes + 'm'; }
        if (hours > 0) { return hours + 'h ' + minutes + 'm'; }
        return minutes + 'm';
    }

    function levelColor(value, fallback) {
        if (value === null || value === undefined) { return fallback; }
        if (value > 90) { return 'var(--err)'; }
        if (value > 75) { return 'var(--warn)'; }
        return fallback;
    }

    function setText(field, value) {
        if (value === null || value === undefined) { return; }
        var nodes = document.querySelectorAll('[data-field="' + field + '"]');
        for (var i = 0; i < nodes.length; i++) { nodes[i].textContent = value; }
    }

    function setBar(id, value, fallback) {
        var el = document.querySelector('[data-bar="' + id + '"]');
        if (!el || value === null || value === undefined) { return; }
        el.style.width = Math.max(0, Math.min(100, value)) + '%';
        el.style.background = levelColor(value, fallback);
    }

    function setPercent(field, value) {
        if (value === null || value === undefined) { return; }
        setText(field, Math.max(0, Math.min(100, value)).toFixed(1) + '%');
    }

    function usageText(percent, text) {
        if (percent === null || percent === undefined) { return text; }
        return Math.max(0, Math.min(100, percent)).toFixed(1) + '% · ' + text;
    }

    /* Every series shares the one 0-100 scale, so the lines mean the same thing. */
    function pushHistory(id, value) {
        if (value === null || value === undefined) { return; }
        if (!trendHistory[id]) { trendHistory[id] = []; }
        trendHistory[id].push(Math.max(0, Math.min(100, value)));
        if (trendHistory[id].length > CONFIG.historyPoints) { trendHistory[id].shift(); }

        var line = document.querySelector('[data-spark="' + id + '"]');
        if (!line || trendHistory[id].length < 2) { return; }
        var series = trendHistory[id];
        var step = 300 / (CONFIG.historyPoints - 1);
        var points = series.map(function (point, index) {
            var x = (index + (CONFIG.historyPoints - series.length)) * step;
            var y = 38 - (point / 100) * 34;
            return x.toFixed(1) + ',' + y.toFixed(1);
        });
        line.setAttribute('points', points.join(' '));
    }

    function applyServer(prefix, server, index) {
        var id = prefix + '-' + index;
        if (!server || server.note) { return; }
        setText(id + '-mem', usageText(server.percent, humanBytes(server.mem_used)
            + (server.mem_total ? ' / ' + humanBytes(server.mem_total) : (prefix === 'redis' ? ' (no maxmemory)' : ''))));
        setText(id + '-keys', String(server.keys === null || server.keys === undefined ? 0 : server.keys));
        setText(id + '-hits', server.hit_rate === null ? 'n/a' : server.hit_rate + '%');
        setText(id + '-clients', server.clients === null || server.clients === undefined ? 'n/a' : String(server.clients));
        setText(id + '-evicted', String(server.evicted === null || server.evicted === undefined ? 0 : server.evicted));
    }

    function apply(metrics) {
        if (!metrics) { return; }
        setText('server-time', metrics.server_time);
        setText('uptime', humanDuration(metrics.uptime_seconds));

        if (metrics.load_average) {
            setText('load-text', metrics.load_average.join(' / '));
            if (metrics.cpu && metrics.cpu.cores) {
                var perCore = Math.min(100, metrics.load_average[0] / metrics.cpu.cores * 100);
                setBar('cpu', perCore, 'var(--cpu)');
                setPercent('load-pct', perCore);
                pushHistory('cpu', perCore);
            }
        }

        if (metrics.memory) {
            var ramText = humanBytes(metrics.memory.used) + ' / ' + humanBytes(metrics.memory.total);
            setText('ram-text', ramText);
            setBar('ram', metrics.memory.percent, 'var(--ram)');
            setPercent('ram-pct', metrics.memory.percent);
            pushHistory('ram', metrics.memory.percent);
        }

        if (metrics.swap) {
            setText('swap-text', humanBytes(metrics.swap.used) + ' / ' + humanBytes(metrics.swap.total));
            setBar('swap', metrics.swap.percent, 'var(--ram)');
            setPercent('swap-pct', metrics.swap.percent);
        }

        (metrics.disks || []).forEach(function (disk, index) {
            if (disk.total === null) { return; }
            if (!disk.reported) {
                setText('disk-' + index + '-text', humanBytes(disk.total) + ' total, usage not reported');
                return;
            }
            setText('disk-' + index + '-text', humanBytes(disk.used) + ' / ' + humanBytes(disk.total));
            setBar('disk-' + index, disk.percent, 'var(--disk)');
            setPercent('disk-' + index + '-pct', disk.percent);
        });

        (metrics.thermal || []).forEach(function (zone, index) {
            setText('thermal-' + index, zone.celsius + ' °C');
        });

        if (metrics.opcache && metrics.opcache.available) {
            if (metrics.opcache.mem_total) {
                setText('opcache-mem', usageText(metrics.opcache.percent,
                    humanBytes(metrics.opcache.mem_used) + ' / ' + humanBytes(metrics.opcache.mem_total)));
            }
            if (metrics.opcache.hit_rate !== null && metrics.opcache.hit_rate !== undefined) {
                setText('opcache-hits', metrics.opcache.hit_rate + '%');
            }
            if (metrics.opcache.scripts !== null && metrics.opcache.scripts !== undefined) {
                setText('opcache-scripts', String(metrics.opcache.scripts));
            }
        }

        if (metrics.apcu && metrics.apcu.available) {
            setText('apcu-entries', String(metrics.apcu.entries));
            setText('apcu-hits', metrics.apcu.hit_rate === null ? 'n/a' : metrics.apcu.hit_rate + '%');
            if (metrics.apcu.mem_total) {
                setText('apcu-mem', usageText(metrics.apcu.percent,
                    humanBytes(metrics.apcu.mem_used) + ' / ' + humanBytes(metrics.apcu.mem_total)));
            }
        }

        (metrics.redis || []).forEach(function (server, index) { applyServer('redis', server, index); });
        (metrics.memcached || []).forEach(function (server, index) { applyServer('mc', server, index); });
    }

    function setState(className, label) {
        if (className === 'paused') { pill.classList.add('paused'); } else { pill.classList.remove('paused'); }
        pillLabel.textContent = label;
    }

    function tick() {
        fetch(CONFIG.liveUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json();
            })
            .then(function (data) {
                failures = 0;
                setState('live', 'Live');
                apply(data.metrics);
            })
            .catch(function () {
                failures++;
                // Three misses in a row means the page is stale, not flaky.
                if (failures >= 3) {
                    clearInterval(timer);
                    setState('paused', 'Updates stopped, reload the page');
                    toggle.textContent = 'Resume updates';
                    paused = true;
                    return;
                }
                setState('paused', 'Reconnecting');
            });
    }

    function start() {
        paused = false;
        failures = 0;
        setState('live', 'Live');
        toggle.textContent = 'Pause updates';
        clearInterval(timer);
        timer = setInterval(tick, CONFIG.refreshSeconds * 1000);
    }

    function stop() {
        paused = true;
        clearInterval(timer);
        setState('paused', 'Paused');
        toggle.textContent = 'Resume updates';
    }

    toggle.addEventListener('click', function () { paused ? start() : stop(); });

    // A background tab should not keep polling the server.
    document.addEventListener('visibilitychange', function () {
        if (paused) { return; }
        clearInterval(timer);
        if (!document.hidden) {
            tick();
            timer = setInterval(tick, CONFIG.refreshSeconds * 1000);
        }
    });

    start();
})();
</script>
</body>
</html>
