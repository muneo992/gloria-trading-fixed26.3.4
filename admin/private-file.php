<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo "Forbidden\n";
    exit;
}

require_once __DIR__ . '/private-files.php';

gt_send_private_upload((string)($_GET['path'] ?? ''));
