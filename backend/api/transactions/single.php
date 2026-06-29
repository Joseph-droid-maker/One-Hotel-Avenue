<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAuth();
$db = getDB();
$id = intval($_GET['id'] ?? 0);
if (!$id) respondError('Transaction ID is required.');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$txn) respondError('Transaction not found.', 404);

    $stmt2 = $db->prepare('SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id');
    $stmt2->bind_param('i', $id);
    $stmt2->execute();
    $txn['items'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close(); $db->close();
    respond(true, $txn);
}
respondError('Method not allowed.', 405);
