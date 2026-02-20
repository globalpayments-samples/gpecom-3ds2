<?php

declare(strict_types=1);

namespace GpecomSdk;

class SecurityHeaders
{
    /**
     * Apply security headers to the response.
     */
    public static function apply(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        // CSP allowing GP domains for scripts, styles, and API calls
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://pay.sandbox.realexpayments.com https://globalpayments-samples.github.io",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://globalpayments-samples.github.io",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https://globalpayments-samples.github.io",
            "connect-src 'self' https://api.sandbox.globalpay-ecommerce.com https://pay.sandbox.realexpayments.com",
            "frame-src 'self' https://pay.sandbox.realexpayments.com https://acs.sandbox.globalpay-ecommerce.com",
        ]);
        header('Content-Security-Policy: ' . $csp);

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Set CORS headers for API endpoints.
     */
    public static function corsHeaders(): void
    {
        $allowedOrigins = ['http://localhost:8080', 'http://127.0.0.1:8080'];
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // Allow same-origin requests (no Origin header)
            header('Access-Control-Allow-Origin: *');
        }

        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Max-Age: 3600');
    }

    /**
     * Validate that the request is POST.
     */
    public static function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
        }
    }

    /**
     * Read and decode JSON request body.
     *
     * @return array The decoded JSON data
     */
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            ApiResponse::error('Empty request body', 400);
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error('Invalid JSON: ' . json_last_error_msg(), 400);
        }

        return self::sanitizeInput($data);
    }

    /**
     * Recursively sanitize input data.
     *
     * @param mixed $input
     * @return mixed
     */
    public static function sanitizeInput($input)
    {
        if (is_string($input)) {
            // Remove null bytes and control characters (except newlines/tabs)
            $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
            return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }

        return $input;
    }

    /**
     * Validate card number using Luhn algorithm.
     */
    public static function validateCardNumber(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        $len = strlen($number);

        if ($len < 13 || $len > 19) {
            return false;
        }

        // Luhn algorithm
        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $digit = (int)$number[$i];
            if ($alt) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $alt = !$alt;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Validate expiry date in MMYY format.
     */
    public static function validateExpDate(string $expDate): bool
    {
        if (!preg_match('/^\d{4}$/', $expDate)) {
            return false;
        }

        $month = (int)substr($expDate, 0, 2);
        $year = (int)substr($expDate, 2, 2);

        if ($month < 1 || $month > 12) {
            return false;
        }

        $currentYear = (int)date('y');
        $currentMonth = (int)date('m');

        // Card is valid if it hasn't expired
        return ($year > $currentYear) || ($year === $currentYear && $month >= $currentMonth);
    }

    /**
     * Validate amount (positive integer, in minor units/cents).
     */
    public static function validateAmount(string $amount): bool
    {
        if (!preg_match('/^\d+$/', $amount)) {
            return false;
        }

        $value = (int)$amount;
        return $value > 0 && $value <= 99999999;
    }

    /**
     * Validate currency code.
     */
    public static function validateCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), ['EUR', 'USD', 'GBP'], true);
    }

    /**
     * Validate CVV (3-4 digits).
     */
    public static function validateCvv(string $cvv): bool
    {
        return (bool)preg_match('/^\d{3,4}$/', $cvv);
    }

    /**
     * Detect card type from card number.
     */
    public static function detectCardType(string $cardNumber): string
    {
        $number = preg_replace('/\D/', '', $cardNumber);

        if (preg_match('/^4/', $number)) {
            return 'VISA';
        }
        if (preg_match('/^5[1-5]/', $number)) {
            return 'MC';
        }
        if (preg_match('/^3[47]/', $number)) {
            return 'AMEX';
        }
        if (preg_match('/^3(?:0[0-5]|[68])/', $number)) {
            return 'DINERS';
        }
        if (preg_match('/^6(?:011|5)/', $number)) {
            return 'DISCOVER';
        }

        return 'VISA'; // default
    }

    /**
     * Log an error to the error log file.
     */
    public static function logError(string $step, \Throwable $e): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf(
            "[%s] [%s] %s: %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $step,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        file_put_contents($logDir . '/3ds2-errors.log', $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log an API request/response.
     */
    public static function logApi(string $step, string $direction, $data): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = sprintf(
            "[%s] [%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            $step,
            $direction,
            is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($logDir . '/3ds2-api.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
