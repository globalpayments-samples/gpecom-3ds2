<?php

declare(strict_types=1);

/**
 * 3DS2 Verify Authentication Endpoint
 *
 * Step 4 of the 3DS2 flow: Verifies authentication after challenge completion.
 * Retrieves the final authentication data from the 3DS2 server.
 *
 * POST /php/api/verify-auth.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GpecomSdk\ApiResponse;
use GpecomSdk\GpecomClient;
use GpecomSdk\SecurityHeaders;

// Apply security headers and CORS
SecurityHeaders::apply();
SecurityHeaders::corsHeaders();
SecurityHeaders::requirePost();

try {
    $data = SecurityHeaders::readJsonBody();

    $serverTransId = $data['server_trans_id'] ?? '';
    $demoMode = (bool)($data['demo_mode'] ?? false);

    if (empty($serverTransId) && !$demoMode) {
        ApiResponse::error('Server transaction ID is required', 400);
    }

    $params = [
        'server_trans_id' => $serverTransId,
        'order_id' => $data['order_id'] ?? '',
        'amount' => $data['amount'] ?? '',
        'currency' => $data['currency'] ?? 'EUR',
    ];

    $client = new GpecomClient();

    if ($demoMode) {
        $result = $client->simulateVerifyAuth($params);
    } else {
        $result = $client->verify3DS2Authentication($params);
    }

    if (!$result['authenticated']) {
        ApiResponse::error('Authentication verification failed', 400, [
            'auth_data' => $result['auth_data'],
        ]);
    }

    ApiResponse::success($result, 'Authentication verified');

} catch (\GlobalPayments\Api\Entities\Exceptions\ApiException $e) {
    SecurityHeaders::logError('verify-auth', $e);
    ApiResponse::error('Authentication verification failed: ' . $e->getMessage(), 502);
} catch (\Exception $e) {
    SecurityHeaders::logError('verify-auth', $e);
    ApiResponse::error('An error occurred during verification', 500);
}
