<?php
//works2/backend/includes/session.php

/**
 * Session Management
 * Handles user sessions, security, and authentication
 */

// Session configuration for production
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 for HTTPS
ini_set('session.cookie_samesite', 'Lax');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

/**
 * Check if user is admin
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Require user to be logged in
 * @return void
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Please login to continue',
            'redirect' => '/login.html'
        ]);
        exit();
    }
}

/**
 * Require admin access
 * @return void
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Admin access required'
        ]);
        exit();
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user data
 * @return array|null
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role']
    ];
}

/**
 * Set user session
 * @param array $user User data
 * @return void
 */
function setUserSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Destroy user session
 * @return void
 */
function destroySession() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Regenerate session ID for security
 * @return void
 */
function regenerateSession() {
    session_regenerate_id(true);
}

/**
 * Validate session integrity
 * @return bool
 */
function validateSession() {
    if (!isset($_SESSION['ip_address']) || !isset($_SESSION['user_agent'])) {
        return false;
    }
    
    if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        return false;
    }
    
    if ($_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        return false;
    }
    
    // Session timeout after 24 hours
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 86400)) {
        destroySession();
        return false;
    }
    
    return true;
}

/**
 * Log user activity
 * @param string $action Action performed
 * @param array $details Additional details
 * @return void
 */
function logActivity($action, $details = []) {
    if (!isLoggedIn()) return;
    
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Activity logging failed: " . $e->getMessage());
    }
}
?>