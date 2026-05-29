<?php
// /EcoStore/backend/api/wishlist.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(200); exit(); }

if ($method === 'GET') {
    requireLogin();
    $stmt = $db->prepare("SELECT w.*, p.name, p.slug, p.price, p.image, p.eco_score FROM wishlist w JOIN products p ON w.product_id = p.id WHERE w.user_id = ? AND p.status = 'active' ORDER BY w.created_at DESC");
    $stmt->execute([$_SESSION['user_id']]);
    sendJsonResponse(['success' => true, 'wishlist' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    requireLogin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['product_id'])) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    $stmt = $db->prepare("INSERT INTO wishlist (user_id, product_id) VALUES (?, ?)");
    $result = $stmt->execute([$_SESSION['user_id'], $data['product_id']]);
    sendJsonResponse($result ? ['success' => true, 'message' => 'Added to wishlist'] : ['success' => false, 'message' => 'Failed'], $result ? 200 : 500);
}

if ($method === 'DELETE') {
    requireLogin();
    $productId = $_GET['product_id'] ?? null;
    if (!$productId) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    $stmt = $db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
    $result = $stmt->execute([$_SESSION['user_id'], $productId]);
    sendJsonResponse($result ? ['success' => true, 'message' => 'Removed from wishlist'] : ['success' => false, 'message' => 'Failed'], $result ? 200 : 500);
}
?>