# GPeCOM 3DS2 - PHP Implementation

3D Secure 2 authentication for Global Payments' XML (GPeCOM) API using the official PHP SDK.

## Quick Start

```bash
cd php
composer install
cd ..
php -S localhost:8080      # start from project root
# Open http://localhost:8080/
```

Sandbox credentials are pre-configured in `.env`.

## Project Structure

```
php/
├── src/
│   ├── GpecomClient.php          # Core 3DS2 SDK wrapper
│   ├── ApiResponse.php           # JSON response helper
│   └── SecurityHeaders.php       # Security & validation utilities
├── api/
│   ├── check-enrollment.php      # Step 1: Check card enrollment
│   ├── initiate-auth.php         # Step 3: Initiate authentication
│   ├── verify-auth.php           # Step 4: Verify challenge result
│   ├── authorize-payment.php     # Step 5: Process payment
│   ├── hpp-request.php           # HPP request generator
│   └── hpp-response.php          # HPP response handler
├── webhooks/
│   ├── method-notification.php   # Device fingerprint callback
│   └── challenge-notification.php # Challenge completion callback
├── logs/                          # API and error logs
├── temp/                          # Temporary session data
├── index.html                     # HPP checkout page
└── .env                           # Credentials
```

## Architecture

The implementation uses the Global Payments PHP SDK (`globalpayments/php-sdk`):

- **3DS2 enrollment & authentication**: REST JSON API via `Gp3DSProvider` at `/3ds2/` endpoints
- **Payment authorization**: XML API via `GpEcomConnector` at `/epage-remote.cgi`

```
Browser                    PHP Server                  GP API                  ACS (Issuer)
  │                           │                          │                        │
  │  1. Submit Card           │                          │                        │
  ├──────────────────────────>│  2. Check Enrollment     │                        │
  │                           ├─────────────────────────>│                        │
  │                           │<─────────────────────────│                        │
  │  3. Method URL iframe     │                          │                        │
  │<──────────────────────────│                          │   4. Fingerprint       │
  ├──────────────────────────────────────────────────────────────────────────────>│
  │                           │<─────────────────────────────────────────────────│
  │  5. Initiate Auth         │  6. Authenticate         │                        │
  ├──────────────────────────>├─────────────────────────>│  7. Risk Check         │
  │                           │                          ├───────────────────────>│
  │                           │<─────────────────────────│<──────────────────────│
  │  8. Result or Challenge   │                          │                        │
  │<──────────────────────────│                          │                        │
  │                           │                          │                        │
  │  [If challenge: display ACS iframe, complete auth, verify]                    │
  │                           │                          │                        │
  │  9. Authorize Payment     │  10. XML Auth            │                        │
  ├──────────────────────────>├─────────────────────────>│                        │
  │                           │<─────────────────────────│                        │
  │  11. Done                 │                          │                        │
  │<──────────────────────────│                          │                        │
```

## API Endpoints

### POST `/php/api/check-enrollment.php`

Check if a card is enrolled in 3DS2.

**Request:**
```json
{
  "card_number": "4222000006285344",
  "exp_date": "1228",
  "card_holder": "John Doe",
  "order_id": "order-123456"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Enrollment check complete",
  "data": {
    "enrolled": "Y",
    "server_trans_id": "abc123-...",
    "method_url": "https://acs.example.com/method",
    "method_data": null,
    "ds_trans_id": null,
    "order_id": "order-123456",
    "acs_start_version": "2.1.0",
    "acs_end_version": "2.2.0"
  }
}
```

### POST `/php/api/initiate-auth.php`

Initiate 3DS2 authentication with browser data.

**Request:**
```json
{
  "card_number": "4222000006285344",
  "amount": "1999",
  "currency": "EUR",
  "exp_date": "1228",
  "card_holder": "John Doe",
  "order_id": "order-123456",
  "server_trans_id": "abc123-...",
  "method_url_complete": "true",
  "browser_data": {
    "accept_header": "text/html,application/xhtml+xml",
    "color_depth": "24",
    "java_enabled": "false",
    "javascript_enabled": "true",
    "language": "en-US",
    "screen_height": "1080",
    "screen_width": "1920",
    "timezone": "0",
    "user_agent": "Mozilla/5.0...",
    "challenge_window_size": "05"
  }
}
```

**Response (frictionless):**
```json
{
  "success": true,
  "data": {
    "challenge_required": false,
    "status": "AUTHENTICATION_SUCCESSFUL",
    "auth_data": {
      "eci": "05",
      "cavv": "AAABBZIGcQ...",
      "ds_trans_id": "xyz789-...",
      "authentication_value": "AAABBZIGcQ...",
      "message_version": "2.2.0"
    }
  }
}
```

