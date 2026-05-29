<?php
// work2/backend/api/admin.php — Seller flow fully fixed: proper columns, history preserved, limits enforced

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = 'localhost'; $dbname = 'ecostore'; $username = 'root'; $password = 'root';
    foreach ([8889, 3306] as $port) {
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch(PDOException $e) { $pdo = null; }
    }
    return null;
}

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
function isLoggedIn()  { return isset($_SESSION['user_id'], $_SESSION['user_email']); }
function isAdmin()     { return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'; }
function requireAdmin() {
    if (!isLoggedIn()) sendJsonResponse(['success'=>false,'message'=>'Please login first','redirect'=>'login.html'], 401);
    if (!isAdmin())    sendJsonResponse(['success'=>false,'message'=>'Admin access required'], 403);
}
function logActivity($action, $details = []) {
    try {
        $db = getDB();
        if (!$db || !isset($_SESSION['user_id'])) return;
        $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], $action, json_encode($details),
                      $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
    } catch (Exception $e) {}
}
function fixMediaPath($path) {
    if (empty($path)) return '';
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    $path = str_replace('/works2/', '/work2/', $path);
    if (strpos($path, '/uploads/') === 0) return '/work2/backend' . $path;
    return $path;
}

// ============================================================
// Ensure seller_rejected_history has ALL required columns
// (runs outside any transaction — DDL causes implicit commit)
// ============================================================
function ensureRejectedHistoryTable($db) {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS seller_rejected_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            application_id INT NULL,
            email VARCHAR(100) NOT NULL,
            brand_name VARCHAR(200),
            country VARCHAR(100),
            website VARCHAR(255),
            categories JSON,
            sustainability_description TEXT,
            certification_file VARCHAR(500),
            rejection_reason TEXT,
            rejection_count INT DEFAULT 1 COMMENT 'Times rejected or revoked (max 5)',
            total_applications INT DEFAULT 1 COMMENT 'Total times ever applied',
            is_blocked BOOLEAN DEFAULT FALSE,
            first_rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_rejected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_reapplied_at TIMESTAMP NULL,
            reviewed_by INT NULL,
            UNIQUE KEY unique_user_rejection (user_id),
            INDEX idx_email (email),
            INDEX idx_blocked (is_blocked)
        )");
        // Add missing columns to existing tables safely
        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM seller_rejected_history")->fetchAll() as $c) $cols[] = $c['Field'];
        $add = [
            'rejection_count'    => "ALTER TABLE seller_rejected_history ADD COLUMN rejection_count INT DEFAULT 1 AFTER rejection_reason",
            'total_applications' => "ALTER TABLE seller_rejected_history ADD COLUMN total_applications INT DEFAULT 1 AFTER rejection_count",
            'is_blocked'         => "ALTER TABLE seller_rejected_history ADD COLUMN is_blocked BOOLEAN DEFAULT FALSE AFTER total_applications",
            'last_reapplied_at'  => "ALTER TABLE seller_rejected_history ADD COLUMN last_reapplied_at TIMESTAMP NULL AFTER last_rejected_at",
            'application_id'     => "ALTER TABLE seller_rejected_history ADD COLUMN application_id INT NULL AFTER user_id",
        ];
        foreach ($add as $col => $sql) {
            if (!in_array($col, $cols)) { try { $db->exec($sql); } catch(Exception $e){} }
        }
        // Rename attempt_count → rejection_count if old schema
        if (in_array('attempt_count', $cols) && !in_array('rejection_count', $cols)) {
            try { $db->exec("ALTER TABLE seller_rejected_history CHANGE attempt_count rejection_count INT DEFAULT 1"); } catch(Exception $e){}
        }
        $done = true;
    } catch (Exception $e) {}
}

$db = getDB();
if (!$db) sendJsonResponse(['success'=>false,'message'=>'Database connection failed'], 500);

