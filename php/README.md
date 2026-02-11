# GPeCOM 3DS2 - PHP

3D Secure 2 implementation for Global Payments' XML (GPeCOM) API, built on top of the `globalpayments/php-sdk`.

## Setup

```bash
cd php && composer install && cd ..
php -S localhost:8080
```

Open http://localhost:8080/ in your browser. Sandbox credentials are already in `.env`.

## How it works

The SDK talks to two different GP APIs under the hood:

- 3DS2 enrollment and authentication go through a REST JSON API at `/3ds2/` (handled by `Gp3DSProvider`)
- Payment authorization goes through the XML API at `/epage-remote.cgi` (handled by `GpEcomConnector`)

The flow looks like this:

```
Browser                    PHP Server                  GP API                  ACS (Issuer)
  |                           |                          |                        |
  |  1. Card details          |                          |                        |
  |-------------------------->|  2. Check enrollment     |                        |
  |                           |------------------------->|                        |
  |                           |<-------------------------|                        |
  |  3. Method URL iframe     |                          |                        |
  |<--------------------------|                          |   4. Fingerprint       |
  |----------------------------------------------------------------------->----->|
  |                           |<--------------------------------------------------|
  |  5. Initiate auth         |  6. Authenticate         |                        |
  |-------------------------->|------------------------->|  7. Risk check         |
  |                           |                          |----------------------->|
  |                           |<-------------------------|<-----------------------|
  |  8. Result or challenge   |                          |                        |
  |<--------------------------|                          |                        |
  |                           |                          |                        |
  |  [If challenge: show ACS iframe, user completes it, then verify]              |
  |                           |                          |                        |
  |  9. Authorize payment     |  10. XML auth            |                        |
  |-------------------------->|------------------------->|                        |
  |                           |<-------------------------|                        |
  |  11. Done                 |                          |                        |
  |<--------------------------|                          |                        |
```

## Files

```
php/
  src/
    GpecomClient.php            SDK wrapper (enrollment, auth, payment)
    ApiResponse.php             JSON response formatting
    SecurityHeaders.php         Validation, headers, logging
  api/
    check-enrollment.php        Step 1 - is this card enrolled?
    initiate-auth.php           Step 3 - authenticate (frictionless or challenge)
    verify-auth.php             Step 4 - get result after challenge
    authorize-payment.php       Step 5 - charge the card
    hpp-request.php             HPP request builder
    hpp-response.php            HPP response handler
  webhooks/
    method-notification.php     ACS device fingerprint callback
    challenge-notification.php  ACS challenge completion callback
  logs/                         Runtime logs
  temp/                         Session data
  .env                          Credentials
```

## API

All endpoints accept POST with JSON body. Pass `"demo_mode": true` to get simulated responses without hitting the GP API.

### `POST /php/api/check-enrollment.php`

```json
{
  "card_number": "4222000006285344",
  "exp_date": "1228",
  "card_holder": "John Doe",
  "order_id": "order-123456"
}
```

Returns `enrolled: "Y"` or `"N"`, plus `server_trans_id`, `method_url`, and ACS version info.

### `POST /php/api/initiate-auth.php`

