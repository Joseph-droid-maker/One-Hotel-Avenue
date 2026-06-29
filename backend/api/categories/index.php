<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAuth();
$db = getDB();

// ── GET: All categories ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query(
        'SELECT c.id, c.name,
                COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
         GROUP BY c.id
         ORDER BY c.name ASC'
    );
    $cats = $result->fetch_all(MYSQLI_ASSOC);
    $db->close();
    respond(true, $cats);
}

// ── POST: Create category (admin only) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();
    $body = getBody();
    $name = trim($body['name'] ?? '');
    if (!$name) respondError('Category name is required.');

    $stmt = $db->prepare('INSERT INTO categories (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    if (!$stmt->execute()) {
        if ($db->errno === 1062) respondError('Category already exists.', 409);
        respondError('Failed to create category.', 500);
    }
    $newId = $stmt->insert_id;
    $stmt->close();
    $db->close();

    respond(true, ['id' => $newId, 'name' => $name, 'product_count' => 0], 'Category created.', 201);
}

// ── DELETE: Remove category (admin only) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAdmin();
    $id = intval($_GET['id'] ?? 0);
    if (!$id) respondError('Category ID is required.');

    $check = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM products WHERE category_id = ? AND is_active = 1'
    );
    $check->bind_param('i', $id);
    $check->execute();
    $cnt = $check->get_result()->fetch_assoc()['cnt'];
    $check->close();

    if ($cnt > 0) {
        respondError("Cannot delete: $cnt product(s) still use this category. Reassign them first.");
    }

    $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    $db->close();
    respond(true, null, 'Category deleted.');
}

respondError('Method not allowed.', 405);
