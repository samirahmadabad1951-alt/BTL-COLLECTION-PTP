<?php
//works2/backend/api/auth.php

/**
 * Authentication API
 * Handles login, registration, logout, profile, and password changes
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Database connection - FIXED to use correct database
$host = 'localhost';
$dbname = 'ecostore';  // Changed to match your database
$username = 'root';
$password = 'root';
$port = 8889;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // Try without port for XAMPP
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e2) {
        // Try fallback database name
        try {
            $dbname = 'ecostore';
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e3) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e3->getMessage()]);
            exit();
        }
    }
}

$action = $_GET['action'] ?? '';

// Check authentication status
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'check') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'loggedIn' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'] ?? '',
                'email' => $_SESSION['user_email'] ?? '',
                'role' => $_SESSION['user_role'] ?? 'user'
            ]
        ]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
    exit();
}

// Get current user (ME)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'me') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT id, name, email, phone, address, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit();
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Email and password required']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone' => $user['phone'] ?? '',
                'address' => $user['address'] ?? '',
                'created_at' => $user['created_at'] ?? date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    }
    exit();
}

// Register
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = $data['name'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $phone = $data['phone'] ?? '';
    $address = $data['address'] ?? '';
    
    if (empty($name) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Name, email and password required']);
        exit();
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
        exit();
    }
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit();
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, address, role) VALUES (?, ?, ?, ?, ?, 'user')");
    
    if ($stmt->execute([$name, $email, $hashedPassword, $phone, $address])) {
        $userId = $pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = 'user';
        
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => 'user'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
    }
    exit();
}

// Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'Logged out']);
    exit();
}

// Update Profile
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && $action === 'profile') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = $data['name'] ?? $_SESSION['user_name'];
    $phone = $data['phone'] ?? '';
    $address = $data['address'] ?? '';
    
    $stmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?");
    $result = $stmt->execute([$name, $phone, $address, $_SESSION['user_id']]);
    
    if ($result) {
        $_SESSION['user_name'] = $name;
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
    exit();
}

// Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change-password') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword)) {
        echo json_encode(['success' => false, 'message' => 'Current password and new password required']);
        exit();
    }
    
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters']);
        exit();
    }
    
    // Verify current password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($currentPassword, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit();
    }
    
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $result = $stmt->execute([$hashedPassword, $_SESSION['user_id']]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to change password']);
    }
    exit();
}

// If no action matched
echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>