```json
{
  "card_number": "4222000006285344",
  "amount": "1999",
  "currency": "EUR",
  "exp_date": "1228",
  "card_holder": "John Doe",
  "order_id": "order-123456",
  "server_trans_id": "from-enrollment-response",
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

Returns either frictionless auth data (`eci`, `cavv`, `ds_trans_id`) or a challenge redirect (`acs_url`, `creq`).

### `POST /php/api/verify-auth.php`

```json
{ "server_trans_id": "from-enrollment-response" }
```

Call this after the user completes a challenge. Returns the same auth data shape as frictionless.

### `POST /php/api/authorize-payment.php`

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

## Test cards

Message Version 2.2 cards. Use any future expiry (e.g. `1228`) and any CVV.

### Visa

| Card Number      | Flow         | Result                                | ECI |
|------------------|--------------|---------------------------------------|-----|
| 4222000006285344 | Frictionless | AUTHENTICATION_SUCCESSFUL             | 05  |
| 4222000009719489 | Frictionless | AUTHENTICATION_SUCCESSFUL (no method) | 05  |
| 4222000005218627 | Frictionless | AUTHENTICATION_ATTEMPTED              | 06  |
| 4222000002144131 | Frictionless | AUTHENTICATION_FAILED                 | 07  |
| 4222000007275799 | Frictionless | AUTHENTICATION_ISSUER_REJECTED        | 07  |
| 4222000008880910 | Frictionless | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 07  |
| 4222000001227408 | Challenge    | CHALLENGE_REQUIRED                    | --  |

### Mastercard

| Card Number      | Flow         | Result                                | ECI |
|------------------|--------------|---------------------------------------|-----|
| 5354560000000004 | Frictionless | AUTHENTICATION_SUCCESSFUL             | 02  |
| 5571596304025153 | Frictionless | AUTHENTICATION_SUCCESSFUL (no method) | 02  |
| 5580364874958322 | Frictionless | AUTHENTICATION_ATTEMPTED              | 01  |
| 5540010585397800 | Frictionless | AUTHENTICATION_FAILED                 | 00  |
| 5588312194362669 | Frictionless | AUTHENTICATION_ISSUER_REJECTED        | 00  |
| 5520680211891022 | Frictionless | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 00  |
| 5506874496684651 | Challenge    | CHALLENGE_REQUIRED                    | --  |

ECI reference: Visa success=05, attempted=06, failed=07. Mastercard success=02, attempted=01, failed=00.

## Sandbox test results (2026-02-09)

All 14 cards tested with real API calls against `api.sandbox.globalpay-ecommerce.com`. 14/14 pass.

| Card             | Brand | Actual Status                         | ECI | CAVV |
|------------------|-------|---------------------------------------|-----|------|
| 4222000006285344 | VISA  | AUTHENTICATION_SUCCESSFUL             | 05  | Yes  |
| 4222000009719489 | VISA  | AUTHENTICATION_SUCCESSFUL             | 05  | Yes  |
| 4222000005218627 | VISA  | AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL | 06  | Yes  |
| 4222000002144131 | VISA  | AUTHENTICATION_FAILED                 | 07  | No   |
| 4222000007275799 | VISA  | AUTHENTICATION_ISSUER_REJECTED        | 07  | No   |
| 4222000008880910 | VISA  | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 07  | No   |
| 4222000001227408 | VISA  | CHALLENGE_REQUIRED                    | N/A | N/A  |
| 5354560000000004 | MC    | AUTHENTICATION_SUCCESSFUL             | 02  | Yes  |
| 5571596304025153 | MC    | AUTHENTICATION_SUCCESSFUL             | 02  | Yes  |
| 5580364874958322 | MC    | AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL | 01  | Yes  |
| 5540010585397800 | MC    | AUTHENTICATION_FAILED                 | 00  | No   |
| 5588312194362669 | MC    | AUTHENTICATION_ISSUER_REJECTED        | 00  | No   |
| 5520680211891022 | MC    | AUTHENTICATION_COULD_NOT_BE_PERFORMED | 00  | No   |
| 5506874496684651 | MC    | CHALLENGE_REQUIRED                    | N/A | N/A  |

Payment authorization was also tested. MC card `5354560000000004` went through end-to-end (auth code 00, authorized). Visa cards get response 560 because the `radoslav/internet` sandbox account only has Mastercard enabled for payment processing.

## Configuration

`.env` contents:

```
GPECOM_MERCHANT_ID=radoslav
GPECOM_SHARED_SECRET=cfJeww9HL2
GPECOM_ACCOUNT=internet
GPECOM_SANDBOX=true

# Optional - omit to skip device fingerprinting
# METHOD_NOTIFICATION_URL=https://your-domain.com/php/webhooks/method-notification.php

# Required for initiate-auth (API rejects the request without it)
CHALLENGE_NOTIFICATION_URL=https://developer.globalpayments.com/3ds2/challenge-notification

DEBUG_MODE=true
```

For local development with working webhooks, run `ngrok http 8080` and put your ngrok URLs in `.env`.

## SDK gotchas

Things that bit us during development:

1. `Secure3dService::execute()` returns a `ThreeDSecure` object directly, not a `Transaction`. Don't do `$result->threeDSecure` -- `$result` already IS the ThreeDSecure.

2. The SDK's `maybeSetKey()` skips `null` but passes through empty strings. If you set `methodNotificationUrl` to `''` instead of `null`, the API gets an empty string and returns 400. Always use `null` for optional URL fields.

3. The SDK catches `GatewayException` internally for GpEcom 3DS2 and doesn't rethrow it when `responseCode != null` and the provider isn't `GpApiConnector` (see `Secure3dBuilder.php:574-588`). You get back a blank `ThreeDSecure` object with no indication anything went wrong.

4. `challengeNotificationUrl` is required in the config for initiate-auth. The API returns 400 without it, even for cards that end up going frictionless.

5. `methodNotificationUrl` is optional. Set it to `null` if you don't need device fingerprinting.

## Troubleshooting

`Connection refused` -- Run the PHP server from the project root, not from `php/`.

`Invalid Hash` -- Check `GPECOM_SHARED_SECRET` in `.env`. Watch for trailing whitespace.

`Order ID Already Exists` -- Every transaction needs a unique order ID.

`Challenge iframe won't load` -- Look at the browser console. Usually a CSP or CORS issue.

`Enrollment returns all nulls` -- You're probably hitting gotcha #1 above.

`400 methodNotificationUrl` -- Gotcha #2. Use `null`, not `''`.

`400 challenge_notification_url` -- Set `CHALLENGE_NOTIFICATION_URL` in `.env`.

### Log files

```bash
tail -f php/logs/3ds2-api.log                  # request/response pairs
tail -f php/logs/3ds2-errors.log               # errors
tail -f php/logs/method-notifications.log       # method URL callbacks
tail -f php/logs/challenge-notifications.log    # challenge callbacks
```

## Security notes

Input validation includes Luhn check, expiry validation, amount bounds, and a currency whitelist. Card numbers are masked in logs. CVV is never logged. Security headers are set on all responses (CSP, X-Content-Type-Options, X-Frame-Options, no-store cache).

For production: switch to HTTPS, use Redis or a database for session state, add rate limiting, swap in production credentials, set `GPECOM_SANDBOX=false`.

## Links

- [GP 3DS2 docs](https://developer.globalpayments.com/ecommerce/risk-management/3ds/overview)
- [GP test card numbers](https://developer.globalpayments.com/ecommerce/resources/test-card-numbers)
- [EMVCo 3DS spec](https://www.emvco.com/emv-technologies/3d-secure/)
