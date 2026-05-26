<?php
require_once 'modules/Vtiger/views/Basic.php';

class RMMDevices_InRelation_View extends Vtiger_Index_View {

    public function process(Vtiger_Request $request): void
    {
        $accountId = (int) $request->get('record');

        [$rmm_url, $rmm_token, $configError] = $this->loadConfig();

        echo '<div class="relatedContainer" style="padding:12px">';

        if ($configError) {
            $this->renderAlert('warning', $configError);
            echo '</div>';
            return;
        }

        $rmmClientId = $this->fetchRmmClientId($accountId);

        if ($rmmClientId === null) {
            $this->renderAlert('info', 'Keine Account-Nummer (account_no) für diesen Datensatz gefunden.');
            echo '</div>';
            return;
        }

        [$clients, $err] = $this->apiGet(rtrim($rmm_url, '/') . '/api/v3/clients/', $rmm_token);
        if ($err !== null) {
            $this->renderAlert('danger', 'TacticalRMM API nicht erreichbar: ' . htmlspecialchars($err));
            echo '</div>';
            return;
        }

        $trmClientId = $this->findTrmClient($clients, $rmmClientId);
        if ($trmClientId === null) {
            $this->renderAlert('warning',
                'Kein TacticalRMM-Client verknüpft (berlicrm_id = <strong>'
                . htmlspecialchars($rmmClientId) . '</strong> nicht gefunden).');
            echo '</div>';
            return;
        }

        [$agents, $err] = $this->apiGet(
            rtrim($rmm_url, '/') . '/api/v3/agents/?client=' . urlencode((string) $trmClientId),
            $rmm_token
        );
        if ($err !== null) {
            $this->renderAlert('danger', 'Fehler beim Laden der Agents: ' . htmlspecialchars($err));
            echo '</div>';
            return;
        }

        $this->renderTable($agents);
        echo '</div>';
    }

    // ─── private helpers ──────────────────────────────────────────────────────

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
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            return [null, $curlErr ?: 'cURL-Fehler'];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return [null, 'HTTP ' . $httpCode];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [null, 'Ungültige JSON-Antwort'];
        }
        return [$data, null];
    }

    /**
     * Searches the TacticalRMM /api/v3/clients/ response for a client
     * whose custom_fields contain berlicrm_id == $rmmClientId.
     * Returns the TacticalRMM client id or null.
     */
    private function findTrmClient(array $clients, string $rmmClientId): ?int
    {
        // API returns either a plain array of clients or {"results": [...]}
        $list = isset($clients['results']) ? $clients['results'] : $clients;
        foreach ($list as $client) {
            $fields = $client['custom_fields'] ?? [];
            foreach ($fields as $field) {
                if (
                    isset($field['field'], $field['value'])
                    && strtolower((string) $field['field']) === 'berlicrm_id'
                    && (string) $field['value'] === $rmmClientId
                ) {
                    return (int) $client['id'];
                }
            }
        }
        return null;
    }

    private function renderTable(array $agents): void
    {
        if (empty($agents)) {
            $this->renderAlert('info', 'Keine Agents für diesen Client gefunden.');
            return;
        }

        // /api/v3/agents/ may return a plain array or {"results": [...]}
        $list = isset($agents['results']) ? $agents['results'] : $agents;

        echo '<table class="table table-bordered listViewEntriesTable" '
            . 'style="width:100%;border-collapse:collapse;font-size:13px">';

        echo '<thead><tr class="listViewHeaders" style="background:#f5f5f5">';
        foreach (['Hostname', 'Status', 'OS', 'Letzter Kontakt', 'CPU %', 'RAM %'] as $col) {
            echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd">'
                . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($list as $agent) {
            $hostname      = htmlspecialchars((string) ($agent['hostname']        ?? ''));
            $rawStatus     = (string) ($agent['status']         ?? '');
            $os            = htmlspecialchars((string) ($agent['operating_system'] ?? $agent['plat'] ?? ''));
            $lastContact   = htmlspecialchars((string) ($agent['last_seen']        ?? $agent['last_alert_time'] ?? ''));
            $cpu           = isset($agent['cpu_load'])  ? (int) $agent['cpu_load']  : null;
            $ram           = isset($agent['used_ram'])  ? (int) $agent['used_ram']  : null;

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

    /** Returns [label, inline-style] for a TacticalRMM agent status string. */
    private function statusDisplay(string $status): array
    {
        return match (strtolower($status)) {
            'online'  => ['Online',  'color:#2e7d32;font-weight:bold'],
            'offline' => ['Offline', 'color:#c62828;font-weight:bold'],
            'overdue' => ['Overdue', 'color:#e65100;font-weight:bold'],
            default   => [htmlspecialchars($status) ?: '–', 'color:#555'],
        };
    }

    /** Returns an inline color style based on a percentage value (green/orange/red). */
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
