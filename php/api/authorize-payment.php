<?php

declare(strict_types=1);

/**
 * Authorize Payment Endpoint
 *
 * This endpoint processes a payment authorization with 3DS2
 * authentication data after successful authentication.
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
    $required = ['card_number', 'amount', 'currency', 'auth_data'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field '{$field}' is required");
        }
    }

    // Validate auth_data structure
    $authRequired = ['eci', 'cavv', 'xid', 'ds_trans_id'];
    foreach ($authRequired as $field) {
        if (!isset($input['auth_data'][$field])) {
            throw new Exception("Authentication data field '{$field}' is required");
        }
    }

    // Get account from environment (must be configured for 3DS2/MPI)
    $account = $_ENV['GPECOM_ACCOUNT'] ?? 'internet';

    // Initialize GPeCOM client
    $client = new GpecomClient(
        $_ENV['GPECOM_MERCHANT_ID'],
        $_ENV['GPECOM_SHARED_SECRET'],
        $_ENV['GPECOM_REFUND_PASSWORD'],
        true, // sandbox mode
        true  // debug mode
    );

    // Configure client with account
    $client->setAccountId($account);

    // Prepare options
    $options = [
        'order_id' => $input['order_id'] ?? uniqid('order-'),
        'exp_date' => $input['exp_date'] ?? '1225',
        'card_holder' => $input['card_holder'] ?? 'John Doe',
        'card_type' => $input['card_type'] ?? 'VISA',
        'cvv' => $input['cvv'] ?? '123',
        'account' => $input['account'] ?? $account
    ];

    // Check if demo mode is enabled
    $demoMode = isset($input['demo_mode']) && $input['demo_mode'] === true;

    if ($demoMode) {
        // Simulate payment authorization
        echo json_encode([
            'success' => true,
            'demo_mode' => true,
            'data' => [
                'authorized' => true,
                'transaction_id' => 'DEMO-' . strtoupper(substr(uniqid(), -8)),
                'order_id' => $options['order_id'],
                'message' => 'Demo Mode - Payment Authorized',
                'timestamp' => date('YmdHis')
            ]
        ]);
        exit;
    }

    // Authorize payment with actual API
    $response = $client->authorizePayment(
        $input['card_number'],
        $input['amount'],
        $input['currency'],
        $input['auth_data'],
        $options
    );

    // Check if authorization was successful
    if ($response['result'] !== '00') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Payment authorization failed',
            'message' => $response['message'],
            'details' => $response
        ]);
        exit;
    }

    // Return successful payment response
    echo json_encode([
        'success' => true,
        'data' => [
            'authorized' => true,
            'transaction_id' => $response['pasref'],
            'order_id' => $response['order_id'],
            'message' => $response['message'],
            'timestamp' => $response['timestamp']
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
