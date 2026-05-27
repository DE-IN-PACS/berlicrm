<?php
require_once __DIR__ . '/../SPAVDevicesHelper.php';

class SPAVDevices_Sync_Action extends Vtiger_Action_Controller {

    private const GQL_QUERY = <<<'GQL'
query Devices($tid: ID, $first: Int!, $page: Int) {
  devices(tid: $tid, first: $first, page: $page) {
    paginatorInfo { hasMorePages lastPage }
    data {
      deviceid tid hostname domain ip os onaccess
      version_product version_vdb
      date_lastseen infection_count
      group { id name }
      license { name }
    }
  }
}
GQL;

    public function checkPermission(Vtiger_Request $request): void {}

    public function process(Vtiger_Request $request): void
    {
        $log = LoggerManager::getLogger('SPAVDevices');
        header('Content-Type: application/json');

        $accountId = (string) $request->get('record');
        $force     = ((string) $request->get('force') === '1');

        $log->debug("SPAVDevices Sync start: accountId={$accountId}, force=" . ($force ? '1' : '0'));

        if ($accountId === '' || $accountId === '0') {
            $log->error('SPAVDevices Sync: Keine Account-ID übergeben');
            echo json_encode(['synced' => false, 'error' => 'Keine Account-ID übergeben']);
            exit();
        }

        [$tid, $dbError] = $this->getTenantId((int)$accountId);
        if ($dbError !== null) {
            $log->error('SPAVDevices Sync: DB-Fehler bei getTenantId: ' . $dbError);
            echo json_encode(['synced' => false, 'error' => 'Datenbankfehler: ' . $dbError]);
            exit();
        }
        if ($tid === null || trim($tid) === '') {
            $log->warn('SPAVDevices Sync: cf_877 ist leer für accountId=' . $accountId);
            echo json_encode(['synced' => false, 'error' => 'cf_877 ist leer — Tenant-ID am Account eintragen']);
            exit();
        }
        $log->debug("SPAVDevices Sync: tid={$tid}");

        [$spavUrl, $spavToken, $intervalHours, $cfgError] = SPAVDevicesHelper::loadConfig();
        if ($cfgError !== null) {
            $log->error('SPAVDevices Sync: Konfigurationsfehler: ' . $cfgError);
            echo json_encode(['synced' => false, 'error' => $cfgError]);
            exit();
        }
        $log->debug("SPAVDevices Sync: config geladen, url={$spavUrl}, interval={$intervalHours}h");

        try {
            $pdo      = SPAVDevicesHelper::getPdo();
            $lastSync = $this->getLastSync($pdo, $accountId);
        } catch (Exception $e) {
            $log->error('SPAVDevices Sync: DB-Fehler beim getLastSync: ' . $e->getMessage());
            echo json_encode(['synced' => false, 'error' => 'Datenbankfehler: ' . $e->getMessage()]);
            exit();
        }

        $needsSync = $force
            || $lastSync === null
            || (time() - strtotime($lastSync)) >= ($intervalHours * 3600);

        $log->debug("SPAVDevices Sync: lastSync={$lastSync}, needsSync=" . ($needsSync ? 'ja' : 'nein'));

        if (!$needsSync) {
            echo json_encode([
                'synced'          => false,
                'last_sync'       => $lastSync,
                'last_sync_hint'  => $this->syncAgeText($lastSync),
                'device_count'    => $this->countDevices($pdo, $accountId),
            ]);
            exit();
        }

        // ── GraphQL-Sync ──────────────────────────────────────────────────────

        $log->debug("SPAVDevices Sync: starte GraphQL-Abruf für tid={$tid}");
        [$allDevices, $apiError] = $this->fetchAllDevices($spavUrl, $spavToken, $tid, $log);
        if ($apiError !== null) {
            $log->error('SPAVDevices Sync: API-Fehler für tid=' . $tid . ': ' . $apiError);
            echo json_encode(['synced' => false, 'error' => $apiError]);
            exit();
        }
        $log->debug('SPAVDevices Sync: ' . count($allDevices) . ' Geräte von API erhalten');

        try {
            $count = $this->persistDevices($pdo, $accountId, $allDevices);
        } catch (Exception $e) {
            $log->error('SPAVDevices Sync: Fehler beim Speichern: ' . $e->getMessage());
            echo json_encode(['synced' => false, 'error' => 'DB-Fehler beim Speichern: ' . $e->getMessage()]);
            exit();
        }
        $log->debug("SPAVDevices Sync: {$count} Geräte gespeichert/aktualisiert");

        $newLastSync = date('Y-m-d H:i:s');
        echo json_encode([
            'synced'         => true,
            'device_count'   => $count,
            'last_sync'      => $newLastSync,
            'last_sync_hint' => $this->syncAgeText($newLastSync),
        ]);
        exit();
    }

