# GPeCOM 3DS2 Testing Environment

> Run the GPeCOM 3DS2 enrollment → authentication → challenge → authorization flow through one shared browser UI and four backend implementations: PHP, Node.js, .NET, and Java.

## Critical Patterns

1. **Treat the root `index.html` and the PHP/Node.js/.NET/Java code as the source of truth.** The shared UI only targets those four backends in `getApiUrl()`. `python/server.py` and `docker-compose.yml` still contain starter-template `/config` flows and Portico-era wiring that do not match the live 3DS2 app.
2. **Do not send empty notification URLs.** PHP, .NET, and Java convert blank `METHOD_NOTIFICATION_URL` / `CHALLENGE_NOTIFICATION_URL` values to `null`, and Node.js only adds those fields when configured. Empty strings produce bad 3DS2 payloads; real challenge testing still needs a public HTTPS `CHALLENGE_NOTIFICATION_URL`.
3. **Use Node.js as the wire-format reference for direct 3DS2 calls.** `nodejs/server.js` builds the REST requests in `gp3dsRequest()`: `Authorization: securehash ...`, amount in minor units, numeric country codes such as `826`, and `method_url_completion` as `YES` or `NO`. Small shape mistakes here cause opaque GP API 400s.
4. **Only authorize after an authenticated or attempted result.** PHP, Node.js, .NET, and Java all continue to payment only when the ECI is `05`, `06`, `02`, or `01`; failed statuses are rejected before the `charge()` call.

## Repository Structure

### PHP (plain PHP + `globalpayments/php-sdk`)
- [`php/src/GpecomClient.php`](php/src/GpecomClient.php) — PHP reference implementation; `check3DS2Enrollment()`, `initiate3DS2Authentication()`, `verify3DS2Authentication()`, `authorizePayment()`, and the `simulate*()` helpers contain the core flow.
- [`php/src/SecurityHeaders.php`](php/src/SecurityHeaders.php) — shared request validation, CORS, CSP, JSON parsing, and logging helpers for the file-based PHP endpoints.
- [`php/api/check-enrollment.php`](php/api/check-enrollment.php), [`php/api/initiate-auth.php`](php/api/initiate-auth.php), [`php/api/verify-auth.php`](php/api/verify-auth.php), [`php/api/authorize-payment.php`](php/api/authorize-payment.php) — anonymous POST entry points that validate input, call `GpecomClient`, and return JSON.
- [`php/webhooks/method-notification.php`](php/webhooks/method-notification.php) / [`php/webhooks/challenge-notification.php`](php/webhooks/challenge-notification.php) — ACS callback handlers; PHP is the only backend that also persists webhook state under [`php/temp/`](php/temp/).
- [`php/api/hpp-request.php`](php/api/hpp-request.php) / [`php/api/hpp-response.php`](php/api/hpp-response.php) — PHP-only hosted payment page helpers; not used by the shared 3DS2 UI.

### Node.js (Express + `globalpayments-api` + direct 3DS2 REST)
- [`nodejs/server.js`](nodejs/server.js) — single-file Node.js backend; `gp3dsRequest()`, `generateHash()`, `buildCard()`, the `simulate*()` helpers, and the anonymous `app.post()` handlers implement everything.
- [`nodejs/.env.example`](nodejs/.env.example) — current 3DS2 env template for Node.js; `.env.sample` is still the older starter-template file.
- [`nodejs/logs/`](nodejs/logs/) — file-based API and webhook logs; there is no separate storage layer.

### .NET (ASP.NET Core minimal API + `GlobalPayments.Api`)
- [`dotnet/Program.cs`](dotnet/Program.cs) — .NET reference implementation; `ConfigureApiEndpoints()`, `BuildCard()`, `BuildBrowserData()`, `ReadJson()`, and the inline `MapPost()` handlers cover the full flow.
- [`dotnet/.env.example`](dotnet/.env.example) — current 3DS2 env template; `.env.sample` is still the older starter-template file.
- [`dotnet/wwwroot/`](dotnet/wwwroot/) — static assets directory; `/` is served from the repo-root `index.html`, not `wwwroot/index.html`.

