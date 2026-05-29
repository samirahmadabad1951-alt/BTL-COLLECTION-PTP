<?php
//works2/backend/api/orders.php - COMPLETE ORDERS API WITH TAX, COMMISSION, AND TSH CURRENCY

/**
 * Orders API
 * Handles order creation, retrieval, and management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Define constants
define('TAX_RATE', 18.0);
define('ADMIN_COMMISSION_RATE', 5.0);

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * GET - Get orders
 */
if ($method === 'GET') {
    if (!isLoggedIn()) {
        sendJsonResponse(['success' => false, 'message' => 'Login required'], 401);
    }
    
    $orderId = $_GET['id'] ?? null;
    
    if ($orderId) {
        // Get single order
        if (isAdmin()) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
            $stmt->execute([$orderId, $_SESSION['user_id']]);
        }
        
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            sendJsonResponse(['success' => false, 'message' => 'Order not found'], 404);
        }
        
        // Get order items
        $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse(['success' => true, 'order' => $order]);
    } else {
        // Get all orders for user
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
        $offset = ($page - 1) * $perPage;
        
        if (isAdmin()) {
            $stmt = $db->prepare("SELECT * FROM orders ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$perPage, $offset]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $db->query("SELECT COUNT(*) as total FROM orders");
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        } else {
            $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$_SESSION['user_id'], $perPage, $offset]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        }
        
        foreach ($orders as &$order) {
            $stmt = $db->prepare("SELECT COUNT(*) as item_count FROM order_items WHERE order_id = ?");
            $stmt->execute([$order['id']]);
            $order['item_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['item_count'];
        }
        
        sendJsonResponse([
            'success' => true,
            'orders' => $orders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage)
            ]
        ]);
    }
}

/**
 * POST - Create new order
 */
if ($method === 'POST') {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['user'])) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id']) && !isset($_SESSION['user'])) {
            sendJsonResponse(['success' => false, 'message' => 'Login required to place order'], 401);
        }
    }
    
    $userId = $_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? null);
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $requiredFields = ['customer_name', 'customer_email', 'customer_phone', 'customer_address', 'payment_method'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            sendJsonResponse(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'], 400);
        }
    }
    
    // Get cart items from request body
    $cartItems = [];
    if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
        $cartItems = $data['items'];
        error_log("Using " . count($cartItems) . " items from request body");
    } else if ($userId) {
        // Fallback to database cart
        $stmt = $db->prepare("
            SELECT c.*, p.name, p.price, p.carbon_saved, p.stock 
            FROM cart c 
            JOIN products p ON c.product_id = p.id 
            WHERE c.user_id = ?
        ");
        $stmt->execute([$userId]);
        $dbCartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($dbCartItems)) {
            foreach ($dbCartItems as $item) {
                $cartItems[] = [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'name' => $item['name'],
                    'carbon_saved' => $item['carbon_saved']
                ];
            }
        }
    }
    
    if (empty($cartItems)) {
        sendJsonResponse(['success' => false, 'message' => 'Cart is empty'], 400);
    }
    
    // Calculate totals
    $total = 0;
    $totalCarbon = 0;
    
    foreach ($cartItems as &$item) {
        if (!isset($item['price']) || $item['price'] <= 0) {
            $stmt = $db->prepare("SELECT price, carbon_saved, stock FROM products WHERE id = ?");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $item['price'] = $product['price'];
                if (!isset($item['carbon_saved'])) {
                    $item['carbon_saved'] = $product['carbon_saved'] ?? 0;
                }
                if ($product['stock'] < $item['quantity']) {
                    sendJsonResponse(['success' => false, 'message' => "Insufficient stock for product ID: {$item['product_id']}"], 400);
                }
            } else {
                sendJsonResponse(['success' => false, 'message' => "Product not found: {$item['product_id']}"], 400);
            }
        }
        $total += $item['quantity'] * $item['price'];
        $totalCarbon += $item['quantity'] * ($item['carbon_saved'] ?? 0);
    }
    
    if (isset($data['total_amount']) && abs($data['total_amount'] - $total) < 0.01) {
        $total = $data['total_amount'];
    }
    
    // Calculate tax and commission
    $taxAmount = $total * (TAX_RATE / 100);
    $adminCommission = $total * (ADMIN_COMMISSION_RATE / 100);
    $sellerAmount = $total - $adminCommission;
    $paymentTransactionId = $data['payment_transaction_id'] ?? null;
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Create order with all columns (tax_amount, admin_commission, seller_amount)
        $orderNumber = generateOrderNumber();
        $stmt = $db->prepare("
            INSERT INTO orders (order_number, user_id, customer_name, customer_email, customer_phone, customer_address, total_amount, tax_amount, admin_commission, seller_amount, total_carbon_saved, payment_method, payment_transaction_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $orderNumber,
            $userId,
            sanitizeInput($data['customer_name']),
            sanitizeInput($data['customer_email']),
            sanitizeInput($data['customer_phone']),
            sanitizeInput($data['customer_address']),
            $total,
            $taxAmount,
            $adminCommission,
            $sellerAmount,
            $totalCarbon,
            sanitizeInput($data['payment_method']),
            $paymentTransactionId,
            'pending'
        ]);
        
        $orderId = $db->lastInsertId();
        
        // Add order items and update stock
        $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, price, carbon_saved) VALUES (?, ?, ?, ?, ?, ?)");
        $stockStmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        
        foreach ($cartItems as $item) {
            $productName = $item['name'] ?? '';
            if (empty($productName)) {
                $stmt2 = $db->prepare("SELECT name FROM products WHERE id = ?");
                $stmt2->execute([$item['product_id']]);
                $product = $stmt2->fetch(PDO::FETCH_ASSOC);
                $productName = $product['name'] ?? 'Product';
            }
            
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $productName,
                $item['quantity'],
                $item['price'],
                $item['carbon_saved'] ?? 0
            ]);
            
            $stockStmt->execute([$item['quantity'], $item['product_id']]);
        }
        
        // Clear cart from database if user is logged in
        if ($userId) {
            $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        
        // Update payment transaction with order_id if exists
        if ($paymentTransactionId) {
            $stmt = $db->prepare("UPDATE payment_transactions SET order_id = ? WHERE transaction_id = ?");
            $stmt->execute([$orderId, $paymentTransactionId]);
        }
        
        // Commit transaction
        $db->commit();
        
        // Log activity
        logActivity('order_created', ['order_number' => $orderNumber, 'total' => $total]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Order placed successfully',
            'order_number' => $orderNumber,
            'order_id' => $orderId
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Order creation failed: ' . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Failed to create order: ' . $e->getMessage()], 500);
    }
}

/**
 * PUT - Update order status (Admin only)
 */
if ($method === 'PUT') {
    requireAdmin();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['order_id']) || !isset($data['status'])) {
        sendJsonResponse(['success' => false, 'message' => 'Order ID and status required'], 400);
    }
    
    $allowedStatuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
    if (!in_array($data['status'], $allowedStatuses)) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid status'], 400);
    }
    
    $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $result = $stmt->execute([$data['status'], $data['order_id']]);
    
    if ($result) {
        logActivity('order_status_updated', ['order_id' => $data['order_id'], 'status' => $data['status']]);
        sendJsonResponse(['success' => true, 'message' => 'Order status updated']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to update order'], 500);
    }
}
?>