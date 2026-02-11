package com.globalpayments.example;

import com.global.api.ServicesContainer;
import com.global.api.entities.Address;
import com.global.api.entities.ThreeDSecure;
import com.global.api.entities.Transaction;
import com.global.api.entities.enums.*;
import com.global.api.entities.exceptions.ApiException;
import com.global.api.paymentMethods.CreditCardData;
import com.global.api.serviceConfigs.GpEcomConfig;
import com.global.api.services.Secure3dService;
import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import io.github.cdimascio.dotenv.Dotenv;
import jakarta.servlet.ServletException;
import jakarta.servlet.annotation.WebServlet;
import jakarta.servlet.http.HttpServlet;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;

import java.io.*;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.security.SecureRandom;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.*;
import org.joda.time.DateTime;

@WebServlet(urlPatterns = {"/api/*", "/webhooks/*", ""})
public class GpecomServlet extends HttpServlet {

    private static final long serialVersionUID = 1L;
    private static final Gson gson = new Gson();
    private static final SecureRandom random = new SecureRandom();
    private static final DateTimeFormatter LOG_FMT = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss");

    private Dotenv dotenv;
    private Path logDir;
    private Path projectRoot;

    // Test cards (Message Version 2.2)
    private static final Map<String, TestCard> TEST_CARDS = new HashMap<>();
    static {
        TEST_CARDS.put("4222000006285344", new TestCard("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", false));
        TEST_CARDS.put("4222000009719489", new TestCard("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", true));
        TEST_CARDS.put("4222000005218627", new TestCard("frictionless", "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL", "06", "VISA", false));
        TEST_CARDS.put("4222000002144131", new TestCard("frictionless", "AUTHENTICATION_FAILED", "07", "VISA", false));
        TEST_CARDS.put("4222000007275799", new TestCard("frictionless", "AUTHENTICATION_ISSUER_REJECTED", "07", "VISA", false));
        TEST_CARDS.put("4222000008880910", new TestCard("frictionless", "AUTHENTICATION_COULD_NOT_BE_PERFORMED", "07", "VISA", false));
        TEST_CARDS.put("4222000001227408", new TestCard("challenge", "CHALLENGE_REQUIRED", null, "VISA", false));
        TEST_CARDS.put("5354560000000004", new TestCard("frictionless", "AUTHENTICATION_SUCCESSFUL", "02", "MC", false));
        TEST_CARDS.put("5571596304025153", new TestCard("frictionless", "AUTHENTICATION_SUCCESSFUL", "02", "MC", true));
        TEST_CARDS.put("5580364874958322", new TestCard("frictionless", "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL", "01", "MC", false));
        TEST_CARDS.put("5540010585397800", new TestCard("frictionless", "AUTHENTICATION_FAILED", "00", "MC", false));
        TEST_CARDS.put("5588312194362669", new TestCard("frictionless", "AUTHENTICATION_ISSUER_REJECTED", "00", "MC", false));
        TEST_CARDS.put("5520680211891022", new TestCard("frictionless", "AUTHENTICATION_COULD_NOT_BE_PERFORMED", "00", "MC", false));
        TEST_CARDS.put("5506874496684651", new TestCard("challenge", "CHALLENGE_REQUIRED", null, "MC", false));
    }

    private static final Set<String> FAILED_STATUSES = Set.of(
        "AUTHENTICATION_FAILED", "AUTHENTICATION_ISSUER_REJECTED", "AUTHENTICATION_COULD_NOT_BE_PERFORMED"
    );
    private static final Set<String> SUCCESS_ECIS = Set.of("05", "06", "02", "01");

    @Override
    public void init() throws ServletException {
        try {
            dotenv = Dotenv.configure().directory("./").ignoreIfMissing().load();

            // Resolve paths
            String basePath = System.getProperty("user.dir");
            logDir = Paths.get(basePath, "logs");
            Files.createDirectories(logDir);
            projectRoot = Paths.get(basePath).getParent();
            if (projectRoot == null) projectRoot = Paths.get(basePath);

            // SDK Configuration
            String merchantId = env("GPECOM_MERCHANT_ID", "radoslav");
            String accountId = env("GPECOM_ACCOUNT", "internet");
            String sharedSecret = env("GPECOM_SHARED_SECRET", "");
            String methodNotificationUrl = env("METHOD_NOTIFICATION_URL", null);
            String challengeNotificationUrl = env("CHALLENGE_NOTIFICATION_URL", null);

            GpEcomConfig config = new GpEcomConfig();
            config.setMerchantId(merchantId);
            config.setAccountId(accountId);
            config.setSharedSecret(sharedSecret);
            config.setSecure3dVersion(Secure3dVersion.TWO);
            // Use null (not empty string) when not configured — SDK's maybeSetKey() skips null but sends empty strings
            if (methodNotificationUrl != null && !methodNotificationUrl.isEmpty())
                config.setMethodNotificationUrl(methodNotificationUrl);
            if (challengeNotificationUrl != null && !challengeNotificationUrl.isEmpty())
                config.setChallengeNotificationUrl(challengeNotificationUrl);
            config.setMerchantContactUrl(env("MERCHANT_CONTACT_URL", "https://developer.globalpayments.com"));

            ServicesContainer.configureService(config);

            System.out.println("3DS2 Java server initialized");
            System.out.println("Backend: GPeCOM (merchant: " + merchantId + ", account: " + accountId + ")");
        } catch (Exception e) {
            throw new ServletException("Failed to initialize 3DS2 servlet", e);
        }
    }

