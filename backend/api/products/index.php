<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = requireAuth();
$db   = getDB();

// ── GET: List all products ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search   = trim($_GET['search'] ?? '');
    $category = intval($_GET['category'] ?? 0);

    $showInactive = (($_GET['status'] ?? '') === 'inactive') && $user['role'] === 'admin';

    $sql    = 'SELECT p.*, c.name AS category_name
               FROM products p
               LEFT JOIN categories c ON p.category_id = c.id
               WHERE p.is_active = ?';
    $params = [$showInactive ? 0 : 1];
    $types  = 'i';

    if ($search) {
        $sql      .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)';
        $like      = "%$search%";
        $params[]  = $like;
        $params[]  = $like;
        $params[]  = $like;
        $types    .= 'sss';
    }

    if ($category > 0) {
        $sql      .= ' AND p.category_id = ?';
        $params[]  = $category;
        $types    .= 'i';
    }

    $sql .= ' ORDER BY p.name ASC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $db->close();
    respond(true, $rows);
}

// ── POST: Create product (admin only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $body  = getBody();
    $name  = trim($body['name'] ?? '');
    $desc      = trim($body['description'] ?? '') ?: null;
    $sku   = trim($body['sku'] ?? '') ?: null;
    $price = floatval($body['price'] ?? 0);
    $costPrice = floatval($body['cost_price'] ?? 0);
    $stock = intval($body['stock'] ?? 0);
    $catId = intval($body['category_id'] ?? 0) ?: null;

    if (!$name)      respondError('Product name is required.');
    if ($price <= 0) respondError('Price must be greater than 0.');
    if ($stock < 0)  respondError('Stock cannot be negative.');

    $stmt = $db->prepare(
        'INSERT INTO products (name, description, sku, price, cost_price, stock, category_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssddii', $name, $desc, $sku, $price, $costPrice, $stock, $catId);

    try {
        $stmt->execute();
    } catch(mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            respondError('SKU already exists. Use a unique SKU.', 409);
        }
        respondError('Failed to create product.', 500);
    }
        

    $newId = $stmt->insert_id;
    $stmt->close();

    $stmt2 = $db->prepare(
        'SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?'
    );
    $stmt2->bind_param('i', $newId);
    $stmt2->execute();
    $product = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    $db->close();

    respond(true, $product, 'Product created.', 201);
}

respondError('Method not allowed.', 405);
