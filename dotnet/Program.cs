using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using GlobalPayments.Api;
using GlobalPayments.Api.Entities;
using GlobalPayments.Api.Entities.Enums;
using GlobalPayments.Api.PaymentMethods;
using GlobalPayments.Api.Services;
using dotenv.net;

namespace Gpecom3ds2;

public class Program
{
    static readonly string LogDir = Path.Combine(AppContext.BaseDirectory, "..", "..", "..", "logs");
    static readonly string ProjectRoot = Path.GetFullPath(Path.Combine(AppContext.BaseDirectory, "..", "..", "..", ".."));

    // Test cards (Message Version 2.2)
    static readonly Dictionary<string, (string Flow, string Result, string? Eci, string Brand, bool NoMethodUrl)> TestCards = new()
    {
        ["4222000006285344"] = ("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", false),
        ["4222000009719489"] = ("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", true),
        ["4222000005218627"] = ("frictionless", "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL", "06", "VISA", false),
        ["4222000002144131"] = ("frictionless", "AUTHENTICATION_FAILED", "07", "VISA", false),
        ["4222000007275799"] = ("frictionless", "AUTHENTICATION_ISSUER_REJECTED", "07", "VISA", false),
        ["4222000008880910"] = ("frictionless", "AUTHENTICATION_COULD_NOT_BE_PERFORMED", "07", "VISA", false),
        ["4222000001227408"] = ("challenge", "CHALLENGE_REQUIRED", null, "VISA", false),
        ["5354560000000004"] = ("frictionless", "AUTHENTICATION_SUCCESSFUL", "02", "MC", false),
        ["5571596304025153"] = ("frictionless", "AUTHENTICATION_SUCCESSFUL", "02", "MC", true),
        ["5580364874958322"] = ("frictionless", "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL", "01", "MC", false),
        ["5540010585397800"] = ("frictionless", "AUTHENTICATION_FAILED", "00", "MC", false),
        ["5588312194362669"] = ("frictionless", "AUTHENTICATION_ISSUER_REJECTED", "00", "MC", false),
        ["5520680211891022"] = ("frictionless", "AUTHENTICATION_COULD_NOT_BE_PERFORMED", "00", "MC", false),
        ["5506874496684651"] = ("challenge", "CHALLENGE_REQUIRED", null, "MC", false),
    };

    public static void Main(string[] args)
    {
        DotEnv.Load();

        Directory.CreateDirectory(LogDir);

        // SDK Configuration
        var merchantId = System.Environment.GetEnvironmentVariable("GPECOM_MERCHANT_ID") ?? "radoslav";
        var accountId = System.Environment.GetEnvironmentVariable("GPECOM_ACCOUNT") ?? "internet";

        ServicesContainer.ConfigureService(new GpEcomConfig
        {
            MerchantId = merchantId,
            AccountId = accountId,
            SharedSecret = System.Environment.GetEnvironmentVariable("GPECOM_SHARED_SECRET"),
            Secure3dVersion = Secure3dVersion.Two,
            // Use null when not configured — SDK's maybeSetKey() skips null but sends empty strings
            MethodNotificationUrl = string.IsNullOrEmpty(System.Environment.GetEnvironmentVariable("METHOD_NOTIFICATION_URL")) ? null : System.Environment.GetEnvironmentVariable("METHOD_NOTIFICATION_URL"),
            ChallengeNotificationUrl = string.IsNullOrEmpty(System.Environment.GetEnvironmentVariable("CHALLENGE_NOTIFICATION_URL")) ? null : System.Environment.GetEnvironmentVariable("CHALLENGE_NOTIFICATION_URL"),
            MerchantContactUrl = System.Environment.GetEnvironmentVariable("MERCHANT_CONTACT_URL") ?? "https://developer.globalpayments.com",
        });

        var builder = WebApplication.CreateBuilder(args);
        var app = builder.Build();

        // Security headers
        app.Use(async (ctx, next) =>
        {
            ctx.Response.Headers["X-Content-Type-Options"] = "nosniff";
            ctx.Response.Headers["X-Frame-Options"] = "SAMEORIGIN";
            ctx.Response.Headers["X-XSS-Protection"] = "1; mode=block";
            ctx.Response.Headers["Referrer-Policy"] = "strict-origin-when-cross-origin";
            ctx.Response.Headers["Cache-Control"] = "no-store, no-cache, must-revalidate";
            ctx.Response.Headers["Access-Control-Allow-Origin"] = "*";
            ctx.Response.Headers["Access-Control-Allow-Methods"] = "POST, GET, OPTIONS";
            ctx.Response.Headers["Access-Control-Allow-Headers"] = "Content-Type";

            if (ctx.Request.Method == "OPTIONS") { ctx.Response.StatusCode = 200; return; }
            await next();
        });

        // Serve root index.html
        app.MapGet("/", () => Results.File(Path.Combine(ProjectRoot, "index.html"), "text/html"));

        // Serve static files from wwwroot
        app.UseStaticFiles();

        // API endpoints
        ConfigureApiEndpoints(app);

        var port = System.Environment.GetEnvironmentVariable("PORT") ?? "3002";
        app.Urls.Add($"http://0.0.0.0:{port}");

        Console.WriteLine($"3DS2 .NET server running at http://localhost:{port}");
        Console.WriteLine($"Backend: GPeCOM (merchant: {merchantId}, account: {accountId})");

        app.Run();
    }

