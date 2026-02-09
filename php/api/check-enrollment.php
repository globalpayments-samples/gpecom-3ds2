<?php

declare(strict_types=1);

/**
 * 3DS2 Check Enrollment Endpoint
 *
 * Step 1 of the 3DS2 flow: Checks if a card is enrolled in 3D Secure 2
 * and retrieves the Method URL for device fingerprinting.
 *
 * POST /php/api/check-enrollment.php
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
    $expDate = $data['exp_date'] ?? '';
    $cardHolder = $data['card_holder'] ?? '';
    $orderId = $data['order_id'] ?? '';
    $demoMode = (bool)($data['demo_mode'] ?? false);

    // Strip HTML entities that sanitization may have added
    $cardNumber = html_entity_decode($cardNumber, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cardNumber = preg_replace('/\D/', '', $cardNumber);

    if (empty($cardNumber)) {
        ApiResponse::error('Card number is required', 400);
    }
    if (!SecurityHeaders::validateCardNumber($cardNumber)) {
        ApiResponse::error('Invalid card number', 400);
    }
    if (empty($expDate) || !SecurityHeaders::validateExpDate(html_entity_decode($expDate, ENT_QUOTES, 'UTF-8'))) {
        ApiResponse::error('Invalid expiry date (MMYY format required)', 400);
    }
    if (empty($cardHolder) || strlen($cardHolder) < 2 || strlen($cardHolder) > 100) {
        ApiResponse::error('Cardholder name must be 2-100 characters', 400);
    }
    if (empty($orderId)) {
        ApiResponse::error('Order ID is required', 400);
    }

    // Auto-detect card type if not provided
    $cardType = $data['card_type'] ?? SecurityHeaders::detectCardType($cardNumber);

    $params = [
        'card_number' => $cardNumber,
        'exp_date' => html_entity_decode($expDate, ENT_QUOTES, 'UTF-8'),
        'card_holder' => $cardHolder,
        'card_type' => $cardType,
        'order_id' => $orderId,
        'amount' => $data['amount'] ?? '1000',
        'currency' => $data['currency'] ?? 'EUR',
    ];

    $client = new GpecomClient();

    if ($demoMode) {
        $result = $client->simulateEnrollment($params);
    } else {
        $result = $client->check3DS2Enrollment($params);
    }

    ApiResponse::success($result, 'Enrollment check complete');

} catch (\GlobalPayments\Api\Entities\Exceptions\ApiException $e) {
    SecurityHeaders::logError('check-enrollment', $e);
    ApiResponse::error('Enrollment check failed: ' . $e->getMessage(), 502);
} catch (\Exception $e) {
    SecurityHeaders::logError('check-enrollment', $e);
    ApiResponse::error('An error occurred during enrollment check', 500);
}
