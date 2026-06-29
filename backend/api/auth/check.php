<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../middleware/auth.php';

$user = requireAuth();
respond(true, array_merge($user, [
    'csrf_token' => $_SESSION['csrf_token'] ?? '',
]));

