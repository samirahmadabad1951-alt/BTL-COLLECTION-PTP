<?php
/**
 * WORK2/backend/config/payment_config.php
 * ==========================================================
 * EcoStore Payment Configuration
 * Supports:
 * - M-Pesa (Vodacom Tanzania / Safaricom)
 * - Tigo Pesa (Tanzania)
 * - Airtel Money (Tanzania)
 * - Stripe (Visa / Mastercard)
 *
 * Compatible with:
 * - MAMP (localhost)
 * - Production servers (HTTPS)
 *
 * IMPORTANT:
 * 1. Replace all placeholder credentials with real API keys.
 * 2. Mobile money callbacks require a publicly accessible HTTPS URL.
 * 3. Stripe test mode works on localhost immediately.
 * ==========================================================
 */

if (!defined('PAYMENT_CONFIG_LOADED')) {
    define('PAYMENT_CONFIG_LOADED', true);

    // ======================================================
    // APPLICATION ENVIRONMENT
    // ======================================================
    // local      = MAMP / localhost development
    // production = Live server with HTTPS
    define('APP_ENV', 'local');

    // ======================================================
    // APPLICATION URL
    // ======================================================
    // Update this if your MAMP URL differs.
    define('APP_URL_LOCAL', 'http://localhost:8888/work2');
    define('APP_URL_PRODUCTION', 'https://yourdomain.com/work2');

    define(
        'APP_URL',
        APP_ENV === 'production'
            ? APP_URL_PRODUCTION
            : APP_URL_LOCAL
    );

    // Backend API base URL
    define('API_URL', APP_URL . '/backend/api');

    // ======================================================
    // CALLBACK BASE URL
    // ======================================================
    // Mobile money providers CANNOT call localhost.
    // For testing mobile money, expose your MAMP site using:
    // - ngrok: https://ngrok.com
    // Example:
    // define('PUBLIC_CALLBACK_BASE_URL', 'https://abc123.ngrok-free.app/work2/backend/api');
    //
    // For production:
    // define('PUBLIC_CALLBACK_BASE_URL', 'https://yourdomain.com/work2/backend/api');

    define(
        'PUBLIC_CALLBACK_BASE_URL',
        APP_ENV === 'production'
            ? 'https://yourdomain.com/work2/backend/api'
            : 'https://your-ngrok-url.ngrok-free.app/work2/backend/api'
    );

    // ======================================================
    // GENERAL PAYMENT SETTINGS
    // ======================================================
    define('PAYMENT_COUNTRY_CODE', 'TZ');
    define('PAYMENT_CURRENCY', 'TZS');
    define('PAYMENT_TIMEZONE', 'Africa/Dar_es_Salaam');

    date_default_timezone_set(PAYMENT_TIMEZONE);

    // Polling settings for mobile money confirmations
    define('PAYMENT_POLL_TIMEOUT', 60);   // seconds
    define('PAYMENT_POLL_INTERVAL', 5);   // seconds

    // ======================================================
    // SUPPORTED PAYMENT METHODS
    // ======================================================
    define('ENABLE_MPESA', true);
    define('ENABLE_TIGO_PESA', true);
    define('ENABLE_AIRTEL_MONEY', true);
    define('ENABLE_STRIPE', true);

    // ======================================================
    // CALLBACK ENDPOINTS
    // ======================================================
    define('MPESA_CALLBACK_URL',  PUBLIC_CALLBACK_BASE_URL . '/mpesa_callback.php');
    define('TIGO_CALLBACK_URL',   PUBLIC_CALLBACK_BASE_URL . '/tigo_callback.php');
    define('AIRTEL_CALLBACK_URL', PUBLIC_CALLBACK_BASE_URL . '/airtel_callback.php');
    define('STRIPE_WEBHOOK_URL',  PUBLIC_CALLBACK_BASE_URL . '/stripe_webhook.php');

    // ======================================================
    // M-PESA (Vodacom Tanzania / Safaricom)
    // ======================================================
    // Credentials:
    // https://developer.vodacom.co.tz
    // https://developer.safaricom.co.ke
    define('MPESA_ENVIRONMENT', 'sandbox'); // sandbox | production

    define('MPESA_CONSUMER_KEY',    'YOUR_MPESA_CONSUMER_KEY_HERE');
    define('MPESA_CONSUMER_SECRET', 'YOUR_MPESA_CONSUMER_SECRET_HERE');
    define('MPESA_SHORTCODE',       'YOUR_BUSINESS_SHORTCODE_HERE');
    define('MPESA_PASSKEY',         'YOUR_LIPA_NA_MPESA_PASSKEY_HERE');

    define(
        'MPESA_BASE_URL',
        MPESA_ENVIRONMENT === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke'
    );

    // ======================================================
    // TIGO PESA (Tanzania)
    // ======================================================
    // Credentials:
    // https://developer.tigo.co.tz
    define('TIGO_ENVIRONMENT', 'sandbox'); // sandbox | production

    define('TIGO_CLIENT_ID',      'YOUR_TIGO_CLIENT_ID_HERE');
    define('TIGO_CLIENT_SECRET',  'YOUR_TIGO_CLIENT_SECRET_HERE');
    define('TIGO_BILLER_CODE',    'YOUR_TIGO_BILLER_CODE_HERE');
    define('TIGO_BILLER_MSISDN',  'YOUR_TIGO_BILLER_MSISDN_HERE');

    define(
        'TIGO_BASE_URL',
        TIGO_ENVIRONMENT === 'production'
            ? 'https://www.tigo.co.tz/tigo-api'
            : 'https://test.tigopesa.co.tz'
    );

    // ======================================================
    // AIRTEL MONEY (Tanzania)
    // ======================================================
    // Credentials:
    // https://developers.airtel.africa
    define('AIRTEL_ENVIRONMENT', 'sandbox'); // sandbox | production

    define('AIRTEL_CLIENT_ID',     'YOUR_AIRTEL_CLIENT_ID_HERE');
    define('AIRTEL_CLIENT_SECRET', 'YOUR_AIRTEL_CLIENT_SECRET_HERE');

    define(
        'AIRTEL_BASE_URL',
        AIRTEL_ENVIRONMENT === 'production'
            ? 'https://openapi.airtel.africa'
            : 'https://openapiuat.airtel.africa'
    );

    define('AIRTEL_COUNTRY', 'TZ');
    define('AIRTEL_CURRENCY', 'TZS');

    // ======================================================
    // STRIPE (Cards: Visa / Mastercard / AMEX)
    // ======================================================
    // Credentials:
    // https://dashboard.stripe.com/apikeys
    // Works on localhost in test mode.
    define('STRIPE_ENVIRONMENT', 'test'); // test | live

    define('STRIPE_SECRET_KEY',      'sk_test_YOUR_STRIPE_SECRET_KEY_HERE');
    define('STRIPE_PUBLISHABLE_KEY', 'pk_test_YOUR_STRIPE_PUBLISHABLE_KEY_HERE');
    define('STRIPE_WEBHOOK_SECRET',  'whsec_YOUR_STRIPE_WEBHOOK_SECRET_HERE');

    // ======================================================
    // PAYMENT SPLITTING
    // ======================================================
    // Platform commission in percent.
    // Example:
    // - Seller receives 95%
    // - Platform (admin) receives 5%
    define('PLATFORM_COMMISSION_PERCENT', 5.00);

    // ======================================================
    // SECURITY
    // ======================================================
    define('PAYMENT_SIGNATURE_SECRET', 'CHANGE_THIS_TO_A_RANDOM_LONG_SECRET');

    // ======================================================
    // LOGGING
    // ======================================================
    define('PAYMENT_LOG_DIR', __DIR__ . '/../logs');
    define('PAYMENT_LOG_FILE', PAYMENT_LOG_DIR . '/payment.log');

    if (!is_dir(PAYMENT_LOG_DIR)) {
        mkdir(PAYMENT_LOG_DIR, 0777, true);
    }

    // ======================================================
    // HELPER FUNCTIONS
    // ======================================================
    if (!function_exists('isProduction')) {
        function isProduction(): bool
        {
            return APP_ENV === 'production';
        }
    }

    if (!function_exists('isLocal')) {
        function isLocal(): bool
        {
            return APP_ENV === 'local';
        }
    }

    if (!function_exists('paymentLog')) {
        function paymentLog(string $message): void
        {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents(
                PAYMENT_LOG_FILE,
                "[{$timestamp}] {$message}" . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    if (!function_exists('getPaymentConfigSummary')) {
        function getPaymentConfigSummary(): array
        {
            return [
                'app_env' => APP_ENV,
                'app_url' => APP_URL,
                'currency' => PAYMENT_CURRENCY,
                'country' => PAYMENT_COUNTRY_CODE,
                'enabled_methods' => [
                    'mpesa' => ENABLE_MPESA,
                    'tigo_pesa' => ENABLE_TIGO_PESA,
                    'airtel_money' => ENABLE_AIRTEL_MONEY,
                    'stripe' => ENABLE_STRIPE,
                ],
                'platform_commission_percent' => PLATFORM_COMMISSION_PERCENT,
            ];
        }
    }
}
?>