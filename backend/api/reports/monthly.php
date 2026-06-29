<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
requireAdmin();
$db = getDB();

$result = $db->query(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
            DATE_FORMAT(created_at,'%M %Y')  AS month_label,
            COUNT(*)                          AS transaction_count,
            SUM(total)                        AS total_sales,
            SUM(item_count)                   AS items_sold
     FROM transactions
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY DATE_FORMAT(created_at,'%Y-%m')
     ORDER BY month DESC"
);
$rows = $result->fetch_all(MYSQLI_ASSOC);
$db->close();
respond(true, $rows);
