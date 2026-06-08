<?php
require_once __DIR__ . '/../SPAVDevicesHelper.php';

class SPAVDevices_InRelation_View extends Vtiger_RelatedList_View {

    public function process(Vtiger_Request $request): void
    {
        $accountId = (int) $request->get('record');

        $html = '<div class="relatedContainer" data-spav="spavdevices" data-device-count="0" style="padding:12px">';

        [$tid, $dbError] = $this->getTenantId($accountId);

        if ($dbError !== null) {
            $html .= $this->renderAlert('danger', 'Datenbankfehler: ' . htmlspecialchars($dbError));
            $html .= '</div>';
            $this->outputHtml($html, $request);
            return;
        }

        if ($tid === null || trim($tid) === '') {
            $html .= $this->renderLicenseSummary([]);
            $html .= $this->renderAlert('info',
                'Kein Securepoint AV Mandant verknüpft — bitte Feld <strong>cf_877</strong> am Account befüllen.');
            $html .= '</div>';
            $this->outputHtml($html, $request);
            return;
        }

        try {
            $pdo     = SPAVDevicesHelper::getPdo();
            $devices = $this->loadDevices($pdo, $accountId);
            $lastSync = $this->getLastSync($pdo, $accountId);
        } catch (Exception $e) {
            error_log('SPAVDevices_List_View::process DB-Fehler: ' . $e->getMessage());
            $html .= $this->renderAlert('danger', 'Datenbankfehler beim Laden der Geräteliste.');
            $html .= '</div>';
            $this->outputHtml($html, $request);
            return;
        }

        $html = str_replace('data-device-count="0"', 'data-device-count="' . count($devices) . '"', $html);
        $html .= $this->renderSyncBar($accountId, $lastSync);
        $html .= $this->renderLicenseSummary($devices);
        $html .= $this->renderTable($devices);
        $html .= $this->renderAutoSyncScript($accountId);
        $html .= $this->renderCountScript();
        $html .= '</div>';

        $this->outputHtml($html, $request);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function outputHtml(string $html, Vtiger_Request $request): void
    {
        $mode   = isset($_GET['mode']) ? (string)$_GET['mode'] : '';
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
               || !empty($_SERVER['HTTP_X_PJAX'])
               || ($mode === 'showRelatedList');

        if ($isAjax) {
            echo $html;
            return;
        }

        try {
            $viewer = $this->getViewer($request);
            $viewer->assign('SPAV_HTML', $html);
            $viewer->view('SPAVDevicesTab.tpl', 'SPAVDevices');
        } catch (Exception $e) {
            echo $html;
        }
    }

    private function getTenantId(int $accountId): array
    {
        try {
            $pdo  = SPAVDevicesHelper::getPdo();
            $stmt = $pdo->prepare('SELECT cf_877 FROM vtiger_accountscf WHERE accountid = ?');
            $stmt->execute([$accountId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return [null, null];
            }
            $val = isset($row['cf_877']) ? trim((string)$row['cf_877']) : '';
            return [$val === '' ? null : $val, null];
        } catch (Exception $e) {
            error_log('SPAVDevices getTenantId error: ' . $e->getMessage());
            return [null, $e->getMessage()];
        }
    }

    private function loadDevices(PDO $pdo, int $accountId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM mft_spav_devices WHERE account_id = ? ORDER BY sync_status ASC, hostname ASC'
        );
        $stmt->execute([(string)$accountId]);
        return $stmt->fetchAll();
    }

    private function getLastSync(PDO $pdo, int $accountId): ?string
    {
        $stmt = $pdo->prepare('SELECT MAX(last_sync) AS ls FROM mft_spav_devices WHERE account_id = ?');
        $stmt->execute([(string)$accountId]);
        $row = $stmt->fetch();
        return ($row && $row['ls'] !== null) ? (string)$row['ls'] : null;
    }

    private function syncAgeText(?string $lastSync): string
    {
        if ($lastSync === null) {
            return 'Noch nicht synchronisiert';
        }
        $ago = time() - strtotime($lastSync);
        if ($ago < 60)     return 'Letzte Synchronisierung: gerade eben';
        if ($ago < 3600)   return 'Letzte Synchronisierung: vor ' . (int)($ago / 60) . ' Minuten';
        if ($ago < 86400)  return 'Letzte Synchronisierung: vor ' . (int)($ago / 3600) . ' Stunden';
        return 'Letzte Synchronisierung: vor ' . (int)($ago / 86400) . ' Tagen';
    }

    private function renderLicenseSummary(array $devices): string
    {
        $total     = count($devices);
        $lost      = 0;
        $counts    = [];

        foreach ($devices as $dev) {
            if ((string)($dev['sync_status'] ?? '') === 'lost') {
                $lost++;
                continue;
            }
            $lic = trim((string)($dev['license_name'] ?? ''));
            $key = $lic !== '' ? $lic : '__none__';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $json = json_encode(['total' => $total, 'lost' => $lost, 'counts' => $counts],
                             JSON_UNESCAPED_UNICODE);

        return <<<JS
<div id="spav-license-summary" style="margin-bottom:10px"></div>
<script type="text/javascript">
(function(){
    var d = {$json};
    if (!d.total) return;
    var active = d.total - d.lost;
    var licensed = 0;
    var pills = '';
    var sorted = Object.keys(d.counts).filter(function(k){ return k !== '__none__'; })
                       .sort(function(a,b){ return d.counts[b]-d.counts[a]; });
    sorted.forEach(function(k){
        licensed += d.counts[k];
        pills += '<span style="display:inline-block;background:#e8f0fe;color:#1a56db;'
               + 'border:1px solid #c3d4fb;border-radius:12px;padding:1px 10px;'
               + 'font-size:12px;margin-left:8px">'
               + k + ' <strong>' + d.counts[k] + '</strong></span>';
    });
    var unlicensed = d.counts['__none__'] || 0;
    if (unlicensed) {
        pills += '<span style="display:inline-block;background:#fef3cd;color:#856404;'
               + 'border:1px solid #fde68a;border-radius:12px;padding:1px 10px;'
               + 'font-size:12px;margin-left:8px">Ohne Lizenz <strong>' + unlicensed + '</strong></span>';
    }
    if (d.lost) {
        pills += '<span style="display:inline-block;background:#fde8e8;color:#9b1c1c;'
               + 'border:1px solid #f8b4b4;border-radius:12px;padding:1px 10px;'
               + 'font-size:12px;margin-left:8px">Nicht gefunden <strong>' + d.lost + '</strong></span>';
    }
    var bar = '<div style="background:#f8f9fa;border:1px solid #e0e0e0;border-radius:4px;'
            + 'padding:8px 12px;font-size:13px;line-height:2">'
            + '<strong style="color:#333">' + d.total + ' Geräte</strong>'
            + '<span style="color:#bbb;margin:0 8px">|</span>'
            + '<span style="color:#2e7d32;font-weight:bold">' + active + ' aktiv</span>'
            + '<span style="color:#bbb;margin:0 8px">|</span>'
            + '<span style="color:#1a56db;font-weight:bold">' + licensed + ' lizenziert</span>'
            + pills + '</div>';
    var el = document.getElementById('spav-license-summary');
    if (el) el.innerHTML = bar;
})();
</script>
JS;
    }

    private function renderSyncBar(int $accountId, ?string $lastSync): string
    {
        $hintText = htmlspecialchars($this->syncAgeText($lastSync));
        return <<<HTML
<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap">
  <span id="spav-last-sync-hint" style="font-size:12px;color:#666">{$hintText}</span>
  <button id="spav-sync-btn"
          style="font-size:12px;padding:3px 10px;cursor:pointer;background:#fff;
                 border:1px solid #bbb;border-radius:3px;white-space:nowrap">
    &#8635; Jetzt synchronisieren
  </button>
  <span id="spav-sync-status" style="font-size:12px;color:#888"></span>
</div>
HTML;
    }

    private function renderTable(array $devices): string
    {
        if (empty($devices)) {
            return $this->renderAlert('info',
                'Keine AV-Clients gefunden — klicke <strong>Jetzt synchronisieren</strong> um Daten zu laden.');
        }

        $th  = 'padding:6px 10px;text-align:left;border:1px solid #ddd;white-space:nowrap;background:#f5f5f5';
        $thS = $th . ';cursor:pointer;user-select:none';
        $td  = 'padding:5px 10px;border:1px solid #ddd;vertical-align:middle';
        $tdc = $td . ';text-align:center';

        $headers = [
            ['Status',                 null],
            ['Hostname',               'hostname'],
            ['Eigene Bezeichnung',     'domain'],
            ['Gruppe',                 'group_name'],
            ['IP-Adresse',             'ip'],
            ['Betriebssystem',         'os'],
            ['Bedrohungen',            'infection_count'],
            ['Letzte Kommunikation',   'date_lastseen'],
            ['On-Access',              null],
            ['Version',                'version_product'],
        ];

        $html  = '<table class="table table-bordered listViewEntriesTable"'
               . ' style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<thead><tr class="listViewHeaders">';
        foreach ($headers as [$label, $colKey]) {
            if ($colKey !== null) {
                $html .= '<th style="' . $thS . '" data-col="' . htmlspecialchars($colKey) . '">'
                       . htmlspecialchars($label)
                       . ' <span class="spav-sort-arrow">&#8597;</span></th>';
            } else {
                $html .= '<th style="' . $th . '">' . htmlspecialchars($label) . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        foreach ($devices as $dev) {
            $isLost = (string)($dev['sync_status'] ?? '') === 'lost';

            [$ampelDot, $ampelTitle, $ampelColor] = $this->statusAmpel(
                (string)($dev['sync_status'] ?? 'active'),
                $dev['date_lastseen'] ?? null
            );

            $hostname  = htmlspecialchars((string)($dev['hostname']      ?? ''));
            $domain    = htmlspecialchars((string)($dev['domain']        ?? ''));
            $groupName = htmlspecialchars((string)($dev['group_name']    ?? ''));
            $ip        = htmlspecialchars((string)($dev['ip']            ?? ''));
            $os        = htmlspecialchars((string)($dev['os']            ?? ''));
            $threats   = (int)($dev['infection_count'] ?? 0);
            $onaccess  = (int)($dev['onaccess'] ?? 0);
            $verProd   = htmlspecialchars((string)($dev['version_product'] ?? ''));
            $verVdb    = htmlspecialchars((string)($dev['version_vdb']     ?? ''));

            $lastSeenRaw = $dev['date_lastseen'] ?? null;
            $lastSeenHtml = $lastSeenRaw !== null
                ? htmlspecialchars($this->formatDate($lastSeenRaw))
                : '<span style="color:#999">&#8211;</span>';

            $threatsHtml = $threats > 0
                ? '<span style="color:#c62828;font-weight:bold">' . $threats . '</span>'
                : '<span style="color:#2e7d32">0</span>';

            $onaccessHtml = $onaccess
                ? '<span style="color:#2e7d32">&#10003;</span>'
                : '<span style="color:#c62828">&#10007;</span>';

            $versionHtml = $verProd !== ''
                ? $verProd . ($verVdb !== '' ? '<br><span style="font-size:11px;color:#666">VDB: ' . $verVdb . '</span>' : '')
                : '<span style="color:#999">&#8211;</span>';

            $ampelCell = '<span title="' . htmlspecialchars($ampelTitle) . '" style="color:' . $ampelColor
                       . ';font-size:16px">&#9679;</span>';

            $lostNote = '';
            if ($isLost && !empty($dev['deleted_at'])) {
                $lostNote = '<br><span style="font-size:11px;color:#999">Nicht mehr gefunden seit: '
                          . htmlspecialchars($this->formatDate((string)$dev['deleted_at'])) . '</span>';
            }

            $rowStyle = $isLost
                ? 'opacity:0.55;background:#f8f8f8'
                : 'border-bottom:1px solid #eee';

            // sort values
            $lastSeenSort = $lastSeenRaw !== null
                ? str_pad((string)(time() - strtotime($lastSeenRaw)), 12, '0', STR_PAD_LEFT)
                : '999999999999';

            $html .= '<tr class="listViewEntries" style="' . $rowStyle . '">';
            $html .= '<td style="' . $tdc . '">' . $ampelCell . '</td>';
            $html .= '<td style="' . $td . '" data-col="hostname"'
                   . ' data-val="' . htmlspecialchars(strtolower((string)($dev['hostname'] ?? ''))) . '">'
                   . $hostname . $lostNote . '</td>';
            $html .= '<td style="' . $td . '" data-col="domain"'
                   . ' data-val="' . htmlspecialchars(strtolower($domain)) . '">' . $domain . '</td>';
            $html .= '<td style="' . $td . '" data-col="group_name"'
                   . ' data-val="' . htmlspecialchars(strtolower($groupName)) . '">' . $groupName . '</td>';
            $html .= '<td style="' . $td . '" data-col="ip"'
                   . ' data-val="' . htmlspecialchars($this->ipSortVal((string)($dev['ip'] ?? ''))) . '">'
                   . $ip . '</td>';
            $html .= '<td style="' . $td . '" data-col="os"'
                   . ' data-val="' . htmlspecialchars(strtolower($os)) . '">' . $os . '</td>';
            $html .= '<td style="' . $tdc . '" data-col="infection_count"'
                   . ' data-val="' . str_pad((string)$threats, 6, '0', STR_PAD_LEFT) . '">' . $threatsHtml . '</td>';
            $html .= '<td style="' . $td . ';white-space:nowrap" data-col="date_lastseen"'
                   . ' data-val="' . htmlspecialchars($lastSeenSort) . '">' . $lastSeenHtml . '</td>';
            $html .= '<td style="' . $tdc . '">' . $onaccessHtml . '</td>';
            $html .= '<td style="' . $td . '" data-col="version_product"'
                   . ' data-val="' . htmlspecialchars(strtolower($verProd)) . '">' . $versionHtml . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= $this->renderSortScript();
        return $html;
    }

    private function statusAmpel(string $syncStatus, ?string $dateLastSeen): array
    {
        if ($syncStatus === 'lost') {
            return ['Verloren', 'Nicht mehr gefunden', '#dc3545'];
        }
        if ($dateLastSeen === null || $dateLastSeen === '' || $dateLastSeen === '0000-00-00 00:00:00') {
            return ['Unbekannt', 'Keine Kommunikation bekannt', '#6c757d'];
        }
        $age = time() - strtotime($dateLastSeen);
        if ($age < 7200) {
            return ['Online', 'Letzte Kommunikation: vor weniger als 2 Stunden', '#28a745'];
        }
        if ($age < 86400) {
            return ['Inaktiv', 'Letzte Kommunikation: vor ' . (int)($age / 3600) . ' Stunden', '#fd7e14'];
        }
        return ['Offline', 'Letzte Kommunikation: vor ' . (int)($age / 86400) . ' Tagen', '#dc3545'];
    }

    private function formatDate(string $raw): string
    {
        if ($raw === '' || $raw === '0000-00-00 00:00:00') return '';
        try {
            $dt = new DateTime($raw);
            $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
            return $dt->format('d.m.Y H:i');
        } catch (Exception $e) {
            return $raw;
        }
    }

    private function ipSortVal(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return $ip;
        $p = [];
        foreach ($parts as $part) {
            $p[] = str_pad((string)(int)$part, 3, '0', STR_PAD_LEFT);
        }
        return implode('.', $p);
    }

    private function renderSortScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
(function(){
    var col = null, dir = 'asc';
    document.querySelectorAll('th[data-col]').forEach(function(th){
        th.addEventListener('click', function(){
            var c = th.getAttribute('data-col');
            dir = (col === c && dir === 'asc') ? 'desc' : 'asc';
            col = c;
            document.querySelectorAll('th[data-col] .spav-sort-arrow').forEach(function(a){ a.textContent = ' ↕'; });
            th.querySelector('.spav-sort-arrow').textContent = dir === 'asc' ? ' ↑' : ' ↓';
            var tbody = th.closest('table').querySelector('tbody');
            var rows  = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            rows.sort(function(a, b){
                var at = a.querySelector('td[data-col="' + col + '"]');
                var bt = b.querySelector('td[data-col="' + col + '"]');
                var av = at ? (at.getAttribute('data-val') || '') : '';
                var bv = bt ? (bt.getAttribute('data-val') || '') : '';
                var cmp = av < bv ? -1 : av > bv ? 1 : 0;
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(function(r){ tbody.appendChild(r); });
        });
    });
})();
</script>
JS;
    }

    private function renderAutoSyncScript(int $accountId): string
    {
        $aid = (int)$accountId;
        return <<<JS
<script type="text/javascript">
(function(\$){
    var accountId = {$aid};

    function updateHint(text) {
        var el = document.getElementById('spav-last-sync-hint');
        if (el) el.textContent = text;
    }

    function setStatus(text) {
        var el = document.getElementById('spav-sync-status');
        if (el) el.textContent = text;
    }

    function reloadTab() {
        \$.ajax({
            url: 'index.php',
            data: {
                module: 'SPAVDevices',
                view:   'Detail',
                record: accountId,
                mode:   'showRelatedList',
                relatedModule: 'SPAVDevices',
                _:      Date.now()
            },
            success: function(html){
                var \$wrap = \$('[data-spav="spavdevices"]').closest('.listViewContents, .contents');
                if (\$wrap.length) \$wrap.html(html);
            },
            error: function(){
                setStatus('Fehler beim Neuladen');
            }
        });
    }

    function doSync(force) {
        var btn = document.getElementById('spav-sync-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Synchronisiere…'; }
        setStatus('');

        \$.ajax({
            url: 'index.php',
            data: {
                module: 'SPAVDevices',
                action: 'Sync',
                record: accountId,
                force:  force ? 1 : 0,
                _:      Date.now()
            },
            dataType: 'json',
            success: function(data){
                if (data && data.synced) {
                    setStatus('Synchronisierung abgeschlossen (' + (data.device_count || 0) + ' Geräte)');
                    reloadTab();
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = '↻ Jetzt synchronisieren'; }
                    if (data && data.last_sync_hint) updateHint(data.last_sync_hint);
                    if (data && data.error) setStatus('Fehler: ' + data.error);
                }
            },
            error: function(){
                if (btn) { btn.disabled = false; btn.textContent = '↻ Jetzt synchronisieren'; }
                setStatus('Verbindungsfehler beim Sync');
            }
        });
    }

    var btn = document.getElementById('spav-sync-btn');
    if (btn) {
        btn.addEventListener('click', function(){ doSync(true); });
    }

    // Auto-Sync im Hintergrund nach kurzem Delay
    setTimeout(function(){ doSync(false); }, 600);

})(jQuery);
</script>
JS;
    }

    private function renderCountScript(): string
    {
        return <<<'JS'
<script type="text/javascript">
(function($){
    setTimeout(function(){
        var $dvi = $('div.detailViewInfo');
        if ($dvi.length) {
            try { $dvi.unblock(); } catch(e) {}
        }
        $dvi.find('.blockUI').remove();
        $dvi.css({'opacity': '', 'pointer-events': ''});

        var count = $('[data-spav="spavdevices"]').data('device-count');
        if (count !== undefined) {
            $('a, span').contents().filter(function(){
                return this.nodeType === 3
                    && this.nodeValue.replace(/\s*\(\d+\)/, '').trim() === 'AV Clients';
            }).each(function(){
                this.nodeValue = 'AV Clients (' + count + ')';
            });
        }
    }, 400);
})(jQuery);
</script>
JS;
    }

    private function renderAlert(string $type, string $html): string
    {
        $map = [
            'info'    => ['#d1ecf1', '#0c5460', '#bee5eb'],
            'warning' => ['#fff3cd', '#856404', '#ffeeba'],
            'danger'  => ['#f8d7da', '#721c24', '#f5c6cb'],
        ];
        [$bg, $fg, $border] = $map[$type] ?? $map['info'];
        return '<div style="background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $border
             . ';padding:10px 14px;border-radius:4px;margin:8px 0">' . $html . '</div>';
    }
}
