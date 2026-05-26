<?php
require_once 'modules/Vtiger/views/Basic.php';

class RMMDevices_InRelation_View extends Vtiger_Index_View {

    private array  $debugLog = [];
    private string $logFile  = 'logs/rmm_debug.log';

    public function process(Vtiger_Request $request): void
    {
        $accountId = (int) $request->get('record');
        $this->log("=== RMM Tab geöffnet | accountid={$accountId} | " . date('Y-m-d H:i:s') . " ===");

        [$rmm_url, $rmm_token, $configError] = $this->loadConfig();

        echo '<div class="relatedContainer" style="padding:12px">';

        if ($configError) {
            $this->log("FEHLER Konfiguration: {$configError}");
            $this->renderAlert('warning', $configError);
            $this->renderDebugPanel();
            echo '</div>';
            return;
        }
        $this->log("Konfiguration OK | rmm_url={$rmm_url}");

        $accountNo = $this->fetchRmmClientId($accountId);
        if ($accountNo === null) {
            $this->log("ABBRUCH: account_no leer oder nicht gefunden");
            $this->renderAlert('info', 'Keine Account-Nummer (account_no) für diesen Datensatz gefunden.');
            $this->renderDebugPanel();
            echo '</div>';
            return;
        }
        $this->log("account_no gefunden: '{$accountNo}'");

        // ── API-Call 1: alle Clients ────────────────────────────────────────
        $url1 = rtrim($rmm_url, '/') . '/api/v3/clients/';
        $this->log("API-Call 1: GET {$url1}");
        [$clients, $err] = $this->apiGet($url1, $rmm_token);
        if ($err !== null) {
            $this->log("FEHLER API-Call 1: {$err}");
            $this->renderAlert('danger', 'TacticalRMM API nicht erreichbar: ' . htmlspecialchars($err));
            $this->renderDebugPanel();
            echo '</div>';
            return;
        }
        $list = isset($clients['results']) ? $clients['results'] : $clients;
        $this->log("API-Call 1 OK | Anzahl Clients: " . count($list));

        // ── Client-Suche ────────────────────────────────────────────────────
        $trmClientId = null;
        foreach ($list as $idx => $client) {
            $clientName   = $client['name'] ?? "#{$idx}";
            $clientId     = $client['id']   ?? '?';
            $fields       = $client['custom_fields'] ?? [];
            $fieldSummary = [];
            foreach ($fields as $f) {
                $fn = $f['field']       ?? '(kein field-Key)';
                $fv = $f['value']       ?? '(kein value-Key)';
                $fieldSummary[] = "{$fn}={$fv}";
                if (
                    isset($f['field'], $f['value'])
                    && strtolower((string) $f['field']) === 'berlicrm_id'
                    && (string) $f['value'] === $accountNo
                ) {
                    $trmClientId = (int) $clientId;
                }
            }
            $this->log(
                "  Client[{$idx}] id={$clientId} name='{$clientName}'"
                . ' | custom_fields=[' . implode(', ', $fieldSummary ?: ['–']) . ']'
                . ($trmClientId === (int) $clientId ? ' ← MATCH' : '')
            );
        }

        if ($trmClientId === null) {
            $this->log("ABBRUCH: kein Client mit berlicrm_id='{$accountNo}' gefunden");
            $this->renderAlert('warning',
                'Kein TacticalRMM-Client verknüpft (berlicrm_id = <strong>'
                . htmlspecialchars($accountNo) . '</strong> nicht gefunden).'
                . ' Alle geprüften Clients und deren Custom Fields im Debug-Panel unten.');
            $this->renderDebugPanel();
            echo '</div>';
            return;
        }
        $this->log("Client gefunden: TacticalRMM client_id={$trmClientId}");

        // ── API-Call 2: Agents ───────────────────────────────────────────────
        $url2 = rtrim($rmm_url, '/') . '/api/v3/agents/?client=' . urlencode((string) $trmClientId);
        $this->log("API-Call 2: GET {$url2}");
        [$agents, $err] = $this->apiGet($url2, $rmm_token);
        if ($err !== null) {
            $this->log("FEHLER API-Call 2: {$err}");
            $this->renderAlert('danger', 'Fehler beim Laden der Agents: ' . htmlspecialchars($err));
            $this->renderDebugPanel();
            echo '</div>';
            return;
        }
        $agentList = isset($agents['results']) ? $agents['results'] : $agents;
        $this->log("API-Call 2 OK | Anzahl Agents: " . count($agentList));

        $this->renderTable($agents);
        $this->renderDebugPanel();
        echo '</div>';
    }

    // ─── private helpers ──────────────────────────────────────────────────────