    private String env(String key, String def) {
        String val = dotenv != null ? dotenv.get(key) : null;
        if (val == null || val.isEmpty()) val = System.getenv(key);
        return (val != null && !val.isEmpty()) ? val : def;
    }

    // ── GET: Serve index.html ──────────────────────────────

    @Override
    protected void doGet(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        // Serve root index.html for the root path
        Path indexFile = projectRoot.resolve("index.html");
        if (Files.exists(indexFile)) {
            resp.setContentType("text/html; charset=UTF-8");
            Files.copy(indexFile, resp.getOutputStream());
        } else {
            resp.setStatus(404);
            resp.getWriter().write("index.html not found at " + indexFile);
        }
    }

    // ── POST Router ────────────────────────────────────────

    @Override
    protected void doPost(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        // CORS + security headers
        resp.setHeader("X-Content-Type-Options", "nosniff");
        resp.setHeader("X-Frame-Options", "SAMEORIGIN");
        resp.setHeader("Access-Control-Allow-Origin", "*");
        resp.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
        resp.setHeader("Access-Control-Allow-Headers", "Content-Type");
        resp.setHeader("Cache-Control", "no-store, no-cache, must-revalidate");

        String servletPath = req.getServletPath();
        String pathInfo = req.getPathInfo();
        String fullPath = servletPath + (pathInfo != null ? pathInfo : "");

        switch (fullPath) {
            case "/api/check-enrollment" -> handleCheckEnrollment(req, resp);
            case "/api/initiate-auth" -> handleInitiateAuth(req, resp);
            case "/api/verify-auth" -> handleVerifyAuth(req, resp);
            case "/api/authorize-payment" -> handleAuthorizePayment(req, resp);
            case "/webhooks/method-notification" -> handleMethodNotification(req, resp);
            case "/webhooks/challenge-notification" -> handleChallengeNotification(req, resp);
            default -> {
                resp.setStatus(404);
                writeJson(resp, errorResponse("Not found: " + fullPath));
            }
        }
    }

    @Override
    protected void doOptions(HttpServletRequest req, HttpServletResponse resp) {
        resp.setHeader("Access-Control-Allow-Origin", "*");
        resp.setHeader("Access-Control-Allow-Methods", "POST, OPTIONS");
        resp.setHeader("Access-Control-Allow-Headers", "Content-Type");
        resp.setStatus(200);
    }

    // ── API: Check Enrollment ──────────────────────────────

    private void handleCheckEnrollment(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        try {
            JsonObject data = readJson(req);
            String cardNumber = cleanCard(getString(data, "card_number"));
            String expDate = getString(data, "exp_date");
            String cardHolder = getString(data, "card_holder");
            String orderId = getString(data, "order_id");
            boolean demoMode = getBool(data, "demo_mode");

            if (cardNumber.isEmpty() || !validateCardNumber(cardNumber)) { writeJson(resp, 400, errorResponse("Invalid card number")); return; }
            if (expDate.isEmpty() || !validateExpDate(expDate)) { writeJson(resp, 400, errorResponse("Invalid expiry date (MMYY format required)")); return; }
            if (cardHolder.isEmpty() || cardHolder.length() < 2 || cardHolder.length() > 100) { writeJson(resp, 400, errorResponse("Cardholder name must be 2-100 characters")); return; }
            if (orderId.isEmpty()) { writeJson(resp, 400, errorResponse("Order ID is required")); return; }

            Map<String, Object> result;
            if (demoMode) {
                result = simulateEnrollment(cardNumber, orderId);
            } else {
                CreditCardData card = buildCard(cardNumber, expDate, cardHolder);
                logApi("check-enrollment", "REQUEST", "card=" + maskCard(cardNumber) + " order_id=" + orderId);

                ThreeDSecure tds = Secure3dService.checkEnrollment(card)
                    .execute(Secure3dVersion.TWO);

                logApi("check-enrollment", "RESPONSE", "enrolled=" + tds.isEnrolled() + " server_trans_id=" + tds.getServerTransactionId());

                boolean enrolled = tds.isEnrolled();
                result = new LinkedHashMap<>();
                result.put("enrolled", enrolled ? "Y" : "N");
                result.put("server_trans_id", tds.getServerTransactionId());
                result.put("method_url", tds.getIssuerAcsUrl());
                result.put("method_data", tds.getPayerAuthenticationRequest());
                result.put("ds_trans_id", tds.getDirectoryServerTransactionId());
                result.put("order_id", orderId);
                result.put("acs_start_version", tds.getAcsStartVersion());
                result.put("acs_end_version", tds.getAcsEndVersion());
                result.put("message", enrolled ? "Card enrolled in 3DS2" : "Card not enrolled");
            }

            writeJson(resp, successResponse("Enrollment check complete", result));
        } catch (ApiException e) {
            logError("check-enrollment", e);
            writeJson(resp, 502, errorResponse("Enrollment check failed: " + e.getMessage()));
        } catch (Exception e) {
            logError("check-enrollment", e);
            writeJson(resp, 500, errorResponse("Enrollment check failed: " + e.getMessage()));
        }
    }