**Response (challenge):**
```json
{
  "success": true,
  "data": {
    "challenge_required": true,
    "status": "CHALLENGE_REQUIRED",
    "challenge": {
      "acs_url": "https://acs.example.com/challenge",
      "creq": "eyJ0aHJlZURT...",
      "server_trans_id": "abc123-..."
    }
  }
}
```

### POST `/php/api/verify-auth.php`

Verify authentication after challenge completion.

**Request:**
```json
{
  "server_trans_id": "abc123-..."
}
```

### POST `/php/api/authorize-payment.php`

Authorize payment with 3DS2 authentication data.

**Request:**
```json
{
  "card_number": "5354560000000004",
  "amount": "1999",
  "currency": "EUR",
  "exp_date": "1228",
  "card_holder": "John Doe",
  "cvv": "123",
  "order_id": "order-123456",
  "auth_data": {
    "eci": "02",
    "cavv": "AAABBZIGcQ...",
    "ds_trans_id": "xyz789-...",
    "authentication_value": "AAABBZIGcQ...",
    "message_version": "2.2.0"
  }
}
```

All endpoints also accept `"demo_mode": true` for simulated responses without API calls.

## Test Cards (Message Version 2.2)

Use any future-dated expiry (e.g. 12/28) and any CVV.

### Visa

| Card Number | Flow | Result | ECI |
|-------------|------|--------|-----|
| 4222000006285344 | Frictionless | AUTHENTICATION_SUCCESSFUL | 05 |
| 4222000009719489 | Frictionless (no method URL) | AUTHENTICATION_SUCCESSFUL | 05 |
| 4222000005218627 | Frictionless | AUTHENTICATION_ATTEMPTED | 06 |
| 4222000002144131 | Frictionless | AUTHENTICATION_FAILED | 07 |
| 4222000007275799 | Frictionless | AUTHENTICATION_ISSUER_REJECTED | 07 |
| 4222000008880910 | Frictionless | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 07 |
| 4222000001227408 | Challenge | CHALLENGE_REQUIRED | -- |

### Mastercard

| Card Number | Flow | Result | ECI |
|-------------|------|--------|-----|
| 5354560000000004 | Frictionless | AUTHENTICATION_SUCCESSFUL | 02 |
| 5571596304025153 | Frictionless (no method URL) | AUTHENTICATION_SUCCESSFUL | 02 |
| 5580364874958322 | Frictionless | AUTHENTICATION_ATTEMPTED | 01 |
| 5540010585397800 | Frictionless | AUTHENTICATION_FAILED | 00 |
| 5588312194362669 | Frictionless | AUTHENTICATION_ISSUER_REJECTED | 00 |
| 5520680211891022 | Frictionless | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 00 |
| 5506874496684651 | Challenge | CHALLENGE_REQUIRED | -- |

### ECI Values

| Brand | Success | Attempted | Failed/Rejected/Unavailable |
|-------|---------|-----------|----------------------------|
| Visa | 05 | 06 | 07 |
| Mastercard | 02 | 01 | 00 |

## Test Results (2026-02-09)

All 14 test cards verified with **real API calls** against the GP sandbox.

**3DS2 Authentication: 14/14 PASS**

| Card Number | Brand | Expected | Actual | ECI | CAVV | Pass |
|-------------|-------|----------|--------|-----|------|------|
| 4222000006285344 | VISA | AUTHENTICATION_SUCCESSFUL | AUTHENTICATION_SUCCESSFUL | 05 | Yes | PASS |
| 4222000009719489 | VISA | AUTHENTICATION_SUCCESSFUL | AUTHENTICATION_SUCCESSFUL | 05 | Yes | PASS |
| 4222000005218627 | VISA | AUTHENTICATION_ATTEMPTED | AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL | 06 | Yes | PASS |
| 4222000002144131 | VISA | AUTHENTICATION_FAILED | AUTHENTICATION_FAILED | 07 | No | PASS |
| 4222000007275799 | VISA | AUTHENTICATION_ISSUER_REJECTED | AUTHENTICATION_ISSUER_REJECTED | 07 | No | PASS |
| 4222000008880910 | VISA | AUTHENTICATION_COULD_NOT_BE_PERFORMED | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 07 | No | PASS |
| 4222000001227408 | VISA | CHALLENGE_REQUIRED | CHALLENGE_REQUIRED | N/A | N/A | PASS |
| 5354560000000004 | MC | AUTHENTICATION_SUCCESSFUL | AUTHENTICATION_SUCCESSFUL | 02 | Yes | PASS |
| 5571596304025153 | MC | AUTHENTICATION_SUCCESSFUL | AUTHENTICATION_SUCCESSFUL | 02 | Yes | PASS |
| 5580364874958322 | MC | AUTHENTICATION_ATTEMPTED | AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL | 01 | Yes | PASS |
| 5540010585397800 | MC | AUTHENTICATION_FAILED | AUTHENTICATION_FAILED | 00 | No | PASS |
| 5588312194362669 | MC | AUTHENTICATION_ISSUER_REJECTED | AUTHENTICATION_ISSUER_REJECTED | 00 | No | PASS |
| 5520680211891022 | MC | AUTHENTICATION_COULD_NOT_BE_PERFORMED | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 00 | No | PASS |
| 5506874496684651 | MC | CHALLENGE_REQUIRED | CHALLENGE_REQUIRED | N/A | N/A | PASS |

