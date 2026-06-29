<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
requireAuth();
$db = getDB();
$threshold = intval($_GET['threshold'] ?? 10);
$stmt = $db->prepare(
    'SELECT p.id, p.name, p.sku, p.stock, p.price, c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE p.is_active = 1 AND p.stock <= ?
     ORDER BY p.stock ASC, p.name ASC'
);
$stmt->bind_param('i', $threshold);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$db->close();
respond(true, ['products' => $rows, 'threshold' => $threshold, 'count' => count($rows)]);
