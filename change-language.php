
<?php

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/' . strtolower($_SESSION['role']) . '/dashboard.php');
    exit;
}

$language = $_POST['language'] ?? 'gu';

if (in_array($language, ['en', 'gu'], true)) {
    $_SESSION['language'] = $language;

    $stmt = $pdo->prepare(
        "UPDATE users SET preferred_language = :language WHERE user_id = :user_id"
    );

    $stmt->execute([
        'language' => $language,
        'user_id' => $_SESSION['user_id']
    ]);
}

$role = strtolower($_SESSION['role'] ?? 'worker');

header('Location: ' . APP_URL . '/' . $role . '/dashboard.php');
exit;
