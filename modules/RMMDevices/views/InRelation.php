<?php
class RMMDevices_InRelation_View extends Vtiger_RelatedList_View {

    private array  $debugLog = [];
    private string $logFile  = '';

    public function process(Vtiger_Request $request)
    {
        error_log('RMMDevices_InRelation_View::process CALLED record=' . $request->get('record'));

        $accountId = (int) $request->get('record');
        $this->log("=== RMM Tab | accountid={$accountId} | " . date('Y-m-d H:i:s') . " ===");

        [$rmm_url, $rmm_token, $configError] = $this->loadConfig();
        $html = '<div class="relatedContainer" data-rmm="rmmdevices" style="padding:12px">';

        if ($configError) {
            $html .= $this->renderAlert('warning', $configError);
            $html .= $this->renderDebugPanel();
            $html .= '</div>';
            $this->sendAndExit($html);
        }

        $accountNo = $this->getAccountNo($accountId);
        if ($accountNo === null) {
            $html .= $this->renderAlert('info', 'Keine Account-Nummer (account_no) für diesen Datensatz gefunden.');
            $html .= $this->renderDebugPanel();
            $html .= '</div>';
            $this->sendAndExit($html);
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
                $html .= $this->renderAlert('danger', 'TacticalRMM /clients/sites/ nicht erreichbar: ' . htmlspecialchars($err));
                $html .= $this->renderDebugPanel();
                $html .= '</div>';
                $this->sendAndExit($html);
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
                $html .= $this->renderAlert('danger', 'TacticalRMM /clients/ nicht erreichbar: ' . htmlspecialchars($err));
                $html .= $this->renderDebugPanel();
                $html .= '</div>';
                $this->sendAndExit($html);
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
            $html .= $this->renderAlert('warning',
                'Kein TacticalRMM-Client verknüpft (berlicrm_id = <strong>'
                . htmlspecialchars($accountNo) . '</strong> nicht gefunden).');
            $html .= $this->renderDebugPanel();
            $html .= '</div>';
            $this->sendAndExit($html);
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
            $html .= $this->renderAlert('danger', 'Fehler beim Laden der Agents: ' . htmlspecialchars($err));
            $html .= $this->renderDebugPanel();
            $html .= '</div>';
            $this->sendAndExit($html);
        }
        $agentList = $agentsData['results'] ?? $agentsData;
        $this->log("Agents geladen: " . count((array)$agentList));

        $html .= $this->renderTable((array)$agentList);
        $html .= $this->renderDebugPanel();
        $html .= '</div>';
        $this->sendAndExit($html);
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

    private function renderDebugPanel(): string
    {
        $id   = 'rmm-debug-' . uniqid();
        $text = implode("\n", array_map('htmlspecialchars', $this->debugLog));
        return <<<HTML
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

    private function renderTable(array $list): string
    {
        if (empty($list)) {
            return $this->renderAlert('info', 'Keine Agents für diesen Client gefunden.');
        }

        $th = 'padding:6px 10px;text-align:left;border:1px solid #ddd;white-space:nowrap';
        $td = 'padding:5px 10px;border:1px solid #ddd;vertical-align:top';
        $tdc = $td . ';text-align:center';

        $html  = $this->renderSummary($list);
        $html .= '<table class="table table-bordered listViewEntriesTable" style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<thead><tr class="listViewHeaders" style="background:#f5f5f5">';
        foreach (['Status', 'Hostname', 'LAN IP', 'OS', 'Seriennummer', 'TeamViewer ID', 'Letzter Kontakt', 'Disk Checks', 'CPU %', 'RAM %'] as $col) {
            $html .= '<th style="' . $th . '">' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($list as $agent) {
            if (!is_array($agent)) continue;

            $rawStatus = (string)($agent['status'] ?? '');
            $hostname  = htmlspecialchars((string)($agent['hostname'] ?? ''));
            $lanIp     = htmlspecialchars($this->extractLanIp($agent));
            $os        = htmlspecialchars($this->shortenOs((string)($agent['operating_system'] ?? $agent['plat'] ?? '')));
            $serial    = htmlspecialchars($this->extractSerial($agent));
            $tvId      = htmlspecialchars($this->extractTeamViewerId($agent));
            $lastSeen  = htmlspecialchars($this->formatLastSeen((string)($agent['last_seen'] ?? $agent['last_alert_time'] ?? '')));
            $diskHtml  = $this->renderDiskChecks($agent);
            $cpu       = isset($agent['cpu_load']) ? (int)$agent['cpu_load'] : null;
            $ram       = isset($agent['used_ram']) ? (int)$agent['used_ram'] : null;

            [$statusLabel, $statusStyle] = $this->statusLabel($rawStatus);
            $cpuHtml = $cpu !== null ? '<span style="' . $this->trafficLight($cpu) . '">' . $cpu . ' %</span>' : '<span style="color:#999">&#8211;</span>';
            $ramHtml = $ram !== null ? '<span style="' . $this->trafficLight($ram) . '">' . $ram . ' %</span>' : '<span style="color:#999">&#8211;</span>';

            $html .= '<tr class="listViewEntries" style="border-bottom:1px solid #eee">';
            $html .= '<td style="' . $td . '"><span style="' . $statusStyle . '">' . $statusLabel . '</span></td>';
            $html .= '<td style="' . $td . '">' . $hostname . '</td>';
            $html .= '<td style="' . $td . '">' . $lanIp . '</td>';
            $html .= '<td style="' . $td . '">' . $os . '</td>';
            $html .= '<td style="' . $td . '">' . $serial . '</td>';
            $html .= '<td style="' . $td . '">' . $tvId . '</td>';
            $html .= '<td style="' . $td . ';white-space:nowrap">' . $lastSeen . '</td>';
            $html .= '<td style="' . $td . '">' . $diskHtml . '</td>';
            $html .= '<td style="' . $tdc . '">' . $cpuHtml . '</td>';
            $html .= '<td style="' . $tdc . '">' . $ramHtml . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderSummary(array $list): string
    {
        $total = count($list);
        $online = $offline = $overdue = 0;
        foreach ($list as $agent) {
            if (!is_array($agent)) continue;
            switch (strtolower((string)($agent['status'] ?? ''))) {
                case 'online':  $online++;  break;
                case 'offline': $offline++; break;
                case 'overdue': $overdue++; break;
            }
        }
        return '<div style="font-size:13px;font-weight:bold;margin-bottom:8px;color:#333">'
            . $total . ' Agents'
            . ' &nbsp;|&nbsp; <span style="color:#2e7d32">' . $online  . ' Online</span>'
            . ' &nbsp;|&nbsp; <span style="color:#e65100">' . $overdue . ' Overdue</span>'
            . ' &nbsp;|&nbsp; <span style="color:#c62828">' . $offline . ' Offline</span>'
            . '</div>';
    }

    private function extractLanIp(array $agent): string
    {
        if (!empty($agent['local_ips']) && is_array($agent['local_ips'])) {
            return (string)reset($agent['local_ips']);
        }
        if (!empty($agent['ip_addresses']) && is_array($agent['ip_addresses'])) {
            return (string)reset($agent['ip_addresses']);
        }
        return (string)($agent['lanip'] ?? '');
    }

    private function shortenOs(string $os): string
    {
        return trim((string)preg_replace('/\s*\(build[^)]*\)/i', '', $os));
    }

    private function extractSerial(array $agent): string
    {
        if (!empty($agent['serial_number'])) {
            return (string)$agent['serial_number'];
        }
        if (isset($agent['wmi_detail']['serial_number']) && $agent['wmi_detail']['serial_number'] !== '') {
            return (string)$agent['wmi_detail']['serial_number'];
        }
        return '';
    }

    private function extractTeamViewerId(array $agent): string
    {
        $fields = isset($agent['custom_fields']) && is_array($agent['custom_fields'])
                  ? $agent['custom_fields'] : [];
        foreach ($fields as $cf) {
            if (!is_array($cf)) continue;
            // Name-based format: {"name": "TeamViewerClientID", "value": "..."}
            $name = strtolower(trim((string)($cf['name'] ?? $cf['field_name'] ?? '')));
            if ($name === 'teamviewerclientid') {
                return (string)($cf['value'] ?? '');
            }
        }
        return '';
    }

    private function formatLastSeen(string $raw): string
    {
        if ($raw === '') return '';
        try {
            $dt = new DateTime($raw);
            $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
            return $dt->format('d.m.Y H:i');
        } catch (Exception $e) {
            return $raw;
        }
    }

    private function renderDiskChecks(array $agent): string
    {
        $checks = isset($agent['checks']) && is_array($agent['checks']) ? $agent['checks'] : [];

        // Normalise: {failing:[...], passing:[...]} or flat array
        $allChecks = [];
        if (isset($checks['failing']) || isset($checks['passing'])) {
            $allChecks = array_merge((array)($checks['failing'] ?? []), (array)($checks['passing'] ?? []));
        } else {
            $allChecks = $checks;
        }

        $lines = [];
        foreach ($allChecks as $check) {
            if (!is_array($check)) continue;
            $type = strtolower((string)($check['check_type'] ?? ''));
            $name = (string)($check['name'] ?? '');
            $isDisk = ($type === 'diskspace')
                   || (stripos($name, 'disk') !== false)
                   || (stripos($name, 'space') !== false);
            if (!$isDisk) continue;

            // Extract percentage
            $pct = null;
            if (isset($check['percent_used'])) {
                $pct = (int)$check['percent_used'];
            } elseif (isset($check['more_info']) && preg_match('/(\d+)\s*%/', (string)$check['more_info'], $m)) {
                $pct = (int)$m[1];
            } elseif (preg_match('/(\d+)\s*%/', $name, $m)) {
                $pct = (int)$m[1];
            }

            $label = htmlspecialchars($name);
            if ($pct !== null) {
                $icon  = $pct >= 85 ? '&#9888;' : '&#10003;';
                $color = $pct >= 85 ? 'color:#c62828' : 'color:#2e7d32';
                $lines[] = '<span style="' . $color . '">' . $label . ' ' . $pct . '% ' . $icon . '</span>';
            } else {
                $lines[] = $label;
            }
        }

        if (empty($lines)) {
            return '<span style="color:#999">&#8211;</span>';
        }
        return implode('<br>', $lines);
    }

    private function statusLabel(string $status): array
    {
        switch (strtolower($status)) {
            case 'online':  return ['Online',  'color:#2e7d32;font-weight:bold'];
            case 'offline': return ['Offline', 'color:#c62828;font-weight:bold'];
            case 'overdue': return ['Overdue', 'color:#e65100;font-weight:bold'];
            default:        return [htmlspecialchars($status) ?: '&#8211;', 'color:#555'];
        }
    }

    private function trafficLight(int $pct): string
    {
        if ($pct >= 90) return 'color:#c62828;font-weight:bold';
        if ($pct >= 70) return 'color:#e65100';
        return 'color:#2e7d32';
    }

    private function renderSelfInsertScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
(function($){
    // Snapshot the HTML of our content div while it is still in the DOM
    // (this script runs during jQuery's .html() call, so the element exists)
    var $rc = $('[data-rmm="rmmdevices"]').first();
    var rmmHtml = $rc.length ? $rc.prop('outerHTML') : '';

    // After a short delay, check whether the content actually made it into the
    // visible content holder and remove any blocking overlay if needed.
    setTimeout(function(){
        var $target = $('div.details div.contents').first();
        if (!$target.length) {
            $target = $('div.contentsDiv div.contents, div.contents').first();
        }

        // If our content is not yet in the target container, force-insert it
        if ($target.length && !$target.find('[data-rmm="rmmdevices"]').length && rmmHtml) {
            $target.html(rmmHtml);
        }

        // Always remove any jQuery blockUI overlay that might still be covering the view
        var $dvi = $('div.detailViewInfo');
        if ($dvi.length) {
            try { $dvi.unblock(); } catch(e) {}
        }
        // Fallback: remove blockUI overlay elements directly
        $dvi.find('.blockUI').remove();
        $dvi.css({'opacity': '', 'pointer-events': ''});
    }, 600);
})(jQuery);
</script>
JS;
    }

    private function sendAndExit(string $html): void
    {
        // csrf-magic.js strips X-PJAX/X-Requested-With headers, causing isAjax()=false,
        // which triggers triggerPreProcess() to buffer a full HTML page before our process()
        // runs. Clean all output buffers so only our partial HTML reaches the browser.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $html;
        exit;
    }

    private function renderAlert(string $type, string $html): string
    {
        $colors = [
            'info'    => ['#d1ecf1', '#0c5460', '#bee5eb'],
            'warning' => ['#fff3cd', '#856404', '#ffeeba'],
            'danger'  => ['#f8d7da', '#721c24', '#f5c6cb'],
        ];
        [$bg, $fg, $border] = $colors[$type] ?? $colors['info'];
        return '<div style="background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $border . ';padding:10px 14px;border-radius:4px;margin:8px 0">' . $html . '</div>';
    }
}
