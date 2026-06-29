<?php
$_env = parse_ini_file(__DIR__ . '/credential.env');

if ($_env === false) {
    http_response_code(500);
    exit('Failed to load environment configuration.');
}

define('DB_HOST', $_env['DB_HOST']);
define('DB_USER', $_env['DB_USER']);
define('DB_PASS', $_env['DB_PASS']);
define('DB_NAME', $_env['DB_NAME']);

function getDB(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]);
        exit;
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
