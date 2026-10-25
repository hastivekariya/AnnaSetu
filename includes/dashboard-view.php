
<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth.php';

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'User';
$language = $_SESSION['language'] ?? 'gu';

$stats = [
    'food_items' => 0,
    'active_users' => 0,
    'centres' => 0,
    'pending_verification' => 0,
    'low_stock' => 0,
    'near_expiry' => 0
];

$recentTransactions = [];
$dbError = false;
$dbErrorMessage = '';

/* ---------- Role Menus ---------- */

$menus = [
    'worker' => [
        ['Dashboard', 'dashboard.php'],
        ['Receive Stock', 'receive-stock.php'],
        ['Daily Consumption', 'daily-consumption.php'],
        ['Beneficiary Distribution', 'distribution.php'],
        ['Current Stock', 'current-stock.php'],
        ['Stock History', 'stock-history.php'],
        ['Reports', 'reports.php'],
        ['Notifications', 'notifications.php'],
        ['My Profile', 'profile.php']
    ],

    'helper' => [
        ['Dashboard', 'dashboard.php'],
        ['Daily Consumption', 'daily-consumption.php'],
        ['Beneficiary Distribution', 'distribution.php'],
        ['Current Stock', 'current-stock.php'],
        ['My Profile', 'profile.php']
    ],

    'supervisor' => [
        ['Dashboard', 'dashboard.php'],
        ['Pending Verification', 'pending-verification.php'],
        ['Low Stock Alerts', 'low-stock.php'],
        ['Expiry Monitoring', 'expiry-monitoring.php'],
        ['Workers and Helpers', 'workers.php'],
        ['Anganwadi Centres', 'centres.php'],
        ['Reports and Analytics', 'reports.php'],
        ['My Profile', 'profile.php']
    ],

    'cdpo' => [
        ['Dashboard', 'dashboard.php'],
        ['Project Overview', 'project-overview.php'],
        ['Anganwadi Centres', 'centres.php'],
        ['Stock Overview', 'stock-overview.php'],
        ['Shortage and Expiry Alerts', 'alerts.php'],
        ['Reports and Analytics', 'reports.php'],
        ['Export Reports', 'export-reports.php'],
        ['My Profile', 'profile.php']
    ],

    'admin' => [
        ['Dashboard', 'dashboard.php'],
        ['User Management', 'users.php'],
        ['Roles and Permissions', 'roles.php'],
        ['Project Management', 'projects.php'],
        ['Centre Management', 'centres.php'],
        ['Food Items', 'food-items.php'],
        ['Stock Settings', 'stock-settings.php'],
        ['Alert Settings', 'alert-settings.php'],
        ['System Reports', 'reports.php'],
        ['Audit Logs', 'audit-logs.php'],
        ['System Settings', 'settings.php'],
        ['My Profile', 'profile.php']
    ]
];

$menuItems = $menus[$role] ?? $menus['worker'];

/* ---------- Translation Helpers ---------- */

function dashboardTranslate($key)
{
    return htmlspecialchars(
        translate($key),
        ENT_QUOTES,
        'UTF-8'
    );
}

function dashboardMenuKey($text)
{
    $keys = [
        'Dashboard' => 'dashboard',
        'Receive Stock' => 'receive_stock',
        'Daily Consumption' => 'daily_consumption',
        'Beneficiary Distribution' => 'beneficiary_distribution',
        'Current Stock' => 'current_stock',
        'Stock History' => 'stock_history',
        'Reports' => 'reports',
        'Notifications' => 'notifications',
        'My Profile' => 'my_profile',
        'Pending Verification' => 'pending_verification',
        'Low Stock Alerts' => 'low_stock_alerts',
        'Expiry Monitoring' => 'expiry_monitoring',
        'Workers and Helpers' => 'workers_and_helpers',
        'Anganwadi Centres' => 'anganwadi_centres',
        'Reports and Analytics' => 'reports_and_analytics',
        'Project Overview' => 'project_overview',
        'Stock Overview' => 'stock_overview',
        'Shortage and Expiry Alerts' => 'shortage_and_expiry_alerts',
        'Export Reports' => 'export_reports',
        'User Management' => 'user_management',
        'Roles and Permissions' => 'roles_and_permissions',
        'Project Management' => 'project_management',
        'Centre Management' => 'centre_management',
        'Food Items' => 'food_items',
        'Stock Settings' => 'stock_settings',
        'Alert Settings' => 'alert_settings',
        'System Reports' => 'system_reports',
        'Audit Logs' => 'audit_logs',
        'System Settings' => 'system_settings'
    ];

    return $keys[$text] ?? strtolower(str_replace(' ', '_', $text));
}

function dashboardFoodName($transaction)
{
    $language = $_SESSION['language'] ?? 'gu';

    if ($language === 'gu' && !empty($transaction['item_name_gu'])) {
        return $transaction['item_name_gu'];
    }

    return $transaction['item_name_en'] ?? 'Food item';
}

