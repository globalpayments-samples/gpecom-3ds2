<?php

declare(strict_types=1);

/**
 * 3DS2 Method URL Notification Handler
 *
 * This webhook receives the notification from the ACS after
 * the 3DS Method (device fingerprinting) has completed.
 *
 * @category  Webhook
 * @package   GlobalPayments_GPeCOM
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Log the incoming request for debugging
$logFile = __DIR__ . '/../logs/method-notifications.log';
$logDir = dirname($logFile);

if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

$timestamp = date('Y-m-d H:i:s');
$requestData = [
    'timestamp' => $timestamp,
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'post_data' => $_POST,
    'raw_body' => file_get_contents('php://input')
];

file_put_contents(
    $logFile,
    json_encode($requestData, JSON_PRETTY_PRINT) . "\n\n",
    FILE_APPEND
);

// Process the method data
try {
    // The ACS sends base64-encoded threeDSMethodData
    $methodData = $_POST['threeDSMethodData'] ?? '';

    if (empty($methodData)) {
        throw new Exception('No method data received');
    }

    // Decode the method data
    $decodedData = base64_decode($methodData);
    $methodInfo = json_decode($decodedData, true);

    // Log the decoded data
    file_put_contents(
        $logFile,
        "Decoded Method Data: " . json_encode($methodInfo, JSON_PRETTY_PRINT) . "\n\n",
        FILE_APPEND
    );

    // Store the completion status (in production, use session/database)
    $serverTransId = $methodInfo['threeDSServerTransID'] ?? 'unknown';
    $statusFile = __DIR__ . "/../temp/method_complete_{$serverTransId}.json";

    $statusDir = dirname($statusFile);
    if (!file_exists($statusDir)) {
        mkdir($statusDir, 0755, true);
    }

    file_put_contents($statusFile, json_encode([
        'completed' => true,
        'timestamp' => $timestamp,
        'data' => $methodInfo
    ]));

    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Method notification received'
    ]);

} catch (Exception $e) {
    file_put_contents(
        $logFile,
        "Error: " . $e->getMessage() . "\n\n",
        FILE_APPEND
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
