<?php

declare(strict_types=1);

/**
 * Global Payments eCOM (GPeCOM) 3DS2 API Client
 *
 * This client handles 3D Secure 2 authentication using the proper
 * 3DS2 REST JSON API (not the legacy XML API).
 *
 * @category  Payment_Gateway
 * @package   GlobalPayments_GPeCOM
 * @author    Radoslav
 * @license   MIT License
 */

namespace GpecomSdk;

use Exception;

class GpecomClient
{
    private string $merchantId;
    private string $accountId;
    private string $sharedSecret;
    private string $refundPassword;
    private string $threeDS2Url;
    private string $xmlApiUrl;
    private bool $debug;
    private ?string $methodNotificationUrl = null;
    private ?string $challengeNotificationUrl = null;

    /**
     * Constructor
     *
     * @param string $merchantId Merchant/Client ID
     * @param string $sharedSecret Shared Secret for authentication
     * @param string $refundPassword Refund & Rebate Password
     * @param bool $sandbox Use sandbox environment
     * @param bool $debug Enable debug logging
     */
    public function __construct(
        string $merchantId,
        string $sharedSecret,
        string $refundPassword,
        bool $sandbox = true,
        bool $debug = false
    ) {
        $this->merchantId = $merchantId;
        $this->sharedSecret = $sharedSecret;
        $this->refundPassword = $refundPassword;
        $this->debug = $debug;
        $this->accountId = 'internet'; // Default, can be set via setAccountId()

        // 3DS2 REST API endpoint (JSON-based)
        $this->threeDS2Url = $sandbox
            ? 'https://api.sandbox.globalpay-ecommerce.com/3ds2/'
            : 'https://api.globalpay-ecommerce.com/3ds2/';

        // Standard XML API endpoint (for payments)
        $this->xmlApiUrl = $sandbox
            ? 'https://api.sandbox.realexpayments.com/epage-remote.cgi'
            : 'https://api.realexpayments.com/epage-remote.cgi';
    }

    /**
     * Set the account ID (sub-account with 3DS2/MPI enabled)
     */
    public function setAccountId(string $accountId): void
    {
        $this->accountId = $accountId;
    }