/* ---------- Dashboard Database Queries ---------- */

try {
    // Total food items
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM food_items"
    );

    $stats['food_items'] = (int) $stmt->fetchColumn();

    // Total active users and Anganwadi centres
    if ($role === 'admin' || $role === 'cdpo') {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM users WHERE is_active = 1"
        );

        $stats['active_users'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM anganwadi_centres"
        );

        $stats['centres'] = (int) $stmt->fetchColumn();
    }

    // Transactions awaiting verification
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM stock_transactions
         WHERE UPPER(status) = 'PENDING'"
    );

    $stats['pending_verification'] = (int) $stmt->fetchColumn();

    /*
     * Calculate available stock.
     * This query assumes stock_transactions uses item_id
     * as its foreign key to food_items.item_id.
     */
    $stmt = $pdo->query(
        "SELECT
            f.item_id,
            f.default_min_stock,
            COALESCE(
                SUM(
                    CASE
                        WHEN UPPER(t.transaction_type) = 'RECEIPT'
                            THEN t.quantity

                        WHEN UPPER(t.transaction_type)
                            IN ('CONSUMPTION', 'DISTRIBUTION')
                            THEN -t.quantity

                        ELSE 0
                    END
                ),
                0
            ) AS balance
         FROM food_items f
         LEFT JOIN stock_transactions t
            ON t.item_id = f.item_id
            AND UPPER(t.status) = 'APPROVED'
         GROUP BY f.item_id, f.default_min_stock"
    );

    while ($row = $stmt->fetch()) {
        if (
            (float) $row['balance']
            <= (float) $row['default_min_stock']
        ) {
            $stats['low_stock']++;
        }
    }

    // Batches expiring within 30 days
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM stock_batches
         WHERE expiry_date IS NOT NULL
           AND expiry_date >= CURDATE()
           AND expiry_date <= DATE_ADD(
               CURDATE(),
               INTERVAL 30 DAY
           )"
    );

    $stats['near_expiry'] = (int) $stmt->fetchColumn();

    // Latest five transactions
    $stmt = $pdo->query(
        "SELECT
            t.transaction_id,
            t.transaction_type,
            t.quantity,
            t.status,
            t.created_at,
            f.item_name_en,
            f.item_name_gu
         FROM stock_transactions t
         LEFT JOIN food_items f
            ON f.item_id = t.item_id
         ORDER BY t.created_at DESC
         LIMIT 5"
    );

    $recentTransactions = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Dashboard database error: ' . $e->getMessage());

    $dbError = true;

    // Show details only during local development.
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $dbErrorMessage = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= dashboardTranslate('dashboard') ?> |
        <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=2"
    >

    <link
    rel="stylesheet"
    href="<?= APP_URL ?>/assets/css/dashboard.css?v=3"
>

<link
    rel="stylesheet"
    href="<?= APP_URL ?>/assets/css/receive-stock.css?v=1"
>
</head>

<body>

