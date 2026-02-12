import express from 'express';
import path from 'path';
import fs from 'fs';
import crypto from 'crypto';
import { fileURLToPath } from 'url';
import * as dotenv from 'dotenv';
import {
    ServicesContainer,
    GpEcomConfig,
    CreditCardData,
    ThreeDSecure,
} from 'globalpayments-api';

dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');

// ── SDK Configuration (for payment authorization only) ───────
// The Node.js SDK's GpEcomConnector doesn't implement 3DS2 (unlike the PHP SDK
// which has Gp3DSProvider). So we use the SDK only for card.charge() and make
// direct REST API calls for 3DS2 operations.

const config = new GpEcomConfig();
config.merchantId = process.env.GPECOM_MERCHANT_ID;
config.accountId = process.env.GPECOM_ACCOUNT || 'internet';
config.sharedSecret = process.env.GPECOM_SHARED_SECRET;
ServicesContainer.configureService(config);

const MERCHANT_ID = process.env.GPECOM_MERCHANT_ID;
const ACCOUNT_ID = process.env.GPECOM_ACCOUNT || 'internet';
const SHARED_SECRET = process.env.GPECOM_SHARED_SECRET;
const METHOD_NOTIFICATION_URL = process.env.METHOD_NOTIFICATION_URL || null;
const CHALLENGE_NOTIFICATION_URL = process.env.CHALLENGE_NOTIFICATION_URL || null;
const MERCHANT_CONTACT_URL = process.env.MERCHANT_CONTACT_URL || 'https://developer.globalpayments.com';
const SANDBOX = (process.env.GPECOM_SANDBOX || 'true') === 'true';
const debug = (process.env.DEBUG_MODE || 'false') === 'true';

const GP_3DS2_BASE_URL = SANDBOX
    ? 'https://api.sandbox.globalpay-ecommerce.com/3ds2/'
    : 'https://api.globalpay-ecommerce.com/3ds2/';

// ── GP 3DS2 REST API Client ─────────────────────────────────
// Replicates the PHP SDK's Gp3DSProvider: makes REST JSON calls to the GP 3DS2 API
// with SHA1 hash authentication.

function generateHash(secret, toHash) {
    const firstPass = crypto.createHash('sha1').update(toHash).digest('hex');
    const secondPass = crypto.createHash('sha1').update(firstPass + '.' + secret).digest('hex');
    return secondPass;
}