    private function log(string $line): void
    {
        $this->debugLog[] = $line;
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function renderDebugPanel(): void
    {
        $id   = 'rmm-debug-' . uniqid();
        $lines = array_map('htmlspecialchars', $this->debugLog);
        $text  = implode("\n", $lines);
        echo <<<HTML
<div style="margin-top:14px">
  <button onclick="var p=document.getElementById('{$id}');p.style.display=p.style.display==='none'?'block':'none'"
          style="font-size:11px;padding:3px 8px;cursor:pointer;background:#f0f0f0;border:1px solid #ccc;border-radius:3px">
    ▶ Debug-Log ein-/ausblenden
  </button>
  <pre id="{$id}" style="display:none;margin-top:6px;padding:10px;background:#1e1e1e;color:#d4d4d4;
       font-size:11px;line-height:1.5;border-radius:4px;overflow:auto;max-height:320px;white-space:pre-wrap">{$text}</pre>
</div>
HTML;
    }

    private function loadConfig(): array
    {
        $path = __DIR__ . '/../../../config_rmm.php';
        if (!file_exists($path)) {
            return [null, null, 'config_rmm.php nicht gefunden (Pfad: ' . $path . ')'];
        }
        $cfg = require $path;
        if (empty($cfg['rmm_url']) || empty($cfg['rmm_token'])) {
            return [null, null, 'config_rmm.php unvollständig: rmm_url und rmm_token werden benötigt.'];
        }
        return [$cfg['rmm_url'], $cfg['rmm_token'], null];
    }

    private function fetchRmmClientId(int $accountId): ?string
    {
        $db     = PearDatabase::getInstance();
        $result = $db->pquery(
            'SELECT account_no FROM vtiger_account WHERE accountid = ?',
            [$accountId]
        );
        $row = $db->fetchByAssoc($result);
        if (!$row || trim((string) $row['account_no']) === '') {
            return null;
        }
        return trim($row['account_no']);
    }

    /**
     * Returns [decoded_array_or_null, error_string_or_null].
     * Logs HTTP status and first 500 chars of response body.
     */
    private function apiGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $this->log("  → HTTP {$httpCode}"
            . ($curlErr ? " | cURL-Fehler: {$curlErr}" : '')
            . " | Body (erste 500 Zeichen): " . substr((string) $body, 0, 500));

        if ($body === false || $curlErr !== '') {
            return [null, $curlErr ?: 'cURL-Fehler'];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return [null, 'HTTP ' . $httpCode . ' – ' . substr((string) $body, 0, 200)];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [null, 'Ungültige JSON-Antwort: ' . substr((string) $body, 0, 200)];
        }
        return [$data, null];
    }

    private function renderTable(array $agents): void
    {
        $list = isset($agents['results']) ? $agents['results'] : $agents;

        if (empty($list)) {
            $this->renderAlert('info', 'Keine Agents für diesen Client gefunden.');
            return;
        }

        echo '<table class="table table-bordered listViewEntriesTable" '
            . 'style="width:100%;border-collapse:collapse;font-size:13px">';

        echo '<thead><tr class="listViewHeaders" style="background:#f5f5f5">';
        foreach (['Hostname', 'Status', 'OS', 'Letzter Kontakt', 'CPU %', 'RAM %'] as $col) {
            echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd">'
                . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($list as $agent) {
            $hostname    = htmlspecialchars((string) ($agent['hostname']         ?? ''));
            $rawStatus   = (string) ($agent['status']          ?? '');
            $os          = htmlspecialchars((string) ($agent['operating_system'] ?? $agent['plat'] ?? ''));
            $lastContact = htmlspecialchars((string) ($agent['last_seen']        ?? $agent['last_alert_time'] ?? ''));
            $cpu         = isset($agent['cpu_load']) ? (int) $agent['cpu_load'] : null;
            $ram         = isset($agent['used_ram']) ? (int) $agent['used_ram'] : null;

            [$statusLabel, $statusStyle] = $this->statusDisplay($rawStatus);

            $cpuDisplay = $cpu !== null
                ? '<span style="' . $this->trafficLight($cpu) . '">' . $cpu . ' %</span>'
                : '<span style="color:#999">–</span>';
            $ramDisplay = $ram !== null
                ? '<span style="' . $this->trafficLight($ram) . '">' . $ram . ' %</span>'
                : '<span style="color:#999">–</span>';

            echo '<tr class="listViewEntries" style="border-bottom:1px solid #eee">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $hostname . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd"><span style="'
                . $statusStyle . '">' . $statusLabel . '</span></td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $os . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $lastContact . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center">' . $cpuDisplay . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center">' . $ramDisplay . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div style="font-size:11px;color:#999;margin-top:6px">'
            . count($list) . ' Agent(s) geladen</div>';
    }

    private function statusDisplay(string $status): array
    {
        return match (strtolower($status)) {
            'online'  => ['Online',  'color:#2e7d32;font-weight:bold'],
            'offline' => ['Offline', 'color:#c62828;font-weight:bold'],
            'overdue' => ['Overdue', 'color:#e65100;font-weight:bold'],
            default   => [htmlspecialchars($status) ?: '–', 'color:#555'],
        };
    }

    private function trafficLight(int $pct): string
    {
        if ($pct >= 90) return 'color:#c62828;font-weight:bold';
        if ($pct >= 70) return 'color:#e65100';
        return 'color:#2e7d32';
    }

    private function renderAlert(string $type, string $html): void
    {
        $colors = [
            'info'    => ['#d1ecf1', '#0c5460', '#bee5eb'],
            'warning' => ['#fff3cd', '#856404', '#ffeeba'],
            'danger'  => ['#f8d7da', '#721c24', '#f5c6cb'],
        ];
        [$bg, $fg, $border] = $colors[$type] ?? $colors['info'];
        echo '<div style="background:' . $bg . ';color:' . $fg . ';border:1px solid '
            . $border . ';padding:10px 14px;border-radius:4px;margin:8px 0">'
            . $html . '</div>';
    }
}
