<?php
/**
 * TacticalRMM Diagnose-Script
 * Aufruf: http://localhost/berlicrmmft/rmm_test.php?record=<accountid>
 * Beispiel: http://localhost/berlicrmmft/rmm_test.php?record=74
 *
 * Löschen nach Diagnose!
 */

// ── Bootstrap: Direkte PDO-Verbindung über vtiger-Config ─────────────────────
chdir(__DIR__);

if (!file_exists('config.php')) {
    die('FEHLER: Dieses Script muss im berliCRM-Root-Verzeichnis liegen.');
}

// vtiger-Konfiguration einlesen – setzt $dbconfig
$dbconfig = [];
require_once 'config.php';

// Fallback: config.db.php direkt lesen falls $dbconfig leer
if (empty($dbconfig['db_hostname']) && file_exists('config.db.php')) {
    require_once 'config.db.php';
}

// PDO-Verbindung aufbauen
$pdo = null;
$pdoError = null;
try {
    $host   = $dbconfig['db_hostname'] ?? $dbconfig['db_server'] ?? '127.0.0.1';
    $port   = !empty($dbconfig['db_port']) ? (int)$dbconfig['db_port'] : 3306;
    $dbname = $dbconfig['db_name']     ?? '';
    $user   = $dbconfig['db_username'] ?? $dbconfig['db_user'] ?? '';
    $pass   = $dbconfig['db_password'] ?? '';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    $pdoError = $e->getMessage();
}

