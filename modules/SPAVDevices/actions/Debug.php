<?php
/**
 * Diagnose-Action: index.php?module=SPAVDevices&action=Debug&record=ACCOUNTID
 * Zeigt alle relevanten Infos für die Fehlersuche (nur für Admins).
 */
require_once __DIR__ . '/../SPAVDevicesHelper.php';

class SPAVDevices_Debug_Action extends Vtiger_Action_Controller {

    public function checkPermission(Vtiger_Request $request): void
    {
        global $current_user;
        if (!is_admin($current_user)) {
            throw new AppException('Nur für Administratoren zugänglich');
        }
    }

    public function process(Vtiger_Request $request): void
    {
        global $dbconfig;

        header('Content-Type: text/html; charset=utf-8');
        $accountId = (int) $request->get('record');

        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<title>SPAVDevices Debug</title>'
           . '<style>body{font-family:monospace;font-size:13px;padding:20px}'
           . 'h2{color:#333}table{border-collapse:collapse;margin:8px 0}'
           . 'td,th{padding:4px 12px;border:1px solid #ccc;text-align:left}'
           . 'th{background:#f0f0f0}.ok{color:green}.err{color:red}.warn{color:#e67e00}'
           . '</style></head><body>';

        echo '<h2>SPAVDevices Diagnose</h2>';
        echo '<p>Account-ID: <strong>' . (int)$accountId . '</strong></p>';

        // ── 1. config_spav.php ────────────────────────────────────────────────
        echo '<h3>1. Konfiguration (config_spav.php)</h3>';
        [$url, $token, $hours, $cfgErr] = SPAVDevicesHelper::loadConfig();
        if ($cfgErr !== null) {
            echo '<p class="err">FEHLER: ' . htmlspecialchars($cfgErr) . '</p>';
        } else {
            echo '<table>';
            echo '<tr><th>Parameter</th><th>Wert</th></tr>';
            echo '<tr><td>spav_url</td><td class="ok">' . htmlspecialchars($url) . '</td></tr>';
            echo '<tr><td>spav_token</td><td class="ok">' . htmlspecialchars(substr($token, 0, 6) . '…') . ' (' . strlen($token) . ' Zeichen)</td></tr>';
            echo '<tr><td>sync_interval_hours</td><td>' . (int)$hours . '</td></tr>';
            echo '</table>';
        }

        // ── 2. Datenbankverbindung ────────────────────────────────────────────
        echo '<h3>2. Datenbankverbindung</h3>';
        try {
            $pdo = SPAVDevicesHelper::getPdo();
            echo '<p class="ok">Verbindung OK (Host: ' . htmlspecialchars($dbconfig['db_server'] ?? '?')
               . ', DB: ' . htmlspecialchars($dbconfig['db_name'] ?? '?') . ')</p>';
        } catch (Exception $e) {
            echo '<p class="err">FEHLER: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</body></html>';
            exit();
        }

        // ── 3. mft_spav_devices Tabelle ───────────────────────────────────────
        echo '<h3>3. Cache-Tabelle mft_spav_devices</h3>';
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'mft_spav_devices'");
            if ($stmt->rowCount() === 0) {
                echo '<p class="err">Tabelle mft_spav_devices existiert NICHT — SQL aus sql/spav_setup.sql ausführen!</p>';
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) AS total, SUM(sync_status='active') AS active, SUM(sync_status='lost') AS lost FROM mft_spav_devices");
                $row  = $stmt->fetch();
                echo '<p class="ok">Tabelle vorhanden. Gesamt: ' . (int)$row['total']
                   . ', aktiv: ' . (int)$row['active'] . ', lost: ' . (int)$row['lost'] . '</p>';

                if ($accountId > 0) {
                    $stmt2 = $pdo->prepare("SELECT COUNT(*) AS n, MAX(last_sync) AS ls FROM mft_spav_devices WHERE account_id=?");
                    $stmt2->execute([$accountId]);
                    $r2 = $stmt2->fetch();
                    echo '<p>Für Account ' . $accountId . ': ' . (int)$r2['n'] . ' Geräte, letzter Sync: '
                       . htmlspecialchars($r2['ls'] ?? 'noch nie') . '</p>';
                }
            }
        } catch (Exception $e) {
            echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        // ── 4. cf_877 Tenant-ID ───────────────────────────────────────────────
        echo '<h3>4. Tenant-ID (cf_877)</h3>';
        if ($accountId <= 0) {
            echo '<p class="warn">Kein record= übergeben — URL mit ?module=SPAVDevices&action=Debug&record=ACCOUNTID aufrufen</p>';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT cf_877 FROM vtiger_accountscf WHERE accountid=?');
                $stmt->execute([$accountId]);
                $row = $stmt->fetch();
                if ($row === false) {
                    echo '<p class="err">Kein Eintrag in vtiger_accountscf für accountid=' . $accountId . '</p>';
                } elseif (trim((string)$row['cf_877']) === '') {
                    echo '<p class="warn">cf_877 ist leer — Tenant-ID am Account eintragen</p>';
                } else {
                    echo '<p class="ok">cf_877 = <strong>' . htmlspecialchars($row['cf_877']) . '</strong></p>';
                }
            } catch (Exception $e) {
                echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }

        // ── 5. vtiger_tab Eintrag ─────────────────────────────────────────────
        echo '<h3>5. vtiger_tab (Modul-Registrierung)</h3>';
        try {
            $stmt = $pdo->query("SELECT tabid, name, presence, tabsequence, tablabel FROM vtiger_tab WHERE name='SPAVDevices'");
            $row  = $stmt->fetch();
            if (!$row) {
                echo '<p class="err">SPAVDevices fehlt in vtiger_tab — SQL ausführen</p>';
            } else {
                echo '<table><tr><th>tabid</th><th>name</th><th>presence</th><th>tabsequence</th><th>tablabel</th></tr>';
                echo '<tr>';
                echo '<td>' . $row['tabid'] . '</td>';
                echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                $presOk = ((int)$row['presence'] !== 1);
                echo '<td class="' . ($presOk ? 'ok' : 'err') . '">' . $row['presence'] . ($presOk ? ' ✓' : ' ✗ (darf nicht 1 sein)') . '</td>';
                $seqOk = ((int)$row['tabsequence'] === -1);
                echo '<td class="' . ($seqOk ? 'ok' : 'err') . '">' . $row['tabsequence'] . ($seqOk ? ' ✓' : ' ✗ (muss -1 sein!) → UPDATE vtiger_tab SET tabsequence=-1 WHERE name=\'SPAVDevices\'') . '</td>';
                echo '<td>' . htmlspecialchars($row['tablabel']) . '</td>';
                echo '</tr></table>';
            }
        } catch (Exception $e) {
            echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        // ── 6. vtiger_relatedlists Eintrag ────────────────────────────────────
        echo '<h3>6. vtiger_relatedlists (Tab-Verknüpfung)</h3>';
        try {
            $stmt = $pdo->query(
                "SELECT r.relation_id, r.tabid, r.related_tabid, r.name, r.presence, t.tabid AS spav_tabid
                 FROM vtiger_relatedlists r
                 LEFT JOIN vtiger_tab t ON t.name='SPAVDevices'
                 WHERE r.name='get_spav_devices'"
            );
            $row = $stmt->fetch();
            if (!$row) {
                echo '<p class="err">Kein Eintrag get_spav_devices in vtiger_relatedlists — SQL ausführen</p>';
            } else {
                echo '<table><tr><th>relation_id</th><th>tabid (Accounts)</th><th>related_tabid</th><th>presence</th></tr>';
                echo '<tr>';
                $ridOk = ((int)$row['relation_id'] > 0);
                echo '<td class="' . ($ridOk ? 'ok' : 'err') . '">' . $row['relation_id'] . ($ridOk ? '' : ' ✗ darf nicht 0 sein') . '</td>';
                echo '<td>' . $row['tabid'] . '</td>';
                $rtOk = ((int)$row['related_tabid'] === (int)$row['spav_tabid'] && (int)$row['related_tabid'] > 0);
                echo '<td class="' . ($rtOk ? 'ok' : 'err') . '">' . $row['related_tabid']
                   . ' (SPAVDevices tabid=' . $row['spav_tabid'] . ')' . ($rtOk ? ' ✓' : ' ✗') . '</td>';
                echo '<td>' . $row['presence'] . '</td>';
                echo '</tr></table>';
            }
        } catch (Exception $e) {
            echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        // ── 7. GraphQL-Test (1 Seite) ─────────────────────────────────────────
        echo '<h3>7. GraphQL API Test</h3>';
        if ($cfgErr !== null) {
            echo '<p class="warn">Übersprungen — config_spav.php fehlerhaft</p>';
        } elseif ($accountId <= 0) {
            echo '<p class="warn">Übersprungen — kein record= übergeben</p>';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT cf_877 FROM vtiger_accountscf WHERE accountid=?');
                $stmt->execute([$accountId]);
                $tidRow = $stmt->fetch();
                $testTid = $tidRow ? trim((string)$tidRow['cf_877']) : '';
                if ($testTid === '') {
                    echo '<p class="warn">Übersprungen — cf_877 leer</p>';
                } else {
                    $gql = 'query Devices($tid:ID,$first:Int!,$page:Int){devices(tid:$tid,first:$first,page:$page){paginatorInfo{hasMorePages}data{deviceid hostname}}}';
                    [$data, $err] = SPAVDevicesHelper::graphqlPost($url, $token, $gql, ['tid' => $testTid, 'first' => 5, 'page' => 1]);
                    if ($err !== null) {
                        echo '<p class="err">API-Fehler: ' . htmlspecialchars($err) . '</p>';
                    } else {
                        $devs = $data['data']['devices']['data'] ?? [];
                        echo '<p class="ok">API antwortet. Erste ' . count($devs) . ' Geräte (max 5):</p>';
                        if (!empty($devs)) {
                            echo '<table><tr><th>deviceid</th><th>hostname</th></tr>';
                            foreach ($devs as $d) {
                                echo '<tr><td>' . htmlspecialchars($d['deviceid'] ?? '') . '</td>'
                                   . '<td>' . htmlspecialchars($d['hostname'] ?? '') . '</td></tr>';
                            }
                            echo '</table>';
                        }
                    }
                }
            } catch (Exception $e) {
                echo '<p class="err">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        }

        echo '<hr><p style="color:#999">SPAVDevices Debug — nur für Admins sichtbar</p>';
        echo '</body></html>';
        exit();
    }
}
