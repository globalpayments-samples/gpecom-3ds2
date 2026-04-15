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

## Features

- **Full 3DS2 Flow** — Enrollment check, device fingerprinting, authentication, challenge, and payment authorization
- **Mixed SDK/Direct API** — .NET and Java use native SDK `Secure3dService`; PHP and Node.js use direct REST + XML calls
- **14 Test Cards** — Covers all 3DS2 outcomes (frictionless, challenge, attempted, failed, rejected)
- **Shared Frontend** — One HTML UI drives all four backends with backend selector dropdown
- **Automated Testing** — Shell script tests all 14 cards against any backend
- **Docker Compose** — Run all services simultaneously with a single command

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

### Option A — Docker (recommended)

The easiest way to run the project. Docker Compose starts the frontend and all backend services with a single command.

**Prerequisites:** [Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Compose)

#### 1. Configure credentials

Copy the `.env.example` file for each backend you want to run and fill in your GP sandbox credentials:

```sh
cp php/.env.example php/.env
cp nodejs/.env.example nodejs/.env
cp dotnet/.env.example dotnet/.env
cp java/.env.example java/.env
```

Edit each `.env` file with your credentials:

```
GPECOM_MERCHANT_ID=your_merchant_id
GPECOM_SHARED_SECRET=your_shared_secret
GPECOM_ACCOUNT=internet
METHOD_NOTIFICATION_URL=https://developer.globalpayments.com/3ds2/method-notification
CHALLENGE_NOTIFICATION_URL=https://developer.globalpayments.com/3ds2/challenge-notification
```

#### 2. Start the services

Run all backends at once:

```sh
docker compose up --build
```

Or start only the frontend and a specific backend (e.g. PHP):

```sh
docker compose up --build frontend php
```

#### 3. Open the UI

Navigate to **`http://localhost:8000`** and select a backend from the dropdown.

#### Port reference

| Service  | URL                        |
|----------|----------------------------|
| Frontend | http://localhost:8000      |
| Node.js  | http://localhost:8001      |
| Python   | http://localhost:8002      |
| PHP      | http://localhost:8003      |
| Java     | http://localhost:8004      |
| Go       | http://localhost:8005      |
| .NET     | http://localhost:8006      |

#### Useful commands

```sh
# View logs for a specific service
docker compose logs -f php

# Stop all services
docker compose down

# Rebuild after code changes
docker compose up --build

# Run automated tests (requires all services healthy)
docker compose --profile testing up --build
```

---

### Option B — Run locally without Docker

**Prerequisites:** Install the runtime for the backend you want to use (PHP + Composer, Node.js, .NET SDK, or Java + Maven).

#### 1. Configure credentials

Copy `.env.example` to `.env` in the backend directory and fill in your credentials:

```sh
cp php/.env.example php/.env
```

#### 2. Start a backend

**PHP:**
```sh
cd php && composer install && php -S localhost:8080
```

**Node.js:**
```sh
cd nodejs && npm install && node server.js
```

**.NET:**
```sh
cd dotnet && dotnet run
```

**Java:**
```sh
cd java && mvn clean package cargo:run
```

#### 3. Serve the frontend

In a separate terminal from the project root:

```sh
python3 -m http.server 8000
```

Then open **`http://localhost:8000`** and select your backend.

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

## Security Considerations

- **HMAC Authentication** — Each request signed with SHA-1 HMAC using Merchant ID + Shared Secret
- **3DS2 Compliance** — Implements full EMV 3-D Secure 2.2 protocol for SCA compliance
- **No Card Storage** — Card data used only for the current transaction flow
- **Credential Isolation** — Store `SHARED_SECRET` in `.env` files, never commit to version control
- **HTTPS Required** — Always use TLS in production; GP sandbox API enforces HTTPS
- **Challenge Iframe** — Issuer challenges run in an isolated iframe provided by the ACS

## GP API Reference

- **3DS2 REST API**: `https://api.sandbox.globalpay-ecommerce.com/3ds2/`
  - `POST protocol-versions` — enrollment check
  - `POST authentications` — initiate authentication
  - `GET authentications/{id}` — get authentication result
- **Payment XML API**: `https://api.sandbox.globalpay-ecommerce.com/epage-remote.cgi`
- **Authentication**: `Authorization: securehash <sha1(sha1(timestamp.merchantid.orderid.amount.currency.cardnumber) + "." + secret)>`
- **3DS2 Header**: `X-GP-Version: 2.2.0`
- **Docs**: [developer.globalpayments.com/ecommerce/3d-secure-two](https://developer.globalpayments.com/ecommerce/3d-secure-two)

## Resources

- [Global Payments Developer Portal](https://developer.globalpayments.com/)
- [API Reference](https://developer.globalpayments.com/api/references-overview)
- [Node.js SDK](https://github.com/globalpayments/node-sdk)
- [Java SDK](https://github.com/globalpayments/java-sdk)
- [.NET SDK](https://github.com/globalpayments/dotnet-sdk)

## Community

- 🌐 **Developer Portal** — [developer.globalpayments.com](https://developer.globalpayments.com)
- 💬 **Discord** — [Join the community](https://discord.gg/myER9G9qkc)
- 📋 **GitHub Discussions** — [github.com/orgs/globalpayments/discussions](https://github.com/orgs/globalpayments/discussions)
- 📧 **Newsletter** — [Subscribe](https://www.globalpayments.com/en-gb/modals/newsletter)
- 💼 **LinkedIn** — [Global Payments for Developers](https://www.linkedin.com/showcase/global-payments-for-developers/posts/?feedView=all)

Have a question or found a bug? [Open an issue](https://github.com/globalpayments-samples/gpecom-3ds2/issues) or reach out at [communityexperience@globalpay.com](mailto:communityexperience@globalpay.com).

## License

MIT