    // ── API: Initiate Authentication ───────────────────────

    private void handleInitiateAuth(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        try {
            JsonObject data = readJson(req);
            String cardNumber = cleanCard(getString(data, "card_number"));
            String amount = getString(data, "amount");
            String currency = getString(data, "currency", "EUR").toUpperCase();
            String serverTransId = getString(data, "server_trans_id");
            boolean demoMode = getBool(data, "demo_mode");

            if (cardNumber.isEmpty() || !validateCardNumber(cardNumber)) { writeJson(resp, 400, errorResponse("Invalid card number")); return; }
            if (amount.isEmpty() || !validateAmount(amount)) { writeJson(resp, 400, errorResponse("Invalid amount")); return; }
            if (!validateCurrency(currency)) { writeJson(resp, 400, errorResponse("Invalid currency (EUR, USD, GBP accepted)")); return; }
            if (serverTransId.isEmpty() && !demoMode) { writeJson(resp, 400, errorResponse("Server transaction ID is required")); return; }

            String expDate = getString(data, "exp_date", "1228");
            String cardHolder = getString(data, "card_holder", "Test Customer");
            String methodUrlComplete = getString(data, "method_url_complete", "true");
            JsonObject browserDataObj = data.has("browser_data") && data.get("browser_data").isJsonObject() ? data.getAsJsonObject("browser_data") : new JsonObject();
            JsonObject customerObj = data.has("customer") && data.get("customer").isJsonObject() ? data.getAsJsonObject("customer") : new JsonObject();

            Map<String, Object> result;
            boolean challengeRequired = false;
            String status = "";

            if (demoMode) {
                result = simulateAuthentication(cardNumber, serverTransId);
                challengeRequired = (boolean) result.get("challenge_required");
                status = (String) result.get("status");
            } else {
                CreditCardData card = buildCard(cardNumber, expDate, cardHolder);
                ThreeDSecure secureEcom = new ThreeDSecure();
                secureEcom.setServerTransactionId(serverTransId);
                secureEcom.setAcsEndVersion("2.2.0");
                card.setThreeDSecure(secureEcom);

                com.global.api.entities.BrowserData browserData = buildBrowserData(browserDataObj, req);
                Address address = new Address();
                address.setStreetAddress1(getString(data, "address", "Flat 123"));
                address.setStreetAddress2(getString(data, "address2", "House 456"));
                address.setCity(getString(data, "city", "Halifax"));
                address.setPostalCode(getString(data, "postal_code", "W5 9HR"));
                address.setCountryCode(getString(data, "country_code", "826"));

                BigDecimal amountDec = new BigDecimal(amount).divide(BigDecimal.valueOf(100));
                MethodUrlCompletion completion = "true".equals(methodUrlComplete) ? MethodUrlCompletion.Yes : MethodUrlCompletion.No;

                String email = customerObj.has("email") ? customerObj.get("email").getAsString() : "test@example.com";

                logApi("initiate-auth", "REQUEST", "card=" + maskCard(cardNumber) + " amount=" + amountDec + " currency=" + currency);

                ThreeDSecure tds = Secure3dService.initiateAuthentication(card, secureEcom)
                    .withAmount(amountDec)
                    .withCurrency(currency)
                    .withAuthenticationSource(AuthenticationSource.Browser)
                    .withMethodUrlCompletion(completion)
                    .withOrderCreateDate(DateTime.now())
                    .withAddress(address, AddressType.Shipping)
                    .withAddress(address, AddressType.Billing)
                    .withBrowserData(browserData)
                    .withCustomerEmail(email)
                    .execute(Secure3dVersion.TWO);

                logApi("initiate-auth", "RESPONSE", "status=" + tds.getStatus() + " eci=" + tds.getEci());

                status = tds.getStatus() != null ? tds.getStatus() : "";
                result = new LinkedHashMap<>();

                if ("CHALLENGE_REQUIRED".equals(status)) {
                    challengeRequired = true;
                    result.put("challenge_required", true);
                    result.put("status", status);
                    Map<String, Object> challenge = new LinkedHashMap<>();
                    challenge.put("acs_url", tds.getIssuerAcsUrl());
                    challenge.put("creq", tds.getPayerAuthenticationRequest());
                    challenge.put("server_trans_id", tds.getServerTransactionId());
                    result.put("challenge", challenge);
                } else {
                    result.put("challenge_required", false);
                    result.put("status", status);
                    result.put("auth_data", buildAuthDataMap(tds));
                }
            }

            if (!challengeRequired && FAILED_STATUSES.contains(status)) {
                writeJson(resp, 400, errorResponse("Authentication failed: " + status));
                return;
            }

            writeJson(resp, successResponse("Authentication initiated", result));
        } catch (ApiException e) {
            logError("initiate-auth", e);
            writeJson(resp, 502, errorResponse("Authentication initiation failed: " + e.getMessage()));
        } catch (Exception e) {
            logError("initiate-auth", e);
            writeJson(resp, 500, errorResponse("Authentication initiation failed: " + e.getMessage()));
        }
    }

