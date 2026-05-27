<?php
require_once __DIR__ . '/RMMDevicesHelper.php';

class RMMDevices_InRelation_View extends Vtiger_RelatedList_View {

    private const CACHE_VERSION = 5;

    private array  $debugLog = [];
    private string $logFile  = '';

    public function process(Vtiger_Request $request)
    {
        error_log('RMMDevices_InRelation_View::process CALLED record=' . $request->get('record'));

        $accountId = (int) $request->get('record');

        $this->log("=== RMM Tab | accountid={$accountId} | " . date('Y-m-d H:i:s') . " ===");

        [$rmm_url, $rmm_token, $rmm_frontend_url, $configError] = $this->loadConfig();
        $html = '<div class="relatedContainer" data-rmm="rmmdevices" data-agent-count="0" style="padding:12px">';

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

        // Refresh-URL für den "Aktualisieren"-Button
        $refreshUrl = 'index.php?module=' . urlencode($request->getModule())
            . '&view=Detail&mode=showRelatedList&relatedModule=RMMDevices'
            . '&record=' . urlencode((string)$accountId)
            . '&tab_label=' . urlencode((string)$request->get('tab_label'))
            . '&force_refresh=1';

        // ── Cache-Check ──────────────────────────────────────────────────────
        $forceRefresh = ((string)$request->get('force_refresh') === '1');
        $cached       = $forceRefresh ? null : $this->loadCache($accountNo);
        $cacheAge     = -1;
        $tvFieldIds   = [];

        if ($cached !== null) {
            $agentList  = (array)($cached['agents']       ?? []);
            $tvFieldIds = (array)($cached['tv_field_ids'] ?? []);
            $cacheAge   = time() - (int)($cached['ts']    ?? 0);
            $this->log("Cache genutzt (Alter: {$cacheAge}s, " . count($agentList) . " Agents)");
        } else {
            // ── Step 1: Custom Field-Definitionen ────────────────────────────
            [$cfDefs, $err]  = $this->rmmGet(rtrim($rmm_url, '/') . '/core/customfields/', $rmm_token);
            $siteFieldIds    = [];
            $clientFieldIds  = [];
            if ($err === null && is_array($cfDefs)) {
                $cfList = $cfDefs['results'] ?? $cfDefs;
                foreach ($cfList as $cf) {
                    if (!is_array($cf)) continue;
                    $cfName  = strtolower(trim((string)($cf['name'] ?? '')));
                    $cfId    = isset($cf['id']) ? (int)$cf['id'] : null;
                    $cfModel = strtolower(trim((string)($cf['model'] ?? '')));
                    if ($cfId === null) continue;
                    if ($cfName === 'berlicrm_id') {
                        if ($cfModel === 'site')   $siteFieldIds[]   = $cfId;
                        if ($cfModel === 'client') $clientFieldIds[] = $cfId;
                    }
                    if ($cfName === 'teamviewerclientid') {
                        $tvFieldIds[] = $cfId;
                    }
                }
            }
            $this->log("fieldIds: client=[" . implode(',', $clientFieldIds) . "] site=[" . implode(',', $siteFieldIds) . "] tv=[" . implode(',', $tvFieldIds) . "]");

            // ── Step 2: Verknüpften Client/Site finden ───────────────────────
            $trmClientId = null;
            $trmSiteId   = null;

            if (!empty($siteFieldIds)) {
                $this->log("Suche in /clients/sites/");
                [$sitesData, $err] = $this->rmmGet(rtrim($rmm_url, '/') . '/clients/sites/', $rmm_token);
                if ($err !== null) {
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

            if ($trmClientId === null && !empty($clientFieldIds)) {
                $this->log("Suche in /clients/");
                [$clientsData, $err] = $this->rmmGet(rtrim($rmm_url, '/') . '/clients/', $rmm_token);
                if ($err !== null) {
                    $html .= $this->renderAlert('danger', 'TacticalRMM /clients/ nicht erreichbar: ' . htmlspecialchars($err));
                    $html .= $this->renderDebugPanel();
                    $html .= '</div>';
                    $this->sendAndExit($html);
                }
                $clientList = $clientsData['results'] ?? $clientsData;
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

            // ── Step 3: Agents laden + anreichern ────────────────────────────
            $agentsUrl = $trmSiteId !== null
                ? rtrim($rmm_url, '/') . '/agents/?site='   . $trmSiteId
                : rtrim($rmm_url, '/') . '/agents/?client=' . $trmClientId;
            $this->log("GET {$agentsUrl}");

            [$agentsData, $err] = $this->rmmGet($agentsUrl, $rmm_token);
            if ($err !== null) {
                $html .= $this->renderAlert('danger', 'Fehler beim Laden der Agents: ' . htmlspecialchars($err));
                $html .= $this->renderDebugPanel();
                $html .= '</div>';
                $this->sendAndExit($html);
            }
            $agentList = array_values((array)($agentsData['results'] ?? $agentsData));
            $this->log("Agents geladen: " . count($agentList));
            $agentList = $this->enrichAgents($agentList, $rmm_url, $rmm_token);

            $this->saveCache($accountNo, [
                'ts'           => time(),
                'agents'       => $agentList,
                'tv_field_ids' => $tvFieldIds,
            ]);
        }

        $html = str_replace('data-agent-count="0"', 'data-agent-count="' . count($agentList) . '"', $html);
        $html .= $this->renderTable($agentList, $tvFieldIds, $cacheAge, $refreshUrl, $rmm_frontend_url);
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
        [$data, $err] = RMMDevicesHelper::rmmGet($url, $token);
        $this->log("  rmmGet " . $url . " → " . ($err !== null ? "ERR:{$err}" : 'OK (' . (is_array($data) ? count($data) . ' keys' : gettype($data)) . ')'));
        return [$data, $err];
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
        return RMMDevicesHelper::loadConfig();
    }

    private function getAccountNo(int $accountId): ?string
    {
        $db  = PearDatabase::getInstance();
        $res = $db->pquery('SELECT account_no FROM vtiger_account WHERE accountid = ?', [$accountId]);
        $row = $db->fetchByAssoc($res);
        if (!$row || trim((string)$row['account_no']) === '') return null;
        return trim($row['account_no']);
    }

    private function renderTable(array $list, array $tvFieldIds = [], int $cacheAge = -1, string $refreshUrl = '', string $rmmFrontendUrl = ''): string
    {
        if (empty($list)) {
            return $this->renderAlert('info', 'Keine Agents für diesen Client gefunden.');
        }

        $th  = 'padding:6px 10px;text-align:left;border:1px solid #ddd;white-space:nowrap';
        $thS = $th . ';cursor:pointer;user-select:none';
        $td  = 'padding:5px 10px;border:1px solid #ddd;vertical-align:top';
        $tdc = $td . ';text-align:center';

        $html  = $this->renderSummary($list, $cacheAge, $refreshUrl);
        $html .= $this->renderTakeControlScript();
        $html .= '<table class="table table-bordered listViewEntriesTable" style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<thead><tr class="listViewHeaders" style="background:#f5f5f5">';

        // [label, sort-key or null]
        $headers = [
            ['Status',          null],
            ['Hostname',        'hostname'],
            ['LAN IP',          'lanip'],
            ['OS',              null],
            ['Seriennummer',    null],
            ['TeamViewer ID',   null],
            ['Letzter Kontakt', null],
            ['RAM',             null],
            ['Disk Checks',     'disks'],
            ['Patches',         'patches'],
            ['Aktionen',        null],
        ];
        foreach ($headers as $hdr) {
            list($label, $colKey) = $hdr;
            if ($colKey !== null) {
                $html .= '<th style="' . $thS . '" data-col="' . $colKey . '">'
                    . htmlspecialchars($label) . ' <span class="sort-arrow">&#8597;</span></th>';
            } else {
                $html .= '<th style="' . $th . '">' . htmlspecialchars($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        foreach ($list as $agent) {
            if (!is_array($agent)) continue;

            $rawStatus = (string)($agent['status'] ?? '');
            $hostname  = (string)($agent['hostname'] ?? '');
            $lanIp     = $this->extractLanIp($agent);
            $os        = htmlspecialchars($this->shortenOs((string)($agent['operating_system'] ?? $agent['plat'] ?? '')));
            $serial    = htmlspecialchars($this->extractSerial($agent));
            $tvId      = $this->extractTeamViewerId($agent, $tvFieldIds);
            $lastSeen  = htmlspecialchars($this->formatLastSeen((string)($agent['last_seen'] ?? $agent['last_alert_time'] ?? '')));

            // RAM capacity (GB, no percentage)
            $totalRam = isset($agent['total_ram']) ? (int)$agent['total_ram'] : null;
            $ramHtml  = $totalRam !== null
                ? htmlspecialchars($totalRam . ' GB')
                : '<span style="color:#999">&#8211;</span>';

            // Disk checks → [html, maxUsedPct]
            [$diskHtml, $diskMaxPct] = $this->renderDiskChecks($agent);

            // Patches pending
            $hasPatch = isset($agent['has_patches_pending']) ? (bool)$agent['has_patches_pending'] : null;
            if ($hasPatch === true) {
                $patchHtml    = '<span style="color:#e65100;font-weight:bold">&#9888; Ja</span>';
                $patchSortVal = '1';
            } else {
                $patchHtml    = '<span style="color:#2e7d32">&#10003; Ok</span>';
                $patchSortVal = '0';
            }

            [$statusLabel, $statusStyle] = $this->statusLabel($rawStatus);
            $actionsHtml = $this->renderActionButtons($agent, $rmmFrontendUrl, $tvId);

            // Sort values
            $hostSortVal = htmlspecialchars(strtolower($hostname));
            $ipSortVal   = htmlspecialchars($this->ipSortVal($lanIp));
            $diskSortVal = str_pad((string)$diskMaxPct, 3, '0', STR_PAD_LEFT);

            $html .= '<tr class="listViewEntries" style="border-bottom:1px solid #eee">';
            $html .= '<td style="' . $td . '"><span style="' . $statusStyle . '">' . $statusLabel . '</span></td>';
            $html .= '<td style="' . $td . '" data-col="hostname" data-val="' . $hostSortVal . '">' . htmlspecialchars($hostname) . '</td>';
            $html .= '<td style="' . $td . '" data-col="lanip"    data-val="' . $ipSortVal   . '">' . htmlspecialchars($lanIp)   . '</td>';
            $html .= '<td style="' . $td . '">' . $os . '</td>';
            $html .= '<td style="' . $td . '">' . $serial . '</td>';
            $html .= '<td style="' . $td . '">' . htmlspecialchars($tvId) . '</td>';
            $html .= '<td style="' . $td . ';white-space:nowrap">' . $lastSeen . '</td>';
            $html .= '<td style="' . $tdc . '">' . $ramHtml . '</td>';
            $html .= '<td style="' . $td . '" data-col="disks"   data-val="' . $diskSortVal   . '">' . $diskHtml   . '</td>';
            $html .= '<td style="' . $tdc . '" data-col="patches" data-val="' . $patchSortVal . '">' . $patchHtml  . '</td>';
            $html .= '<td style="' . $td . ';white-space:nowrap">' . $actionsHtml . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderSortScript();
        return $html;
    }

    private function ipSortVal(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return $ip;
        $padded = [];
        foreach ($parts as $p) {
            $padded[] = str_pad((string)(int)$p, 3, '0', STR_PAD_LEFT);
        }
        return implode('.', $padded);
    }

    private function renderSortScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
(function(){
    var currentCol = null;
    var currentDir = 'asc';
    document.querySelectorAll('th[data-col]').forEach(function(th){
        th.addEventListener('click', function(){
            var col = th.getAttribute('data-col');
            if (currentCol === col) {
                currentDir = currentDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentCol = col;
                currentDir = 'asc';
            }
            document.querySelectorAll('th[data-col]').forEach(function(t){
                t.querySelector('.sort-arrow').textContent = ' ↕';
            });
            th.querySelector('.sort-arrow').textContent =
                currentDir === 'asc' ? ' ↑' : ' ↓';
            var tbody = th.closest('table').querySelector('tbody');
            var rows  = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b){
                var aVal = '';
                var bVal = '';
                var aTd = a.querySelector('td[data-col="' + col + '"]');
                var bTd = b.querySelector('td[data-col="' + col + '"]');
                if (aTd) aVal = aTd.getAttribute('data-val') || '';
                if (bTd) bVal = bTd.getAttribute('data-val') || '';
                var cmp = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
                return currentDir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(function(r){ tbody.appendChild(r); });
        });
    });
})();
</script>
JS;
    }

    private function renderActionButtons(array $agent, string $rmmFrontendUrl, string $tvId): string
    {
        $s  = 'padding:2px 7px;font-size:11px;border-radius:3px;cursor:pointer;border:1px solid;margin-right:3px;white-space:nowrap';
        $bB = $s . ';background:#1976d2;color:#fff;border-color:#1565c0';
        $bG = $s . ';background:#388e3c;color:#fff;border-color:#2e7d32';
        $bO = $s . ';background:#f57c00;color:#fff;border-color:#e65100';

        $html = '';

        // TRMM button — link to web UI filtered by hostname
        if ($rmmFrontendUrl !== '') {
            $hostname = (string)($agent['hostname'] ?? '');
            $trmmUrl  = addslashes(rtrim($rmmFrontendUrl, '/') . '/?search=' . urlencode($hostname));
            $html .= '<button style="' . $bB . '" onclick="window.open(\'' . $trmmUrl . '\',\'_blank\',\'noopener,noreferrer\')">TRMM</button>';
        }

        // TeamViewer button
        if ($tvId !== '') {
            $tvUrl = addslashes('https://start.teamviewer.com/' . urlencode($tvId));
            $html .= '<button style="' . $bG . '" onclick="window.open(\'' . $tvUrl . '\',\'_blank\',\'noopener,noreferrer\')">TeamViewer</button>';
        }

        // Take Control button — only for non-offline agents
        if (strtolower((string)($agent['status'] ?? '')) !== 'offline') {
            $agentId = addslashes((string)($agent['agent_id'] ?? $agent['id'] ?? ''));
            $html .= '<button style="' . $bO . '" onclick="rmmTakeControl(this,\'' . $agentId . '\')">Take Control</button>';
        }

        return $html;
    }

    private function renderTakeControlScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
if (typeof rmmTakeControl === 'undefined') {
    function rmmTakeControl(btn, agentId) {
        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';
        var url = location.pathname + '?module=RMMDevices&view=MeshProxy&agent_id='
                  + encodeURIComponent(agentId);
        fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.url) {
                window.open(data.url, '_blank');
            } else {
                alert('Take Control nicht verfügbar: ' + (data.error || 'Unbekannter Fehler'));
            }
            btn.disabled = false;
            btn.textContent = origText;
        })
        .catch(function(e) {
            alert('Verbindungsfehler: ' + e);
            btn.disabled = false;
            btn.textContent = origText;
        });
    }
}
</script>
JS;
    }

    private function renderSummary(array $list, int $cacheAge = -1, string $refreshUrl = ''): string
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

        $ageText = $cacheAge >= 0
            ? ' <span style="font-size:11px;font-weight:normal;color:#888">&bull; Cache: ' . $cacheAge . 's</span>'
            : '';

        $refreshScript = '';
        $refreshBtn    = '';
        if ($refreshUrl !== '') {
            $refreshScript = <<<'JS'
<script type="text/javascript">
if (typeof rmmRefresh === 'undefined') {
    function rmmRefresh(btn) {
        var origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '...';
        // Prefer vtiger's own tab-click mechanism so the full tab reload path is used
        var $tab = $('a[href*="RMMDevices"], .sideBarLinks a').filter(function(){
            return $(this).text().indexOf('RMM') !== -1;
        }).first();
        if ($tab.length) {
            $tab.trigger('click');
            btn.disabled = false;
            btn.textContent = origText;
            return;
        }
        // Fallback: direct AJAX call with force_refresh
        var record = (new URLSearchParams(window.location.search)).get('record')
                     || $('input[name="record"]').val()
                     || '';
        if (!record) { window.location.reload(); return; }
        $.ajax({
            url: 'index.php',
            data: {
                module: 'Accounts',
                view:   'RMMTab',
                record: record,
                force_refresh: '1',
                _: Date.now()
            },
            success: function(html) {
                var $target = $('[data-rmm="rmmdevices"]')
                              .closest('.contents, .contentsDiv');
                if (!$target.length) {
                    $target = $('div.details div.contents, div.contentsDiv div.contents').first();
                }
                if ($target.length) { $target.html(html); }
                btn.disabled = false;
                btn.textContent = origText;
            },
            error: function() {
                window.location.reload();
            }
        });
    }
}
</script>
JS;
            $refreshBtn = ' <button onclick="rmmRefresh(this);return false;"'
                . ' style="font-size:11px;padding:2px 8px;cursor:pointer;background:#fff;'
                . 'border:1px solid #bbb;border-radius:3px;margin-left:8px">&#8635; Aktualisieren</button>';
        }

        return $refreshScript
            . '<div style="font-size:13px;font-weight:bold;margin-bottom:8px;color:#333">'
            . $total . ' Agents'
            . ' &nbsp;|&nbsp; <span style="color:#2e7d32">' . $online  . ' Online</span>'
            . ' &nbsp;|&nbsp; <span style="color:#e65100">' . $overdue . ' Overdue</span>'
            . ' &nbsp;|&nbsp; <span style="color:#c62828">' . $offline . ' Offline</span>'
            . $ageText . $refreshBtn
            . '</div>';
    }

    private function extractLanIp(array $agent): string
    {
        $val = $agent['local_ips'] ?? $agent['ip_addresses'] ?? $agent['lanip'] ?? '';
        if (is_array($val)) {
            $val = implode(', ', $val);
        }
        $val = trim((string)$val);
        if (strpos($val, ',') !== false) {
            $parts = explode(',', $val);
            return trim($parts[0]);
        }
        return $val;
    }

    private function enrichAgents(array $agents, string $rmm_url, string $rmm_token): array
    {
        $loggedDetailKeys = false;
        $loggedCheckKeys  = false;

        foreach ($agents as &$agent) {
            $agentId = $agent['agent_id'] ?? $agent['id'] ?? null;
            if (!$agentId) continue;

            $base = rtrim($rmm_url, '/') . '/agents/' . $agentId;

            // ── Agent-Detail: custom_fields, CPU, RAM ─────────────────────────
            [$detail, $err] = $this->rmmGet($base . '/', $rmm_token);
            if ($err === null && is_array($detail)) {
                if (!$loggedDetailKeys) {
                    $this->log("Agent-Detail-Keys: " . implode(', ', array_keys($detail)));
                    $loggedDetailKeys = true;
                }
                if (!empty($detail['custom_fields'])) {
                    $agent['custom_fields'] = $detail['custom_fields'];
                }
                // CPU — try common field name variants
                foreach (['cpu_load', 'cpu', 'cpu_usage'] as $key) {
                    if (array_key_exists($key, $detail) && $detail[$key] !== null) {
                        $agent['cpu_load'] = $detail[$key];
                        break;
                    }
                }
                // total_ram capacity (GB) — try common field name variants
                foreach (['total_ram', 'ram_total', 'memory_total'] as $key) {
                    if (array_key_exists($key, $detail) && $detail[$key] !== null && (int)$detail[$key] > 0) {
                        $agent['total_ram'] = (int)$detail[$key];
                        break;
                    }
                }
            }

            // ── Checks: disk space ────────────────────────────────────────────
            [$checks, $err2] = $this->rmmGet($base . '/checks/', $rmm_token);
            if ($err2 === null && is_array($checks)) {
                $checkList = isset($checks['results']) ? $checks['results'] : $checks;
                $agent['checks_detail'] = $checkList;
                if (!$loggedCheckKeys && !empty($checkList)) {
                    $first = reset($checkList);
                    if (is_array($first)) {
                        $this->log("Check-Keys (erster): " . implode(', ', array_keys($first)));
                    }
                    $loggedCheckKeys = true;
                }
            }
        }
        unset($agent);
        return $agents;
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

    private function extractTeamViewerId(array $agent, array $tvFieldIds = []): string
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
            // ID-based format: {"field": 5, "value": "..."} — match against known field IDs
            if (!empty($tvFieldIds) && isset($cf['field']) && is_numeric($cf['field'])) {
                if (in_array((int)$cf['field'], $tvFieldIds, true)) {
                    return (string)($cf['value'] ?? '');
                }
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

    private function renderDiskChecks(array $agent): array
    {
        $sortable = []; // key = drive letter, value = html
        $maxPct   = 0;

        // Primary: checks_detail from /agents/{id}/checks/
        $allChecks = isset($agent['checks_detail']) && is_array($agent['checks_detail'])
                     ? $agent['checks_detail'] : [];

        foreach ($allChecks as $check) {
            if (!is_array($check)) continue;

            // Identify disk checks via readable_desc or presence of 'disk' field
            $rdesc  = (string)($check['readable_desc'] ?? '');
            $isDisk = stripos($rdesc, 'disk') !== false
                   || (isset($check['disk']) && (string)$check['disk'] !== '');
            if (!$isDisk) continue;

            // Drive letter from readable_desc: "Disk Space Check: Drive C: - ..."
            $drive = 'Disk';
            if (preg_match('/Drive\s+([^:\s]+:)/i', $rdesc, $dm)) {
                $drive = trim($dm[1]);
            }

            // more_info + status live inside check_result
            $moreInfo = (string)($check['check_result']['more_info'] ?? '');
            $status   = strtolower((string)($check['check_result']['status'] ?? 'passing'));

            // Calculate used% from "Total: 237.3 GB, Free: 140.1 GB"
            $pct = 0;
            if (preg_match('/Total:\s*([\d.]+)\s*GB,\s*Free:\s*([\d.]+)\s*GB/i', $moreInfo, $m)) {
                $total = (float)$m[1];
                $free  = (float)$m[2];
                $pct   = $total > 0 ? (int)round(($total - $free) / $total * 100) : 0;
            }
            if ($pct > $maxPct) $maxPct = $pct;

            $isPassing   = ($status === 'passing');
            $icon        = $isPassing ? '&#10003;' : '&#9888;';
            $color       = $isPassing ? 'color:#2e7d32' : 'color:#c62828';
            $sortable[$drive] = '<span style="' . $color . '">'
                . htmlspecialchars($drive) . ' ' . $pct . '% ' . $icon . '</span>';
        }

        // Fallback: agent['disks'] from agent listing (percent field)
        if (empty($sortable) && !empty($agent['disks']) && is_array($agent['disks'])) {
            foreach ($agent['disks'] as $disk) {
                if (!is_array($disk)) continue;
                $device = (string)($disk['device'] ?? $disk['name'] ?? '?');
                $pct    = isset($disk['percent']) ? (int)$disk['percent'] : null;
                if ($pct !== null) {
                    if ($pct > $maxPct) $maxPct = $pct;
                    $icon    = $pct >= 85 ? '&#9888;' : '&#10003;';
                    $color   = $pct >= 85 ? 'color:#c62828' : 'color:#2e7d32';
                    $sortable[$device] = '<span style="' . $color . '">'
                        . htmlspecialchars($device) . ' ' . $pct . '% ' . $icon . '</span>';
                } else {
                    $sortable[$device] = htmlspecialchars($device);
                }
            }
        }

        if (empty($sortable)) {
            return ['<span style="color:#999">&#8211;</span>', 0];
        }
        ksort($sortable);
        return [implode('<br>', array_values($sortable)), $maxPct];
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

    private function renderSelfInsertScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
(function($){
    // Snapshot the HTML of our content div while it is still in the DOM
    // (this script runs during jQuery's .html() call, so the element exists)
    var $rc = $('[data-rmm="rmmdevices"]').first();
    var rmmHtml    = $rc.length ? $rc.prop('outerHTML') : '';
    var agentCount = $rc.length ? parseInt($rc.attr('data-agent-count') || '-1', 10) : -1;

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

        // Update the sidebar tab label: "RMM Geräte" → "RMM Geräte (N)"
        if (agentCount >= 0) {
            $('a, span, li').each(function() {
                for (var i = 0; i < this.childNodes.length; i++) {
                    var node = this.childNodes[i];
                    if (node.nodeType === 3 && node.nodeValue.indexOf('RMM') !== -1) {
                        var updated = node.nodeValue.replace(
                            /RMM\s+Geräte(\s*\(\d+\))?/,
                            'RMM Geräte (' + agentCount + ')'
                        );
                        if (updated !== node.nodeValue) {
                            node.nodeValue = updated;
                        }
                    }
                }
            });
        }

        // Always remove any jQuery blockUI overlay that might still be covering the view
        var $dvi = $('div.detailViewInfo');
        if ($dvi.length) {
            try { $dvi.unblock(); } catch(e) {}
        }
        // Fallback: remove blockUI overlay elements directly
        $dvi.find('.blockUI').remove();
        $dvi.css({'opacity': '', 'pointer-events': ''});

        // If page was loaded via #rmm-tab redirect: clean hash, then re-click the tab
        // so vtiger registers it as the active tab in its own state
        if (window.location.hash === '#rmm-tab') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
            setTimeout(function(){
                $('a').filter(function(){
                    return $(this).text().replace(/\s*\(\d+\)/, '').trim() === 'RMM Geräte';
                }).first().trigger('click');
            }, 800);
        }
    }, 600);
})(jQuery);
</script>
JS;
    }

