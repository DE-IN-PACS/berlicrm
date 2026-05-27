<?php
class SPAVDevicesHelper {

    public static function loadConfig(): array
    {
        $path = __DIR__ . '/../../config_spav.php';
        if (!file_exists($path)) {
            return [null, null, 4, 'config_spav.php nicht gefunden (Pfad: ' . $path . ')'];
        }
        $cfg = require $path;
        if (empty($cfg['spav_url']) || empty($cfg['spav_token'])) {
            return [null, null, 4, 'config_spav.php unvollständig: spav_url und spav_token werden benötigt.'];
        }
        return [
            rtrim((string)$cfg['spav_url'], '/'),
            (string)$cfg['spav_token'],
            (int)($cfg['sync_interval_hours'] ?? 4),
            null,
        ];
    }

    public static function getPdo(): PDO
    {
        global $dbconfig;
        $host = $dbconfig['db_server'] ?? 'localhost';
        $port = !empty($dbconfig['db_port']) && $dbconfig['db_port'] !== '3306'
                ? ';port=' . $dbconfig['db_port'] : '';
        $name = $dbconfig['db_name']     ?? '';
        $user = $dbconfig['db_username'] ?? '';
        $pass = $dbconfig['db_password'] ?? '';
        $dsn  = 'mysql:host=' . $host . $port . ';dbname=' . $name . ';charset=utf8mb4';
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * POST GraphQL query. Returns [data_array_or_null, error_string_or_null].
     */
    public static function graphqlPost(string $url, string $token, string $query, array $variables): array
    {
        $payload = json_encode(['query' => $query, 'variables' => $variables]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') {
            return [null, 'cURL-Fehler: ' . $curlErr];
        }
        if ($code < 200 || $code >= 300) {
            return [null, 'HTTP ' . $code . ': ' . substr((string)$body, 0, 200)];
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return [null, 'Ungültige JSON-Antwort: ' . substr((string)$body, 0, 200)];
        }
        if (!empty($data['errors'])) {
            $msg = (string)($data['errors'][0]['message'] ?? json_encode($data['errors']));
            return [null, 'GraphQL-Fehler: ' . $msg];
        }
        return [$data, null];
    }

    public static function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === '0000-00-00 00:00:00') {
            return null;
        }
        try {
            $dt = new DateTime($raw);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}
