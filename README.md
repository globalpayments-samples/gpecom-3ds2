# GPeCOM 3D Secure 2 Testing Environment

A multi-language implementation of 3D Secure 2 (3DS2) authentication using Global Payments' GPeCOM XML API. One shared frontend, four independent backends — PHP, Node.js, .NET, and Java — each implementing the full 3DS2 flow against the GP sandbox.

## 3DS2 Flow

```
Card Entry → Enrollment Check → Method URL → Authentication → Challenge (if needed) → Payment
```

1. **Enrollment** — Check if the card is enrolled in 3DS2 (`POST /3ds2/protocol-versions`)
2. **Method URL** — Device fingerprinting via hidden iframe (optional, card-dependent)
3. **Authentication** — Initiate 3DS2 auth (`POST /3ds2/authentications`)
4. **Challenge** — Cardholder verification if required by issuer (ACS iframe)
5. **Payment** — Authorize payment with 3DS2 data (`POST /epage-remote.cgi`)

## Project Structure

```
├── index.html              # Shared frontend (backend selector, test cards, 3DS2 form)
├── test-all-cards.sh       # Automated 14-card test script
│
├── php/                    # PHP — custom REST/XML client (port 8080)
│   ├── src/GpecomClient.php    # Core 3DS2 + payment client
│   ├── api/                    # Endpoint handlers (check-enrollment, initiate-auth, etc.)
│   └── webhooks/               # Method + challenge notification handlers
│
├── nodejs/                 # Node.js — Express.js + direct REST API (port 3001)
│   └── server.js               # All routes in single file
│
├── dotnet/                 # .NET — ASP.NET Core minimal API + SDK (port 3002)
│   └── Program.cs               # All routes in single file
│
├── java/                   # Java — Jakarta servlet + SDK on embedded Tomcat (port 3003)
│   └── src/.../GpecomServlet.java  # All routes in single servlet
│
└── python/                 # Python — original template placeholder (not 3DS2)
```

## SDK Usage

| Language | SDK | Version | 3DS2 Approach |
|----------|-----|---------|---------------|
| PHP | None (custom client) | — | Direct REST + XML API calls |
| Node.js | `globalpayments-api` | 3.10.6 | Direct REST for 3DS2, SDK for payment auth |
| .NET | `GlobalPayments.Api` | 9.0.16 | Native `Secure3dService` |
| Java | `globalpayments-sdk` | 14.2.20 | Native `Secure3dService` |

**Why PHP and Node.js don't use the SDK for 3DS2:** The PHP SDK's `Gp3DSProvider` exists but silently swallows errors. The Node.js SDK doesn't implement `processSecure3d()` at all. Both use direct HTTP calls to the GP 3DS2 REST API instead.

## Quick Start

### 1. Configure credentials

Copy `.env.example` to `.env` in the backend directory you want to run:

```sh
cp php/.env.example php/.env
# Then edit php/.env with your GP sandbox credentials
```

Required variables:
```
GPECOM_MERCHANT_ID=your_merchant_id
GPECOM_SHARED_SECRET=your_shared_secret
GPECOM_ACCOUNT=internet
METHOD_NOTIFICATION_URL=https://developer.globalpayments.com/3ds2/method-notification
CHALLENGE_NOTIFICATION_URL=https://developer.globalpayments.com/3ds2/challenge-notification
```

### 2. Start a backend

**PHP** (port 8080):
```sh
cd php && composer install && php -S localhost:8080 -t ..
```

**Node.js** (port 3001):
```sh
cd nodejs && npm install && node server.js
```

**.NET** (port 3002):
```sh
cd dotnet && dotnet run
```

**Java** (port 3003):
```sh
cd java && mvn clean package cargo:run
```

### 3. Open the UI

Navigate to `http://localhost:<port>`. The frontend auto-detects which backend you're using based on the port.

## API Endpoints

All four backends expose the same API contract:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/check-enrollment` | POST | Check card 3DS2 enrollment |
| `/api/initiate-auth` | POST | Initiate 3DS2 authentication |
| `/api/verify-auth` | POST | Verify challenge result |
| `/api/authorize-payment` | POST | Authorize payment with 3DS2 data |

PHP uses `/php/api/<endpoint>.php` paths. The frontend handles this automatically.

## Test Cards

14 test cards covering all 3DS2 outcomes (Message Version 2.2):

| Outcome | Visa (ECI) | Mastercard (ECI) |
|---------|-----------|-----------------|
| Frictionless Success | 4222000006285344 (05) | 5354560000000004 (02) |
| Success, no Method URL | 4222000009719489 (05) | 5571596304025153 (02) |
| Attempted | 4222000005218627 (06) | 5580364874958322 (01) |
| Authentication Failed | 4222000002144131 (07) | 5540010585397800 (00) |
| Issuer Rejected | 4222000007275799 (07) | 5588312194362669 (00) |
| Could Not Be Performed | 4222000008880910 (07) | 5520680211891022 (00) |
| Challenge Required | 4222000001227408 (—) | 5506874496684651 (—) |

Use any future expiry (e.g. `1228`) and any CVV (e.g. `123`).

## Automated Testing

Run the 14-card test against any backend:

```sh
./test-all-cards.sh 8080 php      # PHP
./test-all-cards.sh 3001 nodejs   # Node.js
./test-all-cards.sh 3002 dotnet   # .NET
./test-all-cards.sh 3003 java     # Java
```

Each test performs real API calls to the GP sandbox (enrollment + authentication for all 14 cards) and validates the expected status and ECI values.

## GP API Reference

- **3DS2 REST API**: `https://api.sandbox.globalpay-ecommerce.com/3ds2/`
  - `POST protocol-versions` — enrollment check
  - `POST authentications` — initiate authentication
  - `GET authentications/{id}` — get authentication result
- **Payment XML API**: `https://api.sandbox.globalpay-ecommerce.com/epage-remote.cgi`
- **Authentication**: `Authorization: securehash <sha1(sha1(timestamp.merchantid.orderid.amount.currency.cardnumber) + "." + secret)>`
- **3DS2 Header**: `X-GP-Version: 2.2.0`
- **Docs**: [developer.globalpay.com/ecommerce/3d-secure-two](https://developer.globalpay.com/ecommerce/3d-secure-two)