    private function sendAndExit(string $html): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $mode   = isset($_GET['mode']) ? (string)$_GET['mode'] : '';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               || !empty($_SERVER['HTTP_X_PJAX']);
        $isTab  = ($mode === 'showRelatedList') || $isAjax;

        $accountId = (int)(isset($_REQUEST['record']) ? $_REQUEST['record'] : 0);

        if (!$isTab && $accountId > 0) {
            // Direct browser hit — redirect to full Account view and activate the RMM tab via hash
            $redirectUrl = 'index.php?module=Accounts&view=Detail&record=' . $accountId . '#rmm-tab';
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><script>'
               . 'window.location.replace(' . json_encode($redirectUrl) . ');'
               . '</script></head><body></body></html>';
            exit;
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

    // ── Cache (5-Minuten TTL, logs/rmm_cache_{account}.json) ─────────────────

    private function getCachePath(string $accountNo): string
    {
        $root = realpath(__DIR__ . '/../../..');
        $dir  = ($root !== false ? $root : __DIR__ . '/../../..') . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . DIRECTORY_SEPARATOR . 'rmm_cache_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $accountNo) . '.json';
    }

    private function loadCache(string $accountNo): ?array
    {
        $file = $this->getCachePath($accountNo);
        if (!file_exists($file)) return null;
        if ((time() - filemtime($file)) > 300) return null;
        $raw = file_get_contents($file);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        if (($data['version'] ?? 0) !== self::CACHE_VERSION) return null;
        return $data;
    }

    private function saveCache(string $accountNo, array $data): void
    {
        $data['version'] = self::CACHE_VERSION;
        @file_put_contents($this->getCachePath($accountNo), json_encode($data), LOCK_EX);
    }
}
