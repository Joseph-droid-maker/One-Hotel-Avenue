<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

$db = getDB();

// ── GET: list expenses (admin only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireAdmin();

    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');

    $stmt = $db->prepare(
        'SELECT id, expense_date, amount, category, description,
                recorded_by_name, created_at
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         ORDER BY expense_date DESC, created_at DESC'
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sumStmt = $db->prepare(
        'SELECT
           COALESCE(SUM(amount), 0)                                        AS total_expenses,
           COALESCE(SUM(CASE WHEN category = "Food" THEN amount END), 0)   AS food_expenses,
           COALESCE(SUM(CASE WHEN category != "Food" THEN amount END), 0)  AS other_expenses
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?'
    );
    $sumStmt->bind_param('ss', $dateFrom, $dateTo);
    $sumStmt->execute();
    $summary = $sumStmt->get_result()->fetch_assoc();
    $sumStmt->close();
    $db->close();

    respond(true, [
        'expenses'  => $rows,
        'summary'   => $summary,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
    ]);
}

// ── POST: log a new expense (any authenticated user) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireAuth();
    $body = getBody();
    $amount      = floatval($body['amount']      ?? 0);
    $category    = trim($body['category']        ?? '');
    $description = trim($body['description']     ?? '');
    $expenseDate = trim($body['expense_date']    ?? date('Y-m-d'));

    if ($amount <= 0) {
        respondError('Amount must be greater than zero.');
    }

    $validCategories = ['Food', 'Utilities', 'Supplies', 'Transportation', 'Other'];
    if (!in_array($category, $validCategories, true)) {
        respondError('Invalid category. Must be one of: ' . implode(', ', $validCategories));
    }

    if ($description === '') {
        respondError('Description is required.');
    }
    if (strlen($description) > 255) {
        respondError('Description must be 255 characters or fewer.');
    }

    $dt = DateTime::createFromFormat('Y-m-d', $expenseDate);
    if (!$dt || $dt->format('Y-m-d') !== $expenseDate) {
        respondError('Invalid date format. Expected YYYY-MM-DD.');
    }

    $stmt = $db->prepare(
        'INSERT INTO expenses
           (expense_date, amount, category, description, recorded_by_id, recorded_by_name)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sdssis',
        $expenseDate,
        $amount,
        $category,
        $description,
        $user['id'],
        $user['full_name']
    );
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    $fetch = $db->prepare(
        'SELECT id, expense_date, amount, category, description,
                recorded_by_name, created_at
         FROM expenses WHERE id = ?'
    );
    $fetch->bind_param('i', $newId);
    $fetch->execute();
    $row = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    $db->close();

    respond(true, $row, 'Expense logged.', 201);
}

// ── DELETE: remove an expense (admin only) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAdmin();

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError('Missing or invalid expense ID.');
    }

    $stmt = $db->prepare('DELETE FROM expenses WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        $db->close();
        respondError('Expense not found.', 404);
    }
    $stmt->close();
    $db->close();

    respond(true, null, 'Expense deleted.');
}

respondError('Method not allowed.', 405);