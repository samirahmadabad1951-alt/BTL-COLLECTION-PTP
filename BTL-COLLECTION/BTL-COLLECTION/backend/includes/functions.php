<?php
//works2/backend/includes/functions.php

/**
 * Helper Functions
 * Utility functions for the entire application
 */

/**
 * Sanitize input data
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate unique order number
 * @return string Order number
 */
function generateOrderNumber() {
    return 'ECO-' . date('Ymd') . '-' . strtoupper(uniqid()) . '-' . rand(1000, 9999);
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Upload image file
 * @param array $file File from $_FILES
 * @param string $targetDir Target directory
 * @return array Result with success status and file path or error
 */
function uploadImage($file, $targetDir = '../uploads/products/') {
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File is too large. Max 5MB'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP, SVG'];
    }
    
    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $targetFile = $targetDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return ['success' => true, 'path' => '/uploads/products/' . $filename];
    }
    
    return ['success' => false, 'message' => 'Failed to upload file'];
}

/**
 * Send JSON response
 * @param mixed $data Data to send
 * @param int $statusCode HTTP status code
 * @return void
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Format price
 * @param float $price Price value
 * @param string $currency Currency symbol
 * @return string Formatted price
 */
function formatPrice($price, $currency = '$') {
    return $currency . number_format($price, 2);
}

/**
 * Calculate discount price
 * @param float $price Original price
 * @param float $discountPercent Discount percentage
 * @return float Discounted price
 */
function calculateDiscount($price, $discountPercent) {
    return $price - ($price * $discountPercent / 100);
}

/**
 * Get pagination data
 * @param int $page Current page
 * @param int $perPage Items per page
 * @param int $total Total items
 * @return array Pagination data
 */
function getPagination($page, $perPage, $total) {
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    
    return [
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $page > 1,
        'has_next' => $page < $totalPages,
        'prev_page' => $page - 1,
        'next_page' => $page + 1
    ];
}

/**
 * Get product categories
 * @return array List of categories
 */
function getCategories() {
    return ['All', 'Personal Care', 'Drinkware', 'Bags', 'Kitchen', 'Home', 'Garden', 'Electronics', 'Fashion'];
}

/**
 * Get eco labels
 * @return array List of labels
 */
function getEcoLabels() {
    return ['Biodegradable', 'Organic', 'Recycled', 'Reusable', 'Solar', 'Vegan', 'Plastic-Free', 'Zero Waste'];
}

/**
 * Log error to file
 * @param string $message Error message
 * @param string $context Additional context
 * @return void
 */
function logError($message, $context = '') {
    $logDir = __DIR__ . '/../logs/';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $logFile = $logDir . 'error_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . ($context ? " | Context: {$context}" : "") . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Calculate carbon savings for an order
 * @param array $items Order items
 * @return float Total carbon saved
 */
function calculateCarbonSavings($items) {
    $total = 0;
    foreach ($items as $item) {
        $total += ($item['quantity'] ?? 1) * ($item['carbon_saved'] ?? 0);
    }
    return $total;
}
?>