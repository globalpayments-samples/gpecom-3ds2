<?php

declare(strict_types=1);

/**
 * HPP (Hosted Payment Page) Request Generator
 *
 * Following: https://developer.globalpayments.com/ecommerce/payments/hosted-solution/guide
 *
 * Generates the JSON needed to open the Global Payments HPP.
 * HPP handles all 3DS2 authentication automatically.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GlobalPayments\Api\ServiceConfigs\Gateways\GpEcomConfig;
use GlobalPayments\Api\HostedPaymentConfig;
use GlobalPayments\Api\Entities\HostedPaymentData;
use GlobalPayments\Api\Entities\Address;
use GlobalPayments\Api\Entities\Enums\AddressType;
use GlobalPayments\Api\Entities\Enums\HppVersion;
use GlobalPayments\Api\Services\HostedService;
use GlobalPayments\Api\Entities\Exceptions\ApiException;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Load environment variables
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    // Get request parameters
    $amount = (float)($_REQUEST['amount'] ?? '10.00');
    $currency = $_REQUEST['currency'] ?? 'EUR';
    $customerEmail = $_REQUEST['email'] ?? 'customer@example.com';
    $customerName = $_REQUEST['name'] ?? 'John Doe';
    $customerPhone = $_REQUEST['phone'] ?? '44|07123456789';

    // Split name into first and last
    $nameParts = explode(' ', $customerName, 2);
    $firstName = $nameParts[0];
    $lastName = $nameParts[1] ?? 'Customer';

    // Configure GPeCOM for HPP
    $config = new GpEcomConfig();
    $config->merchantId = $_ENV['GPECOM_MERCHANT_ID'];
    $config->accountId = $_ENV['GPECOM_ACCOUNT'] ?? 'internet';
    $config->sharedSecret = $_ENV['GPECOM_SHARED_SECRET'];

    // HPP URL - Sandbox or Production
    $sandbox = ($_ENV['GPECOM_SANDBOX'] ?? 'true') === 'true';
    $config->serviceUrl = $sandbox
        ? 'https://pay.sandbox.realexpayments.com/pay'
        : 'https://pay.realexpayments.com/pay';

    // HPP Configuration - Version 2 required for 3DS2
    $config->hostedPaymentConfig = new HostedPaymentConfig();
    $config->hostedPaymentConfig->version = HppVersion::VERSION_2;

    $service = new HostedService($config);

    // Hosted Payment Data - 3DS2 Mandatory Fields
    $hostedPaymentData = new HostedPaymentData();
    $hostedPaymentData->customerEmail = $customerEmail;
    $hostedPaymentData->customerPhoneMobile = $customerPhone;
    $hostedPaymentData->customerFirstName = $firstName;
    $hostedPaymentData->customerLastName = $lastName;
    $hostedPaymentData->addressesMatch = true;
    $hostedPaymentData->customerCountry = $_REQUEST['customer_country'] ?? 'GB';

    // Response URL for HPP to send the result back
    // Note: For production, this must be a publicly accessible HTTPS URL
    $baseUrl = $_ENV['APP_BASE_URL'] ?? '';
    if (!empty($baseUrl)) {
        $hostedPaymentData->merchantResponseUrl = rtrim($baseUrl, '/') . '/api/hpp-response.php';
    }

    // Billing Address - Required for 3DS2
    $billingAddress = new Address();
    $billingAddress->streetAddress1 = $_REQUEST['address'] ?? 'Flat 123';
    $billingAddress->streetAddress2 = $_REQUEST['address2'] ?? 'House 456';
    $billingAddress->city = $_REQUEST['city'] ?? 'Halifax';
    $billingAddress->postalCode = $_REQUEST['postal_code'] ?? 'W5 9HR';
    $billingAddress->country = $_REQUEST['country'] ?? '826'; // ISO 3166-1 numeric

    // Shipping Address (same as billing for this example)
    $shippingAddress = new Address();
    $shippingAddress->streetAddress1 = $billingAddress->streetAddress1;
    $shippingAddress->streetAddress2 = $billingAddress->streetAddress2;
    $shippingAddress->city = $billingAddress->city;
    $shippingAddress->postalCode = $billingAddress->postalCode;
    $shippingAddress->country = $billingAddress->country;

    // Generate HPP JSON with all 3DS2 required fields
    $hppJson = $service->charge($amount)
        ->withCurrency($currency)
        ->withHostedPaymentData($hostedPaymentData)
        ->withAddress($billingAddress, AddressType::BILLING)
        ->withAddress($shippingAddress, AddressType::SHIPPING)
        ->serialize();

    echo $hppJson;

} catch (ApiException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'API Error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