### Java (Jakarta Servlet + `globalpayments-sdk`)
- [`java/src/main/java/com/globalpayments/example/GpecomServlet.java`](java/src/main/java/com/globalpayments/example/GpecomServlet.java) — single-servlet Java backend; `handleCheckEnrollment()`, `handleInitiateAuth()`, `handleVerifyAuth()`, `handleAuthorizePayment()`, `handleMethodNotification()`, and `handleChallengeNotification()` mirror the .NET flow.
- [`java/pom.xml`](java/pom.xml) — embedded Tomcat/Cargo configuration; this fixes the default Java port to `3003`.
- [`java/.env.example`](java/.env.example) — current 3DS2 env template; `.env.sample` is still the older starter-template file.

### Python (placeholder, not part of the shared 3DS2 app)
- [`python/server.py`](python/server.py) — Flask Portico starter sample with `/config` and `/process-payment`; it is not wired into the shared 3DS2 frontend.
- [`python/.env.sample`](python/.env.sample) — placeholder Portico credentials file.

### Shared
- [`index.html`](index.html) — shared frontend entry point; `getApiUrl()`, `checkEnrollment()`, `initiateAuthentication()`, `handleChallenge()`, `verifyAuthentication()`, and `authorizePayment()` define the browser contract.
- [`test-all-cards.sh`](test-all-cards.sh) — smoke test for the 14 official 3DS2 cards against any backend.
- [`docker-compose.yml`](docker-compose.yml) — multi-service stack, but still wired for starter-template `/config` health checks and a missing `go/` service; useful context, not the canonical API map.

## API Surface

PHP diverges by keeping file-based paths. In the shared repo-root frontend those routes are `/php/...*.php`; when you run `cd php && ./run.sh` directly, the same handlers are served from `/api/*.php` and `/webhooks/*.php`.

| Method | PHP path from repo root | Node.js / .NET / Java path | Purpose |
|--------|--------------------------|-----------------------------|---------|
| POST | `/php/api/check-enrollment.php` | `/api/check-enrollment` | Check 3DS2 enrollment and return `server_trans_id`, `method_url`, and ACS version data. |
| POST | `/php/api/initiate-auth.php` | `/api/initiate-auth` | Start authentication and return either frictionless `auth_data` or challenge payload (`acs_url`, `creq`). |
| POST | `/php/api/verify-auth.php` | `/api/verify-auth` | Fetch the final auth result after a challenge using `server_trans_id`. |
| POST | `/php/api/authorize-payment.php` | `/api/authorize-payment` | Authorize the payment with the returned 3DS data. |
| POST | `/php/webhooks/method-notification.php` | `/webhooks/method-notification` | Receive the ACS device-fingerprinting callback and notify the parent frame. |
| POST | `/php/webhooks/challenge-notification.php` | `/webhooks/challenge-notification` | Receive `cres` after challenge completion and notify the parent frame. |

PHP-only extras:

| Method | Path | Purpose |
|--------|------|---------|
| GET or POST | `/php/api/hpp-request.php` | Build hosted payment page request JSON for the PHP-only HPP flow. |
| GET or POST | `/php/api/hpp-response.php` | Parse the hosted payment page response after the HPP redirect or iframe return. |

Legacy placeholder endpoints (not part of the shared 3DS2 UI):

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/php/config.php` | Return a Portico public key from the leftover PHP starter template. |
| POST | `/php/process-payment.php` | Run the leftover PHP Portico token payment flow. |
| GET | `/config` | Return a Portico public key from `python/server.py`. |
| POST | `/process-payment` | Run the placeholder Python Portico token payment flow. |

## Environment Variables

The live 3DS2 backends use `.env.example` in `php/`, `nodejs/`, `dotnet/`, and `java/`. The checked-in `.env.sample` files are older starter-template leftovers, and `python/.env.sample` belongs to the placeholder Portico sample.

```bash
GPECOM_MERCHANT_ID=your_merchant_id          # Required by PHP/Node.js/.NET/Java
GPECOM_SHARED_SECRET=your_shared_secret      # Required securehash / GpEcomConfig secret
GPECOM_ACCOUNT=internet                      # Defaults to internet when omitted
GPECOM_SANDBOX=true                          # Node.js switches sandbox vs production 3DS2 REST base URL
METHOD_NOTIFICATION_URL=https://...          # Optional; needed for real device fingerprint callbacks
CHALLENGE_NOTIFICATION_URL=https://...       # Required for real challenge-card testing
DEBUG_MODE=true                              # Enables request/response logging where implemented
PORT=3001                                    # Node.js default; .NET 3002, Java 3003, PHP run.sh 8000
```

Code-only extras that are read but not present in the checked-in 3DS2 templates:

- `MERCHANT_CONTACT_URL` — optional in Node.js, PHP, .NET, and Java; defaults to `https://developer.globalpayments.com`
- `APP_BASE_URL` — PHP HPP helper only; used by `php/api/hpp-request.php` to build the HPP response URL

