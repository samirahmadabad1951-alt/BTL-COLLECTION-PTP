<?php
// work2/backend/api/payment.php — REAL PAYMENT INTEGRATION
// Supports: Stripe (cards), M-Pesa STK Push, Tigo Pesa, Airtel Money

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/payment_config.php';

function send($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$db = getDB();
if (!$db) send(['success' => false, 'message' => 'Database connection failed'], 500);

$action = $_GET['action'] ?? 'process';

// GET: Poll transaction status (for mobile money polling)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'check_status') {
    $txId = $_GET['transaction_id'] ?? '';
    if (!$txId) send(['success' => false, 'message' => 'Transaction ID required'], 400);
    $stmt = $db->prepare("SELECT status, payment_response FROM payment_transactions WHERE transaction_id = ?");
    $stmt->execute([$txId]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) send(['success' => false, 'message' => 'Transaction not found'], 404);
    send(['success' => true, 'status' => $tx['status']]);
}

// GET: Return Stripe publishable key (safe to expose)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_pk') {
    send(['success' => true, 'pk' => STRIPE_PUBLISHABLE_KEY]);
}

// GET: Create Stripe PaymentIntent (called before card form render)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'create_stripe_intent') {
    $amount = intval($_GET['amount'] ?? 0);
    if ($amount <= 0) send(['success' => false, 'message' => 'Invalid amount'], 400);

    if (STRIPE_SECRET_KEY === 'sk_test_YOUR_STRIPE_SECRET_KEY_HERE') {
        send(['success' => false, 'message' => 'Stripe not configured yet. Please set up STRIPE_SECRET_KEY in payment_config.php', 'not_configured' => true]);
    }

    $pi = stripeRequest('POST', '/v1/payment_intents', [
        'amount'   => $amount,
        'currency' => strtolower(PAYMENT_CURRENCY),
        'automatic_payment_methods' => ['enabled' => 'true'],
    ]);
    if (isset($pi['error'])) send(['success' => false, 'message' => $pi['error']['message']], 400);
    send(['success' => true, 'client_secret' => $pi['client_secret'], 'payment_intent_id' => $pi['id']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') send(['success' => false, 'message' => 'POST only'], 405);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$amount        = floatval($data['amount'] ?? 0);
$paymentMethod = trim($data['payment_method'] ?? '');
$phoneRef      = trim($data['phone'] ?? $data['payment_reference'] ?? '');
$customerName  = trim($data['customer_name'] ?? '');
$customerEmail = trim($data['customer_email'] ?? '');
$userId        = $_SESSION['user_id'] ?? $data['user_id'] ?? null;

if ($amount <= 0)          send(['success' => false, 'message' => 'Invalid payment amount'], 400);
if (empty($paymentMethod)) send(['success' => false, 'message' => 'Payment method is required'], 400);

$transactionId = 'TXN_' . date('YmdHis') . '_' . strtoupper(bin2hex(random_bytes(4)));

// ============================================================
// STRIPE — Card Payments
// ============================================================
if ($paymentMethod === 'Card') {
    $paymentIntentId = trim($data['payment_intent_id'] ?? '');
    if (empty($paymentIntentId)) {
        send(['success' => false, 'message' => 'Card payment intent ID missing. Please try again.'], 400);
    }

    if (STRIPE_SECRET_KEY === 'sk_test_YOUR_STRIPE_SECRET_KEY_HERE') {
        // Not configured yet — simulate for development
        recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, 'sim_' . $paymentIntentId, null, 'completed', null);
        send(['success' => true, 'message' => 'Card payment recorded (Stripe not yet configured)', 'transaction_id' => $transactionId, 'amount' => $amount, 'payment_method' => $paymentMethod]);
    }

    // Confirm PaymentIntent status
    $pi = stripeRequest('GET', '/v1/payment_intents/' . $paymentIntentId, []);
    if (isset($pi['error'])) send(['success' => false, 'message' => 'Payment verification failed: ' . $pi['error']['message']], 400);

    $piStatus = $pi['status'] ?? '';
    if ($piStatus !== 'succeeded') {
        send(['success' => false, 'message' => 'Payment not completed. Status: ' . $piStatus . '. Please retry.'], 400);
    }

    recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $pi['id'], null, 'completed', json_encode(['stripe_pi' => $pi['id']]));
    send(['success' => true, 'message' => 'Card payment successful', 'transaction_id' => $transactionId, 'amount' => $amount, 'payment_method' => $paymentMethod, 'reference' => $pi['id']]);
}

