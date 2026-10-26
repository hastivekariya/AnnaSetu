<?php
$role = strtolower($_SESSION['role'] ?? 'helper');
$fullName = $_SESSION['full_name'] ?? 'Test helper';
$currentPage = basename($_SERVER['PHP_SELF']);

$menuItems = [
    [
        'label' => 'Dashboard',
        'file' => 'dashboard.php'
    ],
    [
        'label' => 'Daily Consumption',
        'file' => 'daily-consumption.php'
    ],
    [
        'label' => 'Beneficiary Distribution',
        'file' => 'beneficiary-distribution.php'
    ],
    [
        'label' => 'Current Stock',
        'file' => 'current-stock.php'
    ],
    [
        'label' => 'My Profile',
        'file' => 'profile.php'
    ],
];
?>

<aside class="sidebar">

    <!-- Annasetu branding -->
    <div class="sidebar-brand">
        <h3>અન્નસેતુ</h3>
        <p>AnnaSetu</p>
    </div>

    <!-- Logged-in user -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <?= htmlspecialchars(
                strtoupper(substr($fullName, 0, 1)),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

        <div>
            <strong>
                <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
            </strong>

            <small>
                role_<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>
            </small>
        </div>
    </div>

    <!-- Navigation options -->
    <nav class="sidebar-menu">
        <?php foreach ($menuItems as $item): ?>
            <a
                href="<?= htmlspecialchars(
                    APP_URL . '/' . $role . '/' . $item['file'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="menu-link <?= $currentPage === $item['file'] ? 'active' : '' ?>"
            >
                <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Logout stays at the bottom -->
    <a
        href="<?= htmlspecialchars(APP_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>"
        class="logout-link"
    >
        Logout
    </a>

</aside>