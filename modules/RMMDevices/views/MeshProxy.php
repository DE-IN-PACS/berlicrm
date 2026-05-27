<?php
require_once __DIR__ . '/RMMDevicesHelper.php';

class RMMDevices_MeshProxy_View extends Vtiger_Index_View {

    public function process(Vtiger_Request $request)
    {
        $agentId = trim((string)$request->get('agent_id'));
        if ($agentId === '') {
            $this->jsonExit(['error' => 'agent_id fehlt']);
        }

        [$rmm_url, $rmm_token, , $configErr] = RMMDevicesHelper::loadConfig();
        if ($configErr !== null) {
            $this->jsonExit(['error' => $configErr]);
        }

        [$data, $apiErr] = RMMDevicesHelper::rmmGet(
            rtrim($rmm_url, '/') . '/agents/' . rawurlencode($agentId) . '/meshcentral/',
            $rmm_token
        );

        if ($apiErr !== null) {
            $this->jsonExit(['error' => $apiErr]);
        }

        $url = (string)($data['control'] ?? $data['url'] ?? '');
        if ($url === '') {
            $this->jsonExit(['error' => 'Kein Control-Link in der API-Antwort']);
        }

        $this->jsonExit(['url' => $url]);
    }

    private function jsonExit(array $data): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($data);
        exit;
    }

    public function validateRequest(Vtiger_Request $request): void
    {
        $request->validateReadAccess();
    }
}