    // ── API: Verify Authentication ─────────────────────────

    private void handleVerifyAuth(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        try {
            JsonObject data = readJson(req);
            String serverTransId = getString(data, "server_trans_id");
            boolean demoMode = getBool(data, "demo_mode");

            if (serverTransId.isEmpty() && !demoMode) { writeJson(resp, 400, errorResponse("Server transaction ID is required")); return; }

            boolean authenticated;
            Map<String, Object> authData;

            if (demoMode) {
                Map<String, Object> sim = simulateVerifyAuth();
                authenticated = (boolean) sim.get("authenticated");
                @SuppressWarnings("unchecked")
                Map<String, Object> ad = (Map<String, Object>) sim.get("auth_data");
                authData = ad;
            } else {
                logApi("verify-auth", "REQUEST", "server_trans_id=" + serverTransId);

                ThreeDSecure tds = Secure3dService.getAuthenticationData()
                    .withServerTransactionId(serverTransId)
                    .execute();

                logApi("verify-auth", "RESPONSE", "status=" + tds.getStatus() + " eci=" + tds.getEci());

                authenticated = tds.getEci() != null && SUCCESS_ECIS.contains(tds.getEci());
                authData = buildAuthDataMap(tds);
            }

            if (!authenticated) {
                Map<String, Object> details = new LinkedHashMap<>();
                details.put("auth_data", authData);
                writeJson(resp, 400, errorResponseWithDetails("Authentication verification failed", details));
                return;
            }

            Map<String, Object> result = new LinkedHashMap<>();
            result.put("authenticated", true);
            result.put("auth_data", authData);
            writeJson(resp, successResponse("Authentication verified", result));
        } catch (ApiException e) {
            logError("verify-auth", e);
            writeJson(resp, 502, errorResponse("Authentication verification failed: " + e.getMessage()));
        } catch (Exception e) {
            logError("verify-auth", e);
            writeJson(resp, 500, errorResponse("Authentication verification failed: " + e.getMessage()));
        }
    }

    // ── API: Authorize Payment ─────────────────────────────

