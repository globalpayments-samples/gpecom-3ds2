<?php

declare(strict_types=1);

/**
 * Global Payments eCOM (GPeCOM) XML API Client
 *
 * This client handles XML-based communication with Global Payments
 * for 3D Secure 2 authentication flows.
 *
 * @category  Payment_Gateway
 * @package   GlobalPayments_GPeCOM
 * @author    Radoslav
 * @license   MIT License
 */

namespace GpecomSdk;

use DOMDocument;
use Exception;

class GpecomClient
{
    private string $merchantId;
    private string $sharedSecret;
    private string $refundPassword;
    private string $apiUrl;
    private bool $debug;

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

        // Set API endpoint based on environment
        $this->apiUrl = $sandbox
            ? 'https://api.sandbox.globalpay-ecommerce.com/xml'
            : 'https://api.globalpay-ecommerce.com/xml';
    }

    /**
     * Generate SHA1 hash for request authentication
     *
     * @param string $timestamp Request timestamp
     * @param string $merchantId Merchant ID
     * @param string $orderId Order ID
     * @param string $amount Amount (if applicable)
     * @param string $currency Currency (if applicable)
     * @param string $cardNumber Card number (if applicable)
     * @return string SHA1 hash
     */
    private function generateHash(
        string $timestamp,
        string $merchantId,
        string $orderId,
        string $amount = '',
        string $currency = '',
        string $cardNumber = ''
    ): string {
        // First SHA1: timestamp.merchantid.orderid.amount.currency.cardnumber
        $toHash = implode('.', [
            $timestamp,
            $merchantId,
            $orderId,
            $amount,
            $currency,
            $cardNumber
        ]);

        $hash = sha1($toHash);

        // Second SHA1: hash.sharedsecret
        $hash = sha1($hash . '.' . $this->sharedSecret);

        return $hash;
    }

    /**
     * Generate SHA1 hash for 3DS2 verify signature
     *
     * @param string $timestamp Request timestamp
     * @param string $merchantId Merchant ID
     * @param string $orderId Order ID
     * @param string $amount Amount
     * @param string $currency Currency
     * @param string $avsPostcodeResponse AVS postcode response (optional)
     * @return string SHA1 hash
     */
    private function generate3DS2VerifyHash(
        string $timestamp,
        string $merchantId,
        string $orderId,
        string $amount,
        string $currency,
        string $avsPostcodeResponse = ''
    ): string {
        $toHash = implode('.', [
            $timestamp,
            $merchantId,
            $orderId,
            $amount,
            $currency,
            $avsPostcodeResponse
        ]);

        $hash = sha1($toHash);
        $hash = sha1($hash . '.' . $this->sharedSecret);

        return $hash;
    }

    /**
     * Send XML request to GPeCOM API
     *
     * @param string $xmlRequest XML request body
     * @return string XML response
     * @throws Exception
     */
    private function sendRequest(string $xmlRequest): string
    {
        if ($this->debug) {
            error_log("GPeCOM Request:\n" . $this->formatXml($xmlRequest));
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlRequest,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'Content-Length: ' . strlen($xmlRequest)
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("CURL Error: {$error}");
        }

        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: {$httpCode}");
        }

        if ($this->debug) {
            error_log("GPeCOM Response:\n" . $this->formatXml($response));
        }

        return $response;
    }

    /**
     * Format XML for readable output
     *
     * @param string $xml XML string
     * @return string Formatted XML
     */
    private function formatXml(string $xml): string
    {
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        @$dom->loadXML($xml);
        return $dom->saveXML();
    }

    /**
     * Parse XML response into array
     *
     * @param string $xmlResponse XML response string
     * @return array Parsed response data
     */
    private function parseResponse(string $xmlResponse): array
    {
        $dom = new DOMDocument();
        @$dom->loadXML($xmlResponse);

        $response = [
            'timestamp' => $dom->getElementsByTagName('timestamp')->item(0)?->nodeValue ?? '',
            'result' => $dom->getElementsByTagName('result')->item(0)?->nodeValue ?? '',
            'message' => $dom->getElementsByTagName('message')->item(0)?->nodeValue ?? '',
            'order_id' => $dom->getElementsByTagName('orderid')->item(0)?->nodeValue ?? '',
            'pasref' => $dom->getElementsByTagName('pasref')->item(0)?->nodeValue ?? '',
        ];

        // Parse 3DS2 specific fields
        $threedsNode = $dom->getElementsByTagName('threedsecure')->item(0);
        if ($threedsNode) {
            $response['threeds'] = [
                'status' => $threedsNode->getElementsByTagName('status')->item(0)?->nodeValue ?? '',
                'eci' => $threedsNode->getElementsByTagName('eci')->item(0)?->nodeValue ?? '',
                'cavv' => $threedsNode->getElementsByTagName('cavv')->item(0)?->nodeValue ?? '',
                'xid' => $threedsNode->getElementsByTagName('xid')->item(0)?->nodeValue ?? '',
                'ds_trans_id' => $threedsNode->getElementsByTagName('ds_trans_id')->item(0)?->nodeValue ?? '',
                'server_trans_id' => $threedsNode->getElementsByTagName('server_trans_id')->item(0)?->nodeValue ?? '',
                'enrolled' => $threedsNode->getElementsByTagName('enrolled')->item(0)?->nodeValue ?? '',
                'method_url' => $threedsNode->getElementsByTagName('method_url')->item(0)?->nodeValue ?? '',
                'method_url_complete' => $threedsNode->getElementsByTagName('method_url_complete')->item(0)?->nodeValue ?? '',
                'challenge_required' => $threedsNode->getElementsByTagName('challenge_required')->item(0)?->nodeValue ?? '',
                'acs_url' => $threedsNode->getElementsByTagName('acs_url')->item(0)?->nodeValue ?? '',
                'creq' => $threedsNode->getElementsByTagName('creq')->item(0)?->nodeValue ?? '',
            ];
        }

        return $response;
    }

    /**
     * Check 3DS2 enrollment status (Version Check)
     *
     * @param string $cardNumber Card number
     * @param array $options Additional options
     * @return array Response data
     * @throws Exception
     */
    public function check3DS2Enrollment(string $cardNumber, array $options = []): array
    {
        $timestamp = date('YmdHis');
        $orderId = $options['order_id'] ?? uniqid('order-');
        $account = $options['account'] ?? 'internet';
        $expDate = $options['exp_date'] ?? '1225';
        $cardHolder = $options['card_holder'] ?? 'John Doe';
        $cardType = $options['card_type'] ?? 'VISA';

        $hash = $this->generateHash(
            $timestamp,
            $this->merchantId,
            $orderId,
            '',
            '',
            $cardNumber
        );

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<request type="3ds-verifyenrolled" timestamp="{$timestamp}">
    <merchantid>{$this->merchantId}</merchantid>
    <account>{$account}</account>
    <orderid>{$orderId}</orderid>
    <card>
        <number>{$cardNumber}</number>
        <expdate>{$expDate}</expdate>
        <chname>{$cardHolder}</chname>
        <type>{$cardType}</type>
    </card>
    <sha1hash>{$hash}</sha1hash>
</request>
XML;

        $response = $this->sendRequest($xml);
        return $this->parseResponse($response);
    }

    /**
     * Initiate 3DS2 authentication
     *
     * @param string $cardNumber Card number
     * @param string $amount Amount in smallest currency unit (e.g., cents)
     * @param string $currency Currency code (e.g., USD, EUR)
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
        $timestamp = date('YmdHis');
        $orderId = $options['order_id'] ?? uniqid('order-');
        $account = $options['account'] ?? 'internet';
        $expDate = $options['exp_date'] ?? '1225';
        $cardHolder = $options['card_holder'] ?? 'John Doe';
        $cardType = $options['card_type'] ?? 'VISA';
        $serverTransId = $options['server_trans_id'] ?? '';
        $methodUrlComplete = $options['method_url_complete'] ?? 'false';

        $hash = $this->generateHash(
            $timestamp,
            $this->merchantId,
            $orderId,
            $amount,
            $currency,
            $cardNumber
        );

        // Build browser data XML
        $browserXml = $this->buildBrowserDataXml($browserData);

        // Build customer data XML
        $customerXml = $this->buildCustomerDataXml($options['customer'] ?? []);

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<request type="3ds-authenticate" timestamp="{$timestamp}">
    <merchantid>{$this->merchantId}</merchantid>
    <account>{$account}</account>
    <orderid>{$orderId}</orderid>
    <amount currency="{$currency}">{$amount}</amount>
    <card>
        <number>{$cardNumber}</number>
        <expdate>{$expDate}</expdate>
        <chname>{$cardHolder}</chname>
        <type>{$cardType}</type>
    </card>
    <threedsecure>
        <serverTransId>{$serverTransId}</serverTransId>
        <methodUrlComplete>{$methodUrlComplete}</methodUrlComplete>
        {$browserXml}
    </threedsecure>
    {$customerXml}
    <sha1hash>{$hash}</sha1hash>
</request>
XML;

        $response = $this->sendRequest($xml);
        return $this->parseResponse($response);
    }

    /**
     * Verify 3DS2 authentication after challenge
     *
     * @param string $orderId Order ID
     * @param string $amount Amount
     * @param string $currency Currency
     * @param string $paRes Payment authentication response
     * @param array $options Additional options
     * @return array Response data
     * @throws Exception
     */
    public function verify3DS2Authentication(
        string $orderId,
        string $amount,
        string $currency,
        string $paRes,
        array $options = []
    ): array {
        $timestamp = date('YmdHis');
        $account = $options['account'] ?? 'internet';

        $hash = $this->generate3DS2VerifyHash(
            $timestamp,
            $this->merchantId,
            $orderId,
            $amount,
            $currency
        );

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<request type="3ds-verifysig" timestamp="{$timestamp}">
    <merchantid>{$this->merchantId}</merchantid>
    <account>{$account}</account>
    <orderid>{$orderId}</orderid>
    <amount currency="{$currency}">{$amount}</amount>
    <pares>{$paRes}</pares>
    <sha1hash>{$hash}</sha1hash>
</request>
XML;

        $response = $this->sendRequest($xml);
        return $this->parseResponse($response);
    }

    /**
     * Authorize payment with 3DS2 authentication data
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
        $account = $options['account'] ?? 'internet';
        $expDate = $options['exp_date'] ?? '1225';
        $cardHolder = $options['card_holder'] ?? 'John Doe';
        $cardType = $options['card_type'] ?? 'VISA';
        $cvv = $options['cvv'] ?? '123';

        $hash = $this->generateHash(
            $timestamp,
            $this->merchantId,
            $orderId,
            $amount,
            $currency,
            $cardNumber
        );

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
        <eci>{$authData['eci']}</eci>
        <cavv>{$authData['cavv']}</cavv>
        <xid>{$authData['xid']}</xid>
        <ds_trans_id>{$authData['ds_trans_id']}</ds_trans_id>
    </mpi>
    <sha1hash>{$hash}</sha1hash>
</request>
XML;

        $response = $this->sendRequest($xml);
        return $this->parseResponse($response);
    }

    /**
     * Build browser data XML section
     *
     * @param array $browserData Browser information
     * @return string XML string
     */
    private function buildBrowserDataXml(array $browserData): string
    {
        $acceptHeader = htmlspecialchars($browserData['accept_header'] ?? 'text/html,application/xhtml+xml');
        $userAgent = htmlspecialchars($browserData['user_agent'] ?? '');
        $colorDepth = $browserData['color_depth'] ?? '24';
        $javaEnabled = $browserData['java_enabled'] ?? 'false';
        $jsEnabled = $browserData['javascript_enabled'] ?? 'true';
        $language = $browserData['language'] ?? 'en-US';
        $screenHeight = $browserData['screen_height'] ?? '1080';
        $screenWidth = $browserData['screen_width'] ?? '1920';
        $timezone = $browserData['timezone'] ?? '-300';
        $challengeWindowSize = $browserData['challenge_window_size'] ?? '05';

        return <<<XML
<browserData>
            <acceptHeader>{$acceptHeader}</acceptHeader>
            <colorDepth>{$colorDepth}</colorDepth>
            <javaEnabled>{$javaEnabled}</javaEnabled>
            <javaScriptEnabled>{$jsEnabled}</javaScriptEnabled>
            <language>{$language}</language>
            <screenHeight>{$screenHeight}</screenHeight>
            <screenWidth>{$screenWidth}</screenWidth>
            <timeZone>{$timezone}</timeZone>
            <userAgent>{$userAgent}</userAgent>
            <challengeWindowSize>{$challengeWindowSize}</challengeWindowSize>
        </browserData>
XML;
    }

    /**
     * Build customer data XML section
     *
     * @param array $customer Customer information
     * @return string XML string
     */
    private function buildCustomerDataXml(array $customer): string
    {
        if (empty($customer)) {
            return '';
        }

        $email = htmlspecialchars($customer['email'] ?? '');
        $phone = htmlspecialchars($customer['phone'] ?? '');

        $billingXml = '';
        if (!empty($customer['billing'])) {
            $billing = $customer['billing'];
            $street = $billing['street'] ?? '';
            $city = $billing['city'] ?? '';
            $state = $billing['state'] ?? '';
            $postalCode = $billing['postal_code'] ?? '';
            $country = $billing['country'] ?? '';

            $billingXml = <<<XML
<billingAddress>
                <street>{$street}</street>
                <city>{$city}</city>
                <state>{$state}</state>
                <postalCode>{$postalCode}</postalCode>
                <country>{$country}</country>
            </billingAddress>
XML;
        }

        return <<<XML
<customer>
        <email>{$email}</email>
        <mobilePhone>
            <number>{$phone}</number>
        </mobilePhone>
        {$billingXml}
    </customer>
XML;
    }
}
