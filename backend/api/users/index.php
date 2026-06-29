<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
requireAdmin();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query(
        'SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY created_at DESC'
    );
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            ...$row,
            'id'         => (int)  $row['id'],
            'is_active'  => (int)  $row['is_active'],
            
        ];
    }
    respond(true, $users);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = getBody();
    $username = trim($body['username']  ?? '');
    $fullName = trim($body['full_name'] ?? '');
    $password = $body['password']       ?? '';
    $role     = in_array($body['role'] ?? '', ['admin', 'staff']) ? $body['role'] : 'staff';

    if (!$username)            respondError('Username is required.');
    if (!$fullName)            respondError('Full name is required.');
    if (strlen($password) < 6) respondError('Password must be at least 6 characters.');

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $username, $hash, $fullName, $role);

    if (!$stmt->execute()) {
        if ($db->errno === 1062) respondError('Username already exists.', 409);
        respondError('Failed to create user.', 500);
    }

    $newId = $stmt->insert_id;
    $stmt->close();
    $db->close();
    respond(true, [
        'id' => $newId, 'username' => $username,
        'full_name' => $fullName, 'role' => $role, 'is_active' => 1
    ], 'User created.', 201);
}

respondError('Method not allowed.', 405);
