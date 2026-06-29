<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
requireAdmin();
$db = getDB();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

$fmt    = 'Y-m-d';
$dtFrom = DateTime::createFromFormat($fmt, $dateFrom);
$dtTo   = DateTime::createFromFormat($fmt, $dateTo);

if (
    !$dtFrom || !$dtTo ||
    $dtFrom->format($fmt) !== $dateFrom || 
    $dtTo->format($fmt)   !== $dateTo
) {
    respond(false, ['error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

if ($dtFrom > $dtTo) {
    respond(false, ['error' => 'date_from cannot be after date_to.']);
    exit;
}

$dateToExclusive = (clone $dtTo)->modify('+1 day')->format($fmt);

// ── Query 1: per-day transaction breakdown (sargable) ──────────────
$stmt = $db->prepare(
    'SELECT DATE(created_at)   AS date, 
            COUNT(*)           AS transaction_count,
            SUM(total)         AS total_sales, 
            SUM(item_count)    AS items_sold
     FROM transactions 
     WHERE created_at >= ? AND created_at < ?
     GROUP BY DATE(created_at) 
     ORDER BY date DESC'
);
$stmt->bind_param('ss', $dateFrom, $dateToExclusive);
$stmt->execute();
$txRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Query 2: period-level summary (sargable, same bound) ───────────
$stmt2 = $db->prepare(
    'SELECT COUNT(*)    AS total_transactions, 
            SUM(total)  AS total_revenue, 
            AVG(total)  AS avg_transaction
     FROM transactions 
     WHERE  created_at >= ? AND created_at < ?'
);
$stmt2->bind_param('ss', $dateFrom, $dateToExclusive);
$stmt2->execute();
$salesSummary = $stmt2->get_result()->fetch_assoc();
$stmt2->close();


// ── Query 3: per-day expense breakdown ──────────────────────────────
$stmt3 = $db->prepare(
    'SELECT expense_date                                               AS date,
            COALESCE(SUM(amount), 0)                                   AS day_expenses,
            COALESCE(SUM(CASE WHEN category = \'Food\'
                              THEN amount END), 0)                     AS day_food,
            COALESCE(SUM(CASE WHEN category != \'Food\'
                              OR   category IS NULL
                              THEN amount END), 0)                     AS day_other
     FROM   expenses
     WHERE  expense_date BETWEEN ? AND ?
     GROUP  BY expense_date
     ORDER  BY expense_date DESC'
);
$stmt3->bind_param('ss', $dateFrom, $dateTo);
$stmt3->execute();
$expenseRows = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt3->close();

$db->close();

$txByDate = [];
foreach ($txRows as $row) {
    $txByDate[$row['date']] = $row;
}

$expByDate = [];
foreach ($expenseRows as $row) {
    $expByDate[$row['date']] = $row;
}

$period = new DatePeriod(
    $dtFrom,
    new DateInterval('P1D'),
    (clone $dtTo)->modify('+1 day')
);

$dailyRows     = [];
$totalExpenses = 0.0;
$totalFood     = 0.0;

foreach ($period as $dt) {
    $d = $dt->format($fmt);

    $tx  = $txByDate[$d]  ?? null;
    $exp = $expByDate[$d] ?? null;

    $daySales    = (float)($tx['total_sales']        ?? 0);
    $dayTxCount  = (int)  ($tx['transaction_count']  ?? 0);
    $dayItems    = (int)  ($tx['items_sold']         ?? 0);
    $dayExpenses = (float)($exp['day_expenses']      ?? 0);
    $dayFood     = (float)($exp['day_food']          ?? 0);
    $dayOther    = (float)($exp['day_other']         ?? 0);

    $totalExpenses += $dayExpenses;
    $totalFood     += $dayFood;

    $dailyRows[] = [
        'date'              => $d,
        'transaction_count' => $dayTxCount,
        'total_sales'       => $daySales,
        'items_sold'        => $dayItems,
        'day_expenses'      => $dayExpenses,
        'day_food'          => $dayFood,
        'day_other'         => $dayOther,
        'net_sales'         => $daySales - $dayExpenses,
    ];
}

$dailyRows = array_reverse($dailyRows);

$expenseSummary = [
    'total_expenses' => $totalExpenses,
    'food_expenses'  => $totalFood,
    'other_expenses' => $totalExpenses - $totalFood,
];

respond(true, [
    'daily'           => $dailyRows,
    'summary'         => $salesSummary,
    'expense_summary' => $expenseSummary,
    'date_from'       => $dateFrom,
    'date_to'         => $dateTo,
]);

