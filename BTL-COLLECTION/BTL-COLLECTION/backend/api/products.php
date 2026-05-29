<?php
// work2/backend/api/products.php - FULLY FIXED: Multipart upload, seller security, price markup, video support

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (session_status() === PHP_SESSION_NONE) session_start();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// ============================================================
// DATABASE CONNECTION (tries MAMP port 8889 then standard 3306)
// ============================================================
$host = 'localhost'; $dbname = 'ecostore'; $username = 'root'; $password = 'root'; $pdo = null;
foreach ([8889, 3306] as $port) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        break;
    } catch(PDOException $e) { $pdo = null; }
}
if (!$pdo) sendJsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);

// ============================================================
// UPLOAD DIRECTORIES
// ============================================================
define('UPLOAD_BASE', dirname(__DIR__) . '/uploads/');
define('IMAGE_UPLOAD_DIR', UPLOAD_BASE . 'products/images/');
define('VIDEO_UPLOAD_DIR', UPLOAD_BASE . 'products/videos/');

foreach ([IMAGE_UPLOAD_DIR, VIDEO_UPLOAD_DIR] as $dir) {
    if (!file_exists($dir)) mkdir($dir, 0777, true);
}

// Web-accessible path prefix (relative to server root)
define('UPLOAD_WEB_PREFIX', '/work2/backend/uploads/products/');

// ============================================================
// MEDIA UPLOAD HELPER
// ============================================================
function uploadMediaFile($file, $type) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error code: ' . $file['error']];
    }
    $maxSize = ($type === 'image') ? 5 * 1024 * 1024 : 100 * 1024 * 1024; // 5MB images, 100MB videos
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => ($type === 'image' ? '5MB' : '100MB') . ' max size exceeded for ' . $file['name']];
    }
    $allowedImageTypes = ['image/jpeg','image/jpg','image/png','image/gif','image/webp'];
    $allowedVideoTypes = ['video/mp4','video/webm','video/ogg','video/quicktime','video/x-msvideo'];
    $allowed = ($type === 'image') ? $allowedImageTypes : $allowedVideoTypes;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed)) {
        return ['success' => false, 'message' => 'Invalid file type: ' . $mimeType . ' for ' . $file['name']];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Force safe extensions
    $safeExtensions = ['image' => ['jpg','jpeg','png','gif','webp'], 'video' => ['mp4','webm','ogg','mov','avi']];
    if (!in_array($extension, $safeExtensions[$type])) {
        $extension = ($type === 'image') ? 'jpg' : 'mp4';
    }

    $filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $subdir   = ($type === 'image') ? 'images/' : 'videos/';
    $diskPath = UPLOAD_BASE . 'products/' . $subdir . $filename;
    $webPath  = UPLOAD_WEB_PREFIX . $subdir . $filename;

    if (move_uploaded_file($file['tmp_name'], $diskPath)) {
        return ['success' => true, 'path' => $webPath, 'mime' => $mimeType];
    }
    return ['success' => false, 'message' => 'Failed to save file: ' . $file['name']];
}

// Fix legacy or malformed paths
function fixMediaPath($path) {
    if (empty($path)) return '';
    // External URL — return as-is
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    // Fix /works2/ typo
    $path = str_replace('/works2/', '/work2/', $path);
    // Ensure /uploads/ paths get the backend prefix
    if (strpos($path, '/uploads/') === 0) return '/work2/backend' . $path;
    return $path;
}

