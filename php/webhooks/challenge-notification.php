<?php

declare(strict_types=1);

/**
 * 3DS2 Challenge Notification Handler
 *
 * Receives the callback from the ACS after challenge completion.
 * The ACS posts the CRes (Challenge Response) which contains
 * the authentication result after the cardholder completes the challenge.
 *
 * Reference: vendor/globalpayments/php-sdk/examples/gp-ecom/3DS2-Challenge/challengeNotification.php
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

// Read the CRes from the POST body
$cres = $_POST['cres'] ?? '';

if (empty($cres)) {
    http_response_code(400);
    echo '<html><body><p>Missing cres parameter</p></body></html>';
    exit;
}

// Decode the CRes (base64 encoded JSON)
$decodedString = base64_decode($cres, true);
$decodedData = [];

if ($decodedString !== false) {
    $decodedData = json_decode($decodedString, true) ?? [];
}

// Log the challenge notification
$logEntry = sprintf(
    "[%s] Challenge Notification - TransStatus: %s - ServerTransID: %s - Data: %s\n",
    date('Y-m-d H:i:s'),
    $decodedData['transStatus'] ?? 'unknown',
    $decodedData['threeDSServerTransID'] ?? 'unknown',
    $decodedString ?: 'decode_failed'
);
file_put_contents($logDir . '/challenge-notifications.log', $logEntry, FILE_APPEND | LOCK_EX);

// Return HTML/JS that posts the decoded CRes data back to the parent window
// The parent window listener at index.html expects: { type: 'challenge_complete', cres: decodedData }
$safeData = htmlspecialchars($decodedString ?: '{}', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head><title>Challenge Complete</title></head>
<body>
<script>
    var cresData = <?= $decodedString ?: '{}' ?>;
    if (window.parent !== window) {
        window.parent.postMessage({
            type: 'challenge_complete',
            cres: cresData,
            data: cresData
        }, window.location.origin);
    }
</script>
</body>
</html>