<div class="dashboard-layout">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

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
                    <?= htmlspecialchars(
                        $fullName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

                <small>
                    <?= dashboardTranslate('role_' . $role) ?>
                </small>
            </div>

        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>

                <?php
                $menuKey = dashboardMenuKey($menu[0]);

                $menuUrl = APP_URL . '/'
                    . $role . '/'
                    . $menu[1];

                $isDashboard = $menu[1] === 'dashboard.php';
                ?>

                <a
                    href="<?= htmlspecialchars(
                        $menuUrl,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    class="menu-link <?= $isDashboard ? 'active' : '' ?>"
                >
                    <span>
                        <?= dashboardTranslate($menuKey) ?>
                    </span>
                </a>

            <?php endforeach; ?>

        </nav>

        <a
            class="logout-link"
            href="<?= APP_URL ?>/logout.php"
        >
            <?= dashboardTranslate('logout') ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <!-- Header -->
        <header class="dashboard-header">

            <div>
                <h2>
                    <?= dashboardTranslate('welcome') ?>,
                    <?= htmlspecialchars(
                        $fullName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>!
                </h2>

                <p>
                    <?= dashboardTranslate('tagline') ?>
                </p>
            </div>

            <form
                action="<?= APP_URL ?>/change-language.php"
                method="POST"
                class="language-form"
            >

                <label for="language">
                    <?= dashboardTranslate('language') ?>
                </label>

                <select
                    name="language"
                    id="language"
                    onchange="this.form.submit()"
                >
                    <option
                        value="gu"
                        <?= $language === 'gu' ? 'selected' : '' ?>
                    >
                        ગુજરાતી
                    </option>

                    <option
                        value="en"
                        <?= $language === 'en' ? 'selected' : '' ?>
                    >
                        English
                    </option>
                </select>

            </form>

        </header>

        <?php if ($dbError): ?>

            <div class="alert alert-danger">

                <strong>
                    <?= dashboardTranslate('database_error') ?>
                </strong>

                <?php if ($dbErrorMessage !== ''): ?>
                    <p class="mb-0 mt-2">
                        <?= htmlspecialchars(
                            $dbErrorMessage,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>
                <?php endif; ?>

            </div>

        <?php else: ?>

            <!-- Statistics -->
            <section class="stats-grid">

                <div class="stat-card">
                    <p><?= dashboardTranslate('food_items') ?></p>
                    <h3><?= $stats['food_items'] ?></h3>
                </div>

                <?php if ($role === 'admin' || $role === 'cdpo'): ?>

                    <div class="stat-card">
                        <p><?= dashboardTranslate('active_users') ?></p>
                        <h3><?= $stats['active_users'] ?></h3>
                    </div>

                    <div class="stat-card">
                        <p><?= dashboardTranslate('centres') ?></p>
                        <h3><?= $stats['centres'] ?></h3>
                    </div>

                <?php endif; ?>

                <div class="stat-card">
                    <p>
                        <?= dashboardTranslate('pending_verification') ?>
                    </p>
                    <h3><?= $stats['pending_verification'] ?></h3>
                </div>

                <div class="stat-card">
                    <p><?= dashboardTranslate('low_stock') ?></p>
                    <h3><?= $stats['low_stock'] ?></h3>
                </div>

                <div class="stat-card">
                    <p><?= dashboardTranslate('near_expiry') ?></p>
                    <h3><?= $stats['near_expiry'] ?></h3>
                </div>

            </section>

            <!-- Quick Actions -->
            <section class="dashboard-section">

                <div class="section-heading">
                    <h3><?= dashboardTranslate('quick_actions') ?></h3>
                </div>

                <div class="quick-actions">

                    <?php if ($role === 'worker'): ?>

                        <a
                            href="<?= APP_URL ?>/worker/receive-stock.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('receive_stock') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/worker/daily-consumption.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('daily_consumption') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/worker/distribution.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('beneficiary_distribution') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/worker/current-stock.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('current_stock') ?>
                        </a>

                    <?php elseif ($role === 'helper'): ?>

                        <a
                            href="<?= APP_URL ?>/helper/daily-consumption.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('daily_consumption') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/helper/distribution.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('beneficiary_distribution') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/helper/current-stock.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('current_stock') ?>
                        </a>

                    <?php elseif ($role === 'supervisor'): ?>

                        <a
                            href="<?= APP_URL ?>/supervisor/pending-verification.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('pending_verification') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/supervisor/low-stock.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('low_stock_alerts') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/supervisor/reports.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('reports') ?>
                        </a>

                    <?php elseif ($role === 'cdpo'): ?>

                        <a
                            href="<?= APP_URL ?>/cdpo/stock-overview.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('stock_overview') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/cdpo/alerts.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('shortage_and_expiry_alerts') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/cdpo/reports.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('reports_and_analytics') ?>
                        </a>

                    <?php elseif ($role === 'admin'): ?>

                        <a
                            href="<?= APP_URL ?>/admin/users.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('user_management') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/admin/food-items.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('food_items') ?>
                        </a>

                        <a
                            href="<?= APP_URL ?>/admin/centres.php"
                            class="action-card"
                        >
                            <?= dashboardTranslate('centre_management') ?>
                        </a>

                    <?php endif; ?>

                </div>

            </section>

            <!-- Recent Transactions -->
            <section class="dashboard-section">

                <div class="section-heading">
                    <h3>
                        <?= dashboardTranslate('recent_transactions') ?>
                    </h3>

                    <span>
                        <?= dashboardTranslate('latest_activity') ?>
                    </span>
                </div>

                <?php if (empty($recentTransactions)): ?>

                    <div class="empty-state">
                        <?= dashboardTranslate('no_transactions') ?>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead>
                                <tr>
                                    <th>
                                        <?= dashboardTranslate('transaction_id') ?>
                                    </th>

                                    <th>
                                        <?= dashboardTranslate('food_item') ?>
                                    </th>

                                    <th>
                                        <?= dashboardTranslate('transaction_type') ?>
                                    </th>

                                    <th>
                                        <?= dashboardTranslate('quantity') ?>
                                    </th>

                                    <th>
                                        <?= dashboardTranslate('status') ?>
                                    </th>

                                    <th>
                                        <?= dashboardTranslate('date') ?>
                                    </th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($recentTransactions as $transaction): ?>

                                    <tr>

                                        <td>
                                            #<?= (int) $transaction['transaction_id'] ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                dashboardFoodName($transaction),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= dashboardTranslate(
                                                'transaction_' .
                                                strtolower(
                                                    $transaction['transaction_type'] ?? ''
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $transaction['quantity'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= dashboardTranslate(
                                                'status_' .
                                                strtolower(
                                                    $transaction['status'] ?? ''
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php
                                            if (!empty($transaction['created_at'])) {
                                                echo htmlspecialchars(
                                                    date(
                                                        'd-m-Y',
                                                        strtotime($transaction['created_at'])
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
