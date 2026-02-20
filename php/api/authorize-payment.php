<?php

declare(strict_types=1);

/**
 * 3DS2 Authorize Payment Endpoint
 *
 * Step 5 of the 3DS2 flow: Processes the payment authorization
 * with 3DS2 authentication data (ECI, CAVV, etc.).
 *
 * POST /php/api/authorize-payment.php
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
    $cvv = $data['cvv'] ?? '';
    $authData = $data['auth_data'] ?? [];
    $demoMode = (bool)($data['demo_mode'] ?? false);

    if (empty($cardNumber) || !SecurityHeaders::validateCardNumber($cardNumber)) {
        ApiResponse::error('Invalid card number', 400);
    }
    if (empty($amount) || !SecurityHeaders::validateAmount((string)$amount)) {
        ApiResponse::error('Invalid amount', 400);
    }
    if (!SecurityHeaders::validateCurrency($currency)) {
        ApiResponse::error('Invalid currency', 400);
    }
    if (!empty($cvv) && !SecurityHeaders::validateCvv(html_entity_decode($cvv, ENT_QUOTES, 'UTF-8'))) {
        ApiResponse::error('Invalid CVV', 400);
    }
    if (empty($authData) && !$demoMode) {
        ApiResponse::error('Authentication data is required', 400);
    }

    $cardType = $data['card_type'] ?? SecurityHeaders::detectCardType($cardNumber);

    $params = [
        'card_number' => $cardNumber,
        'amount' => (string)$amount,
        'currency' => strtoupper($currency),
        'exp_date' => html_entity_decode($data['exp_date'] ?? '1228', ENT_QUOTES, 'UTF-8'),
        'card_holder' => $data['card_holder'] ?? 'Test Customer',
        'card_type' => $cardType,
        'cvv' => html_entity_decode($cvv, ENT_QUOTES, 'UTF-8'),
        'order_id' => $data['order_id'] ?? '',
        'auth_data' => $authData,
    ];

    $client = new GpecomClient();

    if ($demoMode) {
        $result = $client->simulateAuthorization($params);
    } else {
        $result = $client->authorizePayment($params);
    }

    if (!$result['authorized']) {
        ApiResponse::error($result['message'] ?? 'Payment declined', 400, [
            'response_code' => $result['response_code'] ?? '',
        ]);
    }

    ApiResponse::success($result, 'Payment authorized');

} catch (\GlobalPayments\Api\Entities\Exceptions\ApiException $e) {
    SecurityHeaders::logError('authorize-payment', $e);
    ApiResponse::error('Payment authorization failed: ' . $e->getMessage(), 502);
} catch (\Exception $e) {
    SecurityHeaders::logError('authorize-payment', $e);
    ApiResponse::error('An error occurred during payment authorization', 500);
}