// ============================================================
// MOBILE MONEY — Common phone normalize
// ============================================================
$mobileMethods = ['M-Pesa', 'Tigo Pesa', 'Airtel Money'];
if (in_array($paymentMethod, $mobileMethods)) {
    $digits = preg_replace('/\D/', '', $phoneRef);
    if (strlen($digits) < 9) send(['success' => false, 'message' => 'Please enter a valid phone number for ' . $paymentMethod], 400);
    if (substr($digits, 0, 1) === '0') $digits = '255' . substr($digits, 1);
    if (substr($digits, 0, 3) !== '255') $digits = '255' . $digits;

    if ($paymentMethod === 'M-Pesa') {
        if (MPESA_CONSUMER_KEY === 'YOUR_MPESA_CONSUMER_KEY_HERE') {
            // Not configured — record pending, frontend will show PIN entry dialog then mark complete
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $digits, $digits, 'pending', null);
            send(['success' => true, 'pending' => true, 'message' => 'Enter your M-Pesa PIN on your phone to complete payment.', 'transaction_id' => $transactionId, 'amount' => $amount, 'payment_method' => $paymentMethod, 'poll' => false]);
        }
        $token = mpesaGetToken();
        if (!$token) send(['success' => false, 'message' => 'M-Pesa unavailable. Try another payment method.'], 503);
        $ts   = date('YmdHis');
        $pass = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $ts);
        $body = ['BusinessShortCode' => MPESA_SHORTCODE, 'Password' => $pass, 'Timestamp' => $ts, 'TransactionType' => 'CustomerPayBillOnline', 'Amount' => intval($amount), 'PartyA' => $digits, 'PartyB' => MPESA_SHORTCODE, 'PhoneNumber' => $digits, 'CallBackURL' => MPESA_CALLBACK_URL . '?tx=' . $transactionId, 'AccountReference' => 'ECO' . strtoupper(substr($transactionId,-8)), 'TransactionDesc' => 'EcoStore Order'];
        $res  = mpesaPost(MPESA_BASE_URL . '/mpesa/stkpush/v1/processrequest', $body, $token);
        if (($res['ResponseCode'] ?? '') === '0') {
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $res['CheckoutRequestID'] ?? $transactionId, $digits, 'pending', json_encode($res));
            send(['success' => true, 'pending' => true, 'poll' => true, 'message' => 'M-Pesa prompt sent to ' . formatPhone($digits) . '. Enter your PIN on your phone.', 'transaction_id' => $transactionId, 'checkout_request_id' => $res['CheckoutRequestID'] ?? '']);
        }
        send(['success' => false, 'message' => 'M-Pesa error: ' . ($res['errorMessage'] ?? $res['ResponseDescription'] ?? 'Request failed')], 400);
    }

    if ($paymentMethod === 'Tigo Pesa') {
        if (TIGO_CLIENT_ID === 'YOUR_TIGO_CLIENT_ID_HERE') {
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $digits, $digits, 'pending', null);
            send(['success' => true, 'pending' => true, 'message' => 'Enter your Tigo Pesa PIN on your phone to complete payment.', 'transaction_id' => $transactionId, 'amount' => $amount, 'payment_method' => $paymentMethod, 'poll' => false]);
        }
        $tk = tigoGetToken();
        if (!$tk) send(['success' => false, 'message' => 'Tigo Pesa unavailable.'], 503);
        $body = ['MasterMerchant' => ['Account' => TIGO_BILLER_MSISDN, 'Pin' => TIGO_BILLER_CODE], 'Subscriber' => ['Account' => $digits, 'CountryCode' => '255', 'Country' => 'TZA'], 'TransactionRefId' => $transactionId, 'Payment' => ['Amount' => $amount, 'CurrencyCode' => 'TZS'], 'Timestamp' => date('c'), 'Type' => 'Payment', 'CallbackUrl' => TIGO_CALLBACK_URL . '?tx=' . $transactionId];
        $res = tigoPost(TIGO_BASE_URL . '/tigo-api/v1/payment', $body, $tk);
        if (($res['Status'] ?? '') === '200') {
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $res['ReferenceId'] ?? $transactionId, $digits, 'pending', json_encode($res));
            send(['success' => true, 'pending' => true, 'poll' => true, 'message' => 'Tigo Pesa prompt sent. Enter your PIN to confirm.', 'transaction_id' => $transactionId]);
        }
        send(['success' => false, 'message' => 'Tigo Pesa error: ' . ($res['Message'] ?? 'Request failed')], 400);
    }

    if ($paymentMethod === 'Airtel Money') {
        if (AIRTEL_CLIENT_ID === 'YOUR_AIRTEL_CLIENT_ID_HERE') {
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $digits, $digits, 'pending', null);
            send(['success' => true, 'pending' => true, 'message' => 'Enter your Airtel Money PIN on your phone to complete payment.', 'transaction_id' => $transactionId, 'amount' => $amount, 'payment_method' => $paymentMethod, 'poll' => false]);
        }
        $tk = airtelGetToken();
        if (!$tk) send(['success' => false, 'message' => 'Airtel Money unavailable.'], 503);
        $body = ['reference' => $transactionId, 'subscriber' => ['country' => AIRTEL_COUNTRY, 'currency' => AIRTEL_CURRENCY, 'msisdn' => substr($digits, 3)], 'transaction' => ['amount' => $amount, 'country' => AIRTEL_COUNTRY, 'currency' => AIRTEL_CURRENCY, 'id' => $transactionId]];
        $res = airtelPost(AIRTEL_BASE_URL . '/merchant/v2/payments/', $body, $tk);
        if (($res['status']['code'] ?? '') === '200') {
            recordTransaction($db, $transactionId, $userId, $amount, $paymentMethod, $res['data']['transaction']['id'] ?? $transactionId, $digits, 'pending', json_encode($res));
            send(['success' => true, 'pending' => true, 'poll' => true, 'message' => 'Airtel Money prompt sent. Enter your PIN to confirm.', 'transaction_id' => $transactionId]);
        }
        send(['success' => false, 'message' => 'Airtel Money error: ' . ($res['status']['message'] ?? 'Request failed')], 400);
    }
}