    /**
     * Validate that a URL is suitable for 3DS2 callbacks
     * Must be HTTPS and not localhost
     */
    private function isValidNotificationUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        // Must be HTTPS
        if (!str_starts_with($url, 'https://')) {
            return false;
        }
        // Must not be localhost
        if (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
            return false;
        }
        return true;
    }

    /**
     * Set method notification URL for device fingerprinting
     * URL must be HTTPS and publicly accessible (not localhost)
     */
    public function setMethodNotificationUrl(string $url): void
    {
        if ($this->isValidNotificationUrl($url)) {
            $this->methodNotificationUrl = $url;
        }
    }

    /**
     * Set challenge notification URL
     * URL must be HTTPS and publicly accessible (not localhost)
     */
    public function setChallengeNotificationUrl(string $url): void
    {
        if ($this->isValidNotificationUrl($url)) {
            $this->challengeNotificationUrl = $url;
        }
    }

    /**
     * Generate secure hash for 3DS2 API
     *
     * @param string ...$parts Parts to hash
     * @return string SHA1 hash
     */
    private function generateSecureHash(string ...$parts): string
    {
        $toHash = implode('.', $parts);
        $firstHash = sha1($toHash);
        return sha1($firstHash . '.' . $this->sharedSecret);
    }

    /**
     * Send JSON request to 3DS2 API
     *
     * @param string $method HTTP method (GET/POST)
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @param array $queryParams Query parameters
     * @param string $hash Authorization hash
     * @return array Response data
     * @throws Exception
     */
    private function send3DS2Request(
        string $method,
        string $endpoint,
        ?array $data,
        array $queryParams,
        string $hash
    ): array {
        $url = $this->threeDS2Url . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        if ($this->debug) {
            error_log("3DS2 Request: {$method} {$url}");
            if ($data) {
                $logData = $data;
                // Mask card number in logs
                if (isset($logData['number'])) {
                    $logData['number'] = substr($logData['number'], 0, 6) . '******' . substr($logData['number'], -4);
                }
                if (isset($logData['card_detail']['number'])) {
                    $logData['card_detail']['number'] = substr($logData['card_detail']['number'], 0, 6) . '******' . substr($logData['card_detail']['number'], -4);
                }
                error_log("3DS2 Request Body: " . json_encode($logData, JSON_PRETTY_PRINT));
            }
        }

        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Authorization: securehash ' . $hash,
            'X-GP-Version: 2.2.0'
        ];

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $curlOptions);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($this->debug) {
            error_log("3DS2 Response (HTTP {$httpCode}): " . $response);
        }

        if ($errno) {
            throw new Exception("CURL Error ({$errno}): {$error}");
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $errorMessage = $decoded['error']['message'] ?? $response;
            throw new Exception("3DS2 API Error (HTTP {$httpCode}): {$errorMessage}");
        }

        if ($decoded === null && $response !== 'null') {
            throw new Exception("Invalid JSON response: " . substr($response, 0, 500));
        }

        return $decoded ?? [];
    }

    /**
     * Map card type to 3DS2 scheme name
     */
    private function mapCardScheme(string $cardType): string
    {
        $map = [
            'VISA' => 'VISA',
            'MC' => 'MASTERCARD',
            'MASTERCARD' => 'MASTERCARD',
            'AMEX' => 'AMEX',
            'DINERSCLUB' => 'DINERS',
            'DISCOVER' => 'DISCOVER',
            'JCB' => 'JCB'
        ];
        return $map[strtoupper($cardType)] ?? $cardType;
    }

    /**
     * Check 3DS2 enrollment status (Version Check)
     *
     * This calls the proper 3DS2 protocol-versions endpoint
     *
     * @param string $cardNumber Card number
     * @param array $options Additional options
     * @return array Response data
     * @throws Exception
     */
    public function check3DS2Enrollment(string $cardNumber, array $options = []): array
    {
        $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u');
        $accountId = $options['account'] ?? $this->accountId;
        $cardType = $options['card_type'] ?? 'VISA';

        // Build request
        $request = [
            'request_timestamp' => $timestamp,
            'merchant_id' => $this->merchantId,
            'account_id' => $accountId,
            'number' => $cardNumber,
            'scheme' => $this->mapCardScheme($cardType)
        ];

        if (!empty($this->methodNotificationUrl)) {
            $request['method_notification_url'] = $this->methodNotificationUrl;
        }

        // Generate hash: timestamp.merchantid.cardnumber
        $hash = $this->generateSecureHash($timestamp, $this->merchantId, $cardNumber);

        try {
            $response = $this->send3DS2Request('POST', 'protocol-versions', $request, [], $hash);

            // Format response to match expected structure
            return [
                'result' => '00', // Success
                'message' => 'Card enrollment checked',
                'threeds' => [
                    'enrolled' => ($response['enrolled'] ?? false) ? 'Y' : 'N',
                    'server_trans_id' => $response['server_trans_id'] ?? '',
                    'method_url' => $response['method_url'] ?? '',
                    'ds_trans_id' => $response['ds_trans_id'] ?? '',
                    'acs_start_version' => $response['acs_protocol_version_start'] ?? '',
                    'acs_end_version' => $response['acs_protocol_version_end'] ?? '',
                    'ds_start_version' => $response['ds_protocol_version_start'] ?? '',
                    'ds_end_version' => $response['ds_protocol_version_end'] ?? ''
                ],
                'order_id' => $options['order_id'] ?? '',
                'pasref' => $response['server_trans_id'] ?? '',
                'raw_response' => $response
            ];
        } catch (Exception $e) {
            // Return error in expected format
            return [
                'result' => '500',
                'message' => $e->getMessage(),
                'threeds' => [],
                'error' => true
            ];
        }
    }

    /**
     * Initiate 3DS2 authentication
     *
     * @param string $cardNumber Card number
     * @param string $amount Amount in smallest currency unit
     * @param string $currency Currency code
     * @param array $browserData Browser information
     * @param array $options Additional options
     * @return array Response data
     * @throws Exception
     */
    public function initiate3DS2Authentication(
        string $cardNumber,
        string $amount,
        string $currency,
        array $browserData,
        array $options = []
    ): array {
        $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u');
        $accountId = $options['account'] ?? $this->accountId;
        $serverTransId = $options['server_trans_id'] ?? '';
        $orderId = $options['order_id'] ?? uniqid('order-');
        $cardType = $options['card_type'] ?? 'VISA';
        $expDate = $options['exp_date'] ?? '1225';
        $cardHolder = $options['card_holder'] ?? '';
        $methodUrlComplete = $options['method_url_complete'] ?? 'false';

        // Parse expiry date
        $expMonth = substr($expDate, 0, 2);
        $expYear = substr($expDate, 2, 2);

        // Build request
        $request = [
            'request_timestamp' => $timestamp,
            'authentication_source' => 'BROWSER',
            'authentication_request_type' => 'PAYMENT_TRANSACTION',
            'message_category' => 'PAYMENT_AUTHENTICATION',
            'message_version' => $options['message_version'] ?? '2.2.0',
            'server_trans_id' => $serverTransId,
            'merchant_id' => $this->merchantId,
            'account_id' => $accountId,
            'method_url_completion' => strtoupper($methodUrlComplete) === 'TRUE' ? 'YES' : 'NO',
            'card_detail' => [
                'number' => $cardNumber,
                'scheme' => $this->mapCardScheme($cardType),
                'expiry_month' => $expMonth,
                'expiry_year' => $expYear,
                'full_name' => $cardHolder
            ],
            'order' => [
                'amount' => $amount,
                'currency' => $currency,
                'id' => $orderId,
                'date_time_created' => (new \DateTime())->format(\DateTime::RFC3339_EXTENDED)
            ],
            'browser_data' => [
                'accept_header' => $browserData['accept_header'] ?? 'text/html',
                'color_depth' => $browserData['color_depth'] ?? '24',
                'ip' => $browserData['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
                'java_enabled' => ($browserData['java_enabled'] ?? 'false') === 'true',
                'javascript_enabled' => ($browserData['javascript_enabled'] ?? 'true') === 'true',
                'language' => $browserData['language'] ?? 'en-US',
                'screen_height' => $browserData['screen_height'] ?? '1080',
                'screen_width' => $browserData['screen_width'] ?? '1920',
                'challenge_window_size' => $browserData['challenge_window_size'] ?? '05',
                'timezone' => $browserData['timezone'] ?? '0',
                'user_agent' => $browserData['user_agent'] ?? ''
            ]
        ];

        if (!empty($this->challengeNotificationUrl)) {
            $request['challenge_notification_url'] = $this->challengeNotificationUrl;
        }

        // Add customer email if provided
        if (!empty($options['customer']['email'])) {
            $request['payer'] = [
                'email' => $options['customer']['email']
            ];
        }

        // Generate hash: timestamp.merchantid.cardnumber.servertransid
        $hash = $this->generateSecureHash($timestamp, $this->merchantId, $cardNumber, $serverTransId);

        try {
            $response = $this->send3DS2Request('POST', 'authentications', $request, [], $hash);

            // Determine if challenge is required
            $status = $response['status'] ?? '';
            $challengeRequired = $status === 'CHALLENGE_REQUIRED' ||
                                 ($response['challenge_mandated'] ?? false) === true;

            $result = [
                'result' => '00',
                'message' => 'Authentication initiated',
                'order_id' => $orderId,
                'pasref' => $serverTransId,
                'threeds' => [
                    'status' => $status,
                    'eci' => $response['eci'] ?? '',
                    'cavv' => $response['authentication_value'] ?? '',
                    'xid' => '', // Not used in 3DS2
                    'ds_trans_id' => $response['ds_trans_id'] ?? '',
                    'server_trans_id' => $response['server_trans_id'] ?? $serverTransId,
                    'challenge_required' => $challengeRequired ? 'Y' : 'N',
                    'acs_url' => $response['challenge_request_url'] ?? '',
                    'creq' => $response['encoded_creq'] ?? ''
                ],
                'raw_response' => $response
            ];

            return $result;
        } catch (Exception $e) {
            return [
                'result' => '500',
                'message' => $e->getMessage(),
                'threeds' => [],
                'error' => true
            ];
        }
    }

    /**
     * Get authentication data after challenge completion
     *
     * @param string $serverTransId Server transaction ID
     * @return array Response data
     * @throws Exception
     */
    public function getAuthenticationData(string $serverTransId): array
    {
        $timestamp = (new \DateTime())->format('Y-m-d\TH:i:s.u');

        // Generate hash for GET request
        $hash = $this->generateSecureHash($timestamp, $this->merchantId, $serverTransId);

        $queryParams = [
            'merchant_id' => $this->merchantId,
            'request_timestamp' => $timestamp
        ];

        try {
            $response = $this->send3DS2Request(
                'GET',
                'authentications/' . $serverTransId,
                null,
                $queryParams,
                $hash
            );

            return [
                'result' => '00',
                'message' => 'Authentication data retrieved',
                'threeds' => [
                    'status' => $response['status'] ?? '',
                    'eci' => $response['eci'] ?? '',
                    'cavv' => $response['authentication_value'] ?? '',
                    'ds_trans_id' => $response['ds_trans_id'] ?? '',
                    'server_trans_id' => $serverTransId
                ],
                'raw_response' => $response
            ];
        } catch (Exception $e) {
            return [
                'result' => '500',
                'message' => $e->getMessage(),
                'threeds' => [],
                'error' => true
            ];
        }
    }

    /**
     * Verify 3DS2 authentication (alias for getAuthenticationData)
     */
    public function verify3DS2Authentication(
        string $orderId,
        string $amount,
        string $currency,
        string $serverTransId,
        array $options = []
    ): array {
        return $this->getAuthenticationData($serverTransId);
    }

    /**
     * Authorize payment with 3DS2 authentication data
     *
     * Uses the standard XML API for payment authorization
     *
     * @param string $cardNumber Card number
     * @param string $amount Amount
     * @param string $currency Currency
     * @param array $authData 3DS2 authentication data
     * @param array $options Additional options
     * @return array Response data
     * @throws Exception
     */
    public function authorizePayment(
        string $cardNumber,
        string $amount,
        string $currency,
        array $authData,
        array $options = []
    ): array {
        $timestamp = date('YmdHis');
        $orderId = $options['order_id'] ?? uniqid('order-');
        $account = $options['account'] ?? $this->accountId;
        $expDate = $options['exp_date'] ?? '1225';
        $cardHolder = $options['card_holder'] ?? 'John Doe';
        $cardType = $options['card_type'] ?? 'VISA';
        $cvv = $options['cvv'] ?? '123';

        // Generate hash for XML API
        $hash = $this->generateSecureHash(
            $timestamp,
            $this->merchantId,
            $orderId,
            $amount,
            $currency,
            $cardNumber
        );

        $eci = htmlspecialchars($authData['eci'] ?? '');
        $cavv = htmlspecialchars($authData['cavv'] ?? '');
        $dsTransId = htmlspecialchars($authData['ds_trans_id'] ?? '');
        // For 3DS2, xid is typically the ds_trans_id or empty
        $xid = htmlspecialchars($authData['xid'] ?? $dsTransId);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<request type="auth" timestamp="{$timestamp}">
    <merchantid>{$this->merchantId}</merchantid>
    <account>{$account}</account>
    <orderid>{$orderId}</orderid>
    <amount currency="{$currency}">{$amount}</amount>
    <card>
        <number>{$cardNumber}</number>
        <expdate>{$expDate}</expdate>
        <chname>{$cardHolder}</chname>
        <type>{$cardType}</type>
        <cvn>
            <number>{$cvv}</number>
            <presind>1</presind>
        </cvn>
    </card>
    <autosettle flag="1"/>
    <mpi>
        <eci>{$eci}</eci>
        <cavv>{$cavv}</cavv>
        <xid>{$xid}</xid>
        <ds_trans_id>{$dsTransId}</ds_trans_id>
        <authentication_value>{$cavv}</authentication_value>
        <message_version>2.2.0</message_version>
    </mpi>
    <sha1hash>{$hash}</sha1hash>
</request>
XML;

        return $this->sendXmlRequest($xml);
    }

    /**
     * Send XML request to standard API
     */
    private function sendXmlRequest(string $xml): array
    {
        if ($this->debug) {
            error_log("XML Request URL: " . $this->xmlApiUrl);
            error_log("XML Request:\n" . $this->formatXml($xml));
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->xmlApiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'Content-Length: ' . strlen($xml)
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($this->debug) {
            error_log("XML Response (HTTP {$httpCode}):\n" . $this->formatXml($response));
        }

        if ($errno) {
            throw new Exception("CURL Error ({$errno}): {$error}");
        }

        return $this->parseXmlResponse($response);
    }

    /**
     * Parse XML response into array
     */
    private function parseXmlResponse(string $xmlResponse): array
    {
        $dom = new \DOMDocument();
        @$dom->loadXML($xmlResponse);

        return [
            'timestamp' => $dom->getElementsByTagName('timestamp')->item(0)?->nodeValue ?? '',
            'result' => $dom->getElementsByTagName('result')->item(0)?->nodeValue ?? '',
            'message' => $dom->getElementsByTagName('message')->item(0)?->nodeValue ?? '',
            'order_id' => $dom->getElementsByTagName('orderid')->item(0)?->nodeValue ?? '',
            'pasref' => $dom->getElementsByTagName('pasref')->item(0)?->nodeValue ?? '',
            'authcode' => $dom->getElementsByTagName('authcode')->item(0)?->nodeValue ?? ''
        ];
    }

    /**
     * Format XML for readable output
     */
    private function formatXml(string $xml): string
    {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        @$dom->loadXML($xml);
        return $dom->saveXML() ?: $xml;
    }
}