    static void ConfigureApiEndpoints(WebApplication app)
    {
        app.MapPost("/api/check-enrollment", async (HttpContext ctx) =>
        {
            try
            {
                var data = await ReadJson(ctx);
                var cardNumber = GetString(data, "card_number").Replace(" ", "").Replace("-", "");
                var expDate = GetString(data, "exp_date");
                var cardHolder = GetString(data, "card_holder");
                var orderId = GetString(data, "order_id");
                var demoMode = GetBool(data, "demo_mode");

                if (string.IsNullOrEmpty(cardNumber) || !ValidateCardNumber(cardNumber))
                    return Results.Json(new { success = false, message = "Invalid card number" }, statusCode: 400);
                if (string.IsNullOrEmpty(expDate) || !ValidateExpDate(expDate))
                    return Results.Json(new { success = false, message = "Invalid expiry date (MMYY format required)" }, statusCode: 400);
                if (string.IsNullOrEmpty(cardHolder) || cardHolder.Length < 2 || cardHolder.Length > 100)
                    return Results.Json(new { success = false, message = "Cardholder name must be 2-100 characters" }, statusCode: 400);
                if (string.IsNullOrEmpty(orderId))
                    return Results.Json(new { success = false, message = "Order ID is required" }, statusCode: 400);

                object result;
                if (demoMode)
                {
                    result = SimulateEnrollment(cardNumber, orderId);
                }
                else
                {
                    var card = BuildCard(cardNumber, expDate, cardHolder);
                    LogApi("check-enrollment", "REQUEST", $"card={MaskCard(cardNumber)} order_id={orderId}");

                    var threeDSecure = Secure3dService.CheckEnrollment(card)
                        .Execute(Secure3dVersion.Two);

                    LogApi("check-enrollment", "RESPONSE", $"enrolled={threeDSecure.Enrolled} server_trans_id={threeDSecure.ServerTransactionId}");

                    result = new
                    {
                        enrolled = !string.IsNullOrEmpty(threeDSecure.Enrolled) && threeDSecure.Enrolled != "N" ? "Y" : "N",
                        server_trans_id = threeDSecure.ServerTransactionId,
                        method_url = threeDSecure.IssuerAcsUrl,
                        method_data = threeDSecure.PayerAuthenticationRequest,
                        ds_trans_id = threeDSecure.DirectoryServerTransactionId,
                        order_id = orderId,
                        acs_start_version = threeDSecure.AcsStartVersion,
                        acs_end_version = threeDSecure.AcsEndVersion,
                        message = !string.IsNullOrEmpty(threeDSecure.Enrolled) && threeDSecure.Enrolled != "N" ? "Card enrolled in 3DS2" : "Card not enrolled",
                    };
                }

                return Results.Json(new { success = true, message = "Enrollment check complete", data = result });
            }
            catch (Exception ex)
            {
                LogError("check-enrollment", ex);
                return Results.Json(new { success = false, message = "Enrollment check failed: " + ex.Message }, statusCode: ex is ApiException ? 502 : 500);
            }
        });

        app.MapPost("/api/initiate-auth", async (HttpContext ctx) =>
        {
            try
            {
                var data = await ReadJson(ctx);
                var cardNumber = GetString(data, "card_number").Replace(" ", "").Replace("-", "");
                var amount = GetString(data, "amount");
                var currency = (GetString(data, "currency", "EUR")).ToUpper();
                var serverTransId = GetString(data, "server_trans_id");
                var demoMode = GetBool(data, "demo_mode");

                if (string.IsNullOrEmpty(cardNumber) || !ValidateCardNumber(cardNumber))
                    return Results.Json(new { success = false, message = "Invalid card number" }, statusCode: 400);
                if (string.IsNullOrEmpty(amount) || !ValidateAmount(amount))
                    return Results.Json(new { success = false, message = "Invalid amount" }, statusCode: 400);
                if (!ValidateCurrency(currency))
                    return Results.Json(new { success = false, message = "Invalid currency (EUR, USD, GBP accepted)" }, statusCode: 400);
                if (string.IsNullOrEmpty(serverTransId) && !demoMode)
                    return Results.Json(new { success = false, message = "Server transaction ID is required" }, statusCode: 400);

                var expDate = GetString(data, "exp_date", "1228");
                var cardHolder = GetString(data, "card_holder", "Test Customer");
                var methodUrlComplete = GetString(data, "method_url_complete", "true");
                var browserDataObj = GetObject(data, "browser_data");
                var customerObj = GetObject(data, "customer");

                object result;
                bool challengeRequired = false;
                string status = "";

                if (demoMode)
                {
                    var simResult = SimulateAuthentication(cardNumber, serverTransId);
                    result = simResult;
                    challengeRequired = simResult.challenge_required;
                    status = simResult.status;
                }
                else
                {
                    var card = BuildCard(cardNumber, expDate, cardHolder);
                    var secureEcom = new ThreeDSecure
                    {
                        ServerTransactionId = serverTransId,
                        AcsEndVersion = "2.2.0",
                    };
                    card.ThreeDSecure = secureEcom;

                    var browserData = BuildBrowserData(browserDataObj, ctx);
                    var address = new Address
                    {
                        StreetAddress1 = GetString(data, "address", "Flat 123"),
                        StreetAddress2 = GetString(data, "address2", "House 456"),
                        City = GetString(data, "city", "Halifax"),
                        PostalCode = GetString(data, "postal_code", "W5 9HR"),
                        CountryCode = GetString(data, "country_code", "826"),
                    };

                    var amountDecimal = int.Parse(amount) / 100m;
                    var completion = methodUrlComplete == "true" ? MethodUrlCompletion.YES : MethodUrlCompletion.NO;

                    LogApi("initiate-auth", "REQUEST", $"card={MaskCard(cardNumber)} amount={amountDecimal} currency={currency}");

                    var threeDSecure = Secure3dService.InitiateAuthentication(card, secureEcom)
                        .WithAmount(amountDecimal)
                        .WithCurrency(currency)
                        .WithAuthenticationSource(AuthenticationSource.BROWSER)
                        .WithMethodUrlCompletion(completion)
                        .WithOrderCreateDate(DateTime.Now)
                        .WithAddress(address, AddressType.Shipping)
                        .WithAddress(address, AddressType.Billing)
                        .WithBrowserData(browserData)
                        .WithCustomerEmail(GetNestedString(customerObj, "email", "test@example.com"))
                        .Execute(Secure3dVersion.Two);

                    LogApi("initiate-auth", "RESPONSE", $"status={threeDSecure.Status} eci={threeDSecure.Eci}");

                    status = threeDSecure.Status ?? "";
                    if (status == "CHALLENGE_REQUIRED")
                    {
                        challengeRequired = true;
                        result = new
                        {
                            challenge_required = true,
                            status,
                            challenge = new
                            {
                                acs_url = threeDSecure.IssuerAcsUrl,
                                creq = threeDSecure.PayerAuthenticationRequest,
                                server_trans_id = threeDSecure.ServerTransactionId,
                            },
                        };
                    }
                    else
                    {
                        result = new
                        {
                            challenge_required = false,
                            status,
                            auth_data = new
                            {
                                eci = threeDSecure.Eci,
                                cavv = threeDSecure.AuthenticationValue,
                                xid = threeDSecure.Xid ?? "",
                                ds_trans_id = threeDSecure.DirectoryServerTransactionId,
                                authentication_value = threeDSecure.AuthenticationValue,
                                message_version = threeDSecure.MessageVersion ?? "2.2.0",
                                status,
                            },
                        };
                    }
                }

                var failedStatuses = new[] { "AUTHENTICATION_FAILED", "AUTHENTICATION_ISSUER_REJECTED", "AUTHENTICATION_COULD_NOT_BE_PERFORMED" };
                if (!challengeRequired && failedStatuses.Contains(status))
                    return Results.Json(new { success = false, message = "Authentication failed: " + status }, statusCode: 400);

                return Results.Json(new { success = true, message = "Authentication initiated", data = result });
            }
            catch (Exception ex)
            {
                LogError("initiate-auth", ex);
                return Results.Json(new { success = false, message = "Authentication initiation failed: " + ex.Message }, statusCode: ex is ApiException ? 502 : 500);
            }
        });

        app.MapPost("/api/verify-auth", async (HttpContext ctx) =>
        {
            try
            {
                var data = await ReadJson(ctx);
                var serverTransId = GetString(data, "server_trans_id");
                var demoMode = GetBool(data, "demo_mode");

                if (string.IsNullOrEmpty(serverTransId) && !demoMode)
                    return Results.Json(new { success = false, message = "Server transaction ID is required" }, statusCode: 400);

                object authDataObj;
                bool authenticated;

                if (demoMode)
                {
                    var sim = SimulateVerifyAuth();
                    authenticated = sim.authenticated;
                    authDataObj = sim.auth_data;
                }
                else
                {
                    LogApi("verify-auth", "REQUEST", $"server_trans_id={serverTransId}");

                    var threeDSecure = Secure3dService.GetAuthenticationData()
                        .WithServerTransactionId(serverTransId)
                        .Execute(Secure3dVersion.Two);

                    LogApi("verify-auth", "RESPONSE", $"status={threeDSecure.Status} eci={threeDSecure.Eci}");

                    var successEcis = new[] { "05", "06", "02", "01" };
                    authenticated = successEcis.Contains(threeDSecure.Eci);
                    authDataObj = new
                    {
                        eci = threeDSecure.Eci,
                        cavv = threeDSecure.AuthenticationValue,
                        xid = threeDSecure.Xid ?? "",
                        ds_trans_id = threeDSecure.DirectoryServerTransactionId,
                        authentication_value = threeDSecure.AuthenticationValue,
                        message_version = threeDSecure.MessageVersion ?? "2.2.0",
                        status = threeDSecure.Status,
                    };
                }

                if (!authenticated)
                    return Results.Json(new { success = false, message = "Authentication verification failed", details = new { auth_data = authDataObj } }, statusCode: 400);

                return Results.Json(new { success = true, message = "Authentication verified", data = new { authenticated, auth_data = authDataObj } });
            }
            catch (Exception ex)
            {
                LogError("verify-auth", ex);
                return Results.Json(new { success = false, message = "Authentication verification failed: " + ex.Message }, statusCode: ex is ApiException ? 502 : 500);
            }
        });

        app.MapPost("/api/authorize-payment", async (HttpContext ctx) =>
        {
            try
            {
                var data = await ReadJson(ctx);
                var cardNumber = GetString(data, "card_number").Replace(" ", "").Replace("-", "");
                var amount = GetString(data, "amount");
                var currency = GetString(data, "currency", "EUR").ToUpper();
                var cvv = GetString(data, "cvv");
                var demoMode = GetBool(data, "demo_mode");
                var authData = GetObject(data, "auth_data");

                if (string.IsNullOrEmpty(cardNumber) || !ValidateCardNumber(cardNumber))
                    return Results.Json(new { success = false, message = "Invalid card number" }, statusCode: 400);
                if (string.IsNullOrEmpty(amount) || !ValidateAmount(amount))
                    return Results.Json(new { success = false, message = "Invalid amount" }, statusCode: 400);
                if (!ValidateCurrency(currency))
                    return Results.Json(new { success = false, message = "Invalid currency" }, statusCode: 400);
                if (!string.IsNullOrEmpty(cvv) && !ValidateCvv(cvv))
                    return Results.Json(new { success = false, message = "Invalid CVV" }, statusCode: 400);

                var expDate = GetString(data, "exp_date", "1228");
                var cardHolder = GetString(data, "card_holder", "Test Customer");
                var orderId = GetString(data, "order_id");

                bool authorized;
                string transactionId = "", authCode = "", message = "", responseCode = "";

                if (demoMode)
                {
                    var sim = SimulateAuthorization(cardNumber, orderId);
                    authorized = sim.authorized;
                    transactionId = sim.transaction_id;
                    authCode = sim.auth_code;
                    message = sim.message;
                    responseCode = sim.response_code;
                }
                else
                {
                    var card = BuildCard(cardNumber, expDate, cardHolder);
                    card.Cvn = cvv;

                    var secureEcom = new ThreeDSecure();
                    secureEcom.Eci = GetNestedString(authData, "eci");
                    secureEcom.Cavv = GetNestedString(authData, "cavv");
                    secureEcom.Xid = GetNestedString(authData, "xid");
                    secureEcom.DirectoryServerTransactionId = GetNestedString(authData, "ds_trans_id");
                    secureEcom.AuthenticationValue = GetNestedString(authData, "authentication_value") != "" ? GetNestedString(authData, "authentication_value") : GetNestedString(authData, "cavv");
                    secureEcom.MessageVersion = GetNestedString(authData, "message_version", "2.2.0");
                    card.ThreeDSecure = secureEcom;

                    var amountDecimal = int.Parse(amount) / 100m;

                    LogApi("authorize", "REQUEST", $"card={MaskCard(cardNumber)} amount={amountDecimal} currency={currency} eci={secureEcom.Eci}");

                    var response = card.Charge(amountDecimal)
                        .WithCurrency(currency)
                        .WithOrderId(string.IsNullOrEmpty(orderId) ? null : orderId)
                        .WithAllowDuplicates(true)
                        .Execute();

                    LogApi("authorize", "RESPONSE", $"response_code={response.ResponseCode} transaction_id={response.TransactionId}");

                    authorized = response.ResponseCode == "00";
                    transactionId = response.TransactionId ?? "";
                    authCode = response.AuthorizationCode ?? "";
                    message = authorized ? "Authorised" : (response.ResponseMessage ?? "Payment declined");
                    responseCode = response.ResponseCode ?? "";
                }

                var result = new { authorized, transaction_id = transactionId, order_id = orderId, auth_code = authCode, message, response_code = responseCode };

                if (!authorized)
                    return Results.Json(new { success = false, message, details = new { response_code = responseCode } }, statusCode: 400);

                return Results.Json(new { success = true, message = "Payment authorized", data = result });
            }
            catch (Exception ex)
            {
                LogError("authorize-payment", ex);
                return Results.Json(new { success = false, message = "Payment authorization failed: " + ex.Message }, statusCode: ex is ApiException ? 502 : 500);
            }
        });

        // Webhooks
        app.MapPost("/webhooks/method-notification", async (HttpContext ctx) =>
        {
            var form = await ctx.Request.ReadFormAsync();
            var methodData = form["threeDSMethodData"].ToString();
            var serverTransId = "";

            if (!string.IsNullOrEmpty(methodData))
            {
                try
                {
                    var decoded = JsonSerializer.Deserialize<JsonElement>(Convert.FromBase64String(methodData));
                    serverTransId = decoded.GetProperty("threeDSServerTransID").GetString() ?? "";
                }
                catch { }
            }

            LogApi("method-notification", "RECEIVED", $"server_trans_id={serverTransId}");

            ctx.Response.ContentType = "text/html";
            await ctx.Response.WriteAsync($@"<!DOCTYPE html><html><head><title>3DS Method Complete</title></head><body><script>
                if (window.parent !== window) {{ window.parent.postMessage({{ type: 'method_complete', serverTransId: '{serverTransId}' }}, '*'); }}
            </script></body></html>");
        });

        app.MapPost("/webhooks/challenge-notification", async (HttpContext ctx) =>
        {
            var form = await ctx.Request.ReadFormAsync();
            var cres = form["cres"].ToString();

            if (string.IsNullOrEmpty(cres))
            {
                ctx.Response.StatusCode = 400;
                await ctx.Response.WriteAsync("<html><body><p>Missing cres parameter</p></body></html>");
                return;
            }

            var decodedString = "{}";
            try { decodedString = Encoding.UTF8.GetString(Convert.FromBase64String(cres)); } catch { }

            LogApi("challenge-notification", "RECEIVED", decodedString);

            ctx.Response.ContentType = "text/html";
            await ctx.Response.WriteAsync($@"<!DOCTYPE html><html><head><title>Challenge Complete</title></head><body><script>
                var cresData = {decodedString};
                if (window.parent !== window) {{ window.parent.postMessage({{ type: 'challenge_complete', cres: cresData, data: cresData }}, window.location.origin); }}
            </script></body></html>");
        });
    }

    // ── Helpers ─────────────────────────────────────────────

    static CreditCardData BuildCard(string cardNumber, string expDate, string cardHolder)
    {
        return new CreditCardData
        {
            Number = cardNumber,
            ExpMonth = int.Parse(expDate[..2]),
            ExpYear = int.Parse("20" + expDate[2..]),
            CardHolderName = cardHolder,
        };
    }

    static BrowserData BuildBrowserData(JsonElement? data, HttpContext ctx)
    {
        return new BrowserData
        {
            AcceptHeader = GetNestedString(data, "accept_header", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"),
            ColorDepth = MapColorDepth(GetNestedString(data, "color_depth", "24")),
            IpAddress = ctx.Connection.RemoteIpAddress?.ToString() ?? "127.0.0.1",
            JavaEnabled = GetNestedString(data, "java_enabled") == "true",
            JavaScriptEnabled = GetNestedString(data, "javascript_enabled", "true") == "true",
            Language = GetNestedString(data, "language", "en-US"),
            ScreenHeight = int.TryParse(GetNestedString(data, "screen_height", "1080"), out var h) ? h : 1080,
            ScreenWidth = int.TryParse(GetNestedString(data, "screen_width", "1920"), out var w) ? w : 1920,
            ChallengeWindowSize = MapChallengeWindowSize(GetNestedString(data, "challenge_window_size", "05")),
            Timezone = GetNestedString(data, "timezone", "0"),
            UserAgent = GetNestedString(data, "user_agent", ctx.Request.Headers.UserAgent.ToString()),
        };
    }

    static ColorDepth MapColorDepth(string depth) => depth switch
    {
        "1" => ColorDepth.ONE_BIT, "2" => ColorDepth.TWO_BITS, "4" => ColorDepth.FOUR_BITS,
        "8" => ColorDepth.EIGHT_BITS, "15" => ColorDepth.FIFTEEN_BITS, "16" => ColorDepth.SIXTEEN_BITS,
        "24" => ColorDepth.TWENTY_FOUR_BITS, "32" => ColorDepth.THIRTY_TWO_BITS, "48" => ColorDepth.FORTY_EIGHT_BITS,
        _ => ColorDepth.TWENTY_FOUR_BITS,
    };

    static ChallengeWindowSize MapChallengeWindowSize(string size) => size switch
    {
        "01" => ChallengeWindowSize.WINDOWED_250X400, "02" => ChallengeWindowSize.WINDOWED_390X400,
        "03" => ChallengeWindowSize.WINDOWED_500X600, "04" => ChallengeWindowSize.WINDOWED_600X400,
        "05" => ChallengeWindowSize.FULL_SCREEN,
        _ => ChallengeWindowSize.FULL_SCREEN,
    };

    // ── Validation ─────────────────────────────────────────

    static bool ValidateCardNumber(string number)
    {
        if (number.Length < 13 || number.Length > 19) return false;
        int sum = 0; bool alt = false;
        for (int i = number.Length - 1; i >= 0; i--)
        {
            int d = number[i] - '0';
            if (alt) { d *= 2; if (d > 9) d -= 9; }
            sum += d;
            alt = !alt;
        }
        return sum % 10 == 0;
    }

    static bool ValidateExpDate(string exp)
    {
        if (exp.Length != 4 || !exp.All(char.IsDigit)) return false;
        int month = int.Parse(exp[..2]), year = int.Parse(exp[2..]);
        if (month < 1 || month > 12) return false;
        int curYear = DateTime.Now.Year % 100, curMonth = DateTime.Now.Month;
        return year > curYear || (year == curYear && month >= curMonth);
    }

    static bool ValidateAmount(string amount) => int.TryParse(amount, out var v) && v > 0 && v <= 99999999;
    static bool ValidateCurrency(string c) => new[] { "EUR", "USD", "GBP" }.Contains(c.ToUpper());
    static bool ValidateCvv(string cvv) => cvv.Length >= 3 && cvv.Length <= 4 && cvv.All(char.IsDigit);

    static string MaskCard(string n) => n.Length < 10 ? "****" : n[..6] + new string('*', n.Length - 10) + n[^4..];

    // ── JSON Helpers ───────────────────────────────────────

    static async Task<JsonElement> ReadJson(HttpContext ctx)
    {
        using var doc = await JsonDocument.ParseAsync(ctx.Request.Body);
        return doc.RootElement.Clone();
    }

    static string GetString(JsonElement data, string key, string def = "")
    {
        if (data.TryGetProperty(key, out var val))
        {
            if (val.ValueKind == JsonValueKind.String) return val.GetString() ?? def;
            if (val.ValueKind == JsonValueKind.Number) return val.ToString();
        }
        return def;
    }

    static bool GetBool(JsonElement data, string key)
    {
        if (data.TryGetProperty(key, out var val))
        {
            if (val.ValueKind == JsonValueKind.True) return true;
            if (val.ValueKind == JsonValueKind.String) return val.GetString()?.ToLower() == "true";
        }
        return false;
    }

    static JsonElement? GetObject(JsonElement data, string key)
    {
        if (data.TryGetProperty(key, out var val) && val.ValueKind == JsonValueKind.Object) return val;
        return null;
    }

    static string GetNestedString(JsonElement? data, string key, string def = "")
    {
        if (data == null) return def;
        if (data.Value.TryGetProperty(key, out var val))
        {
            if (val.ValueKind == JsonValueKind.String) return val.GetString() ?? def;
            if (val.ValueKind == JsonValueKind.Number) return val.ToString();
        }
        return def;
    }

    // ── Logging ────────────────────────────────────────────

    static void LogApi(string step, string direction, string data)
    {
        try { File.AppendAllText(Path.Combine(LogDir, "3ds2-api.log"), $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] [{step}] [{direction}] {data}\n"); } catch { }
    }

    static void LogError(string step, Exception ex)
    {
        try { File.AppendAllText(Path.Combine(LogDir, "3ds2-errors.log"), $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] [{step}] {ex.GetType().Name}: {ex.Message}\n"); } catch { }
    }

    // ── Demo Simulations ───────────────────────────────────

    static object SimulateEnrollment(string cardNumber, string orderId)
    {
        TestCards.TryGetValue(cardNumber, out var tc);
        var serverTransId = "demo-" + RandomHex(16);
        var hasMethodUrl = tc.NoMethodUrl == false;

        return new
        {
            enrolled = "Y",
            server_trans_id = serverTransId,
            method_url = hasMethodUrl ? "https://acs.sandbox.example.com/3ds/method" : (string?)null,
            method_data = hasMethodUrl ? Convert.ToBase64String(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { threeDSServerTransID = serverTransId }))) : null,
            ds_trans_id = "demo-ds-" + RandomHex(8),
            order_id = string.IsNullOrEmpty(orderId) ? "demo-order-" + DateTimeOffset.UtcNow.ToUnixTimeSeconds() : orderId,
            acs_start_version = "2.1.0",
            acs_end_version = "2.2.0",
            message = "Card enrolled in 3DS2 (demo)",
        };
    }

    static dynamic SimulateAuthentication(string cardNumber, string serverTransId)
    {
        if (!TestCards.TryGetValue(cardNumber, out var tc))
            tc = ("frictionless", "AUTHENTICATION_SUCCESSFUL", "05", "VISA", false);

        if (string.IsNullOrEmpty(serverTransId)) serverTransId = "demo-" + DateTimeOffset.UtcNow.ToUnixTimeSeconds();

        if (tc.Flow == "challenge")
        {
            return new
            {
                challenge_required = true,
                status = "CHALLENGE_REQUIRED",
                challenge = new
                {
                    acs_url = "https://acs.sandbox.example.com/3ds/challenge",
                    creq = Convert.ToBase64String(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { threeDSServerTransID = serverTransId, messageType = "CReq", messageVersion = "2.2.0" }))),
                    server_trans_id = serverTransId,
                },
                auth_data = (object?)null,
            };
        }

        var isSuccess = tc.Result == "AUTHENTICATION_SUCCESSFUL" || tc.Result == "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL";
        return new
        {
            challenge_required = false,
            status = tc.Result,
            challenge = (object?)null,
            auth_data = (object)new
            {
                eci = tc.Eci ?? "",
                cavv = isSuccess ? RandomBase64(20) : "",
                xid = isSuccess ? RandomBase64(20) : "",
                ds_trans_id = "demo-ds-" + RandomHex(8),
                authentication_value = isSuccess ? RandomBase64(20) : "",
                message_version = "2.2.0",
                status = tc.Result,
            },
        };
    }

    static dynamic SimulateVerifyAuth() => new
    {
        authenticated = true,
        auth_data = (object)new
        {
            eci = "05", cavv = RandomBase64(20), xid = RandomBase64(20),
            ds_trans_id = "demo-ds-" + RandomHex(8), authentication_value = RandomBase64(20),
            message_version = "2.2.0", status = "AUTHENTICATION_SUCCESSFUL",
        },
    };

    static dynamic SimulateAuthorization(string cardNumber, string orderId)
    {
        if (TestCards.TryGetValue(cardNumber, out var tc))
        {
            var failedResults = new[] { "AUTHENTICATION_FAILED", "AUTHENTICATION_ISSUER_REJECTED", "AUTHENTICATION_COULD_NOT_BE_PERFORMED" };
            if (failedResults.Contains(tc.Result))
                return new { authorized = false, transaction_id = "", order_id = orderId, auth_code = "", message = "Payment declined - authentication failed (" + tc.Result + ")", response_code = "110" };
        }

        return new { authorized = true, transaction_id = "demo-" + RandomHex(8), order_id = orderId, auth_code = Random.Shared.Next(100000, 999999).ToString(), message = "Authorised (demo)", response_code = "00" };
    }

    static string RandomHex(int bytes)
    {
        var buf = new byte[bytes];
        RandomNumberGenerator.Fill(buf);
        return Convert.ToHexString(buf).ToLower();
    }

    static string RandomBase64(int bytes)
    {
        var buf = new byte[bytes];
        RandomNumberGenerator.Fill(buf);
        return Convert.ToBase64String(buf);
    }
}
