<?php

declare(strict_types=1);

/**
 * 3DS2 Verify Authentication Endpoint
 *
 * This endpoint verifies the 3D Secure 2 authentication
 * after the cardholder has completed the challenge.
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
    $required = ['order_id', 'amount', 'currency', 'cres'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field '{$field}' is required");
        }
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
        'account' => $input['account'] ?? 'internet'
    ];

    // Verify authentication
    $response = $client->verify3DS2Authentication(
        $input['order_id'],
        $input['amount'],
        $input['currency'],
        $input['cres'], // Challenge Response (CRes)
        $options
    );

    // Check if verification was successful
    if ($response['result'] !== '00') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication verification failed',
            'message' => $response['message'],
            'details' => $response
        ]);
        exit;
    }

    // Return authentication data for authorization
    echo json_encode([
        'success' => true,
        'data' => [
            'authenticated' => true,
            'auth_data' => [
                'eci' => $response['threeds']['eci'] ?? '',
                'cavv' => $response['threeds']['cavv'] ?? '',
                'xid' => $response['threeds']['xid'] ?? '',
                'ds_trans_id' => $response['threeds']['ds_trans_id'] ?? '',
                'status' => $response['threeds']['status'] ?? ''
            ],
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
