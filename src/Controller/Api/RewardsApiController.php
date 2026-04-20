<?php

namespace App\Controller\Api;

use App\Model\Setting;
use App\Service\FeatureService;
use App\Service\RateLimiterService;
use App\Service\RewardFraudService;
use App\Service\RewardService;
use App\Service\SecurityService;

/**
 * RewardsApiController - High-Scale Multi-Server Reporting
 * 
 * Allows remote file servers to securely report download successes to the main node.
 */
class RewardsApiController
{
    /**
     * dropReceipt
     * 
     * Endpoint: POST /api/rewards/receipt
     */
    public function dropReceipt()
    {
        header('Content-Type: application/json');

        if (!FeatureService::rewardsEnabled()) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $clientIp = SecurityService::getClientIp();
        if (!RateLimiterService::check('rewards_receipt', 'ip:' . $clientIp, 120, 60)) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many receipt requests. Please retry shortly.']);
            exit;
        }

        $masterKey = Setting::get('remote_server_api_key', '');
        if (empty($masterKey)) {
            http_response_code(401);
            echo json_encode(['error' => 'Remote reward reporting is not configured.']);
            exit;
        }

        $fraud = new RewardFraudService();
        $result = $fraud->verifyAndRecordRemoteReceipt($_POST, $clientIp);
        if (!$result['ok']) {
            http_response_code((int)($result['code'] ?? 400));
            echo json_encode(['error' => $result['error'] ?? 'Invalid receipt']);
            exit;
        }

        if (($result['status'] ?? '') === 'verified_complete') {
            $service = new RewardService();
            $fraudContext = (new RewardFraudService())->exportRewardSignalContext($result['session'] ?? []);
            $service->trackDownload(
                (int)$result['file']['id'],
                (string)$result['client_ip'],
                $result['downloader_user_id'],
                [
                    'session_id' => $result['session']['id'] ?? null,
                    'proof_status' => $result['proof_status'] ?? 'verified',
                    'asn' => $result['session']['asn'] ?? '',
                    'network_type' => $result['session']['network_type'] ?? '',
                ] + $fraudContext
            );
        }

        echo json_encode([
            'status' => $result['status'] ?? 'accepted',
            'session' => $result['session']['public_id'] ?? null,
        ]);
        exit;
    }
}
