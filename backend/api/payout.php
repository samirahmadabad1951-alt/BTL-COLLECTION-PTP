<?php
// work2/backend/api/payout.php
// Handles seller earnings, payout account, and payout requests

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

$db = getDB();
if (!$db) sendJson(['success' => false, 'message' => 'Database error'], 500);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (!isLoggedIn()) {
    sendJson(['success' => false, 'message' => 'Not logged in'], 401);
}

$userId = (int)$_SESSION['user_id'];

// ============================================================
// GET earnings summary
// ============================================================
if ($method === 'GET' && $action === 'earnings') {
    try {
        // Ensure seller_accounts row exists
        $db->prepare("INSERT IGNORE INTO seller_accounts (user_id, business_name) VALUES (?, '')")
           ->execute([$userId]);

        $stmt = $db->prepare("SELECT pending_balance, available_balance, total_earned, total_withdrawn FROM seller_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            $row = ['pending_balance' => 0, 'available_balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0];
        }
        sendJson(['success' => true, 'earnings' => $row]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================================
// GET payout account details
// ============================================================
if ($method === 'GET' && $action === 'account') {
    try {
        $stmt = $db->prepare("SELECT * FROM seller_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $account = $stmt->fetch();
        sendJson(['success' => true, 'account' => $account ?: null]);
    } catch (Exception $e) {
        sendJson(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================================
// GET payout history
// ============================================================
if ($method === 'GET' && $action === 'history') {
    try {
        $stmt = $db->prepare("SELECT * FROM seller_payouts WHERE seller_id = ? ORDER BY requested_at DESC LIMIT 20");
        $stmt->execute([$userId]);
        $payouts = $stmt->fetchAll() ?: [];
        sendJson(['success' => true, 'payouts' => $payouts]);
    } catch (Exception $e) {
        sendJson(['success' => true, 'payouts' => []]);
    }
}

// ============================================================
// POST save payout account
// ============================================================
if ($method === 'POST' && $action === 'save_account') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (empty($data['business_name'])) {
            sendJson(['success' => false, 'message' => 'Business name is required'], 400);
        }

        // Normalise phone numbers to international format (Tanzania 255...)
        $normalisePhone = function($phone) {
            $phone = preg_replace('/\D/', '', $phone);
            if (strlen($phone) === 10 && $phone[0] === '0') {
                $phone = '255' . substr($phone, 1);
            }
            return $phone ?: null;
        };

        $stmt = $db->prepare("
            INSERT INTO seller_accounts (user_id, business_name, mpesa_phone, tigo_phone, airtel_phone, bank_name, bank_account)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                business_name = VALUES(business_name),
                mpesa_phone   = VALUES(mpesa_phone),
                tigo_phone    = VALUES(tigo_phone),
                airtel_phone  = VALUES(airtel_phone),
                bank_name     = VALUES(bank_name),
                bank_account  = VALUES(bank_account),
                updated_at    = NOW()
        ");
        $stmt->execute([
            $userId,
            $data['business_name'],
            $normalisePhone($data['mpesa_phone']  ?? ''),
            $normalisePhone($data['tigo_phone']   ?? ''),
            $normalisePhone($data['airtel_phone'] ?? ''),
            $data['bank_name']    ?? null,
            $data['bank_account'] ?? null,
        ]);
        sendJson(['success' => true, 'message' => 'Payout account saved!']);
    } catch (Exception $e) {
        sendJson(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================================
// POST request payout
// ============================================================
if ($method === 'POST' && $action === 'request_payout') {
    try {
        $data   = json_decode(file_get_contents('php://input'), true) ?: [];
        $amount = floatval($data['amount'] ?? 0);
        $method_name = $data['payout_method'] ?? 'M-Pesa';

        if ($amount < 1000) {
            sendJson(['success' => false, 'message' => 'Minimum payout is TZS 1,000'], 400);
        }

        // Check available balance
        $stmt = $db->prepare("SELECT available_balance FROM seller_accounts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $acct = $stmt->fetch();
        $available = floatval($acct['available_balance'] ?? 0);

        if ($amount > $available) {
            sendJson(['success' => false, 'message' => 'Insufficient available balance (TZS ' . number_format($available) . ' available)'], 400);
        }

        $db->beginTransaction();
        // Create payout record
        $db->prepare("INSERT INTO seller_payouts (seller_id, amount, payment_method, status) VALUES (?, ?, ?, 'pending')")
           ->execute([$userId, $amount, $method_name]);
        // Deduct from available balance
        $db->prepare("UPDATE seller_accounts SET available_balance = available_balance - ?, total_withdrawn = total_withdrawn + ? WHERE user_id = ?")
           ->execute([$amount, $amount, $userId]);
        $db->commit();

        sendJson(['success' => true, 'message' => 'Payout of TZS ' . number_format($amount) . ' requested. Processed within 1–3 business days.']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJson(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

sendJson(['success' => false, 'message' => 'Unknown action'], 400);
