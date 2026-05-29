<?php
// work2/backend/config/payment_config.php
// ============================================================
// REAL PAYMENT API CREDENTIALS
// Fill in your credentials from each provider's developer portal
// ============================================================

// ----------------------------------------------------------
// M-PESA (Vodacom Tanzania / Safaricom)
// Get credentials at: https://developer.vodacom.co.tz
// or for Kenya: https://developer.safaricom.co.ke
// ----------------------------------------------------------
define('MPESA_ENVIRONMENT',    'sandbox');   // 'sandbox' for testing, 'production' for live
define('MPESA_CONSUMER_KEY',   'YOUR_MPESA_CONSUMER_KEY_HERE');
define('MPESA_CONSUMER_SECRET','YOUR_MPESA_CONSUMER_SECRET_HERE');
define('MPESA_SHORTCODE',      'YOUR_BUSINESS_SHORTCODE_HERE');  // e.g. 174379 for sandbox
define('MPESA_PASSKEY',        'YOUR_LIPA_NA_MPESA_PASSKEY_HERE');
define('MPESA_CALLBACK_URL',   'https://yourdomain.com/work2/backend/api/mpesa_callback.php');

// Sandbox base URL
define('MPESA_BASE_URL', MPESA_ENVIRONMENT === 'production'
    ? 'https://api.safaricom.co.ke'
    : 'https://sandbox.safaricom.co.ke'
);

// ----------------------------------------------------------
// TIGO PESA (Tanzania)
// Get credentials at: https://developer.tigo.co.tz
// ----------------------------------------------------------
define('TIGO_ENVIRONMENT',    'sandbox');   // 'sandbox' or 'production'
define('TIGO_CLIENT_ID',      'YOUR_TIGO_CLIENT_ID_HERE');
define('TIGO_CLIENT_SECRET',  'YOUR_TIGO_CLIENT_SECRET_HERE');
define('TIGO_BILLER_CODE',    'YOUR_TIGO_BILLER_CODE_HERE');
define('TIGO_BILLER_MSISDN',  'YOUR_TIGO_BILLER_MSISDN_HERE');
define('TIGO_CALLBACK_URL',   'https://yourdomain.com/work2/backend/api/tigo_callback.php');

define('TIGO_BASE_URL', TIGO_ENVIRONMENT === 'production'
    ? 'https://www.tigo.co.tz/tigo-api'
    : 'https://test.tigopesa.co.tz'
);

// ----------------------------------------------------------
// AIRTEL MONEY (Tanzania)
// Get credentials at: https://developers.airtel.africa
// ----------------------------------------------------------
define('AIRTEL_ENVIRONMENT',   'sandbox');  // 'sandbox' or 'production'
define('AIRTEL_CLIENT_ID',     'YOUR_AIRTEL_CLIENT_ID_HERE');
define('AIRTEL_CLIENT_SECRET', 'YOUR_AIRTEL_CLIENT_SECRET_HERE');
define('AIRTEL_CALLBACK_URL',  'https://yourdomain.com/work2/backend/api/airtel_callback.php');

define('AIRTEL_BASE_URL', AIRTEL_ENVIRONMENT === 'production'
    ? 'https://openapi.airtel.africa'
    : 'https://openapiuat.airtel.africa'
);
define('AIRTEL_COUNTRY',  'TZ');
define('AIRTEL_CURRENCY', 'TZS');

// ----------------------------------------------------------
// STRIPE (International Cards - Visa, Mastercard)
// Get credentials at: https://dashboard.stripe.com/apikeys
// Works on localhost immediately (no public URL needed for checkout)
// ----------------------------------------------------------
define('STRIPE_ENVIRONMENT',       'test');   // 'test' or 'live'
define('STRIPE_SECRET_KEY',        'sk_test_YOUR_STRIPE_SECRET_KEY_HERE');
define('STRIPE_PUBLISHABLE_KEY',   'pk_test_YOUR_STRIPE_PUBLISHABLE_KEY_HERE');
define('STRIPE_WEBHOOK_SECRET',    'whsec_YOUR_STRIPE_WEBHOOK_SECRET_HERE');

// ----------------------------------------------------------
// GENERAL
// ----------------------------------------------------------
define('PAYMENT_CURRENCY',     'TZS');
define('PAYMENT_COUNTRY_CODE', 'TZ');

// How long to poll for M-Pesa/mobile money confirmation (seconds)
define('PAYMENT_POLL_TIMEOUT', 60);
define('PAYMENT_POLL_INTERVAL', 5);
?>