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

    // Configure client with account and notification URLs
    $client->setAccountId($account);

    // Set notification URLs (client validates they are HTTPS and not localhost)
    if (!empty($_ENV['METHOD_NOTIFICATION_URL'])) {
        $client->setMethodNotificationUrl($_ENV['METHOD_NOTIFICATION_URL']);
    }
    if (!empty($_ENV['CHALLENGE_NOTIFICATION_URL'])) {
        $client->setChallengeNotificationUrl($_ENV['CHALLENGE_NOTIFICATION_URL']);
    }

    // Prepare options
    $options = [
        'order_id' => $input['order_id'] ?? uniqid('order-'),
        'exp_date' => $input['exp_date'] ?? '1225',
        'card_holder' => $input['card_holder'] ?? 'John Doe',
        'card_type' => $input['card_type'] ?? 'VISA',
        'account' => $input['account'] ?? $account,
        'amount' => $input['amount'] ?? '1000',
        'currency' => $input['currency'] ?? 'USD'
    ];

    // Check if demo mode is enabled (for testing when 3DS2 is not configured)
    $demoMode = isset($input['demo_mode']) && $input['demo_mode'] === true;

    if ($demoMode) {
        // Simulate enrollment response for UI testing
        $isChallenge = in_array($input['card_number'], [
            '4012001037461114', // Visa Challenge
            '5425230000004407'  // MC Challenge
        ]);

        echo json_encode([
            'success' => true,
            'demo_mode' => true,
            'data' => [
                'enrolled' => 'Y',
                'server_trans_id' => 'demo-' . uniqid(),
                'method_url' => '',
                'ds_trans_id' => 'demo-ds-' . uniqid(),
                'order_id' => $options['order_id'],
                'pasref' => 'demo-' . time(),
                'message' => 'Demo Mode - Card enrolled',
                'challenge_simulation' => $isChallenge
            ]
        ]);
        exit;
    }

    // Check enrollment with actual API
    $response = $client->check3DS2Enrollment($input['card_number'], $options);

    // Check if request was successful
    // Valid result codes: '00' = success, '110' = not enrolled but can proceed
    $validCodes = ['00', '110'];

    if (!in_array($response['result'], $validCodes)) {
        // Detect MPI/3DS2 configuration errors (multiple possible patterns)
        $isMpiError = false;
        $resultCode = $response['result'];
        $message = strtolower($response['message'] ?? '');

        // Check for various MPI-related error indicators
        if (
            // Result code 503 with MPI mention
            ($resultCode === '503' && stripos($message, 'mpi') !== false) ||
            // Result code 508 (service not configured)
            $resultCode === '508' ||
            // Result code 520 (no response from MPI)
            $resultCode === '520' ||
            // Message contains MPI-related terms
            stripos($message, 'mpi') !== false ||
            stripos($message, '3ds') !== false ||
            stripos($message, '3d secure') !== false ||
            stripos($message, 'not configured') !== false ||
            stripos($message, 'service not available') !== false ||
            // Account-related errors
            (stripos($message, 'account') !== false && stripos($message, 'invalid') !== false)
        ) {
            $isMpiError = true;
        }

        if ($isMpiError) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => '3DS2 not configured',
                'message' => 'Your Global Payments account does not have 3D Secure 2 (MPI) enabled. Please contact Global Payments support to enable 3DS2 on your sandbox account, or enable Demo Mode for UI testing.',
                'hint' => 'Click "Enable Demo Mode" above the form to test the UI flow. Also verify your GPECOM_ACCOUNT setting in .env matches an account with MPI enabled.',
                'account_used' => $options['account'],
                'details' => $response
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Enrollment check failed',
            'message' => $response['message'] ?? 'Unknown error',
            'result_code' => $resultCode,
            'account_used' => $options['account'],
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
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
