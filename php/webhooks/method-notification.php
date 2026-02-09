<?php

declare(strict_types=1);

/**
 * 3DS2 Method URL Notification Handler
 *
 * Receives the callback from the ACS after device fingerprinting (Method URL).
 * The ACS posts threeDSMethodData containing the server transaction ID.
 * This handler logs the notification and notifies the parent window.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$tempDir = __DIR__ . '/../temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

// Read the threeDSMethodData from the POST body
$methodData = $_POST['threeDSMethodData'] ?? '';

$serverTransId = '';
$decoded = [];

if (!empty($methodData)) {
    $decodedJson = base64_decode($methodData, true);
    if ($decodedJson !== false) {
        $decoded = json_decode($decodedJson, true) ?? [];
        $serverTransId = $decoded['threeDSServerTransID'] ?? '';
    }
}

// Log the notification
$logEntry = sprintf(
    "[%s] Method Notification - ServerTransID: %s - Data: %s\n",
    date('Y-m-d H:i:s'),
    $serverTransId ?: 'unknown',
    json_encode($decoded)
);
file_put_contents($logDir . '/method-notifications.log', $logEntry, FILE_APPEND | LOCK_EX);

// Save completion status to temp file for the check-enrollment flow to reference
if (!empty($serverTransId)) {
    $statusFile = $tempDir . '/method-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $serverTransId) . '.json';
    file_put_contents($statusFile, json_encode([
        'completed' => true,
        'server_trans_id' => $serverTransId,
        'timestamp' => date('Y-m-d H:i:s'),
    ]), LOCK_EX);
}

// Return HTML that notifies the parent window via postMessage
?>
<!DOCTYPE html>
<html>
<head><title>3DS Method Complete</title></head>
<body>
<script>
    // Notify parent window that method URL processing is complete
    if (window.parent !== window) {
        window.parent.postMessage({
            type: 'method_complete',
            serverTransId: <?= json_encode($serverTransId) ?>
        }, '*');
    }
</script>
</body>
</html>
