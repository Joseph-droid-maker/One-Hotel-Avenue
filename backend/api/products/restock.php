<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAdmin();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed.', 405);

$body      = getBody();
$productId = intval($body['product_id'] ?? 0);
$quantity  = intval($body['quantity']   ?? 0);
$type      = $body['type']  ?? 'in';  
$notes     = trim($body['notes'] ?? '');

if (!$productId)   respondError('Product ID is required.');
if ($quantity <= 0) respondError('Quantity must be greater than 0.');
if (!in_array($type, ['in', 'out'])) respondError('Type must be "in" or "out".');

$db = getDB();

if ($type === 'out') {
    $stmt = $db->prepare(
        'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ? AND is_active = 1'
    );
    $stmt->bind_param('iii', $quantity, $productId, $quantity);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();

        $diag = $db->prepare('SELECT stock FROM products WHERE id = ? AND is_active = 1');
        $diag->bind_param('i', $productId);
        $diag->execute();
        $row = $diag->get_result()->fetch_assoc();
        $diag->close();

        if (!$row) {
            respondError('Product not found.', 404);
        }

        respondError("Not enough stock. Only {$row['stock']} unit(s) available.");
    }
    $stmt->close();
} else {
    $stmt = $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
    $stmt->bind_param('ii', $quantity, $productId);
    $stmt->execute();
    $stmt->close();
}


$stmt2 = $db->prepare(
    'SELECT p.*, c.name AS category_name FROM products p
     LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?'
);
$stmt2->bind_param('i', $productId);
$stmt2->execute();
$updated = $stmt2->get_result()->fetch_assoc();
$stmt2->close();
$db->close();

$action = $type === 'in' ? "+$quantity stocked in" : "-$quantity stocked out";
respond(true, $updated, "Stock adjusted: $action.");