<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAdmin();
$db = getDB();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$limit    = min(intval($_GET['limit'] ?? 10), 50);


$stmt = $db->prepare(
    'SELECT ti.product_name,
            ti.product_sku,
            SUM(ti.quantity)                                 AS total_qty,
            SUM(ti.subtotal)                                 AS total_revenue,
            COUNT(DISTINCT ti.transaction_id)                AS transaction_count,
            MAX(p.cost_price)                                AS cost_price,
            SUM(ti.quantity) * MAX(COALESCE(p.cost_price,0)) AS total_cost,
            SUM(ti.subtotal) - SUM(ti.quantity) * MAX(COALESCE(p.cost_price,0)) AS profit
     FROM transaction_items ti
     JOIN transactions t ON t.id = ti.transaction_id
     LEFT JOIN products p ON p.id = ti.product_id
     WHERE DATE(t.created_at) BETWEEN ? AND ?
     GROUP BY ti.product_name, ti.product_sku
     ORDER BY total_qty DESC
     LIMIT ?'
);
$stmt->bind_param('ssi', $dateFrom, $dateTo, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$db->close();
respond(true, $rows);