// Extract all images and videos from a product row + product_media table
function getProductMedia($pdo, $productId) {
    $stmt = $pdo->prepare("SELECT id, media_type, file_path, sort_order FROM product_media WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$productId]);
    $media = $stmt->fetchAll();
    $images = []; $videos = [];
    foreach ($media as $m) {
        $fp = fixMediaPath($m['file_path']);
        if ($m['media_type'] === 'image') {
            $images[] = ['id' => $m['id'], 'path' => $fp, 'sort_order' => $m['sort_order']];
        } else {
            $videos[] = ['id' => $m['id'], 'path' => $fp, 'sort_order' => $m['sort_order'], 'is_url' => filter_var($fp, FILTER_VALIDATE_URL)];
        }
    }
    return ['images' => $images, 'videos' => $videos];
}

function enrichProduct($pdo, &$product) {
    $media = getProductMedia($pdo, $product['id']);
    // Return flat arrays of paths for easy use in frontend
    $product['images']       = array_column($media['images'], 'path');
    $product['videos']       = array_column($media['videos'], 'path');
    $product['media_images'] = $media['images']; // includes IDs for admin deletion
    $product['media_videos'] = $media['videos'];
    $product['images_count'] = count($media['images']);
    $product['videos_count'] = count($media['videos']);
    $product['image']        = !empty($product['images']) ? $product['images'][0] : '';
    $product['labels']       = json_decode($product['labels']   ?? '', true) ?: [];
    $product['materials']    = json_decode($product['materials'] ?? '', true) ?: [];
    // Ensure numeric types
    $product['price']        = (float)$product['price'];
    $product['seller_price'] = (float)($product['seller_price'] ?? $product['price']);
    $product['admin_markup'] = (float)($product['admin_markup'] ?? 0);
    $product['eco_score']    = (int)($product['eco_score'] ?? 8);
    $product['stock']        = (int)($product['stock'] ?? 0);
    $product['rating']       = (float)($product['rating'] ?? 0);
    $product['reviews_count']= (int)($product['reviews_count'] ?? 0);
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET — Fetch products
// ============================================================
if ($method === 'GET') {
    try {
        $role   = $_SESSION['user_role'] ?? 'user';
        $userId = $_SESSION['user_id']   ?? 0;

        // Single product by ID
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([(int)$_GET['id']]);
            $product = $stmt->fetch();
            if ($product) {
                // Non-admin/non-owner cannot see inactive/pending products
                if ($product['status'] !== 'active') {
                    if ($role !== 'admin' && ($role !== 'seller' || $product['seller_id'] != $userId)) {
                        sendJsonResponse(['success' => false, 'message' => 'Product not available'], 404);
                    }
                }
                enrichProduct($pdo, $product);
                sendJsonResponse(['success' => true, 'product' => $product]);
            }
            sendJsonResponse(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Build query based on role
        $query  = "SELECT * FROM products WHERE 1=1";
        $params = [];

        if ($role === 'admin') {
            // Admin sees all products (including pending & inactive)
            if (!empty($_GET['status'])) {
                $query .= " AND status = ?"; $params[] = $_GET['status'];
            }
        } elseif ($role === 'seller') {
            // Seller sees all active products + ALL of their own products (pending, inactive, active)
            $query .= " AND (status = 'active' OR seller_id = ?)";
            $params[] = $userId;
        } else {
            // Customers / guests see only active products
            $query .= " AND status = 'active'";
        }

        if (!empty($_GET['category']) && $_GET['category'] !== 'All') {
            $query .= " AND category = ?"; $params[] = $_GET['category'];
        }
        if (!empty($_GET['search'])) {
            $s = '%' . $_GET['search'] . '%';
            $query .= " AND (name LIKE ? OR tagline LIKE ? OR description LIKE ?)";
            $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $query .= " ORDER BY created_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        foreach ($products as &$product) {
            enrichProduct($pdo, $product);
        }
        unset($product);

        sendJsonResponse(['success' => true, 'products' => $products]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
    }
}

// ============================================================
// POST — Add new product (multipart form data with real file uploads)
// ============================================================
if ($method === 'POST') {
    try {
        $role   = $_SESSION['user_role'] ?? 'user';
        $userId = $_SESSION['user_id']   ?? null;

        if (!in_array($role, ['admin', 'seller'])) {
            sendJsonResponse(['success' => false, 'message' => 'Only approved sellers and admins can add products'], 403);
        }

        $uploadedImages = [];
        $uploadedVideos = [];
        $errors = [];

        // ---- Multipart (FormData) request ----
        if (!empty($_FILES)) {
            $data = $_POST;

            // --- Images: accept images[], images[0], images[1]... or single 'image' ---
            $imageFiles = [];
            if (isset($_FILES['images'])) {
                $f = $_FILES['images'];
                if (is_array($f['name'])) {
                    for ($i = 0; $i < count($f['name']); $i++) {
                        if ($f['error'][$i] === UPLOAD_ERR_OK) {
                            $imageFiles[] = [
                                'name'     => $f['name'][$i],
                                'type'     => $f['type'][$i],
                                'tmp_name' => $f['tmp_name'][$i],
                                'error'    => $f['error'][$i],
                                'size'     => $f['size'][$i],
                            ];
                        }
                    }
                } else {
                    if ($f['error'] === UPLOAD_ERR_OK) $imageFiles[] = $f;
                }
            }
            // Also accept individual image[] keys
            foreach ($_FILES as $key => $f) {
                if (preg_match('/^images?\[?\d*\]?$/', $key) && $key !== 'images') {
                    if (!is_array($f['name']) && $f['error'] === UPLOAD_ERR_OK) $imageFiles[] = $f;
                }
            }

            foreach ($imageFiles as $imgFile) {
                $res = uploadMediaFile($imgFile, 'image');
                if ($res['success']) $uploadedImages[] = $res['path'];
                else $errors[] = $res['message'];
            }

            // --- Videos: accept videos[], video_file, single video ---
            $videoFiles = [];
            foreach (['videos', 'video_file', 'video'] as $vKey) {
                if (!isset($_FILES[$vKey])) continue;
                $f = $_FILES[$vKey];
                if (is_array($f['name'])) {
                    for ($i = 0; $i < count($f['name']); $i++) {
                        if ($f['error'][$i] === UPLOAD_ERR_OK) {
                            $videoFiles[] = [
                                'name'     => $f['name'][$i],
                                'type'     => $f['type'][$i],
                                'tmp_name' => $f['tmp_name'][$i],
                                'error'    => $f['error'][$i],
                                'size'     => $f['size'][$i],
                            ];
                        }
                    }
                } else {
                    if ($f['error'] === UPLOAD_ERR_OK) $videoFiles[] = $f;
                }
            }

            foreach ($videoFiles as $vidFile) {
                $res = uploadMediaFile($vidFile, 'video');
                if ($res['success']) $uploadedVideos[] = $res['path'];
                else $errors[] = $res['message'];
            }

            // Video URL (YouTube/Vimeo) as fallback
            if (!empty($data['video_url'])) $uploadedVideos[] = trim($data['video_url']);

            $name        = trim($data['name'] ?? '');
            $sellerPrice = (float)($data['price'] ?? 0);
            $category    = trim($data['category'] ?? '');
            $ecoScore    = (int)($data['eco_score'] ?? 8);

        } else {
            // ---- JSON request ----
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $uploadedImages = $data['images'] ?? [];
            $uploadedVideos = $data['videos'] ?? [];
            if (!empty($data['video_url'])) $uploadedVideos[] = $data['video_url'];

            $name        = trim($data['name'] ?? '');
            $sellerPrice = (float)($data['price'] ?? 0);
            $category    = trim($data['category'] ?? '');
            $ecoScore    = (int)($data['eco_score'] ?? 8);
        }

        if (empty($name) || $sellerPrice <= 0 || empty($category)) {
            sendJsonResponse(['success' => false, 'message' => 'Name, price, and category are required', 'errors' => $errors], 400);
        }
        // Images are optional — if none provided, product will show a placeholder in frontend

        // ---- Generate unique slug ----
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', $name), '-'));
        if (empty($slug)) $slug = 'product';
        $slugBase = $slug;
        $i = 1;
        while (true) {
            $st = $pdo->prepare("SELECT id FROM products WHERE slug = ?");
            $st->execute([$slug]);
            if (!$st->fetch()) break;
            $slug = $slugBase . '-' . $i++;
        }

        // ---- Pricing logic ----
        // Admin: price entered = final price, no markup needed
        // Seller: seller_price = their intended price; markup set to 0 until admin approves and sets it
        $adminMarkup = 0;
        $finalPrice  = $sellerPrice;
        // status: admin products go live immediately; seller products go pending
        $status      = ($role === 'admin') ? 'active' : 'pending';
        $sellerName  = $_SESSION['user_name'] ?? ($role === 'admin' ? 'EcoStore' : 'Seller');

        // ---- Insert product ----
        $stmt = $pdo->prepare("
            INSERT INTO products (
                seller_id, name, slug, tagline, description,
                seller_price, admin_markup, price, currency,
                category, eco_score, carbon_saved,
                labels, seller, materials, impact, stock,
                status, submitted_by_role, certified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $labels    = isset($data['labels'])    ? (is_array($data['labels'])    ? $data['labels']    : array_map('trim', explode(',', $data['labels'])))    : [];
        $materials = isset($data['materials']) ? (is_array($data['materials']) ? $data['materials'] : array_map('trim', explode(',', $data['materials']))) : [];

        $allowedCurrencies = ['TZS', 'USD', 'KES'];
        $currency = strtoupper(trim($data['currency'] ?? 'TZS'));
        if (!in_array($currency, $allowedCurrencies)) $currency = 'TZS';

        $stmt->execute([
            $userId,
            $name,
            $slug,
            $data['tagline']     ?? '',
            $data['description'] ?? '',
            $sellerPrice,
            $adminMarkup,
            $finalPrice,
            $currency,
            $category,
            $ecoScore,
            (float)($data['carbon_saved'] ?? 0),
            json_encode(array_values(array_filter($labels))),
            $sellerName,
            json_encode(array_values(array_filter($materials))),
            $data['impact'] ?? '',
            (int)($data['stock'] ?? 100),
            $status,
            $role,          // submitted_by_role
            1               // certified
        ]);

        $productId = (int)$pdo->lastInsertId();

        // ---- Insert media records ----
        $sortOrder = 0;
        foreach ($uploadedImages as $imgPath) {
            $pdo->prepare("INSERT INTO product_media (product_id, media_type, file_path, sort_order) VALUES (?, 'image', ?, ?)")
                ->execute([$productId, $imgPath, $sortOrder++]);
        }
        foreach ($uploadedVideos as $vidPath) {
            $pdo->prepare("INSERT INTO product_media (product_id, media_type, file_path, sort_order) VALUES (?, 'video', ?, ?)")
                ->execute([$productId, $vidPath, $sortOrder++]);
        }

        $msg = ($role === 'seller')
            ? 'Product submitted for admin review. It will appear in the shop once approved.'
            : 'Product added successfully and is now live.';

        sendJsonResponse([
            'success'      => true,
            'message'      => $msg,
            'id'           => $productId,
            'status'       => $status,
            'seller_price' => $sellerPrice,
            'final_price'  => $finalPrice,
            'images_count' => count($uploadedImages),
            'videos_count' => count($uploadedVideos),
            'errors'       => $errors, // upload warnings (non-fatal)
        ]);

    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
    }
}

// ============================================================
// PUT — Update product
// ============================================================
if ($method === 'PUT') {
    try {
        $role   = $_SESSION['user_role'] ?? 'user';
        $userId = $_SESSION['user_id']   ?? null;

        // Support multipart PUT for adding new media to existing product
        $isMultipart = !empty($_FILES);

        if ($isMultipart) {
            $data = $_POST;
        } else {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
        }

        if (empty($data['id'])) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
        $productId = (int)$data['id'];

        // Fetch existing product
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) sendJsonResponse(['success' => false, 'message' => 'Product not found'], 404);

        // Role-based permission check
        if ($role === 'seller') {
            if ($product['seller_id'] != $userId) {
                sendJsonResponse(['success' => false, 'message' => 'You can only edit your own products'], 403);
            }
            // Sellers can edit their own products at any status.
            // Editing an active product will push it back to pending for admin re-approval.
        } elseif ($role !== 'admin') {
            sendJsonResponse(['success' => false, 'message' => 'Not authorized'], 403);
        }

        $updates = []; $params = [];

        // Shared editable fields
        foreach (['name', 'tagline', 'description', 'category', 'impact'] as $field) {
            if (array_key_exists($field, $data)) { $updates[] = "$field = ?"; $params[] = $data[$field]; }
        }
        if (array_key_exists('currency', $data)) {
            $allowedCurrencies = ['TZS', 'USD', 'KES'];
            $curr = strtoupper(trim($data['currency']));
            if (!in_array($curr, $allowedCurrencies)) $curr = 'TZS';
            $updates[] = "currency = ?"; $params[] = $curr;
        }
        if (array_key_exists('eco_score', $data))    { $updates[] = "eco_score = ?";    $params[] = (int)$data['eco_score']; }
        if (array_key_exists('carbon_saved', $data)) { $updates[] = "carbon_saved = ?"; $params[] = (float)$data['carbon_saved']; }
        if (array_key_exists('stock', $data))        { $updates[] = "stock = ?";        $params[] = (int)$data['stock']; }
        if (array_key_exists('labels', $data)) {
            $labels = is_array($data['labels']) ? $data['labels'] : array_map('trim', explode(',', $data['labels']));
            $updates[] = "labels = ?"; $params[] = json_encode(array_values(array_filter($labels)));
        }
        if (array_key_exists('materials', $data)) {
            $materials = is_array($data['materials']) ? $data['materials'] : array_map('trim', explode(',', $data['materials']));
            $updates[] = "materials = ?"; $params[] = json_encode(array_values(array_filter($materials)));
        }

        if ($role === 'admin') {
            // Admin can change status, price, markup
            if (array_key_exists('status', $data)) { $updates[] = "status = ?"; $params[] = $data['status']; }
            if (array_key_exists('price', $data))  { $updates[] = "price = ?";  $params[] = (float)$data['price']; }

            if (array_key_exists('admin_markup', $data)) {
                $markup = max(0, (float)$data['admin_markup']);
                $updates[] = "admin_markup = ?"; $params[] = $markup;
                // Recalculate final price = seller_price + markup
                $sp = (float)($product['seller_price'] ?: $product['price']);
                $updates[] = "price = ?"; $params[] = round($sp + $markup, 2);
            }

            // Approve shorthand
            if (!empty($data['approve'])) {
                $updates[] = "status = 'active'";
                // If markup provided, set it
                if (array_key_exists('admin_markup', $data)) {
                    // Already handled above
                } elseif (!array_key_exists('price', $data)) {
                    // Keep price as-is
                }
            }
            if (!empty($data['reject'])) { $updates[] = "status = 'inactive'"; }

        } else {
            // Seller: can update their own product fields
            // If product was active and seller is editing it, push back to pending for re-approval
            $wasActive = ($product['status'] === 'active');
            
            if (array_key_exists('price', $data)) {
                $newSellerPrice = (float)$data['price'];
                $updates[] = "seller_price = ?"; $params[] = $newSellerPrice;
                $updates[] = "price = ?";        $params[] = $newSellerPrice; // temp until admin sets markup
            }
            
            if ($wasActive && !empty($updates)) {
                // Push active product back to pending when seller edits it
                $updates[] = "status = 'pending'";
                $updates[] = "admin_markup = 0";
            }
        }

        if (!empty($isMultipart)) {
            // Admin/seller can add more images/videos to existing product via PUT multipart
            $newImages = []; $newVideos = [];
            if (isset($_FILES['images'])) {
                $f = $_FILES['images'];
                if (is_array($f['name'])) {
                    for ($i = 0; $i < count($f['name']); $i++) {
                        if ($f['error'][$i] === UPLOAD_ERR_OK) {
                            $file = ['name'=>$f['name'][$i],'type'=>$f['type'][$i],'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i]];
                            $res = uploadMediaFile($file, 'image');
                            if ($res['success']) $newImages[] = $res['path'];
                        }
                    }
                } elseif ($f['error'] === UPLOAD_ERR_OK) {
                    $res = uploadMediaFile($f, 'image');
                    if ($res['success']) $newImages[] = $res['path'];
                }
            }
            foreach (['videos', 'video_file'] as $vKey) {
                if (!isset($_FILES[$vKey])) continue;
                $f = $_FILES[$vKey];
                if (is_array($f['name'])) {
                    for ($i = 0; $i < count($f['name']); $i++) {
                        if ($f['error'][$i] === UPLOAD_ERR_OK) {
                            $file = ['name'=>$f['name'][$i],'type'=>$f['type'][$i],'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i]];
                            $res = uploadMediaFile($file, 'video');
                            if ($res['success']) $newVideos[] = $res['path'];
                        }
                    }
                } elseif ($f['error'] === UPLOAD_ERR_OK) {
                    $res = uploadMediaFile($f, 'video');
                    if ($res['success']) $newVideos[] = $res['path'];
                }
            }
            // Get current max sort_order
            $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM product_media WHERE product_id = ?");
            $st->execute([$productId]);
            $sortOrder = (int)$st->fetchColumn() + 1;

            foreach ($newImages as $imgPath) {
                $pdo->prepare("INSERT INTO product_media (product_id, media_type, file_path, sort_order) VALUES (?, 'image', ?, ?)")
                    ->execute([$productId, $imgPath, $sortOrder++]);
            }
            foreach ($newVideos as $vidPath) {
                $pdo->prepare("INSERT INTO product_media (product_id, media_type, file_path, sort_order) VALUES (?, 'video', ?, ?)")
                    ->execute([$productId, $vidPath, $sortOrder++]);
            }
        }

        if (empty($updates)) {
            // Nothing to update in main table — media may have been updated though
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $updated = $stmt->fetch();
            enrichProduct($pdo, $updated);
            sendJsonResponse(['success' => true, 'message' => 'Media updated', 'product' => $updated]);
        }

        $params[] = $productId;
        $sql = "UPDATE products SET " . implode(", ", $updates) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($params);

        // Fetch updated product
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $updated = $stmt->fetch();
        enrichProduct($pdo, $updated);

        sendJsonResponse(['success' => true, 'message' => 'Product updated successfully', 'product' => $updated]);

    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
    }
}

// ============================================================
// DELETE — Delete product or single media item
// ============================================================
if ($method === 'DELETE') {
    try {
        $role   = $_SESSION['user_role'] ?? 'user';
        $userId = $_SESSION['user_id']   ?? null;

        // Delete single media item
        if (!empty($_GET['media_id']) && !empty($_GET['product_id'])) {
            $mediaId   = (int)$_GET['media_id'];
            $productId = (int)$_GET['product_id'];

            // Permission: admin always; seller if owns the product
            if ($role !== 'admin') {
                $st = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
                $st->execute([$productId]);
                $p = $st->fetch();
                if (!$p || $p['seller_id'] != $userId) {
                    sendJsonResponse(['success' => false, 'message' => 'Not authorized'], 403);
                }
            }

            $stmt = $pdo->prepare("SELECT file_path FROM product_media WHERE id = ? AND product_id = ?");
            $stmt->execute([$mediaId, $productId]);
            $media = $stmt->fetch();
            if ($media) {
                // Delete from disk only for local files (not external URLs)
                if (!filter_var($media['file_path'], FILTER_VALIDATE_URL)) {
                    $diskPath = dirname(dirname(__DIR__)) . $media['file_path'];
                    if (file_exists($diskPath)) unlink($diskPath);
                }
            }
            $pdo->prepare("DELETE FROM product_media WHERE id = ? AND product_id = ?")->execute([$mediaId, $productId]);
            sendJsonResponse(['success' => true, 'message' => 'Media deleted']);
        }

        // Delete entire product
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        if (!$id) sendJsonResponse(['success' => false, 'message' => 'Product ID required'], 400);

        $stmt = $pdo->prepare("SELECT seller_id, status FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) sendJsonResponse(['success' => false, 'message' => 'Product not found'], 404);

        if ($role !== 'admin') {
            if (!$userId || $p['seller_id'] != $userId) {
                sendJsonResponse(['success' => false, 'message' => 'Not authorized to delete this product'], 403);
            }
            if ($p['status'] === 'active') {
                sendJsonResponse(['success' => false, 'message' => 'Cannot delete an active product. Contact admin.'], 403);
            }
        }

        // Delete media files from disk
        $stmt = $pdo->prepare("SELECT file_path FROM product_media WHERE product_id = ?");
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $m) {
            if (!filter_var($m['file_path'], FILTER_VALIDATE_URL)) {
                $diskPath = dirname(dirname(__DIR__)) . $m['file_path'];
                if (file_exists($diskPath)) unlink($diskPath);
            }
        }
        $pdo->prepare("DELETE FROM product_media WHERE product_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);

        sendJsonResponse(['success' => true, 'message' => 'Product deleted successfully']);

    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
    }
}

sendJsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
?>