<?php

declare(strict_types=1);

/**
 * 3DS2 Initiate Authentication Endpoint
 *
 * Step 3 of the 3DS2 flow: Initiates authentication with browser data.
 * Returns either frictionless approval or challenge requirement.
 *
 * POST /php/api/initiate-auth.php
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

    // Validate required fields
    $cardNumber = $data['card_number'] ?? '';
    $cardNumber = html_entity_decode($cardNumber, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cardNumber = preg_replace('/\D/', '', $cardNumber);
    $amount = $data['amount'] ?? '';
    $currency = $data['currency'] ?? 'EUR';
    $expDate = $data['exp_date'] ?? '';
    $serverTransId = $data['server_trans_id'] ?? '';
    $demoMode = (bool)($data['demo_mode'] ?? false);

    if (empty($cardNumber) || !SecurityHeaders::validateCardNumber($cardNumber)) {
        ApiResponse::error('Invalid card number', 400);
    }
    if (empty($amount) || !SecurityHeaders::validateAmount((string)$amount)) {
        ApiResponse::error('Invalid amount', 400);
    }
    if (!SecurityHeaders::validateCurrency($currency)) {
        ApiResponse::error('Invalid currency (EUR, USD, GBP accepted)', 400);
    }
    if (empty($serverTransId) && !$demoMode) {
        ApiResponse::error('Server transaction ID is required', 400);
    }

    $cardType = $data['card_type'] ?? SecurityHeaders::detectCardType($cardNumber);

    $params = [
        'card_number' => $cardNumber,
        'amount' => (string)$amount,
        'currency' => strtoupper($currency),
        'exp_date' => html_entity_decode($expDate, ENT_QUOTES, 'UTF-8'),
        'card_holder' => $data['card_holder'] ?? 'Test Customer',
        'card_type' => $cardType,
        'order_id' => $data['order_id'] ?? '',
        'server_trans_id' => $serverTransId,
        'method_url_complete' => $data['method_url_complete'] ?? 'true',
        'browser_data' => $data['browser_data'] ?? [],
        'customer' => $data['customer'] ?? [],
    ];

    $client = new GpecomClient();

    if ($demoMode) {
        $result = $client->simulateAuthentication($params);

        // For demo mode, handle failed authentication scenarios
        $failedStatuses = [
            'AUTHENTICATION_FAILED',
            'AUTHENTICATION_ISSUER_REJECTED',
            'AUTHENTICATION_COULD_NOT_BE_PERFORMED',
        ];

        if (!$result['challenge_required'] && in_array($result['status'], $failedStatuses, true)) {
            ApiResponse::error(
                'Authentication failed: ' . $result['status'],
                400,
                ['auth_data' => $result['auth_data']]
            );
        }
    } else {
        $result = $client->initiate3DS2Authentication($params);

        // Handle failed authentication from live API
        $failedStatuses = [
            'AUTHENTICATION_FAILED',
            'AUTHENTICATION_ISSUER_REJECTED',
            'AUTHENTICATION_COULD_NOT_BE_PERFORMED',
        ];

        if (!$result['challenge_required'] && in_array($result['status'], $failedStatuses, true)) {
            ApiResponse::error(
                'Authentication failed: ' . $result['status'],
                400,
                ['auth_data' => $result['auth_data'] ?? []]
            );
        }
    }

    ApiResponse::success($result, 'Authentication initiated');

} catch (\GlobalPayments\Api\Entities\Exceptions\ApiException $e) {
    SecurityHeaders::logError('initiate-auth', $e);
    ApiResponse::error('Authentication initiation failed: ' . $e->getMessage(), 502);
} catch (\Exception $e) {
    SecurityHeaders::logError('initiate-auth', $e);
    ApiResponse::error('An error occurred during authentication', 500);
}