    private void handleAuthorizePayment(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        try {
            JsonObject data = readJson(req);
            String cardNumber = cleanCard(getString(data, "card_number"));
            String amount = getString(data, "amount");
            String currency = getString(data, "currency", "EUR").toUpperCase();
            String cvv = getString(data, "cvv");
            boolean demoMode = getBool(data, "demo_mode");
            JsonObject authDataObj = data.has("auth_data") && data.get("auth_data").isJsonObject() ? data.getAsJsonObject("auth_data") : null;

            if (cardNumber.isEmpty() || !validateCardNumber(cardNumber)) { writeJson(resp, 400, errorResponse("Invalid card number")); return; }
            if (amount.isEmpty() || !validateAmount(amount)) { writeJson(resp, 400, errorResponse("Invalid amount")); return; }
            if (!validateCurrency(currency)) { writeJson(resp, 400, errorResponse("Invalid currency")); return; }
            if (!cvv.isEmpty() && !validateCvv(cvv)) { writeJson(resp, 400, errorResponse("Invalid CVV")); return; }
            if (authDataObj == null && !demoMode) { writeJson(resp, 400, errorResponse("Authentication data is required")); return; }

            String expDate = getString(data, "exp_date", "1228");
            String cardHolder = getString(data, "card_holder", "Test Customer");
            String orderId = getString(data, "order_id");

            Map<String, Object> result;

            if (demoMode) {
                result = simulateAuthorization(cardNumber, orderId);
            } else {
                CreditCardData card = buildCard(cardNumber, expDate, cardHolder);
                card.setCvn(cvv);

                ThreeDSecure secureEcom = new ThreeDSecure();
                secureEcom.setEci(getJsonString(authDataObj, "eci", ""));
                secureEcom.setCavv(getJsonString(authDataObj, "cavv", ""));
                secureEcom.setXid(getJsonString(authDataObj, "xid", ""));
                secureEcom.setDirectoryServerTransactionId(getJsonString(authDataObj, "ds_trans_id", ""));
                String authVal = getJsonString(authDataObj, "authentication_value", "");
                secureEcom.setAuthenticationValue(!authVal.isEmpty() ? authVal : getJsonString(authDataObj, "cavv", ""));
                secureEcom.setMessageVersion(getJsonString(authDataObj, "message_version", "2.2.0"));
                card.setThreeDSecure(secureEcom);

                BigDecimal amountDec = new BigDecimal(amount).divide(BigDecimal.valueOf(100));

                logApi("authorize", "REQUEST", "card=" + maskCard(cardNumber) + " amount=" + amountDec + " currency=" + currency + " eci=" + secureEcom.getEci());

                Transaction response = card.charge(amountDec)
                    .withCurrency(currency)
                    .withOrderId(orderId.isEmpty() ? null : orderId)
                    .withAllowDuplicates(true)
                    .execute();

                logApi("authorize", "RESPONSE", "response_code=" + response.getResponseCode() + " transaction_id=" + response.getTransactionId());

                boolean authorized = "00".equals(response.getResponseCode());
                result = new LinkedHashMap<>();
                result.put("authorized", authorized);
                result.put("transaction_id", response.getTransactionId() != null ? response.getTransactionId() : "");
                result.put("order_id", orderId);
                result.put("auth_code", response.getAuthorizationCode() != null ? response.getAuthorizationCode() : "");
                result.put("message", authorized ? "Authorised" : (response.getResponseMessage() != null ? response.getResponseMessage() : "Payment declined"));
                result.put("response_code", response.getResponseCode() != null ? response.getResponseCode() : "");
            }

            boolean authorized = (boolean) result.get("authorized");
            if (!authorized) {
                Map<String, Object> details = new LinkedHashMap<>();
                details.put("response_code", result.get("response_code"));
                writeJson(resp, 400, errorResponseWithDetails((String) result.get("message"), details));
                return;
            }

            writeJson(resp, successResponse("Payment authorized", result));
        } catch (ApiException e) {
            logError("authorize-payment", e);
            writeJson(resp, 502, errorResponse("Payment authorization failed: " + e.getMessage()));
        } catch (Exception e) {
            logError("authorize-payment", e);
            writeJson(resp, 500, errorResponse("Payment authorization failed: " + e.getMessage()));
        }
    }

    // ── Webhooks ───────────────────────────────────────────

    private void handleMethodNotification(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        String methodData = req.getParameter("threeDSMethodData");
        String serverTransId = "";

        if (methodData != null && !methodData.isEmpty()) {
            try {
                String decoded = new String(Base64.getDecoder().decode(methodData), StandardCharsets.UTF_8);
                JsonObject obj = JsonParser.parseString(decoded).getAsJsonObject();
                serverTransId = obj.has("threeDSServerTransID") ? obj.get("threeDSServerTransID").getAsString() : "";
            } catch (Exception ignored) {}
        }

        logApi("method-notification", "RECEIVED", "server_trans_id=" + serverTransId);

        resp.setContentType("text/html; charset=UTF-8");
        resp.getWriter().write("<!DOCTYPE html><html><head><title>3DS Method Complete</title></head><body><script>" +
            "if (window.parent !== window) { window.parent.postMessage({ type: 'method_complete', serverTransId: '" + escapeJs(serverTransId) + "' }, '*'); }" +
            "</script></body></html>");
    }

    private void handleChallengeNotification(HttpServletRequest req, HttpServletResponse resp) throws IOException {
        String cres = req.getParameter("cres");
        if (cres == null || cres.isEmpty()) {
            resp.setStatus(400);
            resp.getWriter().write("<html><body><p>Missing cres parameter</p></body></html>");
            return;
        }

        String decodedString = "{}";
        try { decodedString = new String(Base64.getDecoder().decode(cres), StandardCharsets.UTF_8); } catch (Exception ignored) {}

        logApi("challenge-notification", "RECEIVED", decodedString);

        resp.setContentType("text/html; charset=UTF-8");
        resp.getWriter().write("<!DOCTYPE html><html><head><title>Challenge Complete</title></head><body><script>" +
            "var cresData = " + decodedString + ";" +
            "if (window.parent !== window) { window.parent.postMessage({ type: 'challenge_complete', cres: cresData, data: cresData }, window.location.origin); }" +
            "</script></body></html>");
    }