// Hilfsfunktion: eine Zeile aus PDO-Query holen
function db_row(PDO $pdo, string $sql, array $params = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function db_all(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

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

// ── DB-Verbindung prüfen ─────────────────────────────────────────────────────
if ($pdo === null) {
    echo "<p class='err'>Datenbankverbindung fehlgeschlagen: " . htmlspecialchars($pdoError ?? 'unbekannter Fehler') . "</p>";
    echo '</body></html>';
    exit;
}
echo "<p class='ok'>Datenbankverbindung OK ({$dbname}@{$host})</p><hr>";

// ── 1. record-Parameter (account_no ODER numerische accountid) ───────────────
$param = isset($_GET['record']) ? trim($_GET['record']) : '';
if ($param === '') {
    echo '<p class="warn">Bitte URL-Parameter angeben: <b>?record=&lt;account_no oder accountid&gt;</b><br>';
    echo 'Beispiele: rmm_test.php?record=ACC27 &nbsp;|&nbsp; rmm_test.php?record=101</p>';

    $rows = db_all($pdo, 'SELECT accountid, accountname, account_no FROM vtiger_account ORDER BY accountname LIMIT 20');
    echo '<p>Verfügbare Accounts (erste 20):</p><pre>';
    foreach ($rows as $row) {
        echo "accountid={$row['accountid']}  account_no={$row['account_no']}  name={$row['accountname']}\n";
    }
    echo '</pre></body></html>';
    exit;
}

// Suche per account_no (Text) oder accountid (Zahl)
echo '<h2>Schritt 1: Account aus vtiger_account</h2>';
if (is_numeric($param)) {
    $row = db_row($pdo, 'SELECT accountid, accountname, account_no FROM vtiger_account WHERE accountid = ?', [(int)$param]);
} else {
    $row = db_row($pdo, 'SELECT accountid, accountname, account_no FROM vtiger_account WHERE account_no = ?', [$param]);
}
if (!$row) {
    echo "<p class='err'>Kein Account mit record=" . htmlspecialchars($param) . " gefunden.</p>";
    die('</body></html>');
}
$accountId   = (int) $row['accountid'];
$accountNo   = trim((string) $row['account_no']);
$accountName = $row['accountname'];
echo "<h2>Account: " . htmlspecialchars($accountName) . "</h2><hr>";
echo "<p class='ok'>accountid: <b>{$accountId}</b> | account_no: <b>" . htmlspecialchars($accountNo) . "</b></p>";
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

// ── 3b. API-Call 0: Custom Field-Definitionen ────────────────────────────────
echo '<hr><h2>Schritt 3: GET /core/customfields/</h2>';
[$cfDefsData, $cfErr, $cfHttpCode, $cfRawBody] = rmm_get($rmmUrl . '/core/customfields/', $rmmToken);

echo "<p>HTTP-Status: <b class='" . ($cfHttpCode >= 200 && $cfHttpCode < 300 ? 'ok' : 'err') . "'>{$cfHttpCode}</b></p>";

$clientFieldIds = [];
$siteFieldIds   = [];

if ($cfErr) {
    echo "<p class='warn'>Custom Fields konnten nicht geladen werden: " . htmlspecialchars($cfErr) . "<br>"
       . "Fallback: Nur Wert-Vergleich ohne Field-ID-Filterung.</p>";
} else {
    $cfDefList = isset($cfDefsData['results']) ? $cfDefsData['results'] : $cfDefsData;
    echo "<p class='ok'>Anzahl Custom Field-Definitionen: <b>" . count($cfDefList) . "</b></p>";
    echo '<pre>';
    foreach ($cfDefList as $cfDef) {
        $cfId    = $cfDef['id']    ?? '?';
        $cfName  = $cfDef['name']  ?? '?';
        $cfModel = $cfDef['model'] ?? '?';
        $isBerli = strtolower(trim((string)$cfName)) === 'berlicrm_id';
        $marker  = $isBerli ? ' ← berlicrm_id' : '';
        echo "id={$cfId}  name=" . htmlspecialchars((string)$cfName)
           . "  model=" . htmlspecialchars((string)$cfModel) . $marker . "\n";
        if ($isBerli && is_numeric($cfId)) {
            $cfModelLower = strtolower(trim((string)$cfModel));
            if (str_contains($cfModelLower, 'client')) {
                $clientFieldIds[] = (int)$cfId;
            } elseif (str_contains($cfModelLower, 'site')) {
                $siteFieldIds[] = (int)$cfId;
            }
        }
    }
    echo '</pre>';
    if (empty($clientFieldIds) && empty($siteFieldIds)) {
        echo "<p class='warn'>WARNUNG: Kein Custom Field 'berlicrm_id' gefunden – noch nicht in TacticalRMM angelegt?<br>"
           . "Fallback: Nur Wert-Vergleich ohne Field-ID-Filterung.</p>";
    } else {
        echo "<p class='ok'>clientFieldIds: [" . implode(', ', $clientFieldIds) . "] | "
           . "siteFieldIds: [" . implode(', ', $siteFieldIds) . "]</p>";
    }
}

// ── 4. API-Call 1: Clients ───────────────────────────────────────────────────
echo '<hr><h2>Schritt 4: GET /clients/</h2>';
[$clientsData, $err, $httpCode, $rawBody] = rmm_get($rmmUrl . '/clients/', $rmmToken);

echo "<p>HTTP-Status: <b class='" . ($httpCode >= 200 && $httpCode < 300 ? 'ok' : 'err') . "'>{$httpCode}</b></p>";
if ($err) {
    // HTML-Antwort statt JSON → typisch bei falschem/abgelaufenem API-Token
    if (stripos($rawBody, '<!DOCTYPE') !== false || stripos($rawBody, '<html') !== false) {
        echo "<p class='err'><b>Der Server hat eine HTML-Seite zurückgegeben statt JSON.</b><br>"
           . "Mögliche Ursachen:<br>"
           . "&nbsp;1. API-Token ungültig oder abgelaufen → TacticalRMM: Settings → API Keys → Token prüfen/neu erstellen<br>"
           . "&nbsp;2. Nginx leitet /api/v3/ nicht zur Django-API weiter (Reverse-Proxy-Problem)</p>";
    } else {
        echo "<p class='err'>Fehler: " . htmlspecialchars($err) . "</p>";
    }
    echo "<p>Raw Body (erste 500 Zeichen):</p><pre>" . htmlspecialchars(substr($rawBody, 0, 500)) . "</pre>";
    die('</body></html>');
}

$clientList = isset($clientsData['results']) ? $clientsData['results'] : $clientsData;
echo "<p class='ok'>Anzahl Clients: <b>" . count($clientList) . "</b></p>";

// Raw-Dump des ersten Clients – zeigt ob eingebettete Sites custom_fields enthalten
if (!empty($clientList)) {
    $first = $clientList[0];
    $firstSites = $first['sites'] ?? [];
    $firstSiteHasCF = !empty($firstSites) && isset($firstSites[0]['custom_fields']);
    echo '<p>Erster Client (id=' . ($first['id'] ?? '?') . ') – eingebettete Sites: '
        . count($firstSites) . ', erste Site hat custom_fields: '
        . '<b class="' . ($firstSiteHasCF ? 'ok' : 'warn') . '">'
        . ($firstSiteHasCF ? 'JA' : 'NEIN – GET /clients/sites/ wird als Fallback genutzt') . '</b></p>';

    // Rohdaten des ersten Clients (Schlüssel-Übersicht + sites-Array)
    $keys = array_keys($first);
    echo '<p>Verfügbare Felder im Client-Objekt: <b>' . implode(', ', $keys) . '</b></p>';
    if (!empty($firstSites)) {
        echo '<p>Erste eingebettete Site – verfügbare Felder: <b>'
            . implode(', ', array_keys($firstSites[0])) . '</b></p>';
        echo '<p>Rohdaten erste Site:</p><pre>'
            . htmlspecialchars(json_encode($firstSites[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            . '</pre>';
    }
}

echo '<p>Alle Clients + Custom Fields + eingebettete Sites:</p><pre>';
$foundClientId = null;
$foundSiteId   = null;
$matchLevel    = null; // 'client' oder 'site'

foreach ($clientList as $i => $client) {
    $cid    = $client['id']   ?? '?';
    $cname  = $client['name'] ?? '?';
    $fields = $client['custom_fields'] ?? [];
    $sites  = $client['sites'] ?? [];

    $fieldStr = [];
    foreach ($fields as $f) {
        $fn = $f['field'] ?? '(?)';
        $fv = $f['value'] ?? '(?)';
        $match = rmm_match_field($f, $clientFieldIds, $accountNo);
        if ($match && $foundClientId === null) {
            $foundClientId = (int) $cid;
            $matchLevel    = 'client';
        }
        $fieldStr[] = ($match ? '>>>' : '   ') . " field={$fn} value=" . htmlspecialchars((string)$fv);
    }

    $clientMarker = ($foundClientId === (int)$cid && $matchLevel === 'client') ? " ← CLIENT-MATCH" : '';
    echo "Client[{$i}] id={$cid}  name=" . htmlspecialchars($cname) . $clientMarker . "\n";
    if ($fieldStr) {
        echo implode("\n", $fieldStr) . "\n";
    } else {
        echo "   (keine client custom_fields)\n";
    }

    // Eingebettete Sites prüfen
    foreach ($sites as $si => $site) {
        $sid       = $site['id']   ?? '?';
        $sname     = $site['name'] ?? "#{$si}";
        $sfields   = $site['custom_fields'] ?? [];
        $sfieldStr = [];
        foreach ($sfields as $sf) {
            $sfn = $sf['field'] ?? '(?)';
            $sfv = $sf['value'] ?? '(?)';
            $smatch = rmm_match_field($sf, $siteFieldIds, $accountNo);
            if ($smatch && $foundClientId === null) {
                $foundSiteId   = (int) $sid;
                $foundClientId = (int) $cid;
                $matchLevel    = 'site';
            }
            $sfieldStr[] = ($smatch ? '   >>>' : '      ') . " field={$sfn} value=" . htmlspecialchars((string)$sfv);
        }
        $siteMarker = ($foundSiteId === (int)$sid) ? " ← SITE-MATCH (clientId={$cid})" : '';
        echo "  Site[{$si}] id={$sid}  name=" . htmlspecialchars($sname) . $siteMarker . "\n";
        if ($sfieldStr) {
            echo implode("\n", $sfieldStr) . "\n";
        } else {
            echo "      (keine site custom_fields)\n";
        }
    }

    echo "\n";
}
echo '</pre>';

// ── Fallback: GET /clients/sites/ falls Sites im Client-Response keine custom_fields hatten ──
if (!$foundClientId && !empty($siteFieldIds)) {
    echo '<hr><h2>Schritt 4b: Fallback GET /clients/sites/ (Sites haben eigene custom_fields)</h2>';
    [$sitesData, $sErr, $sCode, $sBody] = rmm_get($rmmUrl . '/clients/sites/', $rmmToken);
    echo "<p>HTTP-Status: <b class='" . ($sCode >= 200 && $sCode < 300 ? 'ok' : 'err') . "'>{$sCode}</b></p>";
    if (!$sErr) {
        $siteList = isset($sitesData['results']) ? $sitesData['results'] : $sitesData;
        echo "<p class='ok'>Anzahl Sites: <b>" . count($siteList) . "</b></p>";
        // Rohdaten der ersten Site zeigen
        if (!empty($siteList)) {
            echo '<p>Verfügbare Felder im Site-Objekt: <b>'
                . implode(', ', array_keys($siteList[0])) . '</b></p>';
            echo '<p>Rohdaten erste Site:</p><pre>'
                . htmlspecialchars(json_encode($siteList[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                . '</pre>';
        }
        echo '<pre>';
        foreach ($siteList as $si => $site) {
            $sid     = $site['id']     ?? '?';
            $sname   = $site['name']   ?? "#{$si}";
            $sclient = $site['client'] ?? '?';
            $sfields = $site['custom_fields'] ?? [];
            $sfStr   = [];
            foreach ($sfields as $sf) {
                $sfn = $sf['field'] ?? '(?)';
                $sfv = $sf['value'] ?? '(?)';
                $smatch = rmm_match_field($sf, $siteFieldIds, $accountNo);
                if ($smatch && $foundClientId === null) {
                    $foundSiteId   = (int) $sid;
                    $foundClientId = (int) $sclient;
                    $matchLevel    = 'site';
                }
                $sfStr[] = ($smatch ? '>>>' : '   ') . " field={$sfn} value=" . htmlspecialchars((string)$sfv);
            }
            $siteMarker = ($foundSiteId === (int)$sid) ? " ← SITE-MATCH" : '';
            echo "Site[{$si}] id={$sid}  client_id={$sclient}  name=" . htmlspecialchars($sname) . $siteMarker . "\n";
            echo ($sfStr ? implode("\n", $sfStr) : '   (keine custom_fields)') . "\n\n";
        }
        echo '</pre>';
    } else {
        echo "<p class='err'>" . htmlspecialchars($sErr) . "</p>";
        echo "<pre>" . htmlspecialchars(substr($sBody, 0, 300)) . "</pre>";
    }
}

if (!$foundClientId) {
    echo "<p class='err'>Kein Client/Site mit <b>berlicrm_id=" . htmlspecialchars($accountNo) . "</b> gefunden.</p>";
    echo "<p class='warn'>Prüfe:<br>
    1. Ist das Custom Field in TacticalRMM als <b>berlicrm_id</b> (Kleinschreibung) angelegt?<br>
    2. Stimmt der Wert exakt mit <b>" . htmlspecialchars($accountNo) . "</b> überein?<br>
    3. clientFieldIds=[" . implode(',', $clientFieldIds) . "] siteFieldIds=[" . implode(',', $siteFieldIds) . "]</p>";
    die('</body></html>');
}

if ($matchLevel === 'site') {
    echo "<p class='ok'>Match gefunden (Site-Level): TacticalRMM client_id = <b>{$foundClientId}</b>"
       . " | site_id = <b>{$foundSiteId}</b></p>";
} else {
    echo "<p class='ok'>Match gefunden (Client-Level): TacticalRMM client_id = <b>{$foundClientId}</b></p>";
}

// ── 5. API-Call 2: Agents ────────────────────────────────────────────────────
if ($foundSiteId !== null) {
    $agentsUrl = $rmmUrl . '/agents/?site=' . $foundSiteId;
    echo '<hr><h2>Schritt 5: GET /agents/?site=' . $foundSiteId . ' (Site-Level Match)</h2>';
} else {
    $agentsUrl = $rmmUrl . '/agents/?client=' . $foundClientId;
    echo '<hr><h2>Schritt 5: GET /agents/?client=' . $foundClientId . ' (Client-Level Match)</h2>';
}
[$agentsData, $err, $httpCode, $rawBody] = rmm_get($agentsUrl, $rmmToken);

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

/**
 * Prüft ob ein custom_field-Eintrag zur gesuchten Account-Nummer passt.
 *
 * @param array  $f         Ein Eintrag aus custom_fields (keys: field, value, ...)
 * @param array  $fieldIds  Bekannte numerische IDs für "berlicrm_id" aus /core/customfields/
 * @param string $accountNo Die gesuchte Account-Nummer (z.B. "ACC27")
 */
function rmm_match_field(array $f, array $fieldIds, string $accountNo): bool
{
    // Wert muss (case-insensitiv, ohne Leerzeichen) übereinstimmen
    if (!isset($f['value'])) {
        return false;
    }
    if (strtolower(trim((string) $f['value'])) !== strtolower(trim($accountNo))) {
        return false;
    }

    // Kein field-Key vorhanden → kein Match
    if (!isset($f['field'])) {
        return false;
    }

    // Keine bekannten Field-IDs → Fallback: Wert-Match allein genügt (mit Warnung)
    if (empty($fieldIds)) {
        return true;
    }

    // Numerische field-ID → per in_array prüfen
    if (is_numeric($f['field'])) {
        return in_array((int) $f['field'], $fieldIds, true);
    }

    // String-Wert → Kompatibilität mit älteren TRMM-Versionen
    return strtolower(trim((string) $f['field'])) === 'berlicrm_id';
}