**Payment Authorization:**

| Card | Brand | ECI | Result | Notes |
|------|-------|-----|--------|-------|
| 5354560000000004 | MC | 02 | AUTHORIZED (00) | Full end-to-end success |
| 4222000006285344 | Visa | 05 | 560 | Visa not permitted by sandbox merchant config |

The "radoslav/internet" sandbox account only supports Mastercard for payment authorization.

## Configuration

### `.env`

```
GPECOM_MERCHANT_ID=radoslav
GPECOM_SHARED_SECRET=cfJeww9HL2
GPECOM_ACCOUNT=internet
GPECOM_SANDBOX=true

# METHOD_NOTIFICATION_URL is optional (omit for no device fingerprinting)
# CHALLENGE_NOTIFICATION_URL is REQUIRED for initiate-auth
CHALLENGE_NOTIFICATION_URL=https://your-domain.com/php/webhooks/challenge-notification.php

DEBUG_MODE=true
```

For local development with device fingerprinting and challenge flows, use ngrok:
```bash
ngrok http 8080
# Then update .env with your ngrok URLs
```

## SDK Gotchas

These are important quirks discovered during development:

1. **`Secure3dService::execute()` returns `ThreeDSecure` directly** -- not a `Transaction`. Do not access `->threeDSecure` on the result.

2. **`maybeSetKey()` skips `null` but not empty strings** -- use `null` (not `''`) for optional config fields like `methodNotificationUrl`. An empty string causes a 400 error.

3. **SDK silently swallows `GatewayException`** for GpEcom 3DS2 when `responseCode != null` but provider is not `GpApiConnector` (`Secure3dBuilder.php` lines 574-588). Returns an empty `ThreeDSecure` object instead of throwing.

4. **`challengeNotificationUrl` is required** for initiate-auth. The API returns 400 without it.

5. **`methodNotificationUrl` is optional** and can be omitted by setting to `null`.

## Troubleshooting

**"Connection refused"** -- Start the server from the project root: `php -S localhost:8080`

**"Invalid Hash"** -- Verify `GPECOM_SHARED_SECRET` in `.env`. No extra whitespace.

**"Order ID Already Exists"** -- Each transaction needs a unique order ID.

**Challenge iframe not loading** -- Check browser console. Verify CORS and CSP headers.

**All enrollment fields null** -- You're likely accessing `$result->threeDSecure` instead of using `$result` directly. See SDK Gotcha #1 above.

**400 "methodNotificationUrl"** -- The URL is set to empty string instead of `null`. See SDK Gotcha #2.

**400 "challenge_notification_url"** -- This field is required. Set `CHALLENGE_NOTIFICATION_URL` in `.env`.

### Logs

```bash
tail -f php/logs/3ds2-api.log                  # API request/response log
tail -f php/logs/3ds2-errors.log               # Error log
tail -f php/logs/method-notifications.log       # Method URL callbacks
tail -f php/logs/challenge-notifications.log    # Challenge callbacks
```

## Security

- SHA1 hash authentication on all API requests
- Input validation (Luhn check, expiry, amount, currency whitelist)
- Security headers (CSP, X-Content-Type-Options, X-Frame-Options, Cache-Control: no-store)
- Card numbers masked in logs, CVV never logged
- CORS configured for API endpoints

For production: use HTTPS, implement session management with Redis/DB, add rate limiting, get production credentials from GP, set `GPECOM_SANDBOX=false`.

## Resources

- [GP 3DS2 Documentation](https://developer.globalpayments.com/ecommerce/risk-management/3ds/overview)
- [GP Test Card Numbers](https://developer.globalpayments.com/ecommerce/resources/test-card-numbers)
- [EMVCo 3DS Specification](https://www.emvco.com/emv-technologies/3d-secure/)