send(['success' => false, 'message' => 'Unknown payment method'], 400);

// ============================================================
// HELPERS
// ============================================================
function recordTransaction($db, $txId, $userId, $amount, $method, $ref, $phone, $status, $response) {
    try {
        $paidAt = ($status === 'completed') ? date('Y-m-d H:i:s') : null;
        $stmt = $db->prepare("INSERT INTO payment_transactions (transaction_id,user_id,amount,payment_method,payment_reference,phone_number,status,paid_at,payment_response) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),payment_response=VALUES(payment_response),paid_at=VALUES(paid_at)");
        $stmt->execute([$txId,$userId,$amount,$method,$ref,$phone,$status,$paidAt,$response]);
    } catch (PDOException $e) { error_log('recordTransaction: '.$e->getMessage()); }
}

function formatPhone($d) {
    if (strlen($d)===12&&substr($d,0,3)==='255') return '+255 '.substr($d,3,3).' '.substr($d,6,3).' '.substr($d,9,3);
    return '+'.$d;
}

function stripeRequest($method, $path, $params) {
    $ch = curl_init('https://api.stripe.com'.$path);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_USERPWD,STRIPE_SECRET_KEY.':');
    if ($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($params));}
    $res=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
    if($err) return ['error'=>['message'=>'Stripe connection error: '.$err]];
    return json_decode($res,true)?:['error'=>['message'=>'Invalid Stripe response']];
}

function mpesaGetToken() {
    $ch=curl_init(MPESA_BASE_URL.'/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_USERPWD,MPESA_CONSUMER_KEY.':'.MPESA_CONSUMER_SECRET);
    $res=curl_exec($ch);curl_close($ch);
    return json_decode($res,true)['access_token']??null;
}

function mpesaPost($url,$body,$token) {
    $ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json','Authorization: Bearer '.$token]);
    $res=curl_exec($ch);curl_close($ch);return json_decode($res,true)?:[];
}

function tigoGetToken() {
    $ch=curl_init(TIGO_BASE_URL.'/tigo-api/token');curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['client_id'=>TIGO_CLIENT_ID,'client_secret'=>TIGO_CLIENT_SECRET,'grant_type'=>'client_credentials']));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
    $res=curl_exec($ch);curl_close($ch);return json_decode($res,true)['access_token']??null;
}

function tigoPost($url,$body,$token) {
    $ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json','Authorization: Bearer '.$token]);
    $res=curl_exec($ch);curl_close($ch);return json_decode($res,true)?:[];
}

function airtelGetToken() {
    $ch=curl_init(AIRTEL_BASE_URL.'/auth/oauth2/token');curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode(['client_id'=>AIRTEL_CLIENT_ID,'client_secret'=>AIRTEL_CLIENT_SECRET,'grant_type'=>'client_credentials']));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
    $res=curl_exec($ch);curl_close($ch);return json_decode($res,true)['access_token']??null;
}

function airtelPost($url,$body,$token) {
    $ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json','Authorization: Bearer '.$token,'X-Country: '.AIRTEL_COUNTRY,'X-Currency: '.AIRTEL_CURRENCY]);
    $res=curl_exec($ch);curl_close($ch);return json_decode($res,true)?:[];
}