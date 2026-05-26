<?php
/**
 * TacticalRMM Diagnose-Script
 * Aufruf: http://localhost/berlicrmmft/rmm_test.php?record=<accountid>
 * Beispiel: http://localhost/berlicrmmft/rmm_test.php?record=74
 *
 * Löschen nach Diagnose!
 */

// ── Bootstrap: vtiger-Datenbankzugriff laden ─────────────────────────────────
define('DOING_AJAX', true);
chdir(__DIR__);

if (!file_exists('config.php')) {
    die('FEHLER: Dieses Script muss im berliCRM-Root-Verzeichnis liegen.');
}

require_once 'include/database/PearDatabase.php';
require_once 'config.php';

$db = PearDatabase::getInstance();

echo '<html><head><meta charset="utf-8">
<style>
  body  { font-family: monospace; padding: 20px; background:#1e1e1e; color:#d4d4d4; }
  h2    { color:#569cd6 }
  .ok   { color:#4ec9b0 }
  .warn { color:#dcdcaa }
  .err  { color:#f44747 }
  pre   { background:#252526; padding:10px; border-radius:4px; overflow:auto; }
  hr    { border-color:#333 }
</style></head><body>';

echo '<h2>TacticalRMM Diagnose</h2>';

// ── 1. record-Parameter ──────────────────────────────────────────────────────
$accountId = isset($_GET['record']) ? (int) $_GET['record'] : 0;
if (!$accountId) {
    echo '<p class="warn">Bitte URL-Parameter angeben: <b>?record=&lt;accountid&gt;</b><br>';
    echo 'Beispiel: rmm_test.php?record=74</p>';

    $res  = $db->pquery('SELECT accountid, accountname, account_no FROM vtiger_account ORDER BY accountname LIMIT 20', []);
    echo '<p>Verfügbare Accounts (erste 20):</p><pre>';
    while ($row = $db->fetchByAssoc($res)) {
        echo "accountid={$row['accountid']}  account_no={$row['account_no']}  name={$row['accountname']}\n";
    }
    echo '</pre></body></html>';
    exit;
}

echo "<h2>Account-ID: {$accountId}</h2><hr>";

// ── 2. account_no aus vtiger_account ────────────────────────────────────────
echo '<h2>Schritt 1: account_no aus vtiger_account</h2>';
$res = $db->pquery('SELECT accountid, accountname, account_no FROM vtiger_account WHERE accountid = ?', [$accountId]);
$row = $db->fetchByAssoc($res);
if (!$row) {
    echo "<p class='err'>Kein Account mit accountid={$accountId} gefunden.</p>";
    die('</body></html>');
}
$accountNo   = trim((string) $row['account_no']);
$accountName = $row['accountname'];
echo "<p class='ok'>Name: <b>{$accountName}</b> | account_no: <b>" . htmlspecialchars($accountNo) . "</b></p>";
if ($accountNo === '') {
    echo "<p class='err'>account_no ist leer – Tab würde 'Keine Account-Nummer' anzeigen.</p>";
    die('</body></html>');
}

// ── 3. config_rmm.php laden ──────────────────────────────────────────────────
echo '<hr><h2>Schritt 2: config_rmm.php</h2>';
$cfgPath = __DIR__ . '/config_rmm.php';
if (!file_exists($cfgPath)) {
    echo "<p class='err'>config_rmm.php nicht gefunden unter: {$cfgPath}</p>";
    die('</body></html>');
}
$cfg = require $cfgPath;
if (empty($cfg['rmm_url']) || empty($cfg['rmm_token'])) {
    echo "<p class='err'>config_rmm.php unvollständig (rmm_url oder rmm_token fehlt).</p>";
    die('</body></html>');
}
$rmmUrl   = rtrim($cfg['rmm_url'], '/');
$rmmToken = $cfg['rmm_token'];
echo "<p class='ok'>rmm_url: <b>" . htmlspecialchars($rmmUrl) . "</b></p>";
echo "<p class='ok'>rmm_token: <b>" . str_repeat('*', max(4, strlen($rmmToken) - 4)) . substr($rmmToken, -4) . "</b></p>";

// ── 4. API-Call 1: Clients ───────────────────────────────────────────────────
echo '<hr><h2>Schritt 3: GET /api/v3/clients/</h2>';
[$clientsData, $err, $httpCode, $rawBody] = rmm_get($rmmUrl . '/api/v3/clients/', $rmmToken);

echo "<p>HTTP-Status: <b class='" . ($httpCode >= 200 && $httpCode < 300 ? 'ok' : 'err') . "'>{$httpCode}</b></p>";
if ($err) {
    echo "<p class='err'>Fehler: " . htmlspecialchars($err) . "</p>";
    echo "<p>Raw Body (erste 500 Zeichen):</p><pre>" . htmlspecialchars(substr($rawBody, 0, 500)) . "</pre>";
    die('</body></html>');
}

$clientList = isset($clientsData['results']) ? $clientsData['results'] : $clientsData;
echo "<p class='ok'>Anzahl Clients: <b>" . count($clientList) . "</b></p>";

echo '<p>Alle Clients + Custom Fields:</p><pre>';
$foundClientId = null;
foreach ($clientList as $i => $client) {
    $cid    = $client['id']   ?? '?';
    $cname  = $client['name'] ?? '?';
    $fields = $client['custom_fields'] ?? [];

    $fieldStr = [];
    foreach ($fields as $f) {
        $fn = $f['field'] ?? '(?)';
        $fv = $f['value'] ?? '(?)';
        $match = (strtolower((string)$fn) === 'berlicrm_id' && (string)$fv === $accountNo);
        if ($match) $foundClientId = (int) $cid;
        $fieldStr[] = ($match ? '>>>' : '   ') . " {$fn}=" . htmlspecialchars((string)$fv);
    }
    $marker = $foundClientId === (int)$cid ? " ← MATCH" : '';
    echo "Client[{$i}] id={$cid}  name=" . htmlspecialchars($cname) . $marker . "\n";
    if ($fieldStr) {
        echo implode("\n", $fieldStr) . "\n";
    } else {
        echo "   (keine custom_fields)\n";
    }
    echo "\n";
}
echo '</pre>';

if (!$foundClientId) {
    echo "<p class='err'>Kein Client mit <b>berlicrm_id=" . htmlspecialchars($accountNo) . "</b> gefunden.</p>";
    echo "<p class='warn'>Prüfe:<br>
    1. Ist das Custom Field in TacticalRMM als <b>berlicrm_id</b> (Kleinschreibung) angelegt?<br>
    2. Stimmt der Wert exakt mit <b>" . htmlspecialchars($accountNo) . "</b> überein?</p>";
    die('</body></html>');
}

echo "<p class='ok'>Match gefunden: TacticalRMM client_id = <b>{$foundClientId}</b></p>";

// ── 5. API-Call 2: Agents ────────────────────────────────────────────────────
echo '<hr><h2>Schritt 4: GET /api/v3/agents/?client=' . $foundClientId . '</h2>';
[$agentsData, $err, $httpCode, $rawBody] = rmm_get($rmmUrl . '/api/v3/agents/?client=' . $foundClientId, $rmmToken);

echo "<p>HTTP-Status: <b class='" . ($httpCode >= 200 && $httpCode < 300 ? 'ok' : 'err') . "'>{$httpCode}</b></p>";
if ($err) {
    echo "<p class='err'>Fehler: " . htmlspecialchars($err) . "</p>";
    echo "<pre>" . htmlspecialchars(substr($rawBody, 0, 500)) . "</pre>";
    die('</body></html>');
}

$agentList = isset($agentsData['results']) ? $agentsData['results'] : $agentsData;
echo "<p class='ok'>Anzahl Agents: <b>" . count($agentList) . "</b></p>";

echo '<p>Agent-Rohdaten (erste 3):</p><pre>';
foreach (array_slice($agentList, 0, 3) as $agent) {
    echo htmlspecialchars(json_encode($agent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n\n";
}
echo '</pre>';

// ── 6. Log-Test ──────────────────────────────────────────────────────────────
echo '<hr><h2>Schritt 5: Log-Schreibtest</h2>';
$logPath = __DIR__ . '/logs/rmm_debug.log';
$written = @file_put_contents($logPath, date('Y-m-d H:i:s') . " rmm_test.php OK\n", FILE_APPEND | LOCK_EX);
if ($written === false) {
    echo "<p class='err'>Log nicht schreibbar: {$logPath}<br>"
       . "PHP-Fehler: " . htmlspecialchars(error_get_last()['message'] ?? 'unbekannt') . "</p>";
} else {
    echo "<p class='ok'>Log-Datei schreibbar: {$logPath}</p>";
}

echo '<hr><p class="ok"><b>Alle Schritte erfolgreich!</b> Die Tab-View sollte funktionieren.</p>';
echo '<p class="warn">⚠ rmm_test.php nach der Diagnose löschen!</p>';
echo '</body></html>';

// ── Helper ───────────────────────────────────────────────────────────────────
function rmm_get(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $token, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlErr !== '') {
        return [null, $curlErr ?: 'cURL-Fehler', $httpCode, ''];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return [null, 'HTTP ' . $httpCode, $httpCode, (string) $body];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return [null, 'Ungültige JSON-Antwort', $httpCode, (string) $body];
    }
    return [$data, null, $httpCode, (string) $body];
}
