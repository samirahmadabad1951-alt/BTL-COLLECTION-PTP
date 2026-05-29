<?php
// /EcoStore/backend/api/cart.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit(); }

// GET cart
if ($method === 'GET') {
    requireLogin();
    $stmt = $db->prepare("SELECT c.*, p.name, p.price, p.image, p.eco_score, p.carbon_saved, p.stock FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ? AND p.status = 'active'");
    $stmt->execute([$_SESSION['user_id']]);
    $items = $stmt->fetchAll();
    sendJsonResponse(['success' => true, 'cart' => $items]);
}

// POST add to cart
if ($method === 'POST') {
    requireLogin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['product_id'])) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    
    $stmt = $db->prepare("SELECT stock, price FROM products WHERE id = ? AND status = 'active'");
    $stmt->execute([$data['product_id']]);
    $product = $stmt->fetch();
    if (!$product) sendJsonResponse(['success' => false, 'message' => 'Product not found'], 404);
    
    $qty = isset($data['quantity']) ? (int)$data['quantity'] : 1;
    if ($product['stock'] < $qty) sendJsonResponse(['success' => false, 'message' => 'Insufficient stock'], 400);
    
    $stmt = $db->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$_SESSION['user_id'], $data['product_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $newQty = $existing['quantity'] + $qty;
        if ($product['stock'] < $newQty) sendJsonResponse(['success' => false, 'message' => 'Insufficient stock'], 400);
        $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
        $result = $stmt->execute([$newQty, $existing['id']]);
    } else {
        $stmt = $db->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
        $result = $stmt->execute([$_SESSION['user_id'], $data['product_id'], $qty]);
    }
    sendJsonResponse($result ? ['success' => true, 'message' => 'Added to cart'] : ['success' => false, 'message' => 'Failed'], $result ? 200 : 500);
}

// PUT update quantity
if ($method === 'PUT') {
    requireLogin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['product_id']) || !isset($data['quantity'])) sendJsonResponse(['success' => false, 'message' => 'Product ID and quantity required'], 400);
    
    $qty = (int)$data['quantity'];
    if ($qty <= 0) {
        $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $result = $stmt->execute([$_SESSION['user_id'], $data['product_id']]);
    } else {
        $stmt = $db->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->execute([$data['product_id']]);
        $product = $stmt->fetch();
        if ($product && $product['stock'] < $qty) sendJsonResponse(['success' => false, 'message' => 'Insufficient stock'], 400);
        $stmt = $db->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $result = $stmt->execute([$qty, $_SESSION['user_id'], $data['product_id']]);
    }
    sendJsonResponse($result ? ['success' => true, 'message' => 'Cart updated'] : ['success' => false, 'message' => 'Failed'], $result ? 200 : 500);
}

// DELETE remove item
if ($method === 'DELETE') {
    requireLogin();
    $productId = $_GET['product_id'] ?? null;
    if (!$productId) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    $stmt = $db->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
    $result = $stmt->execute([$_SESSION['user_id'], $productId]);
    sendJsonResponse($result ? ['success' => true, 'message' => 'Removed from cart'] : ['success' => false, 'message' => 'Failed'], $result ? 200 : 500);
}
?>