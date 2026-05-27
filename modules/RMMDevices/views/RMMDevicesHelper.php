<?php
class RMMDevicesHelper {

    public static function loadConfig(): array
    {
        $path = __DIR__ . '/../../../config_rmm.php';
        if (!file_exists($path)) {
            return [null, null, '', 'config_rmm.php nicht gefunden (Pfad: ' . $path . ')'];
        }
        $cfg = require $path;
        if (empty($cfg['rmm_url']) || empty($cfg['rmm_token'])) {
            return [null, null, '', 'config_rmm.php unvollständig: rmm_url und rmm_token werden benötigt.'];
        }
        return [
            $cfg['rmm_url'],
            $cfg['rmm_token'],
            (string)($cfg['rmm_frontend_url'] ?? ''),
            null,
        ];
    }

    public static function rmmGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $token, 'Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlErr !== '') return [null, $curlErr ?: 'cURL-Fehler'];
        if ($code < 200 || $code >= 300)        return [null, "HTTP {$code}: " . substr((string)$body, 0, 200)];
        $data = json_decode($body, true);
        if (!is_array($data))                   return [null, 'Ungültige JSON-Antwort: ' . substr((string)$body, 0, 200)];
        return [$data, null];
    }
}
