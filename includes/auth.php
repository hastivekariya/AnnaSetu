
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

    $userRole = strtolower(trim($_SESSION['role'] ?? ''));

    // Support both "worker" and "role_worker" formats.
    if (strpos($userRole, 'role_') === 0) {
        $userRole = substr($userRole, 5);
    }

    $allowedRoles = array_map(function ($role) {
        $role = strtolower(trim($role));

        if (strpos($role, 'role_') === 0) {
            $role = substr($role, 5);
        }

        return $role;
    }, $allowedRoles);

    if (!in_array($userRole, $allowedRoles, true)) {
        http_response_code(403);
        exit('Access denied. Your account role does not have permission to open this page.');
    }
}
