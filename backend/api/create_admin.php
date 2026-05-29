<?php
// works2/backend/api/create_admin.php - Run this once to create admin user
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Admin Account Creator</h1>";

// Direct database connection (no external files)
$host = 'localhost';
$dbname = 'ecostore';
$username = 'root';
$password = 'root';
$port = 8889;

try {
    // Try with port for MAMP
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color:green'>✓ Database connected successfully (with port)!</p>";
} catch(PDOException $e) {
    // Try without port for XAMPP
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<p style='color:green'>✓ Database connected successfully (without port)!</p>";
    } catch(PDOException $e2) {
        // Try fallback database name
        try {
            $dbname = 'ecostore';
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "<p style='color:green'>✓ Database connected to ecostore!</p>";
        } catch(PDOException $e3) {
            die("<p style='color:red'>✗ Database connection failed: " . $e3->getMessage() . "</p>");
        }
    }
}

// Admin details
$name = "Samir Ahmadabad";
$email = "samirahmadabad1950@gmail.com";
$plainPassword = "Theflash@1950";
$hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

// First, check if users table exists
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() == 0) {
        echo "<p style='color:orange'>⚠ Users table not found. Creating database tables first...</p>";
        
        // Create users table
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            phone VARCHAR(20),
            address TEXT,
            role ENUM('user', 'admin', 'seller') DEFAULT 'user',
            email_verified BOOLEAN DEFAULT FALSE,
            verification_token VARCHAR(255),
            reset_token VARCHAR(255),
            reset_expires DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_role (role)
        )";
        $pdo->exec($sql);
        echo "<p style='color:green'>✓ Users table created!</p>";
    }
} catch(PDOException $e) {
    echo "<p style='color:orange'>Note: " . $e->getMessage() . "</p>";
}

// Check if user exists
$stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo "<p>Found existing user with ID: " . $existing['id'] . " and role: " . $existing['role'] . "</p>";
    
    // Update existing user to admin
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin', password = ?, name = ? WHERE email = ?");
    $result = $stmt->execute([$hashedPassword, $name, $email]);
    
    if ($result) {
        echo "<p style='color:green'>✓ User UPDATED to ADMIN successfully!</p>";
        echo "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
        echo "<p><strong>Password:</strong> " . htmlspecialchars($plainPassword) . "</p>";
        echo "<p><strong>Role:</strong> admin</p>";
    } else {
        echo "<p style='color:red'>✗ Failed to update user.</p>";
        print_r($stmt->errorInfo());
    }
} else {
    // Create new admin user
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, email_verified, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())");
    $result = $stmt->execute([$name, $email, $hashedPassword]);
    
    if ($result) {
        $userId = $pdo->lastInsertId();
        echo "<p style='color:green'>✓ Admin user CREATED successfully!</p>";
        echo "<p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>";
        echo "<p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>";
        echo "<p><strong>Password:</strong> " . htmlspecialchars($plainPassword) . "</p>";
        echo "<p><strong>User ID:</strong> " . $userId . "</p>";
        echo "<p><strong>Role:</strong> admin</p>";
    } else {
        echo "<p style='color:red'>✗ Failed to create admin user.</p>";
        print_r($stmt->errorInfo());
    }
}

// Verify the admin user
echo "<hr>";
echo "<h3>Verification:</h3>";
$stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ?");
$stmt->execute([$email]);
$verify = $stmt->fetch(PDO::FETCH_ASSOC);

if ($verify) {
    echo "<p style='color:green'>✓ Admin user verified in database!</p>";
    echo "<pre>";
    print_r($verify);
    echo "</pre>";
} else {
    echo "<p style='color:red'>✗ Admin user NOT found in database!</p>";
}

echo "<hr>";
echo "<a href='../frontend/login.html' style='display:inline-block;padding:10px 20px;background:#22c55e;color:white;text-decoration:none;border-radius:8px;'>Go to Login Page</a>";
echo "&nbsp;&nbsp;&nbsp;";
echo "<a href='../frontend/admin.html' style='display:inline-block;padding:10px 20px;background:#3b82f6;color:white;text-decoration:none;border-radius:8px;'>Go to Admin Panel</a>";
?>