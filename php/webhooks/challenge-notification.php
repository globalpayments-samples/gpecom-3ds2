<?php

declare(strict_types=1);

/**
 * 3DS2 Challenge Notification Handler
 *
 * This webhook receives the notification from the ACS after
 * the cardholder has completed the challenge authentication.
 *
 * @category  Webhook
 * @package   GlobalPayments_GPeCOM
 */

header('Content-Type: text/html; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

// Log the incoming request for debugging
$logFile = __DIR__ . '/../logs/challenge-notifications.log';
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

// Process the challenge response
try {
    // The ACS sends base64-encoded cres (Challenge Response)
    $cres = $_POST['cres'] ?? '';

    if (empty($cres)) {
        throw new Exception('No challenge response received');
    }

    // Decode the challenge response
    $decodedCres = base64_decode($cres);
    $cresData = json_decode($decodedCres, true);

    // Log the decoded data
    file_put_contents(
        $logFile,
        "Decoded Challenge Response: " . json_encode($cresData, JSON_PRETTY_PRINT) . "\n\n",
        FILE_APPEND
    );

    // Store the challenge response (in production, use session/database)
    $serverTransId = $cresData['threeDSServerTransID'] ?? 'unknown';
    $statusFile = __DIR__ . "/../temp/challenge_complete_{$serverTransId}.json";

    $statusDir = dirname($statusFile);
    if (!file_exists($statusDir)) {
        mkdir($statusDir, 0755, true);
    }

    file_put_contents($statusFile, json_encode([
        'completed' => true,
        'timestamp' => $timestamp,
        'cres' => $cres,
        'decoded_data' => $cresData
    ]));

    // Return HTML that notifies the parent window and closes the challenge iframe
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Challenge Complete</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                text-align: center;
                padding: 50px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .message {
                background: rgba(255, 255, 255, 0.1);
                padding: 30px;
                border-radius: 10px;
                backdrop-filter: blur(10px);
            }
            .spinner {
                border: 4px solid rgba(255, 255, 255, 0.3);
                border-top: 4px solid white;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="message">
            <h1>✓ Authentication Complete</h1>
            <div class="spinner"></div>
            <p>Processing your authentication...</p>
        </div>
        <script>
            // Send message to parent window
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'challenge_complete',
                    cres: <?php echo json_encode($cres); ?>,
                    timestamp: <?php echo json_encode($timestamp); ?>
                }, '*');
            }

            // Close the challenge window after a short delay
            setTimeout(function() {
                if (window.parent !== window) {
                    // If in iframe, notify parent
                    window.parent.postMessage({ type: 'close_challenge' }, '*');
                } else {
                    // If standalone window, close it
                    window.close();
                }
            }, 2000);
        </script>
    </body>
    </html>
    <?php

} catch (Exception $e) {
    file_put_contents(
        $logFile,
        "Error: " . $e->getMessage() . "\n\n",
        FILE_APPEND
    );

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Error</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                text-align: center;
                padding: 50px;
                background: #dc3545;
                color: white;
            }
        </style>
    </head>
    <body>
        <h1>❌ Error</h1>
        <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
    </body>
    </html>
    <?php
}
