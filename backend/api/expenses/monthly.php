<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

requireAdmin();
$db = getDB();

$cutoff = (new DateTime('first day of this month'))
    ->modify('-11 months')
    ->format('Y-m-d');

// ── Query 1: Per-month sales ─────────────────────────────────
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
            DATE_FORMAT(created_at,'%M %Y') AS month_label,
            COUNT(*)                         AS transaction_count,
            SUM(total)                       AS total_sales,
            SUM(item_count)                  AS items_sold
     FROM   transactions
     WHERE  created_at >= ?
     GROUP  BY DATE_FORMAT(created_at,'%Y-%m')
     ORDER  BY month DESC"
);
$stmt->bind_param('s', $cutoff);
$stmt->execute();
$txRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Query 2: Per-month expenses ──────────────────────────────
$stmt2 = $db->prepare(
    "SELECT DATE_FORMAT(expense_date,'%Y-%m')                              AS month,
            COALESCE(SUM(amount), 0)                                        AS total_expenses,
            COALESCE(SUM(CASE WHEN category = 'Food'
                              THEN amount END), 0)                          AS food_expenses,
            COALESCE(SUM(CASE WHEN category != 'Food'
                              OR   category IS NULL
                              THEN amount END), 0)                          AS other_expenses
     FROM   expenses
     WHERE  expense_date >= ?
     GROUP  BY DATE_FORMAT(expense_date,'%Y-%m')
     ORDER  BY month DESC"
);
$stmt2->bind_param('s', $cutoff);
$stmt2->execute();
$expenseRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$db->close();

$txByMonth  = [];
foreach ($txRows as $row)     { $txByMonth[$row['month']]  = $row; }

$expByMonth = [];
foreach ($expenseRows as $row) { $expByMonth[$row['month']] = $row; }

$monthlyRows = [];
$dt = new DateTime('first day of this month'); 

for ($i = 0; $i < 12; $i++) {
    $key   = $dt->format('Y-m');
    $label = $dt->format('F Y');

    $tx  = $txByMonth[$key]  ?? null;
    $exp = $expByMonth[$key] ?? null;

    $totalSales    = (float)($tx['total_sales']    ?? 0);
    $totalExpenses = (float)($exp['total_expenses'] ?? 0);

    $monthlyRows[] = [
        'month'             => $key,
        'month_label'       => $label,
        'transaction_count' => (int)  ($tx['transaction_count'] ?? 0),
        'total_sales'       => $totalSales,
        'items_sold'        => (int)  ($tx['items_sold']         ?? 0),
        'total_expenses'    => $totalExpenses,
        'food_expenses'     => (float)($exp['food_expenses']     ?? 0),
        'other_expenses'    => (float)($exp['other_expenses']    ?? 0),
        'net_sales'         => $totalSales - $totalExpenses,
    ];

    $dt->modify('-1 month');
}

respond(true, $monthlyRows);