    // ── Card Builder ───────────────────────────────────────

    private CreditCardData buildCard(String number, String expDate, String cardHolder) {
        CreditCardData card = new CreditCardData();
        card.setNumber(number);
        card.setExpMonth(Integer.parseInt(expDate.substring(0, 2)));
        card.setExpYear(Integer.parseInt("20" + expDate.substring(2, 4)));
        card.setCardHolderName(cardHolder);
        return card;
    }

    private com.global.api.entities.BrowserData buildBrowserData(JsonObject data, HttpServletRequest req) {
        com.global.api.entities.BrowserData browser = new com.global.api.entities.BrowserData();
        browser.setAcceptHeader(getJsonString(data, "accept_header", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"));
        browser.setColorDepth(mapColorDepth(getJsonString(data, "color_depth", "24")));
        browser.setIpAddress(req.getRemoteAddr());
        browser.setJavaEnabled(Boolean.parseBoolean(getJsonString(data, "java_enabled", "false")));
        browser.setJavaScriptEnabled(Boolean.parseBoolean(getJsonString(data, "javascript_enabled", "true")));
        browser.setLanguage(getJsonString(data, "language", "en-US"));
        browser.setScreenHeight(Integer.parseInt(getJsonString(data, "screen_height", "1080")));
        browser.setScreenWidth(Integer.parseInt(getJsonString(data, "screen_width", "1920")));
        browser.setChallengeWindowSize(mapChallengeWindowSize(getJsonString(data, "challenge_window_size", "05")));
        browser.setTimezone(getJsonString(data, "timezone", "0"));
        browser.setUserAgent(getJsonString(data, "user_agent", req.getHeader("User-Agent") != null ? req.getHeader("User-Agent") : "Mozilla/5.0"));
        return browser;
    }

    private Map<String, Object> buildAuthDataMap(ThreeDSecure tds) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("eci", tds.getEci());
        m.put("cavv", tds.getAuthenticationValue());
        m.put("xid", tds.getXid() != null ? tds.getXid() : "");
        m.put("ds_trans_id", tds.getDirectoryServerTransactionId());
        m.put("authentication_value", tds.getAuthenticationValue());
        m.put("message_version", tds.getMessageVersion() != null ? tds.getMessageVersion() : "2.2.0");
        m.put("status", tds.getStatus());
        return m;
    }

    // ── Enum Mappers ───────────────────────────────────────

    private ColorDepth mapColorDepth(String depth) {
        return switch (depth) {
            case "1" -> ColorDepth.OneBit; case "2" -> ColorDepth.TwoBit; case "4" -> ColorDepth.FourBit;
            case "8" -> ColorDepth.EightBit; case "15" -> ColorDepth.FifteenBit; case "16" -> ColorDepth.SixteenBit;
            case "24" -> ColorDepth.TwentyFourBit; case "32" -> ColorDepth.ThirtyTwoBit; case "48" -> ColorDepth.FortyEightBit;
            default -> ColorDepth.TwentyFourBit;
        };
    }

    private ChallengeWindowSize mapChallengeWindowSize(String size) {
        return switch (size) {
            case "01" -> ChallengeWindowSize.Windowed_250x400; case "02" -> ChallengeWindowSize.Windowed_390x400;
            case "03" -> ChallengeWindowSize.Windowed_500x600; case "04" -> ChallengeWindowSize.Windowed_600x400;
            case "05" -> ChallengeWindowSize.FullScreen;
            default -> ChallengeWindowSize.FullScreen;
        };
    }

    // ── Validation ─────────────────────────────────────────

    private boolean validateCardNumber(String number) {
        if (number.length() < 13 || number.length() > 19) return false;
        int sum = 0; boolean alt = false;
        for (int i = number.length() - 1; i >= 0; i--) {
            int d = number.charAt(i) - '0';
            if (alt) { d *= 2; if (d > 9) d -= 9; }
            sum += d;
            alt = !alt;
        }
        return sum % 10 == 0;
    }

    private boolean validateExpDate(String exp) {
        if (exp.length() != 4 || !exp.matches("\\d{4}")) return false;
        int month = Integer.parseInt(exp.substring(0, 2)), year = Integer.parseInt(exp.substring(2, 4));
        if (month < 1 || month > 12) return false;
        Calendar cal = Calendar.getInstance();
        int curYear = cal.get(Calendar.YEAR) % 100, curMonth = cal.get(Calendar.MONTH) + 1;
        return year > curYear || (year == curYear && month >= curMonth);
    }

    private boolean validateAmount(String amount) { try { int v = Integer.parseInt(amount); return v > 0 && v <= 99999999; } catch (Exception e) { return false; } }
    private boolean validateCurrency(String c) { return Set.of("EUR", "USD", "GBP").contains(c.toUpperCase()); }
    private boolean validateCvv(String cvv) { return cvv.matches("\\d{3,4}"); }

    private String maskCard(String n) { return n.length() < 10 ? "****" : n.substring(0, 6) + "*".repeat(n.length() - 10) + n.substring(n.length() - 4); }
    private String cleanCard(String s) { return s.replaceAll("[^0-9]", ""); }

    // ── JSON Helpers ───────────────────────────────────────

    private JsonObject readJson(HttpServletRequest req) throws IOException {
        try (BufferedReader reader = req.getReader()) {
            return JsonParser.parseReader(reader).getAsJsonObject();
        }
    }

    private String getString(JsonObject obj, String key) { return getString(obj, key, ""); }
    private String getString(JsonObject obj, String key, String def) {
        if (obj == null || !obj.has(key) || obj.get(key).isJsonNull()) return def;
        return obj.get(key).getAsString();
    }

    private boolean getBool(JsonObject obj, String key) {
        if (obj == null || !obj.has(key)) return false;
        JsonElement el = obj.get(key);
        if (el.isJsonPrimitive()) {
            if (el.getAsJsonPrimitive().isBoolean()) return el.getAsBoolean();
            if (el.getAsJsonPrimitive().isString()) return "true".equalsIgnoreCase(el.getAsString());
        }
        return false;
    }

    private String getJsonString(JsonObject obj, String key, String def) {
        if (obj == null || !obj.has(key) || obj.get(key).isJsonNull()) return def;
        return obj.get(key).getAsString();
    }

    private void writeJson(HttpServletResponse resp, Map<String, Object> data) throws IOException { writeJson(resp, 200, data); }
    private void writeJson(HttpServletResponse resp, int status, Map<String, Object> data) throws IOException {
        resp.setStatus(status);
        resp.setContentType("application/json; charset=UTF-8");
        resp.getWriter().write(gson.toJson(data));
    }

    private Map<String, Object> successResponse(String message, Map<String, Object> data) {
        Map<String, Object> r = new LinkedHashMap<>();
        r.put("success", true);
        r.put("message", message);
        r.put("data", data);
        return r;
    }

    private Map<String, Object> errorResponse(String message) {
        Map<String, Object> r = new LinkedHashMap<>();
        r.put("success", false);
        r.put("message", message);
        return r;
    }

    private Map<String, Object> errorResponseWithDetails(String message, Map<String, Object> details) {
        Map<String, Object> r = errorResponse(message);
        r.put("details", details);
        return r;
    }

    private String escapeJs(String s) { return s.replace("\\", "\\\\").replace("'", "\\'").replace("\"", "\\\""); }

    // ── Logging ────────────────────────────────────────────

    private void logApi(String step, String direction, String data) {
        try {
            String entry = "[" + LocalDateTime.now().format(LOG_FMT) + "] [" + step + "] [" + direction + "] " + data + "\n";
            Files.writeString(logDir.resolve("3ds2-api.log"), entry, StandardCharsets.UTF_8,
                java.nio.file.StandardOpenOption.CREATE, java.nio.file.StandardOpenOption.APPEND);
        } catch (Exception ignored) {}
    }

    private void logError(String step, Exception ex) {
        try {
            String entry = "[" + LocalDateTime.now().format(LOG_FMT) + "] [" + step + "] " + ex.getClass().getSimpleName() + ": " + ex.getMessage() + "\n";
            Files.writeString(logDir.resolve("3ds2-errors.log"), entry, StandardCharsets.UTF_8,
                java.nio.file.StandardOpenOption.CREATE, java.nio.file.StandardOpenOption.APPEND);
        } catch (Exception ignored) {}
    }

    // ── Demo Simulations ───────────────────────────────────

    private String randomHex(int bytes) {
        byte[] buf = new byte[bytes];
        random.nextBytes(buf);
        StringBuilder sb = new StringBuilder();
        for (byte b : buf) sb.append(String.format("%02x", b));
        return sb.toString();
    }

    private String randomBase64(int bytes) {
        byte[] buf = new byte[bytes];
        random.nextBytes(buf);
        return Base64.getEncoder().encodeToString(buf);
    }

    private Map<String, Object> simulateEnrollment(String cardNumber, String orderId) {
        TestCard tc = TEST_CARDS.get(cardNumber);
        String serverTransId = "demo-" + randomHex(16);
        boolean hasMethodUrl = tc == null || !tc.noMethodUrl;

        Map<String, Object> r = new LinkedHashMap<>();
        r.put("enrolled", "Y");
        r.put("server_trans_id", serverTransId);
        r.put("method_url", hasMethodUrl ? "https://acs.sandbox.example.com/3ds/method" : null);
        r.put("method_data", hasMethodUrl ? Base64.getEncoder().encodeToString(("{\"threeDSServerTransID\":\"" + serverTransId + "\"}").getBytes(StandardCharsets.UTF_8)) : null);
        r.put("ds_trans_id", "demo-ds-" + randomHex(8));
        r.put("order_id", orderId.isEmpty() ? "demo-order-" + System.currentTimeMillis() / 1000 : orderId);
        r.put("acs_start_version", "2.1.0");
        r.put("acs_end_version", "2.2.0");
        r.put("message", "Card enrolled in 3DS2 (demo)");
        return r;
    }

    private Map<String, Object> simulateAuthentication(String cardNumber, String serverTransId) {
        TestCard tc = TEST_CARDS.getOrDefault(cardNumber, new TestCard("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", false));
        if (serverTransId.isEmpty()) serverTransId = "demo-" + System.currentTimeMillis() / 1000;

        Map<String, Object> r = new LinkedHashMap<>();
        if ("challenge".equals(tc.flow)) {
            r.put("challenge_required", true);
            r.put("status", "CHALLENGE_REQUIRED");
            Map<String, Object> challenge = new LinkedHashMap<>();
            challenge.put("acs_url", "https://acs.sandbox.example.com/3ds/challenge");
            challenge.put("creq", Base64.getEncoder().encodeToString(("{\"threeDSServerTransID\":\"" + serverTransId + "\",\"messageType\":\"CReq\",\"messageVersion\":\"2.2.0\"}").getBytes(StandardCharsets.UTF_8)));
            challenge.put("server_trans_id", serverTransId);
            r.put("challenge", challenge);
        } else {
            boolean isSuccess = "AUTHENTICATION_SUCCESSFUL".equals(tc.result) || "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL".equals(tc.result);
            r.put("challenge_required", false);
            r.put("status", tc.result);
            Map<String, Object> authData = new LinkedHashMap<>();
            authData.put("eci", tc.eci != null ? tc.eci : "");
            authData.put("cavv", isSuccess ? randomBase64(20) : "");
            authData.put("xid", isSuccess ? randomBase64(20) : "");
            authData.put("ds_trans_id", "demo-ds-" + randomHex(8));
            authData.put("authentication_value", isSuccess ? randomBase64(20) : "");
            authData.put("message_version", "2.2.0");
            authData.put("status", tc.result);
            r.put("auth_data", authData);
        }
        return r;
    }

    private Map<String, Object> simulateVerifyAuth() {
        Map<String, Object> r = new LinkedHashMap<>();
        r.put("authenticated", true);
        Map<String, Object> authData = new LinkedHashMap<>();
        authData.put("eci", "05");
        authData.put("cavv", randomBase64(20));
        authData.put("xid", randomBase64(20));
        authData.put("ds_trans_id", "demo-ds-" + randomHex(8));
        authData.put("authentication_value", randomBase64(20));
        authData.put("message_version", "2.2.0");
        authData.put("status", "AUTHENTICATION_SUCCESSFUL");
        r.put("auth_data", authData);
        return r;
    }

    private Map<String, Object> simulateAuthorization(String cardNumber, String orderId) {
        TestCard tc = TEST_CARDS.get(cardNumber);
        Map<String, Object> r = new LinkedHashMap<>();

        if (tc != null && FAILED_STATUSES.contains(tc.result)) {
            r.put("authorized", false);
            r.put("transaction_id", "");
            r.put("order_id", orderId);
            r.put("auth_code", "");
            r.put("message", "Payment declined - authentication failed (" + tc.result + ")");
            r.put("response_code", "110");
        } else {
            r.put("authorized", true);
            r.put("transaction_id", "demo-" + randomHex(8));
            r.put("order_id", orderId);
            r.put("auth_code", String.valueOf(100000 + random.nextInt(900000)));
            r.put("message", "Authorised (demo)");
            r.put("response_code", "00");
        }
        return r;
    }

    // ── Test Card Record ───────────────────────────────────

    private static class TestCard {
        final String flow, result, eci, brand;
        final boolean noMethodUrl;
        TestCard(String flow, String result, String eci, String brand, boolean noMethodUrl) {
            this.flow = flow; this.result = result; this.eci = eci; this.brand = brand; this.noMethodUrl = noMethodUrl;
        }
    }
}
