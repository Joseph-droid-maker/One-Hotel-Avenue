<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$body     = getBody();
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (!$username || !$password) {
    respondError('Username and password are required.');
}

$db   = getDB();
$stmt = $db->prepare(
    'SELECT id, username, password_hash, full_name, role, is_active
     FROM users WHERE username = ? LIMIT 1'
);
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$db->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    respondError('Invalid username or password.', 401);
}

if (!$user['is_active']) {
    respondError('This account has been deactivated.', 403);
}

session_regenerate_id(true); 

$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

respond(true, [
    'id'        => $user['id'],
    'username'  => $user['username'],
    'full_name' => $user['full_name'],
    'role'      => $user['role'],
    'csrf_token' => $_SESSION['csrf_token'] ?? '',
], 'Login successful.');
