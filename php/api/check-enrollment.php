<?php

declare(strict_types=1);

/**
 * 3DS2 Check Enrollment Endpoint
 *
 * This endpoint checks if a card is enrolled in 3D Secure 2
 * and retrieves the method URL for device fingerprinting.
 *
 * @category  API_Endpoint
 * @package   GlobalPayments_GPeCOM
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/GpecomClient.php';

use Dotenv\Dotenv;
use GpecomSdk\GpecomClient;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    // Parse JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }

    // Validate required fields
    if (empty($input['card_number'])) {
        throw new Exception('Card number is required');
    }

    // Initialize GPeCOM client
    $client = new GpecomClient(
        $_ENV['GPECOM_MERCHANT_ID'],
        $_ENV['GPECOM_SHARED_SECRET'],
        $_ENV['GPECOM_REFUND_PASSWORD'],
        true, // sandbox mode
        true  // debug mode
    );

    // Prepare options
    $options = [
        'order_id' => $input['order_id'] ?? uniqid('order-'),
        'exp_date' => $input['exp_date'] ?? '1225',
        'card_holder' => $input['card_holder'] ?? 'John Doe',
        'card_type' => $input['card_type'] ?? 'VISA',
        'account' => $input['account'] ?? 'internet'
    ];

    // Check enrollment
    $response = $client->check3DS2Enrollment($input['card_number'], $options);

    // Check if request was successful
    if ($response['result'] !== '00') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Enrollment check failed',
            'message' => $response['message'],
            'details' => $response
        ]);
        exit;
    }

    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => [
            'enrolled' => $response['threeds']['enrolled'] ?? 'N',
            'server_trans_id' => $response['threeds']['server_trans_id'] ?? '',
            'method_url' => $response['threeds']['method_url'] ?? '',
            'ds_trans_id' => $response['threeds']['ds_trans_id'] ?? '',
            'order_id' => $response['order_id'],
            'pasref' => $response['pasref'],
            'message' => $response['message']
        ],
        'raw_response' => $response
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
