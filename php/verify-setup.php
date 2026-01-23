#!/usr/bin/env php
<?php

/**
 * Setup Verification Script
 *
 * Verifies that the GPeCOM 3DS2 environment is properly configured
 */

echo "=================================================\n";
echo "GPeCOM 3DS2 Setup Verification\n";
echo "=================================================\n\n";

$errors = [];
$warnings = [];
$checks = 0;
$passed = 0;

// Check PHP version
$checks++;
echo "✓ Checking PHP version... ";
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    echo "OK (" . PHP_VERSION . ")\n";
    $passed++;
} else {
    echo "FAIL\n";
    $errors[] = "PHP 7.4 or higher required. Found: " . PHP_VERSION;
}

// Check required extensions
$requiredExtensions = ['curl', 'dom', 'json', 'mbstring'];
foreach ($requiredExtensions as $ext) {
    $checks++;
    echo "✓ Checking extension '{$ext}'... ";
    if (extension_loaded($ext)) {
        echo "OK\n";
        $passed++;
    } else {
        echo "FAIL\n";
        $errors[] = "Required PHP extension '{$ext}' is not loaded";
    }
}

// Check autoloader
$checks++;
echo "✓ Checking Composer autoloader... ";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    echo "OK\n";
    $passed++;
} else {
    echo "FAIL\n";
    $errors[] = "Composer autoloader not found. Run: composer install";
}

// Check .env file
$checks++;
echo "✓ Checking .env configuration... ";
if (file_exists(__DIR__ . '/.env')) {
    try {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
        echo "OK\n";
        $passed++;

        // Check required environment variables
        $requiredVars = ['GPECOM_MERCHANT_ID', 'GPECOM_SHARED_SECRET', 'GPECOM_REFUND_PASSWORD'];
        foreach ($requiredVars as $var) {
            $checks++;
            echo "  ✓ Checking '{$var}'... ";
            if (!empty($_ENV[$var])) {
                echo "OK\n";
                $passed++;
            } else {
                echo "MISSING\n";
                $errors[] = "Environment variable '{$var}' is not set in .env";
            }
        }
    } catch (Exception $e) {
        echo "ERROR\n";
        $errors[] = "Failed to load .env: " . $e->getMessage();
    }
} else {
    echo "FAIL\n";
    $errors[] = ".env file not found. Copy .env.sample to .env";
}

// Check directories
$requiredDirs = ['logs', 'temp', 'api', 'webhooks', 'src'];
foreach ($requiredDirs as $dir) {
    $checks++;
    $path = __DIR__ . '/' . $dir;
    echo "✓ Checking directory '{$dir}'... ";
    if (is_dir($path)) {
        if (is_writable($path) || in_array($dir, ['api', 'webhooks', 'src'])) {
            echo "OK\n";
            $passed++;
        } else {
            echo "NOT WRITABLE\n";
            $warnings[] = "Directory '{$dir}' is not writable";
        }
    } else {
        echo "MISSING\n";
        $warnings[] = "Directory '{$dir}' does not exist";
        // Try to create it
        if (mkdir($path, 0755, true)) {
            echo "  → Created directory '{$dir}'\n";
        }
    }
}

// Check files
$requiredFiles = [
    'src/GpecomClient.php',
    'api/check-enrollment.php',
    'api/initiate-auth.php',
    'api/verify-auth.php',
    'api/authorize-payment.php',
    'webhooks/method-notification.php',
    'webhooks/challenge-notification.php',
    'test-3ds2.html'
];

foreach ($requiredFiles as $file) {
    $checks++;
    echo "✓ Checking file '{$file}'... ";
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "OK\n";
        $passed++;
    } else {
        echo "MISSING\n";
        $errors[] = "Required file '{$file}' not found";
    }
}

// Check GpecomClient class
$checks++;
echo "✓ Checking GpecomClient class... ";
try {
    if (class_exists('GpecomSdk\GpecomClient')) {
        echo "OK\n";
        $passed++;
    } else {
        echo "NOT FOUND\n";
        $errors[] = "GpecomClient class not found. Run: composer dump-autoload";
    }
} catch (Exception $e) {
    echo "ERROR\n";
    $errors[] = "Failed to load GpecomClient: " . $e->getMessage();
}

// Summary
echo "\n=================================================\n";
echo "SUMMARY\n";
echo "=================================================\n";
echo "Checks passed: {$passed}/{$checks}\n";

if (!empty($errors)) {
    echo "\n❌ ERRORS (" . count($errors) . "):\n";
    foreach ($errors as $i => $error) {
        echo "  " . ($i + 1) . ". {$error}\n";
    }
}

if (!empty($warnings)) {
    echo "\n⚠️  WARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $i => $warning) {
        echo "  " . ($i + 1) . ". {$warning}\n";
    }
}

if ($passed === $checks) {
    echo "\n✅ Setup verification PASSED!\n";
    echo "\nYou can now start the server:\n";
    echo "  php -S localhost:8080\n";
    echo "\nThen open in your browser:\n";
    echo "  http://localhost:8080/test-3ds2.html\n";
    exit(0);
} else {
    echo "\n❌ Setup verification FAILED!\n";
    echo "\nPlease fix the errors above and run this script again.\n";
    exit(1);
}
