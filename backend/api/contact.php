<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Database connection
$host = 'localhost';
$dbname = 'ecostore';
$username = 'root';
$password = 'root';
$pdo = null;

foreach ([8889, 3306] as $port) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        break;
    } catch(PDOException $e) { $pdo = null; }
}

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Your table already exists, no need to create it again

function sendEmailNotification($to, $subject, $message, $fromName, $fromEmail) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $fromName <$fromEmail>\r\n";
    
    $htmlContent = "<html><body>
        <h2>New Contact Message on EcoStore</h2>
        <p><strong>From:</strong> $fromName ($fromEmail)</p>
        <p><strong>Subject:</strong> $subject</p>
        <p><strong>Message:</strong></p>
        <p>" . nl2br(htmlspecialchars($message)) . "</p>
        <hr>
        <p>Reply to: $fromEmail</p>
    </body></html>";
    
    return mail($to, $subject, $htmlContent, $headers);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET messages (admin only)
if ($method === 'GET' && $action === 'get_messages') {
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $pdo->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
    $messages = $stmt->fetchAll();
    echo json_encode(['success' => true, 'messages' => $messages]);
    exit();
}

// POST - Send contact message
if ($method === 'POST' && empty($action)) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = $data['name'] ?? '';
    $email = $data['email'] ?? '';
    $subject = $data['subject'] ?? 'General Inquiry';
    $message = $data['message'] ?? '';
    
    if (empty($name) || empty($email) || empty($message)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
    // Save to database - using your exact table structure
    $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, subject, message, status) VALUES (?, ?, ?, ?, 'unread')");
    $result = $stmt->execute([$name, $email, $subject, $message]);
    
    if ($result) {
        // Send email notification to admin
        $adminEmail = 'samirahmadabad1950@gmail.com'; // Admin email from your DB
        $emailSent = sendEmailNotification($adminEmail, "New Contact: $subject", $message, $name, $email);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Your message has been sent successfully!',
            'email_sent' => $emailSent
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save message']);
    }
    exit();
}

// POST - Mark message as read (admin only)
if ($method === 'POST' && $action === 'mark_read') {
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $messageId = $data['message_id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
    $stmt->execute([$messageId]);
    
    echo json_encode(['success' => true, 'message' => 'Message marked as read']);
    exit();
}

// DELETE - Delete message (admin only)
if ($method === 'DELETE' && $action === 'delete') {
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $messageId = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    
    echo json_encode(['success' => true, 'message' => 'Message deleted']);
    exit();
}

// DELETE - Clear all messages (admin only)
if ($method === 'DELETE' && $action === 'clear_all') {
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $pdo->exec("DELETE FROM contact_messages");
    
    echo json_encode(['success' => true, 'message' => 'All messages cleared']);
    exit();
}

// POST - Newsletter subscription
if ($method === 'POST' && $action === 'newsletter') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO newsletter_subscribers (email, is_active) VALUES (?, 1)");
        $stmt->execute([$email]);
        echo json_encode(['success' => true, 'message' => 'Successfully subscribed to newsletter!']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            echo json_encode(['success' => false, 'message' => 'Email already subscribed']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Subscription failed']);
        }
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>