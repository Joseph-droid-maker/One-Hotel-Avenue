<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAdmin();
verifyCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed.', 405);
}

if (empty($_FILES['image'])) {
    respondError('No image file uploaded.');
}

$file     = $_FILES['image'];
$maxSize  = 2 * 1024 * 1024; 
$allowed  = ['image/jpeg', 'image/png', 'image/webp'];
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($file['error'] !== UPLOAD_ERR_OK) {
    respondError('Upload failed. Error code: ' . $file['error']);
}
if ($file['size'] > $maxSize) {
    respondError('Image must be under 2 MB.');
}
if (!in_array($mimeType, $allowed, true)) {
    respondError('Only JPG, PNG, and WebP images are allowed.');
}


$ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
$filename = uniqid('prod_', true) . '.' . $ext;
$uploadDir = __DIR__ . '/../../uploads/products/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respondError('Failed to save image.', 500);
}


$productId = intval($_POST['product_id'] ?? 0);
if ($productId) {
    $db   = getDB();
    $stmt = $db->prepare('UPDATE products SET image_path=? WHERE id=?');
    $rel  = 'uploads/products/' . $filename;
    $stmt->bind_param('si', $rel, $productId);
    $stmt->execute();
    $stmt->close();
    $db->close();
}

respond(true, ['path' => 'uploads/products/' . $filename], 'Image uploaded.');
