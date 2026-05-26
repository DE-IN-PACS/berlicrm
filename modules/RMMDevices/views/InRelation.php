<?php
class RMMDevices_InRelation_View extends Vtiger_RelatedList_View {

    private array  $debugLog = [];
    private string $logFile  = '';

    public function process(Vtiger_Request $request): string
    {
        ob_start();

        $accountId = (int) $request->get('record');
        $this->log("=== RMM Tab | accountid={$accountId} | " . date('Y-m-d H:i:s') . " ===");

        [$rmm_url, $rmm_token, $configError] = $this->loadConfig();
        echo '<div class="relatedContainer" style="padding:12px">';

        if ($configError) {
            $this->renderAlert('warning', $configError);
            $this->renderDebugPanel();
            echo '</div>';
            return ob_get_clean();
        }

        $accountNo = $this->getAccountNo($accountId);
        if ($accountNo === null) {
            $this->renderAlert('info', 'Keine Account-Nummer (account_no) für diesen Datensatz gefunden.');
            $this->renderDebugPanel();
            echo '</div>';
            return ob_get_clean();
        }
        $this->log("account_no='{$accountNo}'");

        // ── Step 1: Custom Field-Definitionen ────────────────────────────────
        [$cfDefs, $err] = $this->rmmGet(rtrim($rmm_url, '/') . '/core/customfields/', $rmm_token);
        $siteFieldIds   = [];
        $clientFieldIds = [];
        if ($err === null && is_array($cfDefs)) {
            $cfList = $cfDefs['results'] ?? $cfDefs;
            foreach ($cfList as $cf) {
                if (!is_array($cf)) continue;
                if (strtolower(trim((string)($cf['name'] ?? ''))) !== 'berlicrm_id') continue;
                $cfId    = isset($cf['id']) ? (int)$cf['id'] : null;
                $cfModel = strtolower(trim((string)($cf['model'] ?? '')));
                if ($cfId === null) continue;
                if ($cfModel === 'site')   $siteFieldIds[]   = $cfId;
                if ($cfModel === 'client') $clientFieldIds[] = $cfId;
            }
        }
        $this->log("fieldIds: client=[" . implode(',', $clientFieldIds) . "] site=[" . implode(',', $siteFieldIds) . "]");

        // ── Step 2: Verknüpften Client/Site finden ───────────────────────────
        $trmClientId = null;
        $trmSiteId   = null;

        // 2a: Site-Suche direkt über /clients/sites/
        if (!empty($siteFieldIds)) {
            $this->log("Suche in /clients/sites/ (siteFieldIds=[" . implode(',', $siteFieldIds) . "])");
            [$sitesData, $err] = $this->rmmGet(rtrim($rmm_url, '/') . '/clients/sites/', $rmm_token);
            if ($err !== null) {
                $this->log("FEHLER /clients/sites/: {$err}");
                $this->renderAlert('danger', 'TacticalRMM /clients/sites/ nicht erreichbar: ' . htmlspecialchars($err));
                $this->renderDebugPanel();
                echo '</div>';
                return ob_get_clean();
            }
            $siteList = $sitesData['results'] ?? $sitesData;
            $this->log("Sites geladen: " . count((array)$siteList));
            foreach ((array)$siteList as $site) {
                if (!is_array($site)) continue;
                foreach ((array)($site['custom_fields'] ?? []) as $cf) {
                    if ($this->matchField($cf, $siteFieldIds, $accountNo)) {
                        $trmSiteId   = (int)($site['id']     ?? 0);
                        $trmClientId = (int)($site['client'] ?? 0);
                        $this->log("MATCH Site id={$trmSiteId} client_id={$trmClientId} name='" . ($site['name'] ?? '') . "'");
                        break 2;
                    }
                }
            }
        }

        // 2b: Client-Suche über /clients/ (nur wenn kein Site-Match)
        if ($trmClientId === null && !empty($clientFieldIds)) {
            $this->log("Suche in /clients/ (clientFieldIds=[" . implode(',', $clientFieldIds) . "])");
            [$clientsData, $err] = $this->rmmGet(rtrim($rmm_url, '/') . '/clients/', $rmm_token);
            if ($err !== null) {
                $this->log("FEHLER /clients/: {$err}");
                $this->renderAlert('danger', 'TacticalRMM /clients/ nicht erreichbar: ' . htmlspecialchars($err));
                $this->renderDebugPanel();
                echo '</div>';
                return ob_get_clean();
            }
            $clientList = $clientsData['results'] ?? $clientsData;
            $this->log("Clients geladen: " . count((array)$clientList));
            foreach ((array)$clientList as $client) {
                if (!is_array($client)) continue;
                foreach ((array)($client['custom_fields'] ?? []) as $cf) {
                    if ($this->matchField($cf, $clientFieldIds, $accountNo)) {
                        $trmClientId = (int)($client['id'] ?? 0);
                        $this->log("MATCH Client id={$trmClientId} name='" . ($client['name'] ?? '') . "'");
                        break 2;
                    }
                }
            }
        }

        if ($trmClientId === null) {
            $this->log("Kein Match für berlicrm_id='{$accountNo}'");
            $this->renderAlert('warning',
                'Kein TacticalRMM-Client verknüpft (berlicrm_id = <strong>'
                . htmlspecialchars($accountNo) . '</strong> nicht gefunden).');
            $this->renderDebugPanel();
            echo '</div>';
            return ob_get_clean();
        }

        // ── Step 3: Agents laden ─────────────────────────────────────────────
        if ($trmSiteId !== null) {
            $agentsUrl = rtrim($rmm_url, '/') . '/agents/?site=' . $trmSiteId;
            $this->log("GET {$agentsUrl}");
        } else {
            $agentsUrl = rtrim($rmm_url, '/') . '/agents/?client=' . $trmClientId;
            $this->log("GET {$agentsUrl}");
        }

        [$agentsData, $err] = $this->rmmGet($agentsUrl, $rmm_token);
        if ($err !== null) {
            $this->log("FEHLER Agents: {$err}");
            $this->renderAlert('danger', 'Fehler beim Laden der Agents: ' . htmlspecialchars($err));
            $this->renderDebugPanel();
            echo '</div>';
            return ob_get_clean();
        }
        $agentList = $agentsData['results'] ?? $agentsData;
        $this->log("Agents geladen: " . count((array)$agentList));

        $this->renderTable((array)$agentList);
        $this->renderDebugPanel();
        echo '</div>';

        return ob_get_clean();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function matchField(array $cf, array $fieldIds, string $accountNo): bool
    {
        if (!isset($cf['value'])) return false;
        if (strtolower(trim((string)$cf['value'])) !== strtolower(trim($accountNo))) return false;
        if (!isset($cf['field'])) return false;
        if (empty($fieldIds)) return true;
        if (is_numeric($cf['field'])) return in_array((int)$cf['field'], $fieldIds, true);
        return strtolower(trim((string)$cf['field'])) === 'berlicrm_id';
    }

    private function rmmGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $token, 'Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $this->log("  HTTP {$code}" . ($curlErr ? " cURL:{$curlErr}" : '') . " body[0..300]=" . substr((string)$body, 0, 300));

        if ($body === false || $curlErr !== '') return [null, $curlErr ?: 'cURL-Fehler'];
        if ($code < 200 || $code >= 300)        return [null, "HTTP {$code}: " . substr((string)$body, 0, 200)];
        $data = json_decode($body, true);
        if (!is_array($data))                   return [null, 'Ungültige JSON-Antwort: ' . substr((string)$body, 0, 200)];
        return [$data, null];
    }

