<?php
/**
 * TacticalRMM Diagnose – Löschen nach Diagnose!
 * Aufruf: rmm_test.php?record=ACC27  ODER  rmm_test.php?record=101
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(120);
ini_set('memory_limit', '256M');
header('X-Accel-Buffering: no'); // nginx-Buffering deaktivieren
header('Content-Type: text/html; charset=utf-8');

// ── Log-Datei ─────────────────────────────────────────────────────────────────
chdir(__DIR__);
$_logFile = __DIR__ . '/logs/rmm_diag.log';
if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);
function diag(string $msg): void {
    global $_logFile;
    @file_put_contents($_logFile, date('H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
diag("=== START " . date('Y-m-d H:i:s') . " record=" . ($_GET['record'] ?? '') . " ===");

// ── Bootstrap DB ─────────────────────────────────────────────────────────────
if (!file_exists('config.php')) die('FEHLER: Script muss im berliCRM-Root liegen.');
$dbconfig = [];
require_once 'config.php';
if (empty($dbconfig['db_hostname']) && file_exists('config.db.php')) require_once 'config.db.php';

$pdo = null;
try {
    $host   = $dbconfig['db_hostname'] ?? $dbconfig['db_server'] ?? '127.0.0.1';
    $port   = !empty($dbconfig['db_port']) ? (int)$dbconfig['db_port'] : 3306;
    $dbname = $dbconfig['db_name']     ?? '';
    $user   = $dbconfig['db_username'] ?? $dbconfig['db_user'] ?? '';
    $pass   = $dbconfig['db_password'] ?? '';
    $pdo    = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8", $user, $pass,
                      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    die('DB-Fehler: ' . htmlspecialchars($e->getMessage()));
}

function db_row(PDO $pdo, string $sql, array $p = []): ?array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function db_all(PDO $pdo, string $sql, array $p = []): array {
    $st = $pdo->prepare($sql); $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// ── HTML-Kopf ─────────────────────────────────────────────────────────────────
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4}
h2{color:#569cd6}.ok{color:#4ec9b0}.warn{color:#dcdcaa}.err{color:#f44747}
pre{background:#252526;padding:10px;border-radius:4px;overflow:auto;white-space:pre-wrap}
hr{border-color:#333}
</style></head><body><h2>TacticalRMM Diagnose</h2>';
echo "<p class='ok'>DB OK ({$dbname}@{$host})</p>";
diag("DB OK");

// ── 1. Account aus DB ─────────────────────────────────────────────────────────
echo '<hr><h2>Schritt 1: Account</h2>';
$param = trim($_GET['record'] ?? '');
if ($param === '') {
    echo '<p class="warn">?record=ACC27 oder ?record=101 angeben</p><pre>';
    foreach (db_all($pdo, 'SELECT accountid, accountname, account_no FROM vtiger_account ORDER BY accountname LIMIT 20') as $r) {
        echo "accountid={$r['accountid']}  account_no={$r['account_no']}  name={$r['accountname']}\n";
    }
    echo '</pre></body></html>'; exit;
}
$row = is_numeric($param)
    ? db_row($pdo, 'SELECT accountid,accountname,account_no FROM vtiger_account WHERE accountid=?', [(int)$param])
    : db_row($pdo, 'SELECT accountid,accountname,account_no FROM vtiger_account WHERE account_no=?',  [$param]);
if (!$row) { echo "<p class='err'>Account nicht gefunden: " . htmlspecialchars($param) . "</p></body></html>"; exit; }

$accountId   = (int)$row['accountid'];
$accountNo   = trim($row['account_no']);
$accountName = $row['accountname'];
echo "<p class='ok'>Name: <b>" . htmlspecialchars($accountName) . "</b> | accountid: <b>{$accountId}</b> | account_no: <b>" . htmlspecialchars($accountNo) . "</b></p>";
diag("Account: id={$accountId} no={$accountNo}");

// ── 2. config_rmm.php ─────────────────────────────────────────────────────────
echo '<hr><h2>Schritt 2: config_rmm.php</h2>';
if (!file_exists(__DIR__ . '/config_rmm.php')) { echo "<p class='err'>config_rmm.php fehlt</p></body></html>"; exit; }
$cfg = require __DIR__ . '/config_rmm.php';
$rmmUrl   = rtrim($cfg['rmm_url']   ?? '', '/');
$rmmToken = $cfg['rmm_token'] ?? '';
if (!$rmmUrl || !$rmmToken) { echo "<p class='err'>rmm_url oder rmm_token fehlt</p></body></html>"; exit; }
echo "<p class='ok'>rmm_url: <b>" . htmlspecialchars($rmmUrl) . "</b></p>";
echo "<p class='ok'>rmm_token: <b>" . str_repeat('*', max(4, strlen($rmmToken)-4)) . substr($rmmToken,-4) . "</b></p>";

// ── API-Helper ────────────────────────────────────────────────────────────────
function rmm_get(string $url, string $token): array {
    global $rmmUrl, $rmmToken;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $token, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err) return [null, $err ?: 'cURL-Fehler', $code, ''];
    if ($code < 200 || $code >= 300) return [null, "HTTP {$code}", $code, (string)$body];
    $data = json_decode($body, true);
    if (!is_array($data)) return [null, 'Ungültige JSON-Antwort', $code, (string)$body];
    return [$data, null, $code, (string)$body];
}
function rmm_match(array $cf, array $fieldIds, string $accountNo): bool {
    if (!isset($cf['value'])) return false;
    if (strtolower(trim((string)$cf['value'])) !== strtolower(trim($accountNo))) return false;
    if (!isset($cf['field'])) return false;
    if (empty($fieldIds)) return true;
    if (is_numeric($cf['field'])) return in_array((int)$cf['field'], $fieldIds, true);
    return strtolower(trim((string)$cf['field'])) === 'berlicrm_id';
}

// ── 3. Custom Field-Definitionen ─────────────────────────────────────────────
echo '<hr><h2>Schritt 3: GET /core/customfields/</h2>';
diag("GET /core/customfields/");
[$cfData, $cfErr, $cfCode] = rmm_get($rmmUrl . '/core/customfields/', $rmmToken);
echo "<p>HTTP: <b class='" . ($cfCode >= 200 && $cfCode < 300 ? 'ok' : 'err') . "'>{$cfCode}</b></p>";
$siteFieldIds = $clientFieldIds = [];
if ($cfErr) {
    echo "<p class='warn'>Fehler: " . htmlspecialchars($cfErr) . " – Fallback ohne Field-IDs</p>";
} else {
    $cfList = $cfData['results'] ?? (array)$cfData;
    echo "<p class='ok'>Anzahl Definitionen: <b>" . count($cfList) . "</b></p>";
    echo '<pre>' . htmlspecialchars(json_encode($cfList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    foreach ($cfList as $cf) {
        if (!is_array($cf)) continue;
        if (strtolower(trim((string)($cf['name'] ?? ''))) !== 'berlicrm_id') continue;
        $id = isset($cf['id']) ? (int)$cf['id'] : null;
        if (!$id) continue;
        $model = strtolower(trim((string)($cf['model'] ?? '')));
        if ($model === 'site')   $siteFieldIds[]   = $id;
        if ($model === 'client') $clientFieldIds[] = $id;
    }
    echo "<p class='ok'>siteFieldIds: [" . implode(',', $siteFieldIds) . "] | clientFieldIds: [" . implode(',', $clientFieldIds) . "]</p>";
}
diag("siteFieldIds=[" . implode(',', $siteFieldIds) . "] clientFieldIds=[" . implode(',', $clientFieldIds) . "]");

$foundClientId = $foundSiteId = null;

// ── 4a. Site-Suche direkt über /clients/sites/ ───────────────────────────────
if (!empty($siteFieldIds)) {
    echo '<hr><h2>Schritt 4a: GET /clients/sites/ (Site-Level berlicrm_id)</h2>';
    diag("GET /clients/sites/");
    [$sitesData, $sErr, $sCode] = rmm_get($rmmUrl . '/clients/sites/', $rmmToken);
    echo "<p>HTTP: <b class='" . ($sCode >= 200 && $sCode < 300 ? 'ok' : 'err') . "'>{$sCode}</b></p>";
    if ($sErr) {
        echo "<p class='err'>Fehler: " . htmlspecialchars($sErr) . "</p>";
    } else {
        $siteList = $sitesData['results'] ?? (array)$sitesData;
        echo "<p class='ok'>Anzahl Sites: <b>" . count($siteList) . "</b></p>";
        if (!empty($siteList)) {
            echo '<p>Rohdaten erste Site:</p><pre>'
                . htmlspecialchars(json_encode($siteList[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                . '</pre>';
        }
        echo '<pre>';
        foreach ($siteList as $i => $site) {
            if (!is_array($site)) continue;
            $sid = $site['id'] ?? '?';
            $sname = htmlspecialchars((string)($site['name'] ?? ''));
            $sclient = $site['client'] ?? '?';
            $cfs = $site['custom_fields'] ?? [];
            $cfStr = [];
            foreach ((array)$cfs as $cf) {
                $match = rmm_match($cf, $siteFieldIds, $accountNo);
                if ($match && !$foundClientId) { $foundSiteId = (int)$sid; $foundClientId = (int)$sclient; }
                $cfStr[] = ($match ? '>>> ' : '    ') . "field=" . ($cf['field'] ?? '?') . " value=" . htmlspecialchars((string)($cf['value'] ?? ''));
            }
            echo "Site[{$i}] id={$sid} client_id={$sclient} name={$sname}" . ($foundSiteId === (int)$sid ? " ← MATCH" : '') . "\n";
            echo ($cfStr ? implode("\n", $cfStr) : '    (keine custom_fields)') . "\n\n";
        }
        echo '</pre>';
    }
    diag("Site-Suche: foundClientId={$foundClientId} foundSiteId={$foundSiteId}");
}

// ── 4b. Client-Suche über /clients/ (nur wenn kein Site-Match) ───────────────
if (!$foundClientId && !empty($clientFieldIds)) {
    echo '<hr><h2>Schritt 4b: GET /clients/ (Client-Level berlicrm_id)</h2>';
    diag("GET /clients/");
    [$clientsData, $cErr, $cCode] = rmm_get($rmmUrl . '/clients/', $rmmToken);
    echo "<p>HTTP: <b class='" . ($cCode >= 200 && $cCode < 300 ? 'ok' : 'err') . "'>{$cCode}</b></p>";
    if ($cErr) {
        echo "<p class='err'>Fehler: " . htmlspecialchars($cErr) . "</p>";
    } else {
        $clientList = $clientsData['results'] ?? (array)$clientsData;
        echo "<p class='ok'>Anzahl Clients: <b>" . count($clientList) . "</b></p>";
        echo '<pre>';
        foreach ($clientList as $i => $client) {
            if (!is_array($client)) continue;
            $cid   = $client['id']   ?? '?';
            $cname = htmlspecialchars((string)($client['name'] ?? ''));
            $cfs   = $client['custom_fields'] ?? [];
            $cfStr = [];
            foreach ((array)$cfs as $cf) {
                $match = rmm_match($cf, $clientFieldIds, $accountNo);
                if ($match && !$foundClientId) $foundClientId = (int)$cid;
                $cfStr[] = ($match ? '>>> ' : '    ') . "field=" . ($cf['field'] ?? '?') . " value=" . htmlspecialchars((string)($cf['value'] ?? ''));
            }
            echo "Client[{$i}] id={$cid} name={$cname}" . ($foundClientId === (int)$cid ? " ← MATCH" : '') . "\n";
            echo ($cfStr ? implode("\n", $cfStr) : '    (keine custom_fields)') . "\n\n";
        }
        echo '</pre>';
    }
    diag("Client-Suche: foundClientId={$foundClientId}");
}

// ── Ergebnis ──────────────────────────────────────────────────────────────────
if (!$foundClientId) {
    echo "<p class='err'>Kein Match für berlicrm_id=<b>" . htmlspecialchars($accountNo) . "</b></p>";
    echo "<p class='warn'>siteFieldIds=[" . implode(',', $siteFieldIds) . "] clientFieldIds=[" . implode(',', $clientFieldIds) . "]</p>";
    diag("KEIN MATCH");
    echo '</body></html>'; exit;
}
if ($foundSiteId) {
    echo "<p class='ok'>MATCH (Site-Level): client_id=<b>{$foundClientId}</b> site_id=<b>{$foundSiteId}</b></p>";
} else {
    echo "<p class='ok'>MATCH (Client-Level): client_id=<b>{$foundClientId}</b></p>";
}

// ── 5. Agents laden ───────────────────────────────────────────────────────────
$agentsUrl = $foundSiteId
    ? $rmmUrl . '/agents/?site=' . $foundSiteId
    : $rmmUrl . '/agents/?client=' . $foundClientId;
echo '<hr><h2>Schritt 5: GET ' . htmlspecialchars(parse_url($agentsUrl, PHP_URL_PATH) . '?' . parse_url($agentsUrl, PHP_URL_QUERY)) . '</h2>';
diag("GET {$agentsUrl}");
[$agentsData, $aErr, $aCode] = rmm_get($agentsUrl, $rmmToken);
echo "<p>HTTP: <b class='" . ($aCode >= 200 && $aCode < 300 ? 'ok' : 'err') . "'>{$aCode}</b></p>";
if ($aErr) {
    echo "<p class='err'>Fehler: " . htmlspecialchars($aErr) . "</p>";
} else {
    $agentList = $agentsData['results'] ?? (array)$agentsData;
    echo "<p class='ok'>Anzahl Agents: <b>" . count($agentList) . "</b></p>";
    echo '<pre>' . htmlspecialchars(json_encode(array_slice($agentList, 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}
diag("Agents: " . (isset($agentList) ? count($agentList) : 'err'));

echo '<hr><p class="ok"><b>Fertig!</b></p>';
echo '<p class="warn">rmm_test.php nach Diagnose löschen!</p>';
echo '<p>Log: <code>' . htmlspecialchars($_logFile) . '</code></p>';
echo '</body></html>';