    // ─────────────────────────────────────────────────────────────────────────

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
            return [null, $e->getMessage()];
        }
    }

    private function getLastSync(PDO $pdo, string $accountId): ?string
    {
        $stmt = $pdo->prepare('SELECT MAX(last_sync) AS ls FROM mft_spav_devices WHERE account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        return ($row && $row['ls'] !== null) ? (string)$row['ls'] : null;
    }

    private function countDevices(PDO $pdo, string $accountId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM mft_spav_devices WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return (int)$stmt->fetchColumn();
    }

    private function fetchAllDevices(string $url, string $token, string $tid, $log): array
    {
        $all  = [];
        $page = 1;

        do {
            $log->debug("SPAVDevices fetchAllDevices: Seite {$page}");
            [$data, $err] = SPAVDevicesHelper::graphqlPost($url, $token, self::GQL_QUERY, [
                'tid'   => $tid,
                'first' => 100,
                'page'  => $page,
            ]);

            if ($err !== null) {
                $log->error("SPAVDevices fetchAllDevices Seite {$page} Fehler: {$err}");
                return [null, $err];
            }

            $devices  = (array)($data['data']['devices']['data']          ?? []);
            $hasMore  = (bool)($data['data']['devices']['paginatorInfo']['hasMorePages'] ?? false);
            $log->debug("SPAVDevices fetchAllDevices Seite {$page}: " . count($devices) . " Geräte, hasMore=" . ($hasMore ? 'ja' : 'nein'));

            foreach ($devices as $dev) {
                if (is_array($dev)) {
                    $all[] = $dev;
                }
            }

            $page++;
        } while ($hasMore && $page <= 50);

        return [$all, null];
    }

    private function persistDevices(PDO $pdo, string $accountId, array $apiDevices): int
    {
        $apiIds = [];

        $upsert = $pdo->prepare(
            'INSERT INTO mft_spav_devices
                (account_id, deviceid, tid, hostname, domain, ip, os, onaccess,
                 version_product, version_vdb, date_lastseen, infection_count,
                 group_id, group_name, license_name, first_seen_local, last_sync,
                 sync_status, deleted_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), \'active\', NULL)
             ON DUPLICATE KEY UPDATE
                account_id      = VALUES(account_id),
                tid             = VALUES(tid),
                hostname        = VALUES(hostname),
                domain          = VALUES(domain),
                ip              = VALUES(ip),
                os              = VALUES(os),
                onaccess        = VALUES(onaccess),
                version_product = VALUES(version_product),
                version_vdb     = VALUES(version_vdb),
                date_lastseen   = VALUES(date_lastseen),
                infection_count = VALUES(infection_count),
                group_id        = VALUES(group_id),
                group_name      = VALUES(group_name),
                license_name    = VALUES(license_name),
                last_sync       = NOW(),
                sync_status     = \'active\',
                deleted_at      = NULL'
        );

        foreach ($apiDevices as $dev) {
            $deviceId = (string)($dev['deviceid'] ?? '');
            if ($deviceId === '') continue;

            $apiIds[] = $deviceId;

            $groupId   = (string)($dev['group']['id']    ?? '');
            $groupName = (string)($dev['group']['name']  ?? '');
            $license   = (string)($dev['license']['name'] ?? '');
            $onaccess  = !empty($dev['onaccess']) ? 1 : 0;
            $infections = (int)($dev['infection_count'] ?? 0);
            $lastSeen   = SPAVDevicesHelper::normalizeDate($dev['date_lastseen'] ?? null);

            $upsert->execute([
                $accountId,
                $deviceId,
                (string)($dev['tid']              ?? ''),
                (string)($dev['hostname']          ?? ''),
                (string)($dev['domain']            ?? ''),
                (string)($dev['ip']                ?? ''),
                (string)($dev['os']                ?? ''),
                $onaccess,
                (string)($dev['version_product']   ?? ''),
                (string)($dev['version_vdb']       ?? ''),
                $lastSeen,
                $infections,
                $groupId,
                $groupName,
                $license,
            ]);
        }

        if (empty($apiIds)) {
            $stmt = $pdo->prepare(
                "UPDATE mft_spav_devices
                 SET sync_status='lost', deleted_at=NOW()
                 WHERE account_id=? AND sync_status='active'"
            );
            $stmt->execute([$accountId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($apiIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE mft_spav_devices
                 SET sync_status='lost', deleted_at=NOW()
                 WHERE account_id=? AND sync_status='active' AND deviceid NOT IN ($placeholders)"
            );
            $stmt->execute(array_merge([$accountId], $apiIds));
        }

        return count($apiIds);
    }

    private function syncAgeText(?string $lastSync): string
    {
        if ($lastSync === null) {
            return 'Noch nicht synchronisiert';
        }
        $ago = time() - strtotime($lastSync);
        if ($ago < 60)    return 'Letzte Synchronisierung: gerade eben';
        if ($ago < 3600)  return 'Letzte Synchronisierung: vor ' . (int)($ago / 60) . ' Minuten';
        if ($ago < 86400) return 'Letzte Synchronisierung: vor ' . (int)($ago / 3600) . ' Stunden';
        return 'Letzte Synchronisierung: vor ' . (int)($ago / 86400) . ' Tagen';
    }
}
