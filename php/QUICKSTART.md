# GPeCOM 3DS2 Quick Start Guide

Get up and running with Global Payments 3DS2 testing in 5 minutes!

## ⚡ Quick Setup

### 1. Install Dependencies

```bash
cd php
composer install
```

### 2. Environment Check

Your sandbox credentials are already configured in `.env`:
```
✓ GPECOM_MERCHANT_ID=radoslav
✓ GPECOM_SHARED_SECRET=cfJeww9HL2
✓ GPECOM_REFUND_PASSWORD=b51118f58785274e117efe1bf99d4d50ccb96949
✓ GPECOM_SANDBOX=true
```

### 3. Start Server

```bash
php -S localhost:8080
```

### 4. Open Test Interface

Navigate to: **http://localhost:8080/test-3ds2.html**

## 🎯 Run Your First Test

### Frictionless Transaction (Easiest)

1. The test interface opens with pre-filled test data
2. Click **"4263970000005262"** under Test Card Numbers
3. Click **"Start 3DS2 Authentication"**
4. Watch the 5-step flow complete automatically
5. ✓ Payment approved!

### Challenge Transaction (Advanced)

1. Click **"4012001037461114"** under Test Card Numbers
2. Click **"Start 3DS2 Authentication"**
3. A challenge window will appear
4. Complete the authentication
5. ✓ Payment approved after challenge!

## 📁 Project Structure

```
php/
├── src/
│   └── GpecomClient.php          ← Core XML API client
├── api/
│   ├── check-enrollment.php      ← Step 1: Check card enrollment
│   ├── initiate-auth.php         ← Step 3: Start authentication
│   ├── verify-auth.php           ← Step 4: Verify challenge
│   └── authorize-payment.php     ← Step 5: Process payment
├── webhooks/
│   ├── method-notification.php   ← Device fingerprint callback
│   └── challenge-notification.php ← Challenge completion callback
├── test-3ds2.html                ← Interactive test interface
├── .env                          ← Your credentials (configured)
└── README_3DS2.md                ← Full documentation
```

## 🔑 Test Card Quick Reference

| Card Number | Type | Behavior |
|-------------|------|----------|
| **4263970000005262** | Visa | ✅ Frictionless |
| **4012001037461114** | Visa | 🔐 Challenge |
| **5425230000004415** | MC | ✅ Frictionless |
| **5425230000004407** | MC | 🔐 Challenge |

All cards use:
- **Expiry:** 12/25
- **CVV:** 123 (1234 for AMEX)

## 🔄 The 3DS2 Flow (Simplified)

```
1. Check Enrollment
   ↓
2. Device Fingerprint (Method URL)
   ↓
3. Initiate Authentication
   ↓
4. Challenge (if required) or Frictionless
   ↓
5. Authorize Payment ✓
```

## 🧪 Testing Endpoints Directly

### cURL Examples

**Check Enrollment:**
```bash
curl -X POST http://localhost:8080/api/check-enrollment.php \
  -H "Content-Type: application/json" \
  -d '{
    "card_number": "4263970000005262",
    "exp_date": "1225",
    "card_holder": "John Doe"
  }'
```

**Initiate Authentication:**
```bash
curl -X POST http://localhost:8080/api/initiate-auth.php \
  -H "Content-Type: application/json" \
  -d '{
    "card_number": "4263970000005262",
    "amount": "1000",
    "currency": "USD",
    "exp_date": "1225",
    "card_holder": "John Doe",
    "method_url_complete": "false",
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
  }'
```

## 🐛 Quick Troubleshooting

### "Connection refused" error
```bash
# Make sure the server is running
php -S localhost:8080
```

### "composer: command not found"
```bash
# Install Composer first
# macOS/Linux:
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Windows: Download from https://getcomposer.org/
```

### Can't see the test page
- Check you're accessing: `http://localhost:8080/test-3ds2.html`
- Verify port 8080 isn't in use: `lsof -i :8080`

### Authentication fails
- Verify `.env` credentials are correct
- Check logs: `tail -f logs/*.log` (after creating logs/ directory)
- Enable debug mode: Set `DEBUG_MODE=true` in `.env`

## 📊 View Logs

```bash
# Create logs directory if it doesn't exist
mkdir -p logs

# Watch method URL notifications
tail -f logs/method-notifications.log

# Watch challenge notifications
tail -f logs/challenge-notifications.log
```

## 🚀 Next Steps

1. ✅ Run a frictionless transaction
2. ✅ Try a challenge flow
3. 📖 Read [README_3DS2.md](README_3DS2.md) for full documentation
4. 🧪 Review [TEST_SCENARIOS.md](TEST_SCENARIOS.md) for comprehensive testing
5. 🔧 Customize the integration for your needs

## 💡 Pro Tips

- **Use unique Order IDs:** Each transaction needs a unique `order_id`
- **Test both flows:** Always test frictionless AND challenge scenarios
- **Check ECI values:** ECI `05`/`02` means authenticated (liability shift)
- **Monitor webhooks:** Webhooks log all ACS callbacks
- **Browser DevTools:** Open Console (F12) to see detailed flow

## 📚 Documentation

| Document | Purpose |
|----------|---------|
| **QUICKSTART.md** (this file) | Get started fast |
| **README_3DS2.md** | Complete documentation |
| **TEST_SCENARIOS.md** | Testing guide |

## 🔒 Security Note

⚠️ **Sandbox Only:** These credentials are for sandbox testing only. Never use test credentials in production!

For production:
1. Get production credentials from Global Payments
2. Update `.env` with production values
3. Set `GPECOM_SANDBOX=false`
4. Use HTTPS endpoints
5. Implement proper session management

## ✅ Success Checklist

- [ ] Dependencies installed (`composer install`)
- [ ] Server running on port 8080
- [ ] Test interface loads in browser
- [ ] Frictionless transaction completes
- [ ] Challenge transaction completes
- [ ] Logs directory created
- [ ] Reviewed full README

## 🎉 You're Ready!

Everything is set up and ready to test. The sandbox credentials are configured, test cards are provided, and the full 3DS2 flow is implemented.

**Start testing now:** http://localhost:8080/test-3ds2.html

---

**Questions?** Check [README_3DS2.md](README_3DS2.md) for detailed documentation.

**Need help?** Review [TEST_SCENARIOS.md](TEST_SCENARIOS.md) for troubleshooting.
