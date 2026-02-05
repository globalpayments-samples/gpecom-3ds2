<?php

declare(strict_types=1);

/**
 * 3DS2 Initiate Authentication Endpoint
 *
 * This endpoint initiates the 3D Secure 2 authentication process
 * after the method URL has been completed (or skipped).
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
    $required = ['card_number', 'amount', 'currency', 'browser_data'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field '{$field}' is required");
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
        'server_trans_id' => $input['server_trans_id'] ?? '',
        'method_url_complete' => $input['method_url_complete'] ?? 'false'
    ];

    // Add customer data if provided
    if (!empty($input['customer'])) {
        $options['customer'] = $input['customer'];
    }

    // Check if demo mode is enabled
    $demoMode = isset($input['demo_mode']) && $input['demo_mode'] === true;

    if ($demoMode) {
        // Simulate authentication response
        $isChallenge = isset($input['challenge_simulation']) && $input['challenge_simulation'] === true;

        if ($isChallenge) {
            echo json_encode([
                'success' => true,
                'demo_mode' => true,
                'data' => [
                    'challenge_required' => true,
                    'status' => 'C',
                    'order_id' => $options['order_id'],
                    'pasref' => 'demo-' . time(),
                    'message' => 'Demo Mode - Challenge Required',
                    'challenge' => [
                        'acs_url' => '/webhooks/challenge-notification.php',
                        'creq' => base64_encode(json_encode([
                            'threeDSServerTransID' => 'demo-' . uniqid(),
                            'messageType' => 'CReq',
                            'demo' => true
                        ])),
                        'server_trans_id' => 'demo-' . uniqid()
                    ]
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'demo_mode' => true,
                'data' => [
                    'challenge_required' => false,
                    'status' => 'Y',
                    'order_id' => $options['order_id'],
                    'pasref' => 'demo-' . time(),
                    'message' => 'Demo Mode - Frictionless Success',
                    'auth_data' => [
                        'eci' => '05',
                        'cavv' => 'AAABBZIhcQAAAABvllEIRoEoAAA=',
                        'xid' => base64_encode('demo-' . uniqid()),
                        'ds_trans_id' => 'demo-ds-' . uniqid()
                    ]
                ]
            ]);
        }
        exit;
    }

    // Initiate authentication with actual API
    $response = $client->initiate3DS2Authentication(
        $input['card_number'],
        $input['amount'],
        $input['currency'],
        $input['browser_data'],
        $options
    );

    // Check if request was successful
    if ($response['result'] !== '00') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Authentication initiation failed',
            'message' => $response['message'],
            'details' => $response
        ]);
        exit;
    }

    // Determine authentication status
    $challengeRequired = ($response['threeds']['challenge_required'] ?? 'N') === 'Y';
    $authStatus = $response['threeds']['status'] ?? '';

    $result = [
        'success' => true,
        'data' => [
            'challenge_required' => $challengeRequired,
            'status' => $authStatus,
            'order_id' => $response['order_id'],
            'pasref' => $response['pasref'],
            'message' => $response['message']
        ]
    ];

    // Add challenge data if required
    if ($challengeRequired) {
        $result['data']['challenge'] = [
            'acs_url' => $response['threeds']['acs_url'] ?? '',
            'creq' => $response['threeds']['creq'] ?? '',
            'server_trans_id' => $response['threeds']['server_trans_id'] ?? ''
        ];
    } else {
        // Frictionless flow - include authentication data
        $result['data']['auth_data'] = [
            'eci' => $response['threeds']['eci'] ?? '',
            'cavv' => $response['threeds']['cavv'] ?? '',
            'xid' => $response['threeds']['xid'] ?? '',
            'ds_trans_id' => $response['threeds']['ds_trans_id'] ?? ''
        ];
    }

    $result['raw_response'] = $response;

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
