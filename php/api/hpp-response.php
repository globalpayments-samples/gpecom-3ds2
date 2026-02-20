<?php

declare(strict_types=1);

/**
 * HPP (Hosted Payment Page) Response Handler
 *
 * Receives and validates the response from Global Payments HPP.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GlobalPayments\Api\ServiceConfigs\Gateways\GpEcomConfig;
use GlobalPayments\Api\Services\HostedService;
use GlobalPayments\Api\Entities\Exceptions\ApiException;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Configure GPeCOM
$config = new GpEcomConfig();
$config->merchantId = $_ENV['GPECOM_MERCHANT_ID'];
$config->accountId = $_ENV['GPECOM_ACCOUNT'] ?? 'internet';
$config->sharedSecret = $_ENV['GPECOM_SHARED_SECRET'];

$sandbox = ($_ENV['GPECOM_SANDBOX'] ?? 'true') === 'true';
$config->serviceUrl = $sandbox
    ? 'https://pay.sandbox.realexpayments.com/pay'
    : 'https://pay.realexpayments.com/pay';

$service = new HostedService($config);

// Get response JSON
if (!isset($_REQUEST['hppResponse'])) {
    $responseJson = json_encode($_REQUEST);
    $encoded = false;
} else {
    $responseJson = $_REQUEST['hppResponse'];
    $encoded = true;
}

try {
    // Parse and validate the response
    $parsedResponse = $service->parseResponse($responseJson, $encoded);

    $orderId = $parsedResponse->orderId;
    $responseCode = $parsedResponse->responseCode;
    $responseMessage = $parsedResponse->responseMessage;
    $responseValues = $parsedResponse->responseValues;

    // Check if successful
    $success = $responseCode === '00';

    // Output HTML response (displayed in iframe/lightbox)
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Payment <?= $success ? 'Successful' : 'Failed' ?></title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                background: <?= $success ? '#d4edda' : '#f8d7da' ?>;
            }
            .result {
                text-align: center;
                padding: 2rem;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                max-width: 400px;
            }
            .icon {
                font-size: 48px;
                margin-bottom: 1rem;
            }
            h1 {
                color: <?= $success ? '#155724' : '#721c24' ?>;
                margin: 0 0 0.5rem;
            }
            p {
                color: #666;
                margin: 0.5rem 0;
            }
            .order-id {
                font-family: monospace;
                background: #f5f5f5;
                padding: 0.25rem 0.5rem;
                border-radius: 4px;
            }
            .details {
                margin-top: 1rem;
                padding-top: 1rem;
                border-top: 1px solid #eee;
                font-size: 0.875rem;
                text-align: left;
            }
        </style>
    </head>
    <body>
        <div class="result">
            <div class="icon"><?= $success ? '✅' : '❌' ?></div>
            <h1><?= $success ? 'Payment Successful' : 'Payment Failed' ?></h1>
            <p><?= htmlspecialchars($responseMessage) ?></p>
            <p>Order ID: <span class="order-id"><?= htmlspecialchars($orderId) ?></span></p>

            <?php if ($success && !empty($responseValues)): ?>
            <div class="details">
                <p><strong>Auth Code:</strong> <?= htmlspecialchars($responseValues['AUTHCODE'] ?? 'N/A') ?></p>
                <p><strong>Transaction Ref:</strong> <?= htmlspecialchars($responseValues['PASREF'] ?? 'N/A') ?></p>
            </div>
            <?php endif; ?>
        </div>

        <script>
            // Send result back to parent window (for embedded/lightbox mode)
            if (window.parent !== window) {
                window.parent.postMessage({
                    RESULT: '<?= htmlspecialchars($responseCode) ?>',
                    ORDER_ID: '<?= htmlspecialchars($orderId) ?>',
                    MESSAGE: '<?= htmlspecialchars($responseMessage) ?>',
                    AUTHCODE: '<?= htmlspecialchars($responseValues['AUTHCODE'] ?? '') ?>',
                    PASREF: '<?= htmlspecialchars($responseValues['PASREF'] ?? '') ?>'
                }, '*');
            }
        </script>
    </body>
    </html>
    <?php

} catch (ApiException $e) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Error</title>
        <style>
            body { font-family: sans-serif; padding: 2rem; background: #f8d7da; }
            .error { background: white; padding: 2rem; border-radius: 8px; max-width: 500px; margin: 0 auto; }
            h1 { color: #721c24; }
        </style>
    </head>
    <body>
        <div class="error">
            <h1>Error Processing Payment</h1>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
        </div>
    </body>
    </html>
    <?php
}