    private function log(string $line): void
    {
        $this->debugLog[] = $line;
        if ($this->logFile === '') {
            $root = realpath(__DIR__ . '/../../..');
            $dir  = ($root !== false ? $root : __DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $this->logFile = $dir . DIRECTORY_SEPARATOR . 'rmm_debug.log';
        }
        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function renderDebugPanel(): void
    {
        $id   = 'rmm-debug-' . uniqid();
        $text = implode("\n", array_map('htmlspecialchars', $this->debugLog));
        echo <<<HTML
<div style="margin-top:14px">
  <button onclick="var p=document.getElementById('{$id}');p.style.display=p.style.display==='none'?'block':'none'"
          style="font-size:11px;padding:3px 8px;cursor:pointer;background:#f0f0f0;border:1px solid #ccc;border-radius:3px">
    &#9658; Debug-Log ein-/ausblenden
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

    private function getAccountNo(int $accountId): ?string
    {
        $db  = PearDatabase::getInstance();
        $res = $db->pquery('SELECT account_no FROM vtiger_account WHERE accountid = ?', [$accountId]);
        $row = $db->fetchByAssoc($res);
        if (!$row || trim((string)$row['account_no']) === '') return null;
        return trim($row['account_no']);
    }

    private function renderTable(array $list): void
    {
        if (empty($list)) {
            $this->renderAlert('info', 'Keine Agents für diesen Client gefunden.');
            return;
        }

        echo '<table class="table table-bordered listViewEntriesTable" style="width:100%;border-collapse:collapse;font-size:13px">';
        echo '<thead><tr class="listViewHeaders" style="background:#f5f5f5">';
        foreach (['Hostname', 'Status', 'OS', 'Letzter Kontakt', 'CPU %', 'RAM %'] as $col) {
            echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd">' . htmlspecialchars($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($list as $agent) {
            if (!is_array($agent)) continue;
            $hostname    = htmlspecialchars((string)($agent['hostname']         ?? ''));
            $rawStatus   = (string)($agent['status']            ?? '');
            $os          = htmlspecialchars((string)($agent['operating_system'] ?? $agent['plat'] ?? ''));
            $lastContact = htmlspecialchars((string)($agent['last_seen']        ?? $agent['last_alert_time'] ?? ''));
            $cpu         = isset($agent['cpu_load']) ? (int)$agent['cpu_load'] : null;
            $ram         = isset($agent['used_ram']) ? (int)$agent['used_ram'] : null;

            [$statusLabel, $statusStyle] = $this->statusLabel($rawStatus);
            $cpuHtml = $cpu !== null ? '<span style="' . $this->trafficLight($cpu) . '">' . $cpu . ' %</span>' : '<span style="color:#999">–</span>';
            $ramHtml = $ram !== null ? '<span style="' . $this->trafficLight($ram) . '">' . $ram . ' %</span>' : '<span style="color:#999">–</span>';

            echo '<tr class="listViewEntries" style="border-bottom:1px solid #eee">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $hostname . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd"><span style="' . $statusStyle . '">' . $statusLabel . '</span></td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $os . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd">' . $lastContact . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center">' . $cpuHtml . '</td>';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;text-align:center">' . $ramHtml . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div style="font-size:11px;color:#999;margin-top:6px">' . count($list) . ' Agent(s) geladen</div>';
    }

    private function statusLabel(string $status): array
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
        echo '<div style="background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $border . ';padding:10px 14px;border-radius:4px;margin:8px 0">' . $html . '</div>';
    }
}
