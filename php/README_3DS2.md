# Global Payments eCOM (GPeCOM) 3DS2 Testing Environment

Complete 3D Secure 2 authentication testing environment for Global Payments' XML (GPeCOM) product suite.

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Setup](#setup)
- [API Endpoints](#api-endpoints)
- [3DS2 Flow](#3ds2-flow)
- [Test Cards](#test-cards)
- [Testing Scenarios](#testing-scenarios)
- [Troubleshooting](#troubleshooting)

## 🎯 Overview

This testing environment provides a comprehensive implementation of 3D Secure 2 authentication using Global Payments' GPeCOM XML API. It includes:

- ✅ Complete 3DS2 authentication flow (enrollment, method URL, challenge)
- ✅ XML API client for GPeCOM integration
- ✅ RESTful API endpoints for frontend integration
- ✅ Interactive web interface for testing
- ✅ Webhook handlers for ACS notifications
- ✅ Support for both frictionless and challenge flows
- ✅ Comprehensive logging and debugging

## 🏗️ Architecture

### Components

```
php/
├── src/
│   └── GpecomClient.php          # Core GPeCOM XML API client
├── api/
│   ├── check-enrollment.php      # Step 1: Check card enrollment
│   ├── initiate-auth.php         # Step 3: Initiate authentication
│   ├── verify-auth.php           # Step 4: Verify challenge response
│   └── authorize-payment.php     # Step 5: Authorize payment
├── webhooks/
│   ├── method-notification.php   # Method URL callback handler
│   └── challenge-notification.php # Challenge completion handler
├── logs/                          # Request/response logs
├── temp/                          # Temporary session data
└── test-3ds2.html                # Interactive test interface
```

### 3DS2 Flow Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────┐
│   Browser   │────▶│  PHP Server  │────▶│   GPeCOM    │────▶│   ACS    │
│             │◀────│              │◀────│   API       │◀────│ (Issuer) │
└─────────────┘     └──────────────┘     └─────────────┘     └──────────┘
      │                    │                     │                  │
      │  1. Submit Card    │                     │                  │
      ├───────────────────▶│                     │                  │
      │                    │  2. Check Enrollment│                  │
      │                    ├────────────────────▶│                  │
      │                    │◀────────────────────│                  │
      │                    │  3. Method URL      │                  │
      │◀───────────────────┤                     │                  │
      │  4. Post to Method │                     │  5. Fingerprint  │
      ├────────────────────┼─────────────────────┼─────────────────▶│
      │                    │◀────────────────────┼──────────────────│
      │  6. Initiate Auth  │                     │                  │
      ├───────────────────▶│  7. Authenticate    │                  │
      │                    ├────────────────────▶│  8. Risk Check   │
      │                    │                     ├─────────────────▶│
      │                    │◀────────────────────│◀─────────────────│
      │  9. Challenge URL  │                     │                  │
      │◀───────────────────│                     │                  │
      │ 10. Display        │                     │                  │
      │    Challenge       │                     │  11. OTP/Auth    │
      ├────────────────────┼─────────────────────┼─────────────────▶│
      │                    │◀────────────────────┼──────────────────│
      │ 12. Verify Auth    │                     │                  │
      ├───────────────────▶│ 13. Verify Signature│                  │
      │                    ├────────────────────▶│                  │
      │                    │◀────────────────────│                  │
      │ 14. Authorize      │                     │                  │
      ├───────────────────▶│ 15. Process Payment │                  │
      │                    ├────────────────────▶│                  │
      │                    │◀────────────────────│                  │
      │ 16. Success!       │                     │                  │
      │◀───────────────────│                     │                  │
```

## 🚀 Setup

### Prerequisites

- PHP 7.4 or higher
- Composer
- Web server (Apache, Nginx, or PHP built-in server)

### Installation Steps

1. **Install Dependencies**
   ```bash
   cd php
   composer install
   ```

2. **Configure Environment**

   The `.env` file is already configured with your sandbox credentials:
   ```
   GPECOM_MERCHANT_ID=radoslav
   GPECOM_SHARED_SECRET=cfJeww9HL2
   GPECOM_REFUND_PASSWORD=b51118f58785274e117efe1bf99d4d50ccb96949
   GPECOM_SANDBOX=true
   ```

3. **Start the Server**
   ```bash
   php -S localhost:8080
   ```

4. **Access Test Interface**

   Open your browser to:
   ```
   http://localhost:8080/test-3ds2.html
   ```

### Docker Setup (Alternative)

```bash
docker-compose up php
```

The server will be available at `http://localhost:8080`

## 🔌 API Endpoints

### 1. Check Enrollment
**POST** `/api/check-enrollment.php`

Checks if a card is enrolled in 3D Secure 2 and retrieves the Method URL.

**Request:**
```json
{
  "card_number": "4263970000005262",
  "exp_date": "1225",
  "card_holder": "John Doe",
  "card_type": "VISA",
  "order_id": "order-123456"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "enrolled": "Y",
    "server_trans_id": "abc123...",
    "method_url": "https://acs.example.com/method",
    "ds_trans_id": "xyz789...",
    "order_id": "order-123456",
    "message": "Enrolled"
  }
}
```

### 2. Initiate Authentication
**POST** `/api/initiate-auth.php`

Initiates the 3DS2 authentication process with browser data.

**Request:**
```json
{
  "card_number": "4263970000005262",
  "amount": "1000",
  "currency": "USD",
  "exp_date": "1225",
  "card_holder": "John Doe",
  "order_id": "order-123456",
  "server_trans_id": "abc123...",
  "method_url_complete": "true",
  "browser_data": {
    "accept_header": "text/html,...",
    "color_depth": "24",
    "java_enabled": "false",
    "javascript_enabled": "true",
    "language": "en-US",
    "screen_height": "1080",
    "screen_width": "1920",
    "timezone": "-300",
    "user_agent": "Mozilla/5.0...",
    "challenge_window_size": "05"
  },
  "customer": {
    "email": "test@example.com",
    "phone": "+1234567890"
  }
}
```

**Response (Frictionless):**
```json
{
  "success": true,
  "data": {
    "challenge_required": false,
    "status": "Y",
    "auth_data": {
      "eci": "05",
      "cavv": "AAABBZIGcQAAAABvllEIRoEoAAA=",
      "xid": "MDAyNDEwMT...",
      "ds_trans_id": "xyz789..."
    }
  }
}
```

**Response (Challenge Required):**
```json
{
  "success": true,
  "data": {
    "challenge_required": true,
    "challenge": {
      "acs_url": "https://acs.example.com/challenge",
      "creq": "eyJ0aHJlZURT...",
      "server_trans_id": "abc123..."
    }
  }
}
```

### 3. Verify Authentication
**POST** `/api/verify-auth.php`

Verifies the authentication after challenge completion.

**Request:**
```json
{
  "order_id": "order-123456",
  "amount": "1000",
  "currency": "USD",
  "cres": "eyJtZXNzYWdl..."
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "auth_data": {
      "eci": "05",
      "cavv": "AAABBZIGcQAAAABvllEIRoEoAAA=",
      "xid": "MDAyNDEwMT...",
      "ds_trans_id": "xyz789...",
      "status": "Y"
    }
  }
}
```

### 4. Authorize Payment
**POST** `/api/authorize-payment.php`

Processes the payment authorization with 3DS2 authentication data.

**Request:**
```json
{
  "card_number": "4263970000005262",
  "amount": "1000",
  "currency": "USD",
  "exp_date": "1225",
  "card_holder": "John Doe",
  "cvv": "123",
  "order_id": "order-123456",
  "auth_data": {
    "eci": "05",
    "cavv": "AAABBZIGcQAAAABvllEIRoEoAAA=",
    "xid": "MDAyNDEwMT...",
    "ds_trans_id": "xyz789..."
  }
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "authorized": true,
    "transaction_id": "14938903488121894",
    "order_id": "order-123456",
    "message": "Approved"
  }
}
```

## 🔄 3DS2 Flow

### Complete Authentication Flow

1. **Enrollment Check (Step 1)**
   - Submit card details
   - Verify 3DS2 enrollment status
   - Receive Method URL if available

2. **Method URL Processing (Step 2)**
   - Post to Method URL in hidden iframe
   - ACS collects device fingerprint
   - Webhook receives completion notification

3. **Initiate Authentication (Step 3)**
   - Submit transaction details with browser data
   - ACS performs risk analysis
   - Receives frictionless approval OR challenge requirement

4. **Challenge Flow (Step 4)** *(if required)*
   - Display ACS challenge iframe
   - Cardholder completes authentication (OTP, biometric, etc.)
   - Webhook receives challenge response

5. **Payment Authorization (Step 5)**
   - Submit payment with authentication data
   - Process transaction with liability shift
   - Receive authorization confirmation

### Frictionless Flow
```
Card Details → Enrollment Check → Method URL → Authentication → ✓ Approved
```

### Challenge Flow
```
Card Details → Enrollment Check → Method URL → Authentication → Challenge → Verify → ✓ Approved
```

## 💳 Test Cards

### Visa Test Cards

| Card Number | Expiry | CVV | Scenario |
|-------------|--------|-----|----------|
| 4263970000005262 | 12/25 | 123 | ✅ Frictionless Success |
| 4012001037461114 | 12/25 | 123 | 🔐 Challenge Required |
| 4012001036853337 | 12/25 | 123 | ❌ Authentication Failed |
| 4012001036298889 | 12/25 | 123 | ⚠️ Authentication Unavailable |

### Mastercard Test Cards

| Card Number | Expiry | CVV | Scenario |
|-------------|--------|-----|----------|
| 5425230000004415 | 12/25 | 123 | ✅ Frictionless Success |
| 5425230000004407 | 12/25 | 123 | 🔐 Challenge Required |
| 5425230000004399 | 12/25 | 123 | ❌ Authentication Failed |
| 5425230000004381 | 12/25 | 123 | ⚠️ Not Enrolled |

### American Express Test Cards

| Card Number | Expiry | CVV | Scenario |
|-------------|--------|-----|----------|
| 374101000000608 | 12/25 | 1234 | ✅ Frictionless Success |
| 376525000000010 | 12/25 | 1234 | 🔐 Challenge Required |

## 🧪 Testing Scenarios

### Scenario 1: Successful Frictionless Transaction

1. Open `test-3ds2.html`
2. Click on "4263970000005262" test card
3. Click "Start 3DS2 Authentication"
4. Observe automatic approval without challenge
5. Payment should complete successfully

**Expected Result:** All 5 steps complete with green checkmarks

### Scenario 2: Challenge Flow

1. Open `test-3ds2.html`
2. Click on "4012001037461114" test card
3. Click "Start 3DS2 Authentication"
4. Challenge iframe should appear
5. Complete authentication in the challenge window
6. Payment authorization proceeds after challenge

**Expected Result:** Challenge window appears, authentication completes

### Scenario 3: Failed Authentication

1. Use card "4012001036853337"
2. Start authentication flow
3. Authentication should fail

**Expected Result:** Error message indicating authentication failure

### Scenario 4: Not Enrolled Card

1. Use card "5425230000004381"
2. Start authentication flow
3. Card should be marked as not enrolled

**Expected Result:** Fallback to non-3DS transaction

## 🐛 Troubleshooting

### Common Issues

#### 1. "Connection Refused" Error

**Problem:** Cannot connect to GPeCOM API

**Solution:**
- Verify internet connection
- Check if sandbox URL is correct in GpecomClient.php
- Ensure firewall allows outbound HTTPS connections

#### 2. "Invalid Hash" Error

**Problem:** Authentication hash doesn't match

**Solution:**
- Verify `GPECOM_SHARED_SECRET` in `.env` is correct
- Check timestamp format (YmdHis)
- Ensure no extra whitespace in credentials

#### 3. "Order ID Already Exists"

**Problem:** Duplicate order ID

**Solution:**
- Each transaction needs a unique order ID
- The test interface auto-generates unique IDs
- For API testing, use: `uniqid('order-')`

#### 4. Challenge Window Not Appearing

**Problem:** Challenge iframe doesn't load

**Solution:**
- Check browser console for errors
- Verify `acs_url` in response is valid
- Ensure CORS headers are properly set
- Check if pop-up blocker is interfering

#### 5. Method URL Timeout

**Problem:** Method URL notification not received

**Solution:**
- Method URL may not be supported by all test cards
- Code includes timeout fallback (proceed after 10 seconds)
- Check webhook logs in `/logs/method-notifications.log`

### Debug Mode

Enable detailed logging by setting in `.env`:
```
DEBUG_MODE=true
```

This will log all XML requests and responses to PHP error log.

### Viewing Logs

**Method URL notifications:**
```bash
tail -f php/logs/method-notifications.log
```

**Challenge notifications:**
```bash
tail -f php/logs/challenge-notifications.log
```

**PHP errors:**
```bash
tail -f /var/log/php_errors.log
```

### Testing API Endpoints Directly

Use cURL for direct API testing:

```bash
# Check enrollment
curl -X POST http://localhost:8080/api/check-enrollment.php \
  -H "Content-Type: application/json" \
  -d '{
    "card_number": "4263970000005262",
    "exp_date": "1225",
    "card_holder": "John Doe"
  }'

# Initiate authentication
curl -X POST http://localhost:8080/api/initiate-auth.php \
  -H "Content-Type: application/json" \
  -d @- <<EOF
{
  "card_number": "4263970000005262",
  "amount": "1000",
  "currency": "USD",
  "exp_date": "1225",
  "card_holder": "John Doe",
  "server_trans_id": "SERVER_TRANS_ID_FROM_ENROLLMENT",
  "method_url_complete": "true",
  "browser_data": {
    "color_depth": "24",
    "java_enabled": "false",
    "javascript_enabled": "true",
    "language": "en-US",
    "screen_height": "1080",
    "screen_width": "1920",
    "timezone": "-300",
    "user_agent": "Mozilla/5.0",
    "accept_header": "text/html",
    "challenge_window_size": "05"
  }
}
EOF
```

## 📚 Additional Resources

- [Global Payments 3DS2 Documentation](https://developer.globalpayments.com/ecommerce/risk-management/3ds/overview)
- [3DS2 Browser Authentication Guide](https://developer.globalpayments.com/ecommerce/risk-management/3ds/browser-authentication)
- [EMVCo 3DS Specification](https://www.emvco.com/emv-technologies/3d-secure/)

## 📝 Notes

- **Sandbox Environment:** All transactions use sandbox credentials and will not charge real cards
- **Production Migration:** Update `.env` with production credentials and set `GPECOM_SANDBOX=false`
- **Webhook URLs:** For production, webhook URLs must be publicly accessible HTTPS endpoints
- **PCI Compliance:** Never log or store full card numbers in production
- **Session Management:** Current implementation uses temporary files; use Redis/Database for production

## 🔒 Security Considerations

- ✅ All API requests use SHA1 hash authentication
- ✅ CVV is never stored or logged
- ✅ SSL/TLS required for all communications
- ✅ Challenge responses are base64-encoded and signed
- ✅ Browser fingerprinting helps prevent fraud
- ⚠️ Implement rate limiting in production
- ⚠️ Add CSRF protection for webhooks
- ⚠️ Use session/database instead of temp files for production

## 📄 License

MIT License - See LICENSE file for details

---

**Created for Global Payments GPeCOM 3DS2 Testing**

For questions or issues, refer to the Global Payments developer documentation or contact support.
