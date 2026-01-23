# GPeCOM 3DS2 Test Scenarios

Comprehensive testing scenarios for Global Payments eCOM 3D Secure 2 implementation.

## 🎯 Test Scenario Categories

1. [Successful Transactions](#successful-transactions)
2. [Challenge Flows](#challenge-flows)
3. [Authentication Failures](#authentication-failures)
4. [Edge Cases](#edge-cases)
5. [Error Handling](#error-handling)
6. [Performance Testing](#performance-testing)

---

## ✅ Successful Transactions

### Scenario 1.1: Frictionless Visa Transaction

**Objective:** Verify successful frictionless authentication with Visa card

**Test Card:** 4263970000005262

**Steps:**
1. Open `test-3ds2.html`
2. Enter card: 4263970000005262
3. Expiry: 12/25, CVV: 123
4. Amount: $10.00 (1000 cents)
5. Click "Start 3DS2 Authentication"

**Expected Results:**
- ✓ Enrollment check returns `enrolled: Y`
- ✓ Method URL processed (or skipped)
- ✓ Authentication completes without challenge
- ✓ ECI value: `05` (Visa authenticated)
- ✓ CAVV value present
- ✓ Payment authorized successfully
- ✓ All 5 steps show green checkmarks

**API Response Example:**
```json
{
  "success": true,
  "data": {
    "challenge_required": false,
    "auth_data": {
      "eci": "05",
      "cavv": "AAABBZIGcQAAAABvllEIRoEoAAA=",
      "xid": "MDAyNDEwMTAyODI...",
      "ds_trans_id": "c272b04f-..."
    }
  }
}
```

---

### Scenario 1.2: Frictionless Mastercard Transaction

**Objective:** Verify frictionless authentication with Mastercard

**Test Card:** 5425230000004415

**Steps:**
1. Open test interface
2. Click "5425230000004415" test card
3. Submit form with $15.00 (1500 cents)

**Expected Results:**
- ✓ Enrollment: Y
- ✓ Frictionless approval
- ✓ ECI: `02` (Mastercard authenticated)
- ✓ Payment authorized

---

### Scenario 1.3: Frictionless American Express

**Objective:** Verify AMEX frictionless flow

**Test Card:** 374101000000608

**Steps:**
1. Enter AMEX card: 374101000000608
2. CVV: 1234 (4 digits for AMEX)
3. Amount: $20.00

**Expected Results:**
- ✓ Card type detected as AMEX
- ✓ Frictionless authentication
- ✓ ECI: `05` (AMEX uses Visa ECI values)
- ✓ Authorization successful

---

## 🔐 Challenge Flows

### Scenario 2.1: Challenge Required - Visa

**Objective:** Test challenge authentication flow

**Test Card:** 4012001037461114

**Steps:**
1. Enter card: 4012001037461114
2. Expiry: 12/25, CVV: 123
3. Amount: $25.00 (2500 cents)
4. Submit form
5. Wait for challenge iframe to appear
6. Complete authentication in challenge window

**Expected Results:**
- ✓ Enrollment check successful
- ✓ `challenge_required: true` in response
- ✓ ACS URL and CReq provided
- ✓ Challenge iframe displays
- ✓ After challenge completion, receive CRes
- ✓ Verify authentication endpoint returns auth data
- ✓ Payment authorized with challenge completion

**Challenge Window:**
- Should display ACS authentication interface
- May show OTP input, biometric prompt, or security questions
- Window should close automatically on completion

---

### Scenario 2.2: Challenge Required - Mastercard

**Objective:** Test Mastercard challenge flow

**Test Card:** 5425230000004407

**Steps:**
1. Use test card: 5425230000004407
2. Amount: $30.00
3. Complete full authentication flow including challenge

**Expected Results:**
- ✓ Challenge iframe appears
- ✓ Authentication completes
- ✓ ECI: `02` after successful challenge
- ✓ Payment processed

---

### Scenario 2.3: Challenge with Customer Data

**Objective:** Verify customer information is passed correctly

**Test Card:** 4012001037461114

**Additional Data:**
```json
{
  "customer": {
    "email": "customer@example.com",
    "phone": "+14155551234",
    "billing": {
      "street": "123 Main St",
      "city": "San Francisco",
      "state": "CA",
      "postal_code": "94105",
      "country": "US"
    }
  }
}
```

**Expected Results:**
- ✓ Customer data included in authentication request
- ✓ Risk assessment may use this data
- ✓ Challenge or frictionless decision based on risk

---

## ❌ Authentication Failures

### Scenario 3.1: Authentication Failed

**Objective:** Handle failed authentication gracefully

**Test Card:** 4012001036853337

**Steps:**
1. Enter card: 4012001036853337
2. Attempt authentication

**Expected Results:**
- ✓ Enrollment successful
- ✗ Authentication fails
- ✓ Error message displayed
- ✓ ECI: `00` or `07` (authentication failed)
- ✓ No CAVV value
- ✓ Payment should not be authorized
- ✓ User-friendly error message

**Error Response:**
```json
{
  "success": false,
  "error": "Authentication failed",
  "message": "Unable to authenticate card",
  "data": {
    "status": "N",
    "eci": "07"
  }
}
```

---

### Scenario 3.2: Challenge Timeout

**Objective:** Handle challenge window timeout

**Test Card:** 4012001037461114

**Steps:**
1. Start challenge flow
2. Wait 10+ minutes without completing challenge
3. Observe timeout behavior

**Expected Results:**
- ✓ Challenge window times out after 10 minutes
- ✓ Error message displayed
- ✓ Transaction cannot proceed
- ✓ User can retry

---

### Scenario 3.3: Challenge Cancelled

**Objective:** Handle user cancellation of challenge

**Test Card:** 4012001037461114

**Steps:**
1. Start challenge flow
2. Close challenge window or click "Cancel" in ACS interface

**Expected Results:**
- ✓ Transaction marked as cancelled
- ✓ Appropriate error message
- ✓ User can retry with same or different card

---

## ⚠️ Edge Cases

### Scenario 4.1: Not Enrolled Card

**Objective:** Handle card not enrolled in 3DS2

**Test Card:** 5425230000004381

**Steps:**
1. Enter card: 5425230000004381
2. Attempt enrollment check

**Expected Results:**
- ✓ Enrollment returns `enrolled: N`
- ✓ Flow should handle gracefully
- ✓ Option to proceed with non-3DS transaction
- ✓ Liability shift NOT available

---

### Scenario 4.2: 3DS2 Unavailable

**Objective:** Handle temporary 3DS2 unavailability

**Test Card:** 4012001036298889

**Steps:**
1. Use card: 4012001036298889
2. Check enrollment

**Expected Results:**
- ✓ System indicates 3DS unavailable
- ✓ Fallback to alternative authentication
- ✓ Or cancel transaction with user notification

---

### Scenario 4.3: Method URL Not Supported

**Objective:** Handle cards without Method URL

**Steps:**
1. Use any test card
2. Mock `method_url: null` in enrollment response

**Expected Results:**
- ✓ Method URL step skipped
- ✓ Proceed directly to authentication
- ✓ Flow completes successfully

---

### Scenario 4.4: Multiple Currencies

**Objective:** Test different currency codes

**Test Cards:** Any frictionless card

**Test Data:**
- USD: $10.00 (1000)
- EUR: €10.00 (1000)
- GBP: £10.00 (1000)

**Expected Results:**
- ✓ Each currency processes correctly
- ✓ Amount formatting is correct
- ✓ Authentication and payment successful

---

### Scenario 4.5: Large Transaction Amounts

**Objective:** Test high-value transactions

**Test Card:** 4263970000005262

**Test Amounts:**
- $100.00 (10000)
- $500.00 (50000)
- $1,000.00 (100000)

**Expected Results:**
- ✓ Higher amounts may trigger challenge
- ✓ Authentication completes
- ✓ Payment authorized

---

## 🚨 Error Handling

### Scenario 5.1: Invalid Card Number

**Test Data:** 4111111111111111 (Luhn valid but test)

**Expected Results:**
- ✓ Client-side validation catches invalid format
- ✓ Or server returns clear error message
- ✓ No API call made with invalid data

---

### Scenario 5.2: Expired Card

**Test Card:** 4263970000005262
**Expiry:** 01/20 (expired)

**Expected Results:**
- ✓ Validation error before 3DS2 flow
- ✓ Clear error message
- ✓ User prompted to update expiry

---

### Scenario 5.3: Network Timeout

**Objective:** Simulate API timeout

**Steps:**
1. Mock slow/no response from GPeCOM API
2. Observe timeout handling

**Expected Results:**
- ✓ Request times out after 30 seconds
- ✓ User-friendly error message
- ✓ Option to retry
- ✓ No hanging requests

---

### Scenario 5.4: Invalid API Credentials

**Objective:** Test with wrong credentials

**Steps:**
1. Modify `.env` with invalid `GPECOM_SHARED_SECRET`
2. Attempt transaction

**Expected Results:**
- ✓ Authentication hash mismatch
- ✓ API returns 401 or authentication error
- ✓ Clear error logged
- ✓ No sensitive data exposed in error

---

### Scenario 5.5: Malformed XML Response

**Objective:** Handle unexpected API responses

**Steps:**
1. Mock malformed XML from API
2. Observe error handling

**Expected Results:**
- ✓ Parser catches malformed XML
- ✓ Generic error returned to user
- ✓ Full error logged for debugging
- ✓ Application doesn't crash

---

## 🚀 Performance Testing

### Scenario 6.1: Concurrent Transactions

**Objective:** Test multiple simultaneous authentications

**Steps:**
1. Open 5+ browser tabs
2. Start authentication in each simultaneously
3. Monitor server performance

**Expected Results:**
- ✓ All transactions process independently
- ✓ No session conflicts
- ✓ Response times < 3 seconds
- ✓ No race conditions

---

### Scenario 6.2: Rapid Sequential Transactions

**Objective:** Test quick succession of transactions

**Steps:**
1. Complete one transaction
2. Immediately start another
3. Repeat 10 times

**Expected Results:**
- ✓ Each transaction gets unique order ID
- ✓ No caching issues
- ✓ All transactions complete successfully

---

### Scenario 6.3: Challenge Window Response Time

**Objective:** Measure challenge load time

**Metric:** Time from initiate-auth to challenge iframe render

**Expected Results:**
- ✓ Challenge appears in < 2 seconds
- ✓ ACS interface loads quickly
- ✓ No broken images or assets

---

## 📊 Test Results Template

Use this template to document test execution:

```markdown
### Test Execution: [Date]

**Scenario:** [Scenario Number and Name]

**Tester:** [Your Name]

**Environment:**
- Server: [localhost:8080 / staging / production]
- PHP Version: [7.4 / 8.0 / 8.1]
- Browser: [Chrome 120 / Firefox 121 / Safari 17]

**Test Results:**
- [ ] Pass
- [ ] Fail
- [ ] Blocked

**Actual Results:**
[Description of what actually happened]

**Screenshots/Logs:**
[Attach relevant screenshots or log excerpts]

**Notes:**
[Any additional observations]
```

---

## 🔍 Debugging Tips

### Enable Verbose Logging

```php
// In .env
DEBUG_MODE=true
```

### View Real-time Logs

```bash
# Method URL notifications
tail -f logs/method-notifications.log

# Challenge notifications
tail -f logs/challenge-notifications.log

# PHP errors
tail -f /var/log/php_errors.log
```

### Inspect XML Requests

Enable debug mode in `GpecomClient.php` - all XML requests and responses will be logged.

### Browser DevTools

- **Network Tab:** Monitor API calls and responses
- **Console Tab:** Check for JavaScript errors
- **Application Tab:** Inspect session storage

---

## 📋 Testing Checklist

Before considering testing complete, verify:

- [ ] All frictionless scenarios pass
- [ ] Challenge flows work correctly
- [ ] Error handling is graceful
- [ ] Edge cases handled appropriately
- [ ] Performance meets requirements
- [ ] Logging captures necessary data
- [ ] Security measures in place
- [ ] Documentation is accurate
- [ ] User experience is smooth
- [ ] Mobile responsive (if applicable)

---

## 🎓 Best Practices

1. **Always test both frictionless and challenge flows**
2. **Use unique order IDs for each test**
3. **Clear browser cache between major test runs**
4. **Document any unexpected behavior**
5. **Test on multiple browsers**
6. **Verify webhook handlers receive callbacks**
7. **Check logs after each test**
8. **Test with various amounts and currencies**
9. **Simulate network issues**
10. **Test error recovery mechanisms**

---

## 📞 Support

If you encounter issues not covered in these scenarios:

1. Check the main [README_3DS2.md](README_3DS2.md)
2. Review [Global Payments Documentation](https://developer.globalpayments.com)
3. Check logs in `/logs` directory
4. Contact Global Payments support with transaction details

---

**Happy Testing! 🚀**

Remember: Thorough testing in sandbox prevents issues in production.
