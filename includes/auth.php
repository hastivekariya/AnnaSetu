
<?php

require_once __DIR__ . '/init.php';

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireRole($allowedRoles)
{
    requireLogin();

    $userRole = strtolower($_SESSION['role'] ?? '');

    if (!in_array($userRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to open this page.');
    }
}