function getTimestamp() {
    const now = new Date();
    const pad = (n, len = 2) => String(n).padStart(len, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}.${pad(now.getMilliseconds(), 3)}000`;
}

function mapCardScheme(cardNumber) {
    if (/^4/.test(cardNumber)) return 'VISA';
    if (/^5[1-5]/.test(cardNumber)) return 'MASTERCARD';
    if (/^3[47]/.test(cardNumber)) return 'AMEX';
    if (/^3(?:0[0-5]|[68])/.test(cardNumber)) return 'DINERS';
    if (/^6(?:011|5)/.test(cardNumber)) return 'DISCOVER';
    return 'VISA';
}

async function gp3dsRequest(method, endpoint, body, queryParams) {
    let url = GP_3DS2_BASE_URL + endpoint;
    if (queryParams) {
        const qs = new URLSearchParams(queryParams).toString();
        url += '?' + qs;
    }

    const headers = {
        'X-GP-Version': '2.2.0',
        'Content-Type': 'application/json',
    };

    // Authorization hash is set by callers via body.__hash
    if (body && body.__hash) {
        headers['Authorization'] = 'securehash ' + body.__hash;
        delete body.__hash;
    } else if (queryParams && queryParams.__hash) {
        headers['Authorization'] = 'securehash ' + queryParams.__hash;
        delete queryParams.__hash;
        // Rebuild URL without __hash
        url = GP_3DS2_BASE_URL + endpoint;
        if (Object.keys(queryParams).length) {
            url += '?' + new URLSearchParams(queryParams).toString();
        }
    }

    logApi('gp-3ds2-api', method + ' ' + endpoint, body ? JSON.stringify(body) : '');

    const response = await fetch(url, {
        method,
        headers,
        body: method !== 'GET' ? JSON.stringify(body) : undefined,
    });

    const text = await response.text();
    logApi('gp-3ds2-api', 'RESPONSE ' + response.status, text.substring(0, 500));

    if (!response.ok) {
        throw new Error(`GP 3DS2 API error ${response.status}: ${text}`);
    }

    return JSON.parse(text);
}

// ── Test Cards (Message Version 2.2) ───────────────────────

const TEST_CARDS = {
    '4222000006285344': { flow: 'frictionless', result: 'AUTHENTICATION_SUCCESSFUL', eci: '05', brand: 'VISA' },
    '4222000009719489': { flow: 'frictionless', result: 'AUTHENTICATION_SUCCESSFUL', eci: '05', brand: 'VISA', noMethodUrl: true },
    '4222000005218627': { flow: 'frictionless', result: 'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL', eci: '06', brand: 'VISA' },
    '4222000002144131': { flow: 'frictionless', result: 'AUTHENTICATION_FAILED', eci: '07', brand: 'VISA' },
    '4222000007275799': { flow: 'frictionless', result: 'AUTHENTICATION_ISSUER_REJECTED', eci: '07', brand: 'VISA' },
    '4222000008880910': { flow: 'frictionless', result: 'AUTHENTICATION_COULD_NOT_BE_PERFORMED', eci: '07', brand: 'VISA' },
    '4222000001227408': { flow: 'challenge', result: 'CHALLENGE_REQUIRED', eci: null, brand: 'VISA' },
    '5354560000000004': { flow: 'frictionless', result: 'AUTHENTICATION_SUCCESSFUL', eci: '02', brand: 'MC' },
    '5571596304025153': { flow: 'frictionless', result: 'AUTHENTICATION_SUCCESSFUL', eci: '02', brand: 'MC', noMethodUrl: true },
    '5580364874958322': { flow: 'frictionless', result: 'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL', eci: '01', brand: 'MC' },
    '5540010585397800': { flow: 'frictionless', result: 'AUTHENTICATION_FAILED', eci: '00', brand: 'MC' },
    '5588312194362669': { flow: 'frictionless', result: 'AUTHENTICATION_ISSUER_REJECTED', eci: '00', brand: 'MC' },
    '5520680211891022': { flow: 'frictionless', result: 'AUTHENTICATION_COULD_NOT_BE_PERFORMED', eci: '00', brand: 'MC' },
    '5506874496684651': { flow: 'challenge', result: 'CHALLENGE_REQUIRED', eci: null, brand: 'MC' },
};

// ── Helpers ─────────────────────────────────────────────────

const logDir = path.join(__dirname, 'logs');
if (!fs.existsSync(logDir)) fs.mkdirSync(logDir, { recursive: true });

function logApi(step, direction, data) {
    const entry = `[${new Date().toISOString()}] [${step}] [${direction}] ${typeof data === 'string' ? data : JSON.stringify(data)}\n`;
    fs.appendFileSync(path.join(logDir, '3ds2-api.log'), entry);
}

function logError(step, err) {
    const entry = `[${new Date().toISOString()}] [${step}] ${err.name}: ${err.message}\n`;
    fs.appendFileSync(path.join(logDir, '3ds2-errors.log'), entry);
}

function maskCard(number) {
    const clean = number.replace(/\D/g, '');
    if (clean.length < 10) return '****';
    return clean.slice(0, 6) + '*'.repeat(clean.length - 10) + clean.slice(-4);
}

function validateCardNumber(number) {
    const clean = number.replace(/\D/g, '');
    if (clean.length < 13 || clean.length > 19) return false;
    let sum = 0, alt = false;
    for (let i = clean.length - 1; i >= 0; i--) {
        let d = parseInt(clean[i], 10);
        if (alt) { d *= 2; if (d > 9) d -= 9; }
        sum += d;
        alt = !alt;
    }
    return sum % 10 === 0;
}

function validateExpDate(exp) {
    if (!/^\d{4}$/.test(exp)) return false;
    const month = parseInt(exp.slice(0, 2), 10);
    const year = parseInt(exp.slice(2, 4), 10);
    if (month < 1 || month > 12) return false;
    const now = new Date();
    const curYear = now.getFullYear() % 100;
    const curMonth = now.getMonth() + 1;
    return year > curYear || (year === curYear && month >= curMonth);
}

function validateAmount(amount) {
    const val = parseInt(amount, 10);
    return !isNaN(val) && val > 0 && val <= 99999999;
}

function validateCurrency(currency) {
    return ['EUR', 'USD', 'GBP'].includes(currency.toUpperCase());
}

function validateCvv(cvv) {
    return /^\d{3,4}$/.test(cvv);
}

function randomHex(bytes) {
    return crypto.randomBytes(bytes).toString('hex');
}

function randomBase64(bytes) {
    return crypto.randomBytes(bytes).toString('base64');
}

function buildCard(params) {
    const card = new CreditCardData();
    card.number = (params.card_number || '').replace(/\D/g, '');
    const expDate = params.exp_date || '1228';
    card.expMonth = expDate.slice(0, 2);
    card.expYear = '20' + expDate.slice(2, 4);
    card.cardHolderName = params.card_holder || 'Test Customer';
    return card;
}

function mapColorDepth(depth) {
    const map = {
        '1': 'ONE_BIT', '2': 'TWO_BITS', '4': 'FOUR_BITS',
        '8': 'EIGHT_BITS', '15': 'FIFTEEN_BITS', '16': 'SIXTEEN_BITS',
        '24': 'TWENTY_FOUR_BITS', '32': 'THIRTY_TWO_BITS', '48': 'FORTY_EIGHT_BITS',
    };
    return map[depth] || 'TWENTY_FOUR_BITS';
}

function mapChallengeWindowSize(size) {
    const map = {
        '01': 'WINDOWED_250X400', '02': 'WINDOWED_390X400',
        '03': 'WINDOWED_500X600', '04': 'WINDOWED_600X400',
        '05': 'FULL_SCREEN',
    };
    return map[size] || 'FULL_SCREEN';
}

// ── Security Middleware ─────────────────────────────────────

function securityHeaders(req, res, next) {
    res.set({
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'SAMEORIGIN',
        'X-XSS-Protection': '1; mode=block',
        'Referrer-Policy': 'strict-origin-when-cross-origin',
        'Cache-Control': 'no-store, no-cache, must-revalidate',
    });
    next();
}

function corsHeaders(req, res, next) {
    res.set({
        'Access-Control-Allow-Origin': '*',
        'Access-Control-Allow-Methods': 'POST, OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type',
    });
    if (req.method === 'OPTIONS') return res.sendStatus(200);
    next();
}

// ── Express Setup ───────────────────────────────────────────

const app = express();
const port = process.env.PORT || 3001;

app.use(securityHeaders);
app.use(corsHeaders);
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Serve root index.html and static assets from project root
app.get('/', (req, res) => res.sendFile(path.join(projectRoot, 'index.html')));
app.use(express.static(projectRoot, { index: false }));

// ── API: Check Enrollment ──────────────────────────────────
// Direct REST call to GP 3DS2 API: POST /protocol-versions

app.post('/api/check-enrollment', async (req, res) => {
    try {
        const data = req.body;
        const cardNumber = (data.card_number || '').replace(/\D/g, '');
        const expDate = data.exp_date || '';
        const cardHolder = data.card_holder || '';
        const orderId = data.order_id || '';
        const demoMode = !!data.demo_mode;

        if (!cardNumber || !validateCardNumber(cardNumber))
            return res.status(400).json({ success: false, message: 'Invalid card number' });
        if (!expDate || !validateExpDate(expDate))
            return res.status(400).json({ success: false, message: 'Invalid expiry date (MMYY format required)' });
        if (!cardHolder || cardHolder.length < 2 || cardHolder.length > 100)
            return res.status(400).json({ success: false, message: 'Cardholder name must be 2-100 characters' });
        if (!orderId)
            return res.status(400).json({ success: false, message: 'Order ID is required' });

        let result;
        if (demoMode) {
            result = simulateEnrollment(cardNumber, orderId);
        } else {
            logApi('check-enrollment', 'REQUEST', { card: maskCard(cardNumber), order_id: orderId });

            const timestamp = getTimestamp();
            const hash = generateHash(SHARED_SECRET, [timestamp, MERCHANT_ID, cardNumber].join('.'));

            const requestBody = {
                request_timestamp: timestamp,
                merchant_id: MERCHANT_ID,
                account_id: ACCOUNT_ID,
                number: cardNumber,
                scheme: mapCardScheme(cardNumber),
                __hash: hash,
            };
            // Only include method_notification_url if configured (null = omit, not empty string)
            if (METHOD_NOTIFICATION_URL) {
                requestBody.method_notification_url = METHOD_NOTIFICATION_URL;
            }

            const doc = await gp3dsRequest('POST', 'protocol-versions', requestBody);

            const enrolled = doc.enrolled === true || doc.enrolled === 'True' || doc.enrolled === 'Y';

            // Generate method data (SDK does this internally from server_trans_id + methodNotificationUrl)
            let methodData = null;
            if (doc.method_data && doc.method_data.encoded_method_data) {
                methodData = doc.method_data.encoded_method_data;
            } else if (doc.method_url && doc.server_trans_id && METHOD_NOTIFICATION_URL) {
                const methodDataObj = {
                    threeDSServerTransID: doc.server_trans_id,
                    threeDSMethodNotificationURL: METHOD_NOTIFICATION_URL,
                };
                methodData = Buffer.from(JSON.stringify(methodDataObj)).toString('base64');
            }

            logApi('check-enrollment', 'RESPONSE', { enrolled, server_trans_id: doc.server_trans_id });

            result = {
                enrolled: enrolled ? 'Y' : 'N',
                server_trans_id: doc.server_trans_id || null,
                method_url: doc.method_url || null,
                method_data: methodData,
                ds_trans_id: doc.ds_trans_id || null,
                order_id: orderId,
                acs_start_version: doc.acs_protocol_version_start || null,
                acs_end_version: doc.acs_protocol_version_end || null,
                message: enrolled ? 'Card enrolled in 3DS2' : 'Card not enrolled',
            };
        }

        res.json({ success: true, message: 'Enrollment check complete', data: result });
    } catch (err) {
        logError('check-enrollment', err);
        res.status(502).json({ success: false, message: 'Enrollment check failed: ' + err.message });
    }
});

// ── API: Initiate Authentication ───────────────────────────
// Direct REST call to GP 3DS2 API: POST /authentications

app.post('/api/initiate-auth', async (req, res) => {
    try {
        const data = req.body;
        const cardNumber = (data.card_number || '').replace(/\D/g, '');
        const amount = data.amount || '';
        const currency = (data.currency || 'EUR').toUpperCase();
        const serverTransId = data.server_trans_id || '';
        const demoMode = !!data.demo_mode;

        if (!cardNumber || !validateCardNumber(cardNumber))
            return res.status(400).json({ success: false, message: 'Invalid card number' });
        if (!amount || !validateAmount(String(amount)))
            return res.status(400).json({ success: false, message: 'Invalid amount' });
        if (!validateCurrency(currency))
            return res.status(400).json({ success: false, message: 'Invalid currency (EUR, USD, GBP accepted)' });
        if (!serverTransId && !demoMode)
            return res.status(400).json({ success: false, message: 'Server transaction ID is required' });

        let result;
        if (demoMode) {
            result = simulateAuthentication(cardNumber, serverTransId, data);
        } else {
            const expDate = data.exp_date || '1228';
            const cardHolder = data.card_holder || 'Test Customer';
            const methodUrlComplete = (data.method_url_complete || 'true') === 'true' ? 'YES' : 'NO';
            const browserData = data.browser_data || {};
            const customer = data.customer || {};

            const timestamp = getTimestamp();
            const hash = generateHash(SHARED_SECRET, [timestamp, MERCHANT_ID, cardNumber, serverTransId].join('.'));

            // Parse cardholder name
            const names = cardHolder.split(' ', 2);
            const firstName = names[0] || '';
            const lastName = names.length >= 2 ? names[1] : names[0];
            const expMonth = expDate.slice(0, 2);
            const expYear = expDate.slice(2, 4);

            // Format amount: integer in minor units (already is, but format as string of cents)
            const amountFormatted = String(parseInt(amount, 10)).padStart(4, '0');

            const requestBody = {
                request_timestamp: timestamp,
                authentication_source: 'BROWSER',
                authentication_request_type: 'PAYMENT_TRANSACTION',
                message_category: 'PAYMENT_AUTHENTICATION',
                message_version: data.acs_end_version || '2.2.0',
                server_trans_id: serverTransId,
                merchant_id: MERCHANT_ID,
                account_id: ACCOUNT_ID,
                method_url_completion: methodUrlComplete,
                merchant_contact_url: MERCHANT_CONTACT_URL,
                card_detail: {
                    number: cardNumber,
                    scheme: mapCardScheme(cardNumber),
                    expiry_month: expMonth,
                    expiry_year: expYear,
                    full_name: cardHolder,
                    first_name: firstName,
                    last_name: lastName,
                },
                order: {
                    amount: amountFormatted,
                    currency: currency,
                    id: data.order_id || 'nodejs-' + Date.now(),
                    date_time_created: new Date().toISOString(),
                    address_match_indicator: false,
                    shipping_address: {
                        line1: data.address || 'Flat 123',
                        line2: data.address2 || 'House 456',
                        city: data.city || 'Halifax',
                        postal_code: data.postal_code || 'W5 9HR',
                        country: data.country_code || '826',
                    },
                },
                payer: {
                    email: customer.email || 'test@example.com',
                    billing_address: {
                        line1: data.address || 'Flat 123',
                        line2: data.address2 || 'House 456',
                        city: data.city || 'Halifax',
                        postal_code: data.postal_code || 'W5 9HR',
                        country: data.country_code || '826',
                    },
                },
                browser_data: {
                    accept_header: browserData.accept_header || 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    color_depth: mapColorDepth(browserData.color_depth || '24'),
                    ip: req.ip || '127.0.0.1',
                    java_enabled: (browserData.java_enabled || 'false') === 'true',
                    javascript_enabled: (browserData.javascript_enabled || 'true') === 'true',
                    language: browserData.language || 'en-US',
                    screen_height: parseInt(browserData.screen_height || '1080', 10),
                    screen_width: parseInt(browserData.screen_width || '1920', 10),
                    challenge_window_size: mapChallengeWindowSize(browserData.challenge_window_size || '05'),
                    timezone: browserData.timezone || '0',
                    user_agent: browserData.user_agent || req.get('User-Agent') || 'Mozilla/5.0',
                },
                __hash: hash,
            };

            // Only include challenge_notification_url if configured (required for challenges)
            if (CHALLENGE_NOTIFICATION_URL) {
                requestBody.challenge_notification_url = CHALLENGE_NOTIFICATION_URL;
            }

            logApi('initiate-auth', 'REQUEST', { card: maskCard(cardNumber), amount, currency, server_trans_id: serverTransId });

            const doc = await gp3dsRequest('POST', 'authentications', requestBody);

            logApi('initiate-auth', 'RESPONSE', { status: doc.status, eci: doc.eci, challenge_mandated: doc.challenge_mandated });

            const status = doc.status || '';

            if (status === 'CHALLENGE_REQUIRED') {
                result = {
                    challenge_required: true,
                    status,
                    challenge: {
                        acs_url: doc.challenge_request_url || '',
                        creq: doc.encoded_creq || '',
                        server_trans_id: doc.server_trans_id || serverTransId,
                    },
                };
            } else {
                result = {
                    challenge_required: false,
                    status,
                    auth_data: {
                        eci: doc.eci || null,
                        cavv: doc.authentication_value || '',
                        xid: '',
                        ds_trans_id: doc.ds_trans_id || '',
                        authentication_value: doc.authentication_value || '',
                        message_version: doc.message_version || '2.2.0',
                        status,
                    },
                };
            }
        }

        // Return error for failed authentications
        const failedStatuses = ['AUTHENTICATION_FAILED', 'AUTHENTICATION_ISSUER_REJECTED', 'AUTHENTICATION_COULD_NOT_BE_PERFORMED'];
        if (!result.challenge_required && failedStatuses.includes(result.status)) {
            return res.status(400).json({ success: false, message: 'Authentication failed: ' + result.status, details: { auth_data: result.auth_data || {} } });
        }

        res.json({ success: true, message: 'Authentication initiated', data: result });
    } catch (err) {
        logError('initiate-auth', err);
        res.status(502).json({ success: false, message: 'Authentication initiation failed: ' + err.message });
    }
});

// ── API: Verify Authentication ─────────────────────────────
// Direct REST call to GP 3DS2 API: GET /authentications/{serverTransId}

app.post('/api/verify-auth', async (req, res) => {
    try {
        const data = req.body;
        const serverTransId = data.server_trans_id || '';
        const demoMode = !!data.demo_mode;

        if (!serverTransId && !demoMode)
            return res.status(400).json({ success: false, message: 'Server transaction ID is required' });

        let result;
        if (demoMode) {
            result = simulateVerifyAuth();
        } else {
            logApi('verify-auth', 'REQUEST', { server_trans_id: serverTransId });

            const timestamp = getTimestamp();
            const hash = generateHash(SHARED_SECRET, [timestamp, MERCHANT_ID, serverTransId].join('.'));

            const queryParams = {
                merchant_id: MERCHANT_ID,
                request_timestamp: timestamp,
                __hash: hash,
            };

            const doc = await gp3dsRequest('GET', `authentications/${serverTransId}`, null, queryParams);

            logApi('verify-auth', 'RESPONSE', { status: doc.status, eci: doc.eci });

            const authenticated = ['05', '06', '02', '01'].includes(doc.eci);
            result = {
                authenticated,
                auth_data: {
                    eci: doc.eci || null,
                    cavv: doc.authentication_value || '',
                    xid: '',
                    ds_trans_id: doc.ds_trans_id || '',
                    authentication_value: doc.authentication_value || '',
                    message_version: doc.message_version || '2.2.0',
                    status: doc.status || '',
                },
            };
        }

        if (!result.authenticated)
            return res.status(400).json({ success: false, message: 'Authentication verification failed', details: { auth_data: result.auth_data } });

        res.json({ success: true, message: 'Authentication verified', data: result });
    } catch (err) {
        logError('verify-auth', err);
        res.status(502).json({ success: false, message: 'Authentication verification failed: ' + err.message });
    }
});

// ── API: Authorize Payment ─────────────────────────────────
// Uses SDK's card.charge() via GpEcomConnector (XML API)

app.post('/api/authorize-payment', async (req, res) => {
    try {
        const data = req.body;
        const cardNumber = (data.card_number || '').replace(/\D/g, '');
        const amount = data.amount || '';
        const currency = (data.currency || 'EUR').toUpperCase();
        const cvv = data.cvv || '';
        const authData = data.auth_data || {};
        const demoMode = !!data.demo_mode;

        if (!cardNumber || !validateCardNumber(cardNumber))
            return res.status(400).json({ success: false, message: 'Invalid card number' });
        if (!amount || !validateAmount(String(amount)))
            return res.status(400).json({ success: false, message: 'Invalid amount' });
        if (!validateCurrency(currency))
            return res.status(400).json({ success: false, message: 'Invalid currency' });
        if (cvv && !validateCvv(cvv))
            return res.status(400).json({ success: false, message: 'Invalid CVV' });
        if (!Object.keys(authData).length && !demoMode)
            return res.status(400).json({ success: false, message: 'Authentication data is required' });

        const params = {
            card_number: cardNumber, amount: String(amount), currency, exp_date: data.exp_date || '1228',
            card_holder: data.card_holder || 'Test Customer', cvv, order_id: data.order_id || '', auth_data: authData,
        };

        let result;
        if (demoMode) {
            result = simulateAuthorization(params);
        } else {
            const card = buildCard(params);
            card.cvn = cvv;

            const secureEcom = new ThreeDSecure();
            secureEcom.eci = authData.eci || '';
            secureEcom.cavv = authData.cavv || '';
            secureEcom.xid = authData.xid || '';
            secureEcom.directoryServerTransactionId = authData.ds_trans_id || '';
            secureEcom.authenticationValue = authData.authentication_value || authData.cavv || '';
            secureEcom.messageVersion = authData.message_version || '2.2.0';
            card.threeDSecure = secureEcom;

            const amountDecimal = parseInt(params.amount, 10) / 100;

            logApi('authorize', 'REQUEST', { card: maskCard(cardNumber), amount: amountDecimal, currency, order_id: params.order_id, eci: authData.eci });

            const response = await card.charge(amountDecimal)
                .withCurrency(currency)
                .withOrderId(params.order_id || undefined)
                .withAllowDuplicates(true)
                .execute();

            logApi('authorize', 'RESPONSE', { response_code: response.responseCode, response_message: response.responseMessage, transaction_id: response.transactionId });

            const authorized = response.responseCode === '00';
            result = {
                authorized,
                transaction_id: response.transactionId || '',
                order_id: params.order_id || '',
                auth_code: response.authorizationCode || '',
                message: authorized ? 'Authorised' : (response.responseMessage || 'Payment declined'),
                response_code: response.responseCode,
            };
        }

        if (!result.authorized)
            return res.status(400).json({ success: false, message: result.message || 'Payment declined', details: { response_code: result.response_code } });

        res.json({ success: true, message: 'Payment authorized', data: result });
    } catch (err) {
        logError('authorize-payment', err);
        res.status(502).json({ success: false, message: 'Payment authorization failed: ' + err.message });
    }
});

// ── Webhooks ───────────────────────────────────────────────

app.post('/webhooks/method-notification', (req, res) => {
    const methodData = req.body.threeDSMethodData || '';
    let serverTransId = '';
    let decoded = {};

    if (methodData) {
        try {
            decoded = JSON.parse(Buffer.from(methodData, 'base64').toString());
            serverTransId = decoded.threeDSServerTransID || '';
        } catch (e) { /* ignore decode errors */ }
    }

    const entry = `[${new Date().toISOString()}] Method Notification - ServerTransID: ${serverTransId || 'unknown'} - Data: ${JSON.stringify(decoded)}\n`;
    fs.appendFileSync(path.join(logDir, 'method-notifications.log'), entry);

    res.send(`<!DOCTYPE html><html><head><title>3DS Method Complete</title></head><body><script>
        if (window.parent !== window) { window.parent.postMessage({ type: 'method_complete', serverTransId: ${JSON.stringify(serverTransId)} }, '*'); }
    </script></body></html>`);
});

app.post('/webhooks/challenge-notification', (req, res) => {
    const cres = req.body.cres || '';
    if (!cres) return res.status(400).send('<html><body><p>Missing cres parameter</p></body></html>');

    let decodedString = '{}', decodedData = {};
    try {
        decodedString = Buffer.from(cres, 'base64').toString();
        decodedData = JSON.parse(decodedString);
    } catch (e) { /* ignore decode errors */ }

    const entry = `[${new Date().toISOString()}] Challenge Notification - TransStatus: ${decodedData.transStatus || 'unknown'} - ServerTransID: ${decodedData.threeDSServerTransID || 'unknown'}\n`;
    fs.appendFileSync(path.join(logDir, 'challenge-notifications.log'), entry);

    res.send(`<!DOCTYPE html><html><head><title>Challenge Complete</title></head><body><script>
        var cresData = ${decodedString};
        if (window.parent !== window) { window.parent.postMessage({ type: 'challenge_complete', cres: cresData, data: cresData }, window.location.origin); }
    </script></body></html>`);
});

// ── Demo Mode Simulations ──────────────────────────────────

function simulateEnrollment(cardNumber, orderId) {
    const testCard = TEST_CARDS[cardNumber];
    const serverTransId = 'demo-' + randomHex(16);
    const hasMethodUrl = !(testCard && testCard.noMethodUrl);

    return {
        enrolled: 'Y',
        server_trans_id: serverTransId,
        method_url: hasMethodUrl ? 'https://acs.sandbox.example.com/3ds/method' : null,
        method_data: hasMethodUrl ? Buffer.from(JSON.stringify({ threeDSServerTransID: serverTransId, threeDSMethodNotificationURL: METHOD_NOTIFICATION_URL || 'http://localhost:3001/webhooks/method-notification' })).toString('base64') : null,
        ds_trans_id: 'demo-ds-' + randomHex(8),
        order_id: orderId || 'demo-order-' + Date.now(),
        acs_start_version: '2.1.0',
        acs_end_version: '2.2.0',
        message: 'Card enrolled in 3DS2 (demo)',
    };
}

function simulateAuthentication(cardNumber, serverTransId, data) {
    let testCard = TEST_CARDS[cardNumber];
    if (!testCard) testCard = { flow: 'frictionless', result: 'AUTHENTICATION_SUCCESSFUL', eci: '05', brand: 'VISA' };
    if (!serverTransId) serverTransId = 'demo-' + Date.now();

    if (testCard.flow === 'challenge') {
        return {
            challenge_required: true,
            status: 'CHALLENGE_REQUIRED',
            challenge: {
                acs_url: 'https://acs.sandbox.example.com/3ds/challenge',
                creq: Buffer.from(JSON.stringify({ threeDSServerTransID: serverTransId, acsTransID: 'demo-acs-' + randomHex(8), messageType: 'CReq', messageVersion: '2.2.0', challengeWindowSize: '05' })).toString('base64'),
                server_trans_id: serverTransId,
            },
        };
    }

    const isSuccess = ['AUTHENTICATION_SUCCESSFUL', 'AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL'].includes(testCard.result);
    return {
        challenge_required: false,
        status: testCard.result,
        auth_data: {
            eci: testCard.eci,
            cavv: isSuccess ? randomBase64(20) : '',
            xid: isSuccess ? randomBase64(20) : '',
            ds_trans_id: 'demo-ds-' + randomHex(8),
            authentication_value: isSuccess ? randomBase64(20) : '',
            message_version: '2.2.0',
            status: testCard.result,
        },
    };
}

function simulateVerifyAuth() {
    return {
        authenticated: true,
        auth_data: {
            eci: '05', cavv: randomBase64(20), xid: randomBase64(20),
            ds_trans_id: 'demo-ds-' + randomHex(8), authentication_value: randomBase64(20),
            message_version: '2.2.0', status: 'AUTHENTICATION_SUCCESSFUL',
        },
    };
}

function simulateAuthorization(params) {
    const cardNumber = params.card_number.replace(/\D/g, '');
    const testCard = TEST_CARDS[cardNumber];
    const failedResults = ['AUTHENTICATION_FAILED', 'AUTHENTICATION_ISSUER_REJECTED', 'AUTHENTICATION_COULD_NOT_BE_PERFORMED'];

    if (testCard && failedResults.includes(testCard.result)) {
        return { authorized: false, transaction_id: '', order_id: params.order_id, auth_code: '', message: 'Payment declined - authentication failed (' + testCard.result + ')', response_code: '110' };
    }

    return { authorized: true, transaction_id: 'demo-' + randomHex(8), order_id: params.order_id, auth_code: String(Math.floor(100000 + Math.random() * 900000)), message: 'Authorised (demo)', response_code: '00' };
}

// ── Start ──────────────────────────────────────────────────

app.listen(port, '0.0.0.0', () => {
    console.log(`3DS2 Node.js server running at http://localhost:${port}`);
    console.log(`Backend: GPeCOM (merchant: ${MERCHANT_ID}, account: ${ACCOUNT_ID})`);
    console.log(`3DS2 API: ${GP_3DS2_BASE_URL}`);
});