Legacy placeholder variables still appear outside the live 3DS2 flow:

- `PUBLIC_API_KEY` / `SECRET_API_KEY` — used by `python/server.py` and the leftover PHP starter-template files [`php/config.php`](php/config.php) and [`php/process-payment.php`](php/process-payment.php)
- `GPECOM_REFUND_PASSWORD` — present in `php/.env.sample`, but only checked by the legacy `verify-setup.php` script

## Test Cards / Sandbox Credentials

This repo is GPeCOM, not GP-API or Portico: PHP, .NET, and Java configure `GpEcomConfig`, and Node.js signs GPeCOM REST/XML requests directly. Use GPeCOM sandbox merchant credentials from the Global Payments developer portal: <https://developer.globalpayments.com/>.

| Outcome | Visa | Mastercard |
|--------|------|------------|
| Frictionless success | `4222000006285344` | `5354560000000004` |
| Frictionless success (no Method URL) | `4222000009719489` | `5571596304025153` |
| Attempted | `4222000005218627` | `5580364874958322` |
| Authentication failed | `4222000002144131` | `5540010585397800` |
| Issuer rejected | `4222000007275799` | `5588312194362669` |
| Could not be performed | `4222000008880910` | `5520680211891022` |
| Challenge required | `4222000001227408` | `5506874496684651` |

Use any future expiry such as `1228` and any CVV such as `123`.

## API Request Shape

Only Node.js builds the 3DS2 REST payload directly:

- Base URL: `https://api.sandbox.globalpay-ecommerce.com/3ds2/` when `GPECOM_SANDBOX=true`, otherwise `https://api.globalpay-ecommerce.com/3ds2/`.
- Headers: `X-GP-Version: 2.2.0`, `Content-Type: application/json`, `Authorization: securehash <hash>`.
- Securehash inputs: enrollment = `timestamp.merchantId.cardNumber`; authentication = `timestamp.merchantId.cardNumber.serverTransId`; verify = `timestamp.merchantId.serverTransId`.
- `order.amount` stays in minor units as a zero-padded string, `country` values are numeric codes such as `826`, and `method_url_completion` must be `YES` or `NO`.
- `challenge_notification_url` is only added when configured; `merchant_contact_url` defaults to `https://developer.globalpayments.com`.

## Architecture Summary

**Browser flow:** `index.html` → check enrollment → optional method iframe → initiate authentication → optional ACS challenge iframe → verify auth → authorize payment.

**SDK flow:** PHP, .NET, and Java build `ThreeDSecure` state in the SDK, call `Secure3dService`, then attach the returned values to `card.charge()` for authorization.

**Direct-call flow:** Node.js signs REST requests to `/3ds2/` for enrollment/auth/verify, then switches back to the SDK `GpEcomConfig` client for the XML authorization step.

## Security Notes

This is demo code: there is no application auth, no rate limiting, and webhook completion is trusted through browser `postMessage` flows. Request and response data is logged under each language's `logs/` directory, and PHP additionally persists method-completion state under `php/temp/`. Use real HTTPS callback URLs, tighten CORS, and reduce logging before adapting this to production.

## How to Run

```bash
cd nodejs && ./run.sh    # Node.js — :3001, serves the repo-root index.html at /
cd dotnet && ./run.sh    # .NET — :3002, serves the repo-root index.html at /
cd java && ./run.sh      # Java — :3003, serves the repo-root index.html at /
cd php && ./run.sh       # PHP — :8000, serves php/ directly; API is /api/*.php
```

