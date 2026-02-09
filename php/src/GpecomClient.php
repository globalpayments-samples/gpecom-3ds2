<?php

declare(strict_types=1);

namespace GpecomSdk;

use Dotenv\Dotenv;
use GlobalPayments\Api\Entities\Address;
use GlobalPayments\Api\Entities\BrowserData;
use GlobalPayments\Api\Entities\Enums\AddressType;
use GlobalPayments\Api\Entities\Enums\AuthenticationSource;
use GlobalPayments\Api\Entities\Enums\ChallengeWindowSize;
use GlobalPayments\Api\Entities\Enums\ColorDepth;
use GlobalPayments\Api\Entities\Enums\MethodUrlCompletion;
use GlobalPayments\Api\Entities\Enums\Secure3dVersion;
use GlobalPayments\Api\Entities\ThreeDSecure;
use GlobalPayments\Api\PaymentMethods\CreditCardData;
use GlobalPayments\Api\ServiceConfigs\Gateways\GpEcomConfig;
use GlobalPayments\Api\Services\Secure3dService;
use GlobalPayments\Api\ServicesContainer;

class GpecomClient
{
    private string $merchantId;
    private string $sharedSecret;
    private string $account;
    private bool $sandbox;
    private bool $debug;
    private bool $sdkConfigured = false;

    /**
     * Official 3DS2 Message Version 2.2 test cards from Global Payments documentation.
     */
    private const TEST_CARDS = [
        // Visa
        '4222000006285344' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_SUCCESSFUL', 'eci' => '05', 'brand' => 'VISA'],
        '4222000009719489' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_SUCCESSFUL', 'eci' => '05', 'brand' => 'VISA', 'no_method_url' => true],
        '4222000005218627' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL', 'eci' => '06', 'brand' => 'VISA'],
        '4222000002144131' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_FAILED', 'eci' => '07', 'brand' => 'VISA'],
        '4222000007275799' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_ISSUER_REJECTED', 'eci' => '07', 'brand' => 'VISA'],
        '4222000008880910' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_COULD_NOT_BE_PERFORMED', 'eci' => '07', 'brand' => 'VISA'],
        '4222000001227408' => ['flow' => 'challenge', 'result' => 'CHALLENGE_REQUIRED', 'eci' => null, 'brand' => 'VISA'],
        // Mastercard
        '5354560000000004' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_SUCCESSFUL', 'eci' => '02', 'brand' => 'MC'],
        '5571596304025153' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_SUCCESSFUL', 'eci' => '02', 'brand' => 'MC', 'no_method_url' => true],
        '5580364874958322' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL', 'eci' => '01', 'brand' => 'MC'],
        '5540010585397800' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_FAILED', 'eci' => '00', 'brand' => 'MC'],
        '5588312194362669' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_ISSUER_REJECTED', 'eci' => '00', 'brand' => 'MC'],
        '5520680211891022' => ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_COULD_NOT_BE_PERFORMED', 'eci' => '00', 'brand' => 'MC'],
        '5506874496684651' => ['flow' => 'challenge', 'result' => 'CHALLENGE_REQUIRED', 'eci' => null, 'brand' => 'MC'],
    ];

    public function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();

        $this->merchantId = $_ENV['GPECOM_MERCHANT_ID'];
        $this->sharedSecret = $_ENV['GPECOM_SHARED_SECRET'];
        $this->account = $_ENV['GPECOM_ACCOUNT'] ?? 'internet';
        $this->sandbox = ($_ENV['GPECOM_SANDBOX'] ?? 'true') === 'true';
        $this->debug = ($_ENV['DEBUG_MODE'] ?? 'false') === 'true';

