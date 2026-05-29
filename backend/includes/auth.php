<?php
//works2/backend/includes/auth.php

/**
 * Authentication API
 * Handles user registration, login, logout, and profile management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

require_once '../config/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * User Registration
 */
if ($method === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $requiredFields = ['name', 'email', 'password'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            sendJsonResponse(['success' => false, 'message' => ucfirst($field) . ' is required'], 400);
        }
    }
    
    // Validate email format
    if (!validateEmail($data['email'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid email format'], 400);
    }
    
    // Validate password strength
    if (strlen($data['password']) < 6) {
        sendJsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
    }
    
    // Check if email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    if ($stmt->fetch()) {
        sendJsonResponse(['success' => false, 'message' => 'Email already registered'], 409);
    }
    
    // Hash password
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
    
    // Generate verification token
    $verificationToken = bin2hex(random_bytes(32));
    
    // Insert user
    $stmt = $db->prepare("INSERT INTO users (name, email, password, phone, address, verification_token) VALUES (?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([
        sanitizeInput($data['name']),
        $data['email'],
        $hashedPassword,
        $data['phone'] ?? '',
        $data['address'] ?? '',
        $verificationToken
    ]);
    
    if ($result) {
        $userId = $db->lastInsertId();
        
        // Set session
        setUserSession([
            'id' => $userId,
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'user'
        ]);
        
        // Log activity
        logActivity('user_registered', ['email' => $data['email']]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $userId,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => 'user'
            ]
        ]);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Registration failed'], 500);
    }
}

/**
 * User Login
 */
if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email']) || empty($data['password'])) {
        sendJsonResponse(['success' => false, 'message' => 'Email and password required'], 400);
    }
    
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($data['password'], $user['password'])) {
        // Update last login
        $stmt = $db->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Set session
        setUserSession([
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);
        
        // Regenerate session ID for security
        regenerateSession();
        
        // Log activity
        logActivity('user_login', ['email' => $data['email']]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone' => $user['phone'],
                'address' => $user['address']
            ]
        ]);
    } else {
        logActivity('failed_login', ['email' => $data['email'], 'ip' => $_SERVER['REMOTE_ADDR']]);
        sendJsonResponse(['success' => false, 'message' => 'Invalid email or password'], 401);
    }
}

/**
 * User Logout
 */
if ($method === 'POST' && $action === 'logout') {
    logActivity('user_logout', ['user_id' => getCurrentUserId()]);
    destroySession();
    sendJsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}

/**
 * Get Current User
 */
if ($method === 'GET' && $action === 'me') {
    if (!isLoggedIn()) {
        sendJsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
    }
    
    $stmt = $db->prepare("SELECT id, name, email, phone, address, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    sendJsonResponse(['success' => true, 'user' => $user]);
}

/**
 * Update User Profile
 */
if ($method === 'PUT' && $action === 'profile') {
    requireLogin();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $updates = [];
    $params = [];
    
    if (isset($data['name'])) {
        $updates[] = "name = ?";
        $params[] = sanitizeInput($data['name']);
        $_SESSION['user_name'] = $data['name'];
    }
    
    if (isset($data['phone'])) {
        $updates[] = "phone = ?";
        $params[] = sanitizeInput($data['phone']);
    }
    
    if (isset($data['address'])) {
        $updates[] = "address = ?";
        $params[] = sanitizeInput($data['address']);
    }
    
    if (empty($updates)) {
        sendJsonResponse(['success' => false, 'message' => 'No fields to update'], 400);
    }
    
    $params[] = $_SESSION['user_id'];
    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $result = $stmt->execute($params);
    
    if ($result) {
        logActivity('profile_updated', ['user_id' => $_SESSION['user_id']]);
        sendJsonResponse(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to update profile'], 500);
    }
}

/**
 * Change Password
 */
if ($method === 'POST' && $action === 'change-password') {
    requireLogin();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['current_password']) || empty($data['new_password'])) {
        sendJsonResponse(['success' => false, 'message' => 'Current password and new password required'], 400);
    }
    
    if (strlen($data['new_password']) < 6) {
        sendJsonResponse(['success' => false, 'message' => 'New password must be at least 6 characters'], 400);
    }
    
    // Verify current password
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($data['current_password'], $user['password'])) {
        sendJsonResponse(['success' => false, 'message' => 'Current password is incorrect'], 401);
    }
    
    // Update password
    $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    $result = $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
    
    if ($result) {
        logActivity('password_changed', ['user_id' => $_SESSION['user_id']]);
        sendJsonResponse(['success' => true, 'message' => 'Password changed successfully']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to change password'], 500);
    }
}

/**
 * Request Password Reset
 */
if ($method === 'POST' && $action === 'forgot-password') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        sendJsonResponse(['success' => false, 'message' => 'Email required'], 400);
    }
    
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch();
    
    if ($user) {
        $resetToken = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $stmt->execute([$resetToken, $expires, $user['id']]);
        
        sendJsonResponse(['success' => true, 'message' => 'Password reset instructions sent to your email']);
    } else {
        // Don't reveal if email exists for security
        sendJsonResponse(['success' => true, 'message' => 'If the email exists, reset instructions will be sent']);
    }
}

/**
 * Reset Password
 */
if ($method === 'POST' && $action === 'reset-password') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['token']) || empty($data['new_password'])) {
        sendJsonResponse(['success' => false, 'message' => 'Token and new password required'], 400);
    }
    
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$data['token']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid or expired reset token'], 400);
    }
    
    $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    $result = $stmt->execute([$hashedPassword, $user['id']]);
    
    if ($result) {
        sendJsonResponse(['success' => true, 'message' => 'Password reset successfully']);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Failed to reset password'], 500);
    }
}
?>