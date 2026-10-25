
<?php
/*
 * worker/notifications.php
 * Fetch and display notifications for the logged-in Worker.
 */

require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['notification_csrf'])) {
    $_SESSION['notification_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['notification_csrf'];

$notifications = [];
$centres = [];
$centreIds = [];
$centreId = 0;
$unreadCount = 0;
$totalCount = 0;
$errors = [];

function ntEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ntText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function ntDate($value): string
{
    if (empty($value)) {
        return '';
    }

    $time = strtotime((string)$value);

    return $time ? date('d M Y, h:i A', $time) : '';
}

/* Actual notification types from the database ENUM */
$typeLabels = [
    'LOW_STOCK' => [
        ntText('Low Stock', 'ઓછો સ્ટોક'),
        'type-stock'
    ],
    'NEAR_EXPIRY' => [
        ntText('Near Expiry', 'સમાપ્તિ નજીક'),
        'type-expiry'
    ],
    'EXPIRED' => [
        ntText('Expired Stock', 'સમાપ્ત થયેલ સ્ટોક'),
        'type-expiry'
    ],
    'PENDING_VERIFICATION' => [
        ntText('Pending Verification', 'ચકાસણી બાકી'),
        'type-approval'
    ],
    'SYSTEM' => [
        ntText('System Message', 'સિસ્ટમ સંદેશ'),
        'type-general'
    ]
];

/* Notification filters */
$filter = strtoupper(trim((string)($_GET['filter'] ?? 'ALL')));

$allowedFilters = [
    'ALL',
    'UNREAD',
    'LOW_STOCK',
    'NEAR_EXPIRY',
    'EXPIRED',
    'PENDING_VERIFICATION',
    'SYSTEM'
];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'ALL';
}

/* Redirect after POST actions */
function ntRedirect(string $filter, int $centreId): void
{
    $params = [];

    if ($filter !== 'ALL') {
        $params['filter'] = $filter;
    }

    if ($centreId > 0) {
        $params['centre_id'] = $centreId;
    }

    $url = APP_URL . '/worker/notifications.php';

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

try {
    /*
     * Find centres assigned to this worker.
     * This first query assumes centre_assignments has is_active
     * and anganwadi_centres has is_active.
     */
    $stmt = $pdo->prepare(
        "SELECT c.centre_id, c.centre_name
         FROM anganwadi_centres c
         INNER JOIN centre_assignments ca
             ON ca.centre_id = c.centre_id
         WHERE ca.user_id = :user_id
           AND ca.is_active = 1
           AND c.is_active = 1
         ORDER BY c.centre_name"
    );

    $stmt->execute(['user_id' => $userId]);
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Fallback: use users.centre_id if no centre assignment
     * was found. This assumes users.centre_id exists.
     */
    if (empty($centres)) {
        $stmt = $pdo->prepare(
            "SELECT c.centre_id, c.centre_name
             FROM users u
             INNER JOIN anganwadi_centres c
                 ON c.centre_id = u.centre_id
             WHERE u.user_id = :user_id
               AND c.is_active = 1"
        );

        $stmt->execute(['user_id' => $userId]);
        $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $centreIds = array_map(
        'intval',
        array_column($centres, 'centre_id')
    );

    /* Select only a centre assigned to this worker */
    $requestedCentre = filter_input(
        INPUT_GET,
        'centre_id',
        FILTER_VALIDATE_INT
    );

    $centreId = (
        $requestedCentre !== false &&
        $requestedCentre !== null &&
        in_array((int)$requestedCentre, $centreIds, true)
    )
        ? (int)$requestedCentre
        : (int)($centreIds[0] ?? 0);

    /*
     * Mark notifications as read.
     * POST requests require a valid CSRF token.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedToken)) {
            http_response_code(403);
            exit('Invalid request token. Please refresh the page.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($centreId > 0 && $action === 'mark_read') {
            $notificationId = filter_var(
                $_POST['notification_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($notificationId !== false && $notificationId > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE notifications
                     SET is_read = 1
                     WHERE notification_id = :notification_id
                       AND user_id = :user_id
                       AND centre_id = :centre_id"
                );

                $stmt->execute([
                    'notification_id' => $notificationId,
                    'user_id' => $userId,
                    'centre_id' => $centreId
                ]);
            }
        } elseif ($centreId > 0 && $action === 'mark_all_read') {
            $stmt = $pdo->prepare(
                "UPDATE notifications
                 SET is_read = 1
                 WHERE user_id = :user_id
                   AND centre_id = :centre_id
                   AND is_read = 0"
            );

            $stmt->execute([
                'user_id' => $userId,
                'centre_id' => $centreId
            ]);
        }

        ntRedirect($filter, $centreId);
    }

    /*
     * Fetch notifications for the selected centre.
     */
    if ($centreId > 0) {

        /* Count all unread notifications */
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM notifications
             WHERE user_id = :user_id
               AND centre_id = :centre_id
               AND is_read = 0"
        );

        $stmt->execute([
            'user_id' => $userId,
            'centre_id' => $centreId
        ]);

        $unreadCount = (int)$stmt->fetchColumn();

        /* Count notifications matching the selected filter */
        $countSql = "
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = :user_id
              AND centre_id = :centre_id
        ";

        $countParams = [
            'user_id' => $userId,
            'centre_id' => $centreId
        ];

        if ($filter === 'UNREAD') {
            $countSql .= " AND is_read = 0";
        } elseif (isset($typeLabels[$filter])) {
            $countSql .= " AND notification_type = :type";
            $countParams['type'] = $filter;
        }

        $stmt = $pdo->prepare($countSql);
        $stmt->execute($countParams);
        $totalCount = (int)$stmt->fetchColumn();

        /*
         * IMPORTANT:
         * These column names match your actual notifications table.
         */
        $sql = "
            SELECT
                notification_id,
                title_en,
                title_gu,
                message_en,
                message_gu,
                notification_type,
                is_read,
                created_at
            FROM notifications
            WHERE user_id = :user_id
              AND centre_id = :centre_id
        ";

        $params = [
            'user_id' => $userId,
            'centre_id' => $centreId
        ];

        if ($filter === 'UNREAD') {
            $sql .= " AND is_read = 0";
        } elseif (isset($typeLabels[$filter])) {
            $sql .= " AND notification_type = :type";
            $params['type'] = $filter;
        }

        $sql .= "
            ORDER BY is_read ASC, created_at DESC,
                     notification_id DESC
            LIMIT 100
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log('Worker notifications error: ' . $e->getMessage());

    $errors[] = ntText(
        'Unable to load notifications. Please check your database tables and columns.',
        'સૂચનાઓ લોડ થઈ શકી નથી. કૃપા કરીને ડેટાબેઝના ટેબલ અને કોલમ તપાસો.'
    );
}

/* Sidebar menu */
$menuItems = [
    ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
    ['receive-stock.php', 'Receive Stock', 'સ્ટોક મેળવો'],
    ['daily-consumption.php', 'Daily Consumption', 'દૈનિક વપરાશ'],
    ['distribution.php', 'Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ'],
    ['current-stock.php', 'Current Stock', 'હાલનો સ્ટોક'],
    ['stock-history.php', 'Stock History', 'સ્ટોકનો ઇતિહાસ'],
    ['reports.php', 'Reports', 'અહેવાલો'],
    ['notifications.php', 'Notifications', 'સૂચનાઓ'],
    ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
];
?>

<!DOCTYPE html>
<html lang="<?= ntEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= ntEscape(ntText('Notifications', 'સૂચનાઓ')) ?> | AnnaSetu
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=3"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/receive-stock.css?v=2"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/notifications.css?v=2"
    >
</head>

<body>
<div class="dashboard-layout">

    <aside class="sidebar">
        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <?= ntEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= ntEscape($fullName) ?></strong>
                <small><?= ntEscape(ntText('Worker', 'કાર્યકર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($menuItems as $menu): ?>
                <a
                    href="<?= ntEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                    class="menu-link <?= $menu[0] === 'notifications.php' ? 'active' : '' ?>"
                >
                    <span><?= ntEscape(ntText($menu[1], $menu[2])) ?></span>

                    <?php if (
                        $menu[0] === 'notifications.php' &&
                        $unreadCount > 0
                    ): ?>
                        <span class="notification-menu-count">
                            <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= ntEscape(ntText('Logout', 'લૉગઆઉટ')) ?>
        </a>
    </aside>

    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content notification-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/worker/dashboard.php">
                &larr;
                <?= ntEscape(ntText(
                    'Back to Dashboard',
                    'ડેશબોર્ડ પર પાછા જાઓ'
                )) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= ntEscape(ntText('Notifications', 'સૂચનાઓ')) ?>
                </h1>

                <p>
                    <?= ntEscape(ntText(
                        'View stock alerts, expiry alerts and important messages.',
                        'સ્ટોકની ચેતવણીઓ, સમાપ્તિની ચેતવણીઓ અને મહત્વપૂર્ણ સંદેશા જુઓ.'
                    )) ?>
                </p>
            </div>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger">
                    <?= ntEscape($error) ?>
                </div>
            <?php endforeach; ?>

            <?php if (empty($centres)): ?>

                <div class="alert alert-warning">
                    <?= ntEscape(ntText(
                        'No centre is assigned to your account.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી.'
                    )) ?>
                </div>

            <?php else: ?>

                <!-- Notification statistics -->
                <section class="notification-stats">

                    <div class="notification-stat">
                        <span class="notification-stat-icon">🔔</span>
                        <div>
                            <p><?= ntEscape(ntText('Unread', 'ન વાંચેલી')) ?></p>
                            <h3><?= $unreadCount ?></h3>
                        </div>
                    </div>

                    <div class="notification-stat">
                        <span class="notification-stat-icon">📋</span>
                        <div>
                            <p>
                                <?= ntEscape(ntText(
                                    'Matching Notifications',
                                    'મેળ ખાતી સૂચનાઓ'
                                )) ?>
                            </p>
                            <h3><?= $totalCount ?></h3>
                        </div>
                    </div>

                </section>

                <section class="dashboard-section notification-panel">

                    <div class="section-heading notification-heading">
                        <div>
                            <h3>
                                <?= ntEscape(ntText(
                                    'Your Notifications',
                                    'તમારી સૂચનાઓ'
                                )) ?>
                            </h3>

                            <p>
                                <?= ntEscape(ntText(
                                    'Newest messages appear first.',
                                    'નવા સંદેશા પહેલા દેખાય છે.'
                                )) ?>
                            </p>
                        </div>

                        <?php if ($unreadCount > 0): ?>
                            <form method="POST">
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= ntEscape($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="mark_all_read"
                                >

                                <button
                                    class="btn notification-mark-all"
                                    type="submit"
                                >
                                    <?= ntEscape(ntText(
                                        'Mark all as read',
                                        'બધી વાંચેલી કરો'
                                    )) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Filters -->
                    <form method="GET" class="notification-filters">

                        <?php if (count($centres) > 1): ?>
                            <div class="notification-filter-centre">
                                <label for="centre_id">
                                    <?= ntEscape(ntText('Centre', 'કેન્દ્ર')) ?>
                                </label>

                                <select
                                    name="centre_id"
                                    id="centre_id"
                                    class="form-select"
                                >
                                    <?php foreach ($centres as $centre): ?>
                                        <option
                                            value="<?= (int)$centre['centre_id'] ?>"
                                            <?= (int)$centre['centre_id'] === $centreId ? 'selected' : '' ?>
                                        >
                                            <?= ntEscape($centre['centre_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="notification-filter-type">
                            <label for="filter">
                                <?= ntEscape(ntText('Show', 'બતાવો')) ?>
                            </label>

                            <select
                                name="filter"
                                id="filter"
                                class="form-select"
                            >
                                <option
                                    value="ALL"
                                    <?= $filter === 'ALL' ? 'selected' : '' ?>
                                >
                                    <?= ntEscape(ntText(
                                        'All notifications',
                                        'બધી સૂચનાઓ'
                                    )) ?>
                                </option>

                                <option
                                    value="UNREAD"
                                    <?= $filter === 'UNREAD' ? 'selected' : '' ?>
                                >
                                    <?= ntEscape(ntText(
                                        'Unread only',
                                        'ફક્ત ન વાંચેલી'
                                    )) ?>
                                </option>

                                <?php foreach ($typeLabels as $code => $label): ?>
                                    <option
                                        value="<?= ntEscape($code) ?>"
                                        <?= $filter === $code ? 'selected' : '' ?>
                                    >
                                        <?= ntEscape($label[0]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="btn submit-button" type="submit">
                            <?= ntEscape(ntText(
                                'Apply Filter',
                                'ફિલ્ટર લાગુ કરો'
                            )) ?>
                        </button>

                    </form>

                    <!-- Notification list -->
                    <?php if (empty($notifications)): ?>

                        <div class="notification-empty">
                            <div class="notification-empty-icon">🔔</div>

                            <h4>
                                <?= ntEscape(ntText(
                                    'No notifications found',
                                    'કોઈ સૂચના મળી નથી'
                                )) ?>
                            </h4>

                            <p>
                                <?= ntEscape(ntText(
                                    'There are no notifications for this filter.',
                                    'આ ફિલ્ટર માટે કોઈ સૂચના ઉપલબ્ધ નથી.'
                                )) ?>
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="notification-list">

                            <?php foreach ($notifications as $notification): ?>

                                <?php
                                $type = strtoupper(
                                    $notification['notification_type'] ?? 'SYSTEM'
                                );

                                $typeInfo = $typeLabels[$type]
                                    ?? $typeLabels['SYSTEM'];

                                $isRead = (int)$notification['is_read'] === 1;

                                $icon = [
                                    'LOW_STOCK' => '📦',
                                    'NEAR_EXPIRY' => '⏰',
                                    'EXPIRED' => '⚠️',
                                    'PENDING_VERIFICATION' => '✓',
                                    'SYSTEM' => '🔔'
                                ][$type] ?? '🔔';

                                $title = $language === 'gu'
                                    ? ($notification['title_gu'] ?: $notification['title_en'])
                                    : ($notification['title_en'] ?: $notification['title_gu']);

                                $message = $language === 'gu'
                                    ? ($notification['message_gu'] ?? '')
                                    : ($notification['message_en'] ?? '');

                                if (trim($message) === '') {
                                    $message = $language === 'gu'
                                        ? ($notification['message_en'] ?? '')
                                        : ($notification['message_gu'] ?? '');
                                }
                                ?>

                                <article class="notification-item <?= $isRead ? 'is-read' : 'is-unread' ?>">

                                    <div class="notification-icon <?= ntEscape($typeInfo[1]) ?>">
                                        <?= $icon ?>
                                    </div>

                                    <div class="notification-body">

                                        <div class="notification-title-row">
                                            <h4><?= ntEscape($title) ?></h4>

                                            <?php if (!$isRead): ?>
                                                <span
                                                    class="unread-dot"
                                                    title="<?= ntEscape(ntText('Unread', 'ન વાંચેલી')) ?>"
                                                ></span>
                                            <?php endif; ?>
                                        </div>

                                        <p><?= nl2br(ntEscape($message)) ?></p>

                                        <div class="notification-meta">

                                            <span class="notification-type <?= ntEscape($typeInfo[1]) ?>">
                                                <?= ntEscape($typeInfo[0]) ?>
                                            </span>

                                            <span>
                                                <?= ntEscape(ntDate($notification['created_at'])) ?>
                                            </span>

                                            <span>
                                                <?= ntEscape(ntText(
                                                    $isRead ? 'Read' : 'Unread',
                                                    $isRead ? 'વાંચેલી' : 'ન વાંચેલી'
                                                )) ?>
                                            </span>

                                        </div>
                                    </div>

                                    <?php if (!$isRead): ?>
                                        <form method="POST" class="notification-action">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= ntEscape($csrfToken) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="mark_read"
                                            >

                                            <input
                                                type="hidden"
                                                name="notification_id"
                                                value="<?= (int)$notification['notification_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="notification-read-btn"
                                            >
                                                <?= ntEscape(ntText(
                                                    'Mark read',
                                                    'વાંચેલી કરો'
                                                )) ?>
                                            </button>

                                        </form>
                                    <?php endif; ?>

                                </article>

                            <?php endforeach; ?>

                        </div>

                        <?php if ($totalCount > 100): ?>
                            <p class="notification-limit-note">
                                <?= ntEscape(ntText(
                                    'Showing the latest 100 matching notifications.',
                                    'તાજેતરની 100 મેળ ખાતી સૂચનાઓ બતાવવામાં આવી છે.'
                                )) ?>
                            </p>
                        <?php endif; ?>

                    <?php endif; ?>

                </section>

            <?php endif; ?>

        </div>
    </main>
</div>

</body>
</html>
