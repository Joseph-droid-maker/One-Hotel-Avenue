<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAuth();
$db = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) respondError('Product ID is required.');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare(
        'SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.id = ? AND p.is_active = 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $db->close();
    if (!$product) respondError('Product not found.', 404);
    respond(true, $product);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireAdmin();
    $body  = getBody();
    $name  = trim($body['name'] ?? '');
    $desc      = trim($body['description'] ?? '') ?: null;
    $sku   = trim($body['sku']  ?? '') ?: null;
    $price = floatval($body['price'] ?? 0);
    $costPrice = floatval($body['cost_price'] ?? 0);
    $stock = intval($body['stock']   ?? 0);
    $catId = intval($body['category_id'] ?? 0) ?: null;
    
    if (!$name)      respondError('Product name is required.');
    if ($price <= 0) respondError('Price must be greater than 0.');
    if ($stock < 0)  respondError('Stock cannot be negative.');

    $stmt = $db->prepare(
        'UPDATE products SET name=?, description=?, sku=?, price=?, cost_price=?, stock=?, category_id=? WHERE id=? AND is_active=1'
    );
    $stmt->bind_param('sssddiii', $name, $desc, $sku, $price, $costPrice, $stock, $catId, $id);
    if (!$stmt->execute()) {
        if ($db->errno === 1062) respondError('SKU already exists.', 409);
        respondError('Failed to update product.', 500);
    }
    $stmt->close();

    $stmt2 = $db->prepare(
        'SELECT p.*, c.name AS category_name FROM products p
         LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?'
    );
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $product = $stmt2->get_result()->fetch_assoc();
    $stmt2->close(); $db->close();
    respond(true, $product, 'Product updated.');
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAdmin();
    $stmt = $db->prepare('UPDATE products SET is_active = 0 WHERE id = ? AND is_active = 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $affected = $stmt->affected_rows;

    $stmt->close();
    $db->close();

    if (!$affected) {
        respondError('Product not found or already inactive.', 404);
    }

    respond(true, null, 'Product deactivated.');
}


if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    requireAdmin(); // only the admin can bring a product back into circulation

    // Guard: "AND is_active = 0" means this can only succeed on a row that
    // is currently inactive. Calling PATCH on an already-active product
    // reports "not found" instead of silently returning success — slightly
    // more defensive than the equivalent users/single.php PATCH, which
    // doesn't have this guard. Worth backporting there too, but out of
    // scope for this change.
    $stmt = $db->prepare('UPDATE products SET is_active = 1 WHERE id = ? AND is_active = 0');
    $stmt->bind_param('i', $id);

    if (!$stmt->execute()) respondError('Failed to reactivate product.', 500);

    $affected = $stmt->affected_rows; // 0 = guard above didn't match anything
    $stmt->close();
    $db->close();

    if (!$affected) respondError('Product not found or already active.', 404);

    respond(true, null, 'Product reactivated.');
}



respondError('Method not allowed.', 405);
