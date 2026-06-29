<?php

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        respondError('Forbidden. Admin access required.', 403);
    }
    return $user;
}

function requireAuth(): array {
    if (empty($_SESSION['user_id'])) {
        respondError('Unauthorized. Please log in.', 401);
    }
    
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close(); $db->close();

    if (!$user || !$user['is_active']) {
        session_destroy();
        respondError('Account is inactive or does not exist.', 401);
    }
    return $user;
}