        $this->configureSDK();
    }

    private function configureSDK(): void
    {
        if ($this->sdkConfigured) {
            return;
        }

        $config = new GpEcomConfig();
        $config->merchantId = $this->merchantId;
        $config->accountId = $this->account;
        $config->sharedSecret = $this->sharedSecret;
        $config->secure3dVersion = Secure3dVersion::TWO;

        // Notification URLs (require public HTTPS for live testing)
        // Use null (not empty string) when not configured so the SDK omits the field entirely
        $config->methodNotificationUrl = !empty($_ENV['METHOD_NOTIFICATION_URL']) ? $_ENV['METHOD_NOTIFICATION_URL'] : null;
        $config->challengeNotificationUrl = !empty($_ENV['CHALLENGE_NOTIFICATION_URL']) ? $_ENV['CHALLENGE_NOTIFICATION_URL'] : null;
        $config->merchantContactUrl = $_ENV['MERCHANT_CONTACT_URL'] ?? 'https://developer.globalpayments.com';

        ServicesContainer::configureService($config);
        $this->sdkConfigured = true;
    }

    // ──────────────────────────────────────────
    // 3DS2 Operations (using SDK)
    // ──────────────────────────────────────────

    /**
     * Step 1: Check if the card is enrolled in 3DS2.
     */
    public function check3DS2Enrollment(array $params): array
    {
        $card = $this->buildCard($params);

        SecurityHeaders::logApi('check-enrollment', 'REQUEST', [
            'card' => $this->maskCardNumber($params['card_number']),
            'order_id' => $params['order_id'] ?? 'auto',
        ]);

        // execute() returns ThreeDSecure object directly (not a Transaction)
        $threeDSecure = Secure3dService::checkEnrollment($card)
            ->execute('default', Secure3dVersion::TWO);

        SecurityHeaders::logApi('check-enrollment', 'RESPONSE', [
            'enrolled' => $threeDSecure->enrolled,
            'server_trans_id' => $threeDSecure->serverTransactionId,
            'status' => $threeDSecure->status,
        ]);

        return [
            'enrolled' => $threeDSecure->enrolled ? 'Y' : 'N',
            'server_trans_id' => $threeDSecure->serverTransactionId,
            'method_url' => $threeDSecure->issuerAcsUrl ?: null,
            'method_data' => $threeDSecure->payerAuthenticationRequest ?: null,
            'ds_trans_id' => $threeDSecure->directoryServerTransactionId,
            'order_id' => $params['order_id'] ?? null,
            'acs_start_version' => $threeDSecure->acsStartVersion,
            'acs_end_version' => $threeDSecure->acsEndVersion,
            'message' => $threeDSecure->enrolled ? 'Card enrolled in 3DS2' : 'Card not enrolled',
        ];
    }

    /**
     * Step 3: Initiate 3DS2 authentication.
     */
    public function initiate3DS2Authentication(array $params): array
    {
        $card = $this->buildCard($params);

        // Build ThreeDSecure object from enrollment data
        $secureEcom = new ThreeDSecure();
        $secureEcom->serverTransactionId = $params['server_trans_id'];
        $secureEcom->acsEndVersion = $params['acs_end_version'] ?? '2.2.0';

        $card->threeDSecure = $secureEcom;

        // Build browser data
        $browserData = $this->buildBrowserData($params['browser_data'] ?? []);

        // Build address
        $address = new Address();
        $address->streetAddress1 = $params['address'] ?? 'Flat 123';
        $address->streetAddress2 = $params['address2'] ?? 'House 456';
        $address->city = $params['city'] ?? 'Halifax';
        $address->postalCode = $params['postal_code'] ?? 'W5 9HR';
        $address->countryCode = $params['country_code'] ?? '826';

        // Amount in minor units (cents) -> major units
        $amount = ((int)($params['amount'] ?? '1000')) / 100;
        $currency = $params['currency'] ?? 'EUR';

        // Method URL completion status
        $methodUrlComplete = ($params['method_url_complete'] ?? 'true') === 'true'
            ? MethodUrlCompletion::YES
            : MethodUrlCompletion::NO;

        SecurityHeaders::logApi('initiate-auth', 'REQUEST', [
            'card' => $this->maskCardNumber($params['card_number']),
            'amount' => $amount,
            'currency' => $currency,
            'server_trans_id' => $params['server_trans_id'],
        ]);

        // execute() returns ThreeDSecure object directly (not a Transaction)
        $threeDSecure = Secure3dService::initiateAuthentication($card, $secureEcom)
            ->withAmount($amount)
            ->withCurrency($currency)
            ->withAuthenticationSource(AuthenticationSource::BROWSER)
            ->withMethodUrlCompletion($methodUrlComplete)
            ->withOrderCreateDate(date('Y-m-d H:i:s'))
            ->withAddress($address, AddressType::SHIPPING)
            ->withAddress($address, AddressType::BILLING)
            ->withBrowserData($browserData)
            ->withCustomerEmail($params['customer']['email'] ?? 'test@example.com')
            ->execute();

        SecurityHeaders::logApi('initiate-auth', 'RESPONSE', [
            'status' => $threeDSecure->status,
            'challenge_mandated' => $threeDSecure->challengeMandated ?? false,
            'eci' => $threeDSecure->eci,
        ]);

        if ($threeDSecure->status === 'CHALLENGE_REQUIRED') {
            return [
                'challenge_required' => true,
                'status' => $threeDSecure->status,
                'challenge' => [
                    'acs_url' => $threeDSecure->issuerAcsUrl,
                    'creq' => $threeDSecure->payerAuthenticationRequest,
                    'server_trans_id' => $threeDSecure->serverTransactionId,
                ],
            ];
        }

        // Frictionless result
        return [
            'challenge_required' => false,
            'status' => $threeDSecure->status,
            'auth_data' => [
                'eci' => $threeDSecure->eci,
                'cavv' => $threeDSecure->authenticationValue,
                'xid' => $threeDSecure->xid ?? '',
                'ds_trans_id' => $threeDSecure->directoryServerTransactionId,
                'authentication_value' => $threeDSecure->authenticationValue,
                'message_version' => $threeDSecure->messageVersion ?? '2.2.0',
                'status' => $threeDSecure->status,
            ],
        ];
    }

    /**
     * Step 4: Verify authentication after challenge completion.
     */
    public function verify3DS2Authentication(array $params): array
    {
        $serverTransId = $params['server_trans_id'];

        SecurityHeaders::logApi('verify-auth', 'REQUEST', [
            'server_trans_id' => $serverTransId,
        ]);

        // execute() returns ThreeDSecure object directly (not a Transaction)
        $threeDSecure = Secure3dService::getAuthenticationData()
            ->withServerTransactionId($serverTransId)
            ->execute();

        SecurityHeaders::logApi('verify-auth', 'RESPONSE', [
            'status' => $threeDSecure->status,
            'eci' => $threeDSecure->eci,
        ]);

        $authenticated = in_array($threeDSecure->eci, ['05', '06', '02', '01'], true);

        return [
            'authenticated' => $authenticated,
            'auth_data' => [
                'eci' => $threeDSecure->eci,
                'cavv' => $threeDSecure->authenticationValue,
                'xid' => $threeDSecure->xid ?? '',
                'ds_trans_id' => $threeDSecure->directoryServerTransactionId,
                'authentication_value' => $threeDSecure->authenticationValue,
                'message_version' => $threeDSecure->messageVersion ?? '2.2.0',
                'status' => $threeDSecure->status,
            ],
        ];
    }

    /**
     * Step 5: Authorize payment with 3DS2 authentication data.
     */
    public function authorizePayment(array $params): array
    {
        $card = $this->buildCard($params);
        $card->cvn = $params['cvv'] ?? '';

        // Build ThreeDSecure object from auth data
        $authData = $params['auth_data'] ?? [];
        $secureEcom = new ThreeDSecure();
        $secureEcom->eci = $authData['eci'] ?? '';
        $secureEcom->cavv = $authData['cavv'] ?? '';
        $secureEcom->xid = $authData['xid'] ?? '';
        $secureEcom->directoryServerTransactionId = $authData['ds_trans_id'] ?? '';
        $secureEcom->authenticationValue = $authData['authentication_value'] ?? $authData['cavv'] ?? '';
        $secureEcom->messageVersion = $authData['message_version'] ?? '2.2.0';

        $card->threeDSecure = $secureEcom;

        // Amount in minor units (cents) -> major units
        $amount = ((int)($params['amount'] ?? '1000')) / 100;
        $currency = $params['currency'] ?? 'EUR';

        SecurityHeaders::logApi('authorize', 'REQUEST', [
            'card' => $this->maskCardNumber($params['card_number']),
            'amount' => $amount,
            'currency' => $currency,
            'order_id' => $params['order_id'] ?? 'auto',
            'eci' => $authData['eci'] ?? '',
        ]);

        $response = $card->charge($amount)
            ->withCurrency($currency)
            ->withOrderId($params['order_id'] ?? null)
            ->withAllowDuplicates(true)
            ->execute();

        SecurityHeaders::logApi('authorize', 'RESPONSE', [
            'response_code' => $response->responseCode,
            'response_message' => $response->responseMessage,
            'transaction_id' => $response->transactionId,
        ]);

        $authorized = $response->responseCode === '00';

        return [
            'authorized' => $authorized,
            'transaction_id' => $response->transactionId ?? '',
            'order_id' => $params['order_id'] ?? '',
            'auth_code' => $response->authorizationCode ?? '',
            'message' => $authorized ? 'Authorised' : ($response->responseMessage ?? 'Payment declined'),
            'response_code' => $response->responseCode,
        ];
    }

    // ──────────────────────────────────────────
    // Demo Mode Simulations
    // ──────────────────────────────────────────

    public function simulateEnrollment(array $params): array
    {
        $cardNumber = preg_replace('/\D/', '', $params['card_number']);
        $testCard = self::TEST_CARDS[$cardNumber] ?? null;

        $serverTransId = 'demo-' . bin2hex(random_bytes(16));
        $hasMethodUrl = !($testCard['no_method_url'] ?? false);

        return [
            'enrolled' => 'Y',
            'server_trans_id' => $serverTransId,
            'method_url' => $hasMethodUrl ? 'https://acs.sandbox.example.com/3ds/method' : null,
            'method_data' => $hasMethodUrl ? base64_encode(json_encode([
                'threeDSServerTransID' => $serverTransId,
                'threeDSMethodNotificationURL' => $_ENV['METHOD_NOTIFICATION_URL'] ?? 'http://localhost:8080/php/webhooks/method-notification.php',
            ])) : null,
            'ds_trans_id' => 'demo-ds-' . bin2hex(random_bytes(8)),
            'order_id' => $params['order_id'] ?? 'demo-order-' . time(),
            'acs_start_version' => '2.1.0',
            'acs_end_version' => '2.2.0',
            'message' => 'Card enrolled in 3DS2 (demo)',
        ];
    }

    public function simulateAuthentication(array $params): array
    {
        $cardNumber = preg_replace('/\D/', '', $params['card_number']);
        $testCard = self::TEST_CARDS[$cardNumber] ?? null;

        if (!$testCard) {
            // Unknown card - default to frictionless success
            $testCard = ['flow' => 'frictionless', 'result' => 'AUTHENTICATION_SUCCESSFUL', 'eci' => '05', 'brand' => 'VISA'];
        }

        $serverTransId = $params['server_trans_id'] ?? 'demo-' . time();

        if ($testCard['flow'] === 'challenge') {
            return [
                'challenge_required' => true,
                'status' => 'CHALLENGE_REQUIRED',
                'challenge' => [
                    'acs_url' => 'https://acs.sandbox.example.com/3ds/challenge',
                    'creq' => base64_encode(json_encode([
                        'threeDSServerTransID' => $serverTransId,
                        'acsTransID' => 'demo-acs-' . bin2hex(random_bytes(8)),
                        'messageType' => 'CReq',
                        'messageVersion' => '2.2.0',
                        'challengeWindowSize' => '05',
                    ])),
                    'server_trans_id' => $serverTransId,
                ],
            ];
        }

        // Frictionless scenarios
        $isSuccess = in_array($testCard['result'], [
            'AUTHENTICATION_SUCCESSFUL',
            'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL',
        ], true);

        return [
            'challenge_required' => false,
            'status' => $testCard['result'],
            'auth_data' => [
                'eci' => $testCard['eci'],
                'cavv' => $isSuccess ? base64_encode(random_bytes(20)) : '',
                'xid' => $isSuccess ? base64_encode(random_bytes(20)) : '',
                'ds_trans_id' => 'demo-ds-' . bin2hex(random_bytes(8)),
                'authentication_value' => $isSuccess ? base64_encode(random_bytes(20)) : '',
                'message_version' => '2.2.0',
                'status' => $testCard['result'],
            ],
        ];
    }

    public function simulateVerifyAuth(array $params): array
    {
        return [
            'authenticated' => true,
            'auth_data' => [
                'eci' => '05',
                'cavv' => base64_encode(random_bytes(20)),
                'xid' => base64_encode(random_bytes(20)),
                'ds_trans_id' => 'demo-ds-' . bin2hex(random_bytes(8)),
                'authentication_value' => base64_encode(random_bytes(20)),
                'message_version' => '2.2.0',
                'status' => 'AUTHENTICATION_SUCCESSFUL',
            ],
        ];
    }

    public function simulateAuthorization(array $params): array
    {
        $cardNumber = preg_replace('/\D/', '', $params['card_number']);
        $testCard = self::TEST_CARDS[$cardNumber] ?? null;

        // Cards with failed authentication should not authorize
        $failedResults = [
            'AUTHENTICATION_FAILED',
            'AUTHENTICATION_ISSUER_REJECTED',
            'AUTHENTICATION_COULD_NOT_BE_PERFORMED',
        ];

        if ($testCard && in_array($testCard['result'], $failedResults, true)) {
            return [
                'authorized' => false,
                'transaction_id' => '',
                'order_id' => $params['order_id'] ?? '',
                'auth_code' => '',
                'message' => 'Payment declined - authentication failed (' . $testCard['result'] . ')',
                'response_code' => '110',
            ];
        }

        return [
            'authorized' => true,
            'transaction_id' => 'demo-' . bin2hex(random_bytes(8)),
            'order_id' => $params['order_id'] ?? '',
            'auth_code' => (string)random_int(100000, 999999),
            'message' => 'Authorised (demo)',
            'response_code' => '00',
        ];
    }

    // ──────────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────────

    private function buildCard(array $params): CreditCardData
    {
        $card = new CreditCardData();
        $card->number = preg_replace('/\D/', '', $params['card_number']);

        $expDate = $params['exp_date'] ?? '1228';
        $card->expMonth = substr($expDate, 0, 2);
        $card->expYear = '20' . substr($expDate, 2, 2);
        $card->cardHolderName = $params['card_holder'] ?? 'Test Customer';

        // Set card type if provided
        if (!empty($params['card_type'])) {
            $card->cardType = $params['card_type'];
        }

        return $card;
    }

    private function buildBrowserData(array $data): BrowserData
    {
        $browser = new BrowserData();
        $browser->acceptHeader = $data['accept_header']
            ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        $browser->colorDepth = $this->mapColorDepth($data['color_depth'] ?? '24');
        $browser->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $browser->javaEnabled = ($data['java_enabled'] ?? 'false') === 'true';
        $browser->javaScriptEnabled = ($data['javascript_enabled'] ?? 'true') === 'true';
        $browser->language = $data['language'] ?? 'en-US';
        $browser->screenHeight = (int)($data['screen_height'] ?? '1080');
        $browser->screenWidth = (int)($data['screen_width'] ?? '1920');
        $browser->challengWindowSize = $this->mapChallengeWindowSize($data['challenge_window_size'] ?? '05');
        $browser->timeZone = $data['timezone'] ?? '0';
        $browser->userAgent = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0');

        return $browser;
    }

    private function mapColorDepth(string $depth): string
    {
        $map = [
            '1' => ColorDepth::ONE_BIT,
            '2' => ColorDepth::TWO_BITS,
            '4' => ColorDepth::FOUR_BITS,
            '8' => ColorDepth::EIGHT_BITS,
            '15' => ColorDepth::FIFTEEN_BITS,
            '16' => ColorDepth::SIXTEEN_BITS,
            '24' => ColorDepth::TWENTY_FOUR_BITS,
            '32' => ColorDepth::THIRTY_TWO_BITS,
            '48' => ColorDepth::FORTY_EIGHT_BITS,
        ];

        return $map[$depth] ?? ColorDepth::TWENTY_FOUR_BITS;
    }

    private function mapChallengeWindowSize(string $size): string
    {
        $map = [
            '01' => ChallengeWindowSize::WINDOWED_250X400,
            '02' => ChallengeWindowSize::WINDOWED_390X400,
            '03' => ChallengeWindowSize::WINDOWED_500X600,
            '04' => ChallengeWindowSize::WINDOWED_600X400,
            '05' => ChallengeWindowSize::FULL_SCREEN,
        ];

        return $map[$size] ?? ChallengeWindowSize::FULL_SCREEN;
    }

    private function maskCardNumber(string $number): string
    {
        $clean = preg_replace('/\D/', '', $number);
        if (strlen($clean) < 10) {
            return '****';
        }
        return substr($clean, 0, 6) . str_repeat('*', strlen($clean) - 10) . substr($clean, -4);
    }

    /**
     * Check if a card number is a known test card.
     */
    public function getTestCardInfo(string $cardNumber): ?array
    {
        $clean = preg_replace('/\D/', '', $cardNumber);
        return self::TEST_CARDS[$clean] ?? null;
    }
}