If you want the shared repo-root frontend while testing PHP locally, serve the repo root separately (for example `python3 -m http.server 8080`) and point it at the PHP backend. `docker compose up` exists, but the compose file still reflects starter-template `/config` health checks and a missing Go service, so prefer the per-language `run.sh` scripts.

Real challenge cards require a browser because the ACS challenge runs inside an iframe. Curl can exercise the enrollment/auth/payment endpoints, but it cannot complete the issuer challenge UI. The webhook endpoints are also browser/ACS-driven: call them only if you are simulating ACS posts yourself.

## How to Verify

```bash
BASE=http://localhost:3001   # Use 3002 for .NET, 3003 for Java; for PHP use http://localhost:8000 and /api/*.php paths

curl -X POST "$BASE/api/check-enrollment" -H "Content-Type: application/json" -d '{"card_number":"4222000006285344","exp_date":"1228","card_holder":"Test Customer","order_id":"demo-1","demo_mode":true}'
# Expected: {"success":true,"data":{"server_trans_id":"...","enrolled":"Y",...}}

curl -X POST "$BASE/api/initiate-auth" -H "Content-Type: application/json" -d '{"card_number":"4222000006285344","amount":"1999","currency":"EUR","server_trans_id":"demo-1","method_url_complete":"true","browser_data":{"color_depth":"24","javascript_enabled":"true","java_enabled":"false","language":"en-US","screen_height":"1080","screen_width":"1920","timezone":"0","user_agent":"Mozilla/5.0","challenge_window_size":"05"},"demo_mode":true}'
# Expected: {"success":true,"data":{"challenge_required":false,"auth_data":{"eci":"05",...}}}

curl -X POST "$BASE/api/verify-auth" -H "Content-Type: application/json" -d '{"server_trans_id":"demo-1","demo_mode":true}'
# Expected: {"success":true,"data":{"authenticated":true,"auth_data":{"eci":"05",...}}}

curl -X POST "$BASE/api/authorize-payment" -H "Content-Type: application/json" -d '{"card_number":"4222000006285344","amount":"1999","currency":"EUR","exp_date":"1228","card_holder":"Test Customer","cvv":"123","auth_data":{"eci":"05","cavv":"demo","authentication_value":"demo","ds_trans_id":"demo","message_version":"2.2.0"},"demo_mode":true}'
# Expected: {"success":true,"data":{"authorized":true,"response_code":"00",...}}
```

For PHP, replace `/api/<endpoint>` with `/api/<endpoint>.php` when running `php/run.sh`, or `/php/api/<endpoint>.php` when the repo-root frontend is serving the app. The webhook routes follow the same pattern: `/webhooks/method-notification.php` and `/webhooks/challenge-notification.php`.

`php/api/hpp-request.php` is a PHP-only HPP helper; `php/api/hpp-response.php` plus the webhook endpoints are callback targets from the hosted payment page or ACS and are best verified through a real browser flow. The legacy `/php/config.php`, `/php/process-payment.php`, `/config`, and `/process-payment` endpoints belong to leftover starter templates and are outside the supported 3DS2 smoke test.

## Making Changes

All four live implementations must keep the same enrollment/auth/verify/authorize behavior. Apply backend logic changes to PHP, Node.js, .NET, and Java in separate commits; do not treat Python as a peer implementation unless the repo is explicitly expanded. Shared files — [`index.html`](index.html), [`test-all-cards.sh`](test-all-cards.sh), [`docker-compose.yml`](docker-compose.yml), and the per-language `.env.example` / `.env.sample` files — affect multiple backends and should not be changed in isolation.

## SDK Versions

- PHP: `globalpayments/php-sdk` `13.3.0` (from `php/composer.lock`; not declared in `php/composer.json`)
- Node.js: `globalpayments-api` `^3.10.6` (`3.10.10` locked in `nodejs/package-lock.json`)
- .NET: `GlobalPayments.Api` `9.0.16`
- Java: `globalpayments-sdk` `14.2.20`
- Python placeholder: `globalpayments.api` `2.0.4`