// Runtime migrations
try { $db->exec("ALTER TABLE seller_applications ADD COLUMN IF NOT EXISTS user_id INT NULL AFTER email"); } catch(Exception $e) {}
try {
    $cols = $db->query("SHOW COLUMNS FROM seller_applications LIKE 'user_id'")->fetchAll();
    if (empty($cols)) $db->exec("ALTER TABLE seller_applications ADD COLUMN user_id INT NULL AFTER email");
} catch(Exception $e) {}
try {
    $roleCol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($roleCol && strpos($roleCol['Type'], 'seller') === false) {
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','seller') DEFAULT 'user'");
    }
} catch(Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================================
// PUBLIC: Test
// ============================================================
if ($method === 'GET' && $action === 'test') {
    sendJsonResponse(['success'=>true,'message'=>'Admin API working','timestamp'=>date('Y-m-d H:i:s')]);
}

// ============================================================
// PUBLIC: Submit seller application
// Any logged-in non-admin user can apply. Blocked users cannot re-apply.
// ============================================================
if ($method === 'POST' && $action === 'submit-seller-application') {
    try {
        $isMultipart = !empty($_FILES);
        $data = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

        $required = ['brand_name','email','country','sustainability_description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                sendJsonResponse(['success'=>false,'message'=>ucfirst(str_replace('_',' ',$field)).' is required'], 400);
            }
        }

        $userId = $_SESSION['user_id'] ?? null;
        $email  = trim($data['email']);

        // Check if user is blocked
        ensureRejectedHistoryTable($db);
        if ($userId) {
            $blocked = $db->prepare("SELECT rejection_count, is_blocked FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
            $blocked->execute([$userId]);
            $bRow = $blocked->fetch();
            if ($bRow && ($bRow['is_blocked'] || intval($bRow['rejection_count']) >= 5)) {
                sendJsonResponse(['success'=>false,'message'=>'Your account has reached the maximum of 5 rejections. You cannot re-apply. Please contact the admin.'], 403);
            }
        }

        // Check for existing pending/approved application
        $stmt = $db->prepare("SELECT id, status FROM seller_applications WHERE user_id = ? AND status IN ('pending','approved') ORDER BY submitted_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($existing['status'] === 'pending')  sendJsonResponse(['success'=>false,'message'=>'You already have a pending application. Please wait for admin review.'], 400);
            if ($existing['status'] === 'approved') sendJsonResponse(['success'=>false,'message'=>'Your application is already approved! Go to your Seller Dashboard.'], 400);
        }

        // Handle certification file upload
        $certFilePath = '';
        if ($isMultipart && isset($_FILES['cert_file']) && $_FILES['cert_file']['error'] === UPLOAD_ERR_OK) {
            $certDir = dirname(__DIR__) . '/uploads/certifications/';
            if (!file_exists($certDir)) mkdir($certDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['cert_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
                $certFilename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                if (move_uploaded_file($_FILES['cert_file']['tmp_name'], $certDir . $certFilename)) {
                    $certFilePath = '/work2/backend/uploads/certifications/' . $certFilename;
                }
            }
        }

        $categories = $data['categories'] ?? [];
        if (is_string($categories)) $categories = json_decode($categories, true) ?: explode(',', $categories);

        // Get total application count for this user (how many times they've applied ever)
        $countStmt = $db->prepare("SELECT COUNT(*) FROM seller_applications WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $prevCount = intval($countStmt->fetchColumn());

        // Also check rejected history for total_applications
        $histStmt = $db->prepare("SELECT total_applications FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
        $histStmt->execute([$userId]);
        $histRow = $histStmt->fetch();
        $histTotal = $histRow ? intval($histRow['total_applications']) : 0;

        $attemptCount = max($prevCount, $histTotal) + 1;

        $db->prepare("INSERT INTO seller_applications
            (brand_name, email, user_id, country, website, categories, sustainability_description, certification_file, submitted_by, status, attempt_count)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)")
           ->execute([
               $data['brand_name'], $email, $userId,
               $data['country'], $data['website'] ?? '',
               json_encode($categories),
               $data['sustainability_description'],
               $certFilePath ?: ($data['certification_file'] ?? ''),
               $userId,
               $attemptCount
           ]);

        // Update last_reapplied_at in rejected history if they have one
        if ($histRow) {
            $db->prepare("UPDATE seller_rejected_history SET last_reapplied_at = NOW(), total_applications = ? WHERE user_id = ?")
               ->execute([$attemptCount, $userId]);
        }

        sendJsonResponse(['success'=>true,'message'=>'Application submitted! We will review within 24 hours. 🌱']);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: My application status (any logged-in user)
// Priority: seller role > pending in seller_applications > rejected history > none
// ============================================================
if ($method === 'GET' && $action === 'my-application') {
    if (!isLoggedIn()) sendJsonResponse(['success'=>false,'message'=>'Not logged in'], 401);

    try {
        $userId = $_SESSION['user_id'] ?? null;
        $email  = $_SESSION['user_email'] ?? '';

        if (!$userId) sendJsonResponse(['success'=>true,'application'=>null]);

        // 1. Check current role in DB (authoritative)
        $uStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch();

        if ($u && $u['role'] === 'seller') {
            // Approved — find their application row
            $aStmt = $db->prepare("SELECT id, status, brand_name, submitted_at, reviewed_at, attempt_count FROM seller_applications WHERE user_id = ? AND status = 'approved' ORDER BY reviewed_at DESC LIMIT 1");
            $aStmt->execute([$userId]);
            $app = $aStmt->fetch();
            if ($app) {
                sendJsonResponse(['success'=>true,'application'=>$app]);
            }
            // No row but role=seller: synthesize
            sendJsonResponse(['success'=>true,'application'=>['id'=>0,'status'=>'approved','brand_name'=>'','submitted_at'=>null,'reviewed_at'=>null,'attempt_count'=>1]]);
        }

        // 2. Check pending application
        $pStmt = $db->prepare("SELECT id, status, brand_name, submitted_at, reviewed_at, attempt_count FROM seller_applications WHERE user_id = ? AND status = 'pending' ORDER BY submitted_at DESC LIMIT 1");
        $pStmt->execute([$userId]);
        $pendingApp = $pStmt->fetch();
        if ($pendingApp) {
            sendJsonResponse(['success'=>true,'application'=>$pendingApp]);
        }

        // 3. Check rejected history
        ensureRejectedHistoryTable($db);
        $rStmt = $db->prepare("SELECT
            id,
            'rejected' AS status,
            brand_name,
            first_rejected_at AS submitted_at,
            last_rejected_at AS reviewed_at,
            rejection_count,
            total_applications,
            is_blocked,
            rejection_reason
            FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
        $rStmt->execute([$userId]);
        $rejectedApp = $rStmt->fetch();
        if ($rejectedApp) {
            sendJsonResponse(['success'=>true,'application'=>$rejectedApp]);
        }

        // 4. No application found
        sendJsonResponse(['success'=>true,'application'=>null]);

    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUBLIC GET: Site settings
// ============================================================
if ($method === 'GET' && $action === 'public-site-settings') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $adminUser = $db->query("SELECT email, phone, address FROM users WHERE role='admin' LIMIT 1")->fetch();
        $defaults = [
            'admin_email'   => $adminUser['email']   ?? '',
            'admin_phone'   => $adminUser['phone']   ?? '',
            'admin_address' => $adminUser['address'] ?? 'Dar es Salaam, Tanzania',
            'support_hours' => 'Mon-Fri, 9am-6pm EAT',
            'site_name'     => 'EcoStore',
        ];
        foreach ($defaults as $k => $v) {
            if ($v) $db->prepare("INSERT IGNORE INTO site_settings (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
        }
        $stmt = $db->query("SELECT `key`, `value` FROM site_settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        sendJsonResponse(['success'=>true,'settings'=>$settings]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>true,'settings'=>[]]);
    }
}

// ============================================================
// ALL remaining endpoints require admin
// ============================================================
requireAdmin();

// ============================================================
// GET: Dashboard stats
// ============================================================
if ($method === 'GET' && $action === 'stats') {
    try {
        $stats = [];
        $stats['total_users']       = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $stats['new_users_30d']     = (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $stats['active_products']   = (int)$db->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
        $stats['pending_products']  = (int)$db->query("SELECT COUNT(*) FROM products WHERE status = 'pending'")->fetchColumn();
        $stats['total_orders']      = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $stats['total_revenue']     = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status = 'completed'")->fetchColumn();
        $stats['revenue_30d']       = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $stats['total_carbon_saved']= (float)$db->query("SELECT COALESCE(SUM(total_carbon_saved),0) FROM orders")->fetchColumn();
        $stats['pending_sellers']   = (int)$db->query("SELECT COUNT(*) FROM seller_applications WHERE status = 'pending'")->fetchColumn();

        $stats['recent_orders'] = $db->query("SELECT id, order_number, customer_name, total_amount, status, created_at FROM orders ORDER BY created_at DESC LIMIT 10")->fetchAll() ?: [];
        $stats['recent_users']  = $db->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll() ?: [];

        sendJsonResponse(['success'=>true,'stats'=>$stats]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: All users
// ============================================================
if ($method === 'GET' && $action === 'users') {
    try {
        $search = $_GET['search'] ?? '';
        $query  = "SELECT id, name, email, phone, address, role, created_at FROM users";
        $params = [];
        if (!empty($search)) {
            $query .= " WHERE name LIKE ? OR email LIKE ?";
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        $query .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($query); $stmt->execute($params);
        sendJsonResponse(['success'=>true,'users'=>$stmt->fetchAll() ?: []]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Update user role
// ============================================================
if ($method === 'PUT' && $action === 'user-role') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['user_id']) || empty($data['role'])) sendJsonResponse(['success'=>false,'message'=>'User ID and role required'], 400);
        if (!in_array($data['role'], ['user','admin','seller'])) sendJsonResponse(['success'=>false,'message'=>'Invalid role'], 400);
        if ($data['user_id'] == $_SESSION['user_id']) sendJsonResponse(['success'=>false,'message'=>'Cannot change your own role'], 400);
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$data['role'], $data['user_id']]);
        logActivity('user_role_changed', ['user_id'=>$data['user_id'],'new_role'=>$data['role']]);
        sendJsonResponse(['success'=>true,'message'=>'User role updated to '.$data['role']]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// DELETE: Delete user
// ============================================================
if ($method === 'DELETE' && $action === 'user') {
    try {
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        if (!$userId) sendJsonResponse(['success'=>false,'message'=>'User ID required'], 400);
        if ($userId == $_SESSION['user_id']) sendJsonResponse(['success'=>false,'message'=>'Cannot delete your own account'], 400);
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$userId]);
        if ($stmt->rowCount() > 0) {
            logActivity('user_deleted', ['user_id'=>$userId]);
            sendJsonResponse(['success'=>true,'message'=>'User deleted']);
        } else {
            sendJsonResponse(['success'=>false,'message'=>'User not found or is an admin'], 404);
        }
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: Pending products for admin review
// ============================================================
if ($method === 'GET' && $action === 'pending-verifications') {
    try {
        $stmt = $db->prepare("SELECT p.*, u.name AS seller_user_name, u.email AS seller_email FROM products p LEFT JOIN users u ON u.id = p.seller_id WHERE p.status = 'pending' ORDER BY p.created_at ASC");
        $stmt->execute();
        $pending = $stmt->fetchAll() ?: [];
        foreach ($pending as &$product) {
            $product['labels']    = json_decode($product['labels']    ?? '', true) ?: [];
            $product['materials'] = json_decode($product['materials'] ?? '', true) ?: [];
            $stmt2 = $db->prepare("SELECT id, media_type, file_path, sort_order FROM product_media WHERE product_id = ? ORDER BY sort_order, id ASC");
            $stmt2->execute([$product['id']]);
            $media = $stmt2->fetchAll();
            $images = []; $videos = [];
            foreach ($media as $m) {
                $fp = fixMediaPath($m['file_path']);
                $entry = ['id'=>$m['id'],'path'=>$fp,'is_url'=>filter_var($fp,FILTER_VALIDATE_URL)];
                if ($m['media_type'] === 'image') $images[] = $entry; else $videos[] = $entry;
            }
            $product['media_images']  = $images;
            $product['media_videos']  = $videos;
            $product['images']        = array_column($images, 'path');
            $product['videos']        = array_column($videos, 'path');
            $product['images_count']  = count($images);
            $product['videos_count']  = count($videos);
            $product['seller_price']  = (float)($product['seller_price'] ?? $product['price']);
            $product['admin_markup']  = (float)($product['admin_markup'] ?? 0);
            $product['price']         = (float)$product['price'];
        }
        unset($product);
        sendJsonResponse(['success'=>true,'pending'=>$pending]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Approve product (with optional admin markup)
// ============================================================
if ($method === 'PUT' && $action === 'approve-product') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['product_id'])) sendJsonResponse(['success'=>false,'message'=>'Product ID required'], 400);
        $productId = (int)$data['product_id'];
        $stmt = $db->prepare("SELECT id, seller_price, price, submitted_by_role FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) sendJsonResponse(['success'=>false,'message'=>'Product not found'], 404);
        $sellerPrice = (float)($product['seller_price'] ?: $product['price']);
        $adminMarkup = isset($data['admin_markup']) ? max(0, (float)$data['admin_markup']) : 0;
        $finalPrice  = round($sellerPrice + $adminMarkup, 2);
        if (isset($data['final_price']) && (float)$data['final_price'] > 0) {
            $finalPrice  = (float)$data['final_price'];
            $adminMarkup = round($finalPrice - $sellerPrice, 2);
            if ($adminMarkup < 0) $adminMarkup = 0;
        }
        $db->prepare("UPDATE products SET status = 'active', admin_markup = ?, price = ? WHERE id = ?")->execute([$adminMarkup, $finalPrice, $productId]);
        logActivity('product_approved', ['product_id'=>$productId,'seller_price'=>$sellerPrice,'admin_markup'=>$adminMarkup,'final_price'=>$finalPrice]);
        sendJsonResponse(['success'=>true,'message'=>'Product approved and is now live!','seller_price'=>$sellerPrice,'admin_markup'=>$adminMarkup,'final_price'=>$finalPrice]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Reject product
// ============================================================
if ($method === 'PUT' && $action === 'reject-product') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['product_id'])) sendJsonResponse(['success'=>false,'message'=>'Product ID required'], 400);
        $db->prepare("UPDATE products SET status = 'inactive' WHERE id = ?")->execute([$data['product_id']]);
        logActivity('product_rejected', ['product_id'=>$data['product_id'],'reason'=>$data['reason']??'']);
        sendJsonResponse(['success'=>true,'message'=>'Product rejected']);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: All products
// ============================================================
if ($method === 'GET' && $action === 'products') {
    try {
        $status = $_GET['status'] ?? '';
        $query  = "SELECT p.*, u.name AS seller_user_name, u.email AS seller_email FROM products p LEFT JOIN users u ON u.id = p.seller_id WHERE 1=1";
        $params = [];
        if (!empty($status) && $status !== 'all') { $query .= " AND p.status = ?"; $params[] = $status; }
        $query .= " ORDER BY p.created_at DESC";
        $stmt = $db->prepare($query); $stmt->execute($params);
        $products = $stmt->fetchAll() ?: [];
        foreach ($products as &$p) {
            $stmt2 = $db->prepare("SELECT id, media_type, file_path, sort_order FROM product_media WHERE product_id = ? ORDER BY sort_order, id ASC");
            $stmt2->execute([$p['id']]);
            $media = $stmt2->fetchAll();
            $images = []; $videos = [];
            foreach ($media as $m) {
                $fp = fixMediaPath($m['file_path']);
                if ($m['media_type'] === 'image') $images[] = ['id'=>$m['id'],'path'=>$fp];
                else $videos[] = ['id'=>$m['id'],'path'=>$fp,'is_url'=>filter_var($fp,FILTER_VALIDATE_URL)];
            }
            $p['images']        = array_column($images, 'path');
            $p['videos']        = array_column($videos, 'path');
            $p['media_images']  = $images;
            $p['media_videos']  = $videos;
            $p['images_count']  = count($images);
            $p['videos_count']  = count($videos);
            $p['image']         = !empty($p['images']) ? $p['images'][0] : '';
            $p['labels']        = json_decode($p['labels']    ?? '', true) ?: [];
            $p['materials']     = json_decode($p['materials'] ?? '', true) ?: [];
            $p['seller_price']  = (float)($p['seller_price'] ?? $p['price']);
            $p['admin_markup']  = (float)($p['admin_markup'] ?? 0);
            $p['price']         = (float)$p['price'];
        }
        unset($p);
        sendJsonResponse(['success'=>true,'products'=>$products]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: All orders
// ============================================================
if ($method === 'GET' && $action === 'orders') {
    try {
        $status = $_GET['status'] ?? '';
        $query  = "SELECT * FROM orders";
        $params = [];
        if (!empty($status) && $status !== 'all') { $query .= " WHERE status = ?"; $params[] = $status; }
        $query .= " ORDER BY created_at DESC";
        $stmt = $db->prepare($query); $stmt->execute($params);
        $orders = $stmt->fetchAll() ?: [];
        foreach ($orders as &$order) {
            $stmt2 = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt2->execute([$order['id']]);
            $order['items'] = $stmt2->fetchAll() ?: [];
        }
        unset($order);
        sendJsonResponse(['success'=>true,'orders'=>$orders]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Update order status
// ============================================================
if ($method === 'PUT' && $action === 'order-status') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['order_id']) || empty($data['status'])) sendJsonResponse(['success'=>false,'message'=>'Order ID and status required'], 400);
        $allowed = ['pending','processing','shipped','completed','cancelled','refunded'];
        if (!in_array($data['status'], $allowed)) sendJsonResponse(['success'=>false,'message'=>'Invalid status'], 400);
        $db->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$data['status'], $data['order_id']]);
        logActivity('order_status_updated', ['order_id'=>$data['order_id'],'status'=>$data['status']]);
        sendJsonResponse(['success'=>true,'message'=>'Order status updated']);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET: Seller applications (all statuses — properly joined)
// pending + approved  → seller_applications
// rejected            → seller_rejected_history
// ============================================================
if ($method === 'GET' && $action === 'seller-applications') {
    try {
        ensureRejectedHistoryTable($db);
        $applications = [];

        // PENDING + APPROVED from seller_applications
        $stmt = $db->prepare("
            SELECT sa.*,
                   u.name    AS applicant_name,
                   u.role    AS current_role,
                   applicant.name  AS user_name,
                   applicant.email AS user_email
            FROM seller_applications sa
            LEFT JOIN users u         ON u.id         = sa.submitted_by
            LEFT JOIN users applicant ON applicant.id = sa.user_id
            WHERE sa.status IN ('pending', 'approved')
            ORDER BY sa.submitted_at DESC
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $app) {
            $app['categories']  = json_decode($app['categories'] ?? '', true) ?: [];
            $app['source']      = 'applications';
            $app['reviewed_at'] = $app['reviewed_at'] ?? null;
            $applications[]     = $app;
        }

        // REJECTED from seller_rejected_history
        $stmt2 = $db->prepare("
            SELECT srh.*,
                   u.name AS user_name,
                   u.email AS user_email,
                   u.role AS current_role,
                   'rejected' AS status
            FROM seller_rejected_history srh
            LEFT JOIN users u ON u.id = srh.user_id
            ORDER BY srh.last_rejected_at DESC
        ");
        $stmt2->execute();
        foreach ($stmt2->fetchAll() as $app) {
            $app['categories']    = json_decode($app['categories'] ?? '', true) ?: [];
            $app['source']        = 'rejected_history';
            $app['submitted_at']  = $app['first_rejected_at'] ?? null;
            $app['reviewed_at']   = $app['last_rejected_at']  ?? null;
            $app['applicant_name']= $app['user_name'] ?? '';
            $applications[]       = $app;
        }

        usort($applications, function($a, $b) {
            $ta = strtotime($a['submitted_at'] ?? '1970-01-01') ?: 0;
            $tb = strtotime($b['submitted_at'] ?? '1970-01-01') ?: 0;
            return $tb - $ta;
        });

        sendJsonResponse(['success'=>true,'applications'=>$applications]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>true,'applications'=>[],'debug'=>$e->getMessage()]);
    }
}

// ============================================================
// PUT: Approve seller application
// → user role = 'seller', create seller_accounts row, keep application
// ============================================================
if ($method === 'PUT' && $action === 'approve-seller') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['application_id'])) sendJsonResponse(['success'=>false,'message'=>'Application ID required'], 400);

        $stmt = $db->prepare("SELECT * FROM seller_applications WHERE id = ?");
        $stmt->execute([$data['application_id']]);
        $application = $stmt->fetch();
        if (!$application) sendJsonResponse(['success'=>false,'message'=>'Application not found'], 404);

        // Resolve user_id
        $userId = $application['user_id'] ?? null;
        if (!$userId) {
            $uStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $uStmt->execute([$application['email']]);
            $u = $uStmt->fetch();
            if ($u) $userId = $u['id'];
        }
        if (!$userId) sendJsonResponse(['success'=>false,'message'=>'User not found. They must register first.'], 400);

        $uInfo = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $uInfo->execute([$userId]);
        $user = $uInfo->fetch();

        $db->beginTransaction();
        $db->prepare("UPDATE seller_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$_SESSION['user_id'], $data['application_id']]);
        $db->prepare("UPDATE users SET role = 'seller' WHERE id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO seller_accounts (user_id, brand_name, email, country, website, categories, sustainability_description, certification_file, approved_at, approved_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name), approved_at=NOW(), approved_by=VALUES(approved_by)")
           ->execute([$userId, $application['brand_name'], $application['email'], $application['country']??'', $application['website']??'',
                      $application['categories']??'[]', $application['sustainability_description']??'', $application['certification_file']??'', $_SESSION['user_id']]);
        // Remove from rejected history — seller is now approved, they must not appear in Rejected tab
        $db->prepare("DELETE FROM seller_rejected_history WHERE user_id = ?")->execute([$userId]);
        $db->commit();

        logActivity('seller_approved', ['application_id'=>$data['application_id'],'user_id'=>$userId]);
        sendJsonResponse(['success'=>true,'message'=>'Seller approved! '.($user['name']??'').' can now upload products.','user_id'=>$userId,'user_name'=>$user['name']??'','email'=>$user['email']??'']);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJsonResponse(['success'=>false,'message'=>'Error: '.$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Reject seller application
// → moves to seller_rejected_history (upsert), increments rejection_count
// → deletes from seller_applications
// → if rejection_count >= 5, set is_blocked = TRUE
// ============================================================
if ($method === 'PUT' && $action === 'reject-seller') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['application_id'])) sendJsonResponse(['success'=>false,'message'=>'Application ID required'], 400);

        $stmt = $db->prepare("SELECT * FROM seller_applications WHERE id = ?");
        $stmt->execute([$data['application_id']]);
        $application = $stmt->fetch();
        if (!$application) sendJsonResponse(['success'=>false,'message'=>'Application not found'], 404);

        // DDL outside transaction
        ensureRejectedHistoryTable($db);

        $rejectionReason = trim($data['reason'] ?? '');
        $reviewedBy      = $_SESSION['user_id'] ?? null;
        $userId          = $application['user_id'] ?? null;
        $attemptCount    = intval($application['attempt_count'] ?? 1);

        // Check existing rejection row to calculate new rejection_count
        $existStmt = $db->prepare("SELECT rejection_count, total_applications FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
        $existStmt->execute([$userId]);
        $existRow = $existStmt->fetch();
        $newRejCount = ($existRow ? intval($existRow['rejection_count']) : 0) + 1;
        $newTotalApps = max($attemptCount, $existRow ? intval($existRow['total_applications']) : 0);
        $isBlocked = ($newRejCount >= 5) ? 1 : 0;

        $db->beginTransaction();

        $db->prepare("INSERT INTO seller_rejected_history
            (user_id, email, brand_name, country, website, categories, sustainability_description,
             certification_file, rejection_reason, rejection_count, total_applications, is_blocked, reviewed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                brand_name = VALUES(brand_name),
                country = VALUES(country),
                website = VALUES(website),
                categories = VALUES(categories),
                sustainability_description = VALUES(sustainability_description),
                certification_file = VALUES(certification_file),
                rejection_reason = VALUES(rejection_reason),
                rejection_count = rejection_count + 1,
                total_applications = GREATEST(total_applications, VALUES(total_applications)),
                is_blocked = IF(rejection_count + 1 >= 5, TRUE, FALSE),
                last_rejected_at = NOW(),
                reviewed_by = VALUES(reviewed_by)
        ")->execute([
            $userId,
            $application['email'],
            $application['brand_name'],
            $application['country']  ?? '',
            $application['website']  ?? '',
            $application['categories'] ?? '[]',
            $application['sustainability_description'] ?? '',
            $application['certification_file'] ?? '',
            $rejectionReason,
            $newRejCount,
            $newTotalApps,
            $isBlocked,
            $reviewedBy
        ]);

        // Remove from seller_applications
        $db->prepare("DELETE FROM seller_applications WHERE id = ?")->execute([$data['application_id']]);

        // Ensure user role is 'user' (not 'seller')
        if ($userId) $db->prepare("UPDATE users SET role = 'user' WHERE id = ? AND role != 'admin'")->execute([$userId]);

        $db->commit();

        $msg = $isBlocked
            ? 'Application rejected. This user has now reached 5 rejections and is permanently blocked from re-applying.'
            : 'Application rejected and moved to rejection history. Rejections: '.$newRejCount.'/5.';

        logActivity('seller_rejected', ['application_id'=>$data['application_id'],'email'=>$application['email'],'rejection_count'=>$newRejCount]);
        sendJsonResponse(['success'=>true,'message'=>$msg,'rejection_count'=>$newRejCount,'is_blocked'=>(bool)$isBlocked]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Revoke approved seller
// → moves to seller_rejected_history, deletes from seller_applications
// → removes seller_accounts row, downgrades role to 'user'
// ============================================================
if ($method === 'PUT' && $action === 'revoke-seller') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['application_id'])) sendJsonResponse(['success'=>false,'message'=>'Application ID required'], 400);

        $stmt = $db->prepare("SELECT * FROM seller_applications WHERE id = ?");
        $stmt->execute([$data['application_id']]);
        $application = $stmt->fetch();
        if (!$application) sendJsonResponse(['success'=>false,'message'=>'Application not found'], 404);

        $userId = $application['user_id'] ?? null;
        if (!$userId) {
            $uStmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $uStmt->execute([$application['email']]);
            $u = $uStmt->fetch();
            if ($u) $userId = $u['id'];
        }

        // DDL outside transaction
        ensureRejectedHistoryTable($db);

        // Check existing rejection history
        $existStmt = $db->prepare("SELECT rejection_count, total_applications FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
        $existStmt->execute([$userId]);
        $existRow = $existStmt->fetch();
        $newRejCount  = ($existRow ? intval($existRow['rejection_count']) : 0) + 1;
        $attemptCount = intval($application['attempt_count'] ?? 1);
        $newTotalApps = max($attemptCount, $existRow ? intval($existRow['total_applications']) : 0);
        $isBlocked    = ($newRejCount >= 5) ? 1 : 0;

        $db->beginTransaction();

        $db->prepare("INSERT INTO seller_rejected_history
            (user_id, email, brand_name, country, website, categories, sustainability_description,
             certification_file, rejection_reason, rejection_count, total_applications, is_blocked, reviewed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Access revoked by admin', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                rejection_reason  = 'Access revoked by admin',
                rejection_count   = rejection_count + 1,
                total_applications = GREATEST(total_applications, VALUES(total_applications)),
                is_blocked        = IF(rejection_count + 1 >= 5, TRUE, FALSE),
                last_rejected_at  = NOW(),
                reviewed_by       = VALUES(reviewed_by)
        ")->execute([
            $userId, $application['email'], $application['brand_name'],
            $application['country'] ?? '', $application['website'] ?? '',
            $application['categories'] ?? '[]',
            $application['sustainability_description'] ?? '',
            $application['certification_file'] ?? '',
            $newRejCount, $newTotalApps, $isBlocked,
            $_SESSION['user_id'] ?? null
        ]);

        // Remove from seller_applications
        $db->prepare("DELETE FROM seller_applications WHERE id = ?")->execute([$data['application_id']]);

        // Remove seller_accounts row (they're no longer a seller)
        if ($userId) {
            $db->prepare("DELETE FROM seller_accounts WHERE user_id = ?")->execute([$userId]);
            $db->prepare("UPDATE users SET role = 'user' WHERE id = ? AND role = 'seller'")->execute([$userId]);
        }

        $db->commit();

        logActivity('seller_revoked', ['application_id'=>$data['application_id'],'user_id'=>$userId,'rejection_count'=>$newRejCount]);
        sendJsonResponse(['success'=>true,'message'=>'Seller access revoked. User downgraded to regular user.','rejection_count'=>$newRejCount,'is_blocked'=>(bool)$isBlocked]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJsonResponse(['success'=>false,'message'=>'Error: '.$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Re-approve rejected seller (by application_id from seller_applications)
// ============================================================
if ($method === 'PUT' && $action === 're-approve-seller') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['application_id'])) sendJsonResponse(['success'=>false,'message'=>'Application ID required'], 400);

        $stmt = $db->prepare("SELECT * FROM seller_applications WHERE id = ?");
        $stmt->execute([$data['application_id']]);
        $application = $stmt->fetch();
        if (!$application) sendJsonResponse(['success'=>false,'message'=>'Application not found'], 404);

        $userId = $application['user_id'] ?? null;
        if (!$userId) {
            $u = $db->prepare("SELECT id FROM users WHERE email = ?");
            $u->execute([$application['email']]);
            $row = $u->fetch();
            if ($row) $userId = $row['id'];
        }
        if (!$userId) sendJsonResponse(['success'=>false,'message'=>'User account not found.'], 400);

        $uInfo = $db->prepare("SELECT name, email FROM users WHERE id = ?");
        $uInfo->execute([$userId]);
        $user = $uInfo->fetch();

        $db->beginTransaction();
        $db->prepare("UPDATE seller_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
           ->execute([$_SESSION['user_id'], $data['application_id']]);
        $db->prepare("UPDATE users SET role = 'seller' WHERE id = ?")->execute([$userId]);
        $db->prepare("INSERT INTO seller_accounts (user_id, brand_name, email, approved_at, approved_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name), approved_at=NOW(), approved_by=VALUES(approved_by)")
           ->execute([$userId, $application['brand_name'], $application['email'], $_SESSION['user_id']]);
        $db->commit();

        logActivity('seller_re_approved', ['application_id'=>$data['application_id'],'user_id'=>$userId]);
        sendJsonResponse(['success'=>true,'message'=>'Seller re-approved! '.($user['name']??'').' can now upload products.','user_id'=>$userId]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// PUT: Re-approve seller by email (from rejected_history)
// Admin re-approves a rejected seller. History row is KEPT (not deleted).
// ============================================================
if ($method === 'PUT' && $action === 're-approve-seller-by-email') {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        if (empty($data['email']) && empty($data['user_id'])) sendJsonResponse(['success'=>false,'message'=>'Email or user_id required'], 400);

        $email  = trim($data['email'] ?? '');
        $userId = isset($data['user_id']) ? intval($data['user_id']) : null;

        // Find user
        if ($userId) {
            $uStmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
        } else {
            $uStmt = $db->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
            $uStmt->execute([$email]);
        }
        $user = $uStmt->fetch();
        if (!$user) sendJsonResponse(['success'=>false,'message'=>'User not found. They must register first.'], 400);

        $userId = $user['id'];
        $email  = $user['email'];

        // Get brand name from rejected history
        ensureRejectedHistoryTable($db);
        $histStmt = $db->prepare("SELECT brand_name, rejection_count, total_applications FROM seller_rejected_history WHERE user_id = ? LIMIT 1");
        $histStmt->execute([$userId]);
        $histRow = $histStmt->fetch();
        $brandName    = $histRow['brand_name'] ?? $user['name'];
        $totalApps    = $histRow ? intval($histRow['total_applications']) : 1;

        $db->beginTransaction();

        // Update user role to seller
        $db->prepare("UPDATE users SET role = 'seller' WHERE id = ?")->execute([$userId]);

        // Create/update seller_accounts
        $db->prepare("INSERT INTO seller_accounts (user_id, brand_name, email, approved_at, approved_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name), approved_at=NOW(), approved_by=VALUES(approved_by)")
           ->execute([$userId, $brandName, $email, $_SESSION['user_id']]);

        // Create an approved application record so it shows in approved tab
        $existApproved = $db->prepare("SELECT id FROM seller_applications WHERE user_id = ? AND status = 'approved' LIMIT 1");
        $existApproved->execute([$userId]);
        if (!$existApproved->fetch()) {
            $db->prepare("INSERT INTO seller_applications
                (brand_name, email, user_id, country, status, attempt_count, submitted_by, reviewed_by, reviewed_at)
                VALUES (?, ?, ?, '', 'approved', ?, ?, ?, NOW())")
               ->execute([$brandName, $email, $userId, $totalApps, $_SESSION['user_id'], $_SESSION['user_id']]);
        } else {
            $db->prepare("UPDATE seller_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND status != 'approved'")
               ->execute([$_SESSION['user_id'], $userId]);
        }

        // IMPORTANT: Keep the rejected_history row — do NOT delete it.
        // It preserves the full history. The fact that rejected_history exists
        // does NOT mean the user is currently rejected — their ROLE is the source of truth.
        // Just clear the last_rejected marker so admin knows they were re-approved.
        // (We intentionally leave the row so history is visible in the Rejected tab.)

        $db->commit();

        logActivity('seller_re_approved_by_email', ['user_id'=>$userId,'email'=>$email]);
        sendJsonResponse([
            'success'   => true,
            'message'   => 'Seller re-approved! '.$user['name'].' can now upload products.',
            'user_id'   => $userId,
            'user_name' => $user['name']
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        sendJsonResponse(['success'=>false,'message'=>'Re-approve error: '.$e->getMessage()], 500);
    }
}

// ============================================================
// DELETE: Delete product
// ============================================================
if ($method === 'DELETE' && $action === 'product') {
    try {
        $id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
        if (!$id) sendJsonResponse(['success'=>false,'message'=>'Product ID required'], 400);
        $stmt = $db->prepare("SELECT file_path FROM product_media WHERE product_id = ?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $m) {
            if (!filter_var($m['file_path'], FILTER_VALIDATE_URL)) {
                $diskPath = dirname(dirname(__DIR__)) . $m['file_path'];
                if (file_exists($diskPath)) unlink($diskPath);
            }
        }
        $db->prepare("DELETE FROM product_media WHERE product_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        logActivity('product_deleted', ['product_id'=>$id]);
        sendJsonResponse(['success'=>true,'message'=>'Product deleted']);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

// ============================================================
// GET/PUT: Site settings
// ============================================================
if ($action === 'site-settings') {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (`key` VARCHAR(100) PRIMARY KEY, `value` TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        if ($method === 'PUT') {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $allowed = ['admin_email','admin_phone','admin_address','support_hours','site_name'];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $data)) {
                    $db->prepare("INSERT INTO site_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?")->execute([$k, $data[$k], $data[$k]]);
                }
            }
            logActivity('site_settings_updated', $data);
            sendJsonResponse(['success'=>true,'message'=>'Settings saved']);
        }
        $defaults = ['admin_email'=>'samirahmadabad1950@gmail.com','admin_phone'=>'+255764005707','admin_address'=>'Dar es Salaam, Tanzania','support_hours'=>'Mon-Fri, 9am-6pm EAT','site_name'=>'EcoStore'];
        foreach ($defaults as $k => $v) $db->prepare("INSERT IGNORE INTO site_settings (`key`, `value`) VALUES (?, ?)")->execute([$k, $v]);
        $stmt = $db->query("SELECT `key`, `value` FROM site_settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        sendJsonResponse(['success'=>true,'settings'=>$settings]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>true,'settings'=>[]]);
    }
}

// ============================================================
// GET: Admin profile
// ============================================================
if ($method === 'GET' && $action === 'admin-profile') {
    try {
        $stmt = $db->prepare("SELECT id, name, email, phone, address FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        sendJsonResponse(['success'=>true,'admin'=>$stmt->fetch()]);
    } catch (Exception $e) {
        sendJsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
    }
}

sendJsonResponse(['success'=>false,'message'=>'Unknown action: '.$action], 400);
?>