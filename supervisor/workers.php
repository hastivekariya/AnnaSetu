<?php
// supervisor/dashboard.php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['supervisor']);

$role = 'supervisor';
$userId = (int)($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'Supervisor';
$language = $_SESSION['language'] ?? $_SESSION['preferred_language'] ?? 'gu';

function smEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function smText(string $en, string $gu): string {
    return (($_SESSION['language'] ?? $_SESSION['preferred_language'] ?? 'gu') === 'gu') ? $gu : $en;
}

function smNumber($value): string {
    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
}

function smItemName(array $row): string {
    if (($_SESSION['language'] ?? $_SESSION['preferred_language'] ?? 'gu') === 'gu'
        && !empty($row['item_name_gu'])) {
        return (string)$row['item_name_gu'];
    }
    return (string)($row['item_name_en'] ?? '');
}

/*
 * Create unique named placeholders for each SQL IN (...) condition.
 * This avoids PDO HY093 errors caused by reusing the same placeholder.
 */
function smMakeInList(array $ids, string $prefix, array &$params): string {
    $holders = [];
    foreach (array_values($ids) as $i => $id) {
        $key = $prefix . $i;
        $holders[] = ':' . $key;
        $params[$key] = (int)$id;
    }
    return $holders ? implode(',', $holders) : 'NULL';
}

$centres = [];
$staff = [];
$pending = [];
$recent = [];
$lowStock = [];
$stats = [
    'centres' => 0,
    'workers' => 0,
    'helpers' => 0,
    'pending' => 0,
    'low_stock' => 0,
    'expired' => 0
];
$dbError = '';

try {
    // Find centres explicitly assigned to this supervisor.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.centre_id, c.centre_name, c.centre_code
         FROM anganwadi_centres c
         INNER JOIN centre_assignments ca ON ca.centre_id = c.centre_id
         WHERE ca.user_id = :user_id
           AND ca.is_active = 1
           AND c.is_active = 1
         ORDER BY c.centre_name"
    );
    $stmt->execute(['user_id' => $userId]);
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no supervisor centre assignment exists, show all active centres.
    // For production, replace this fallback with your project's proper scope.
    if (!$centres) {
        $stmt = $pdo->query(
            "SELECT centre_id, centre_name, centre_code
             FROM anganwadi_centres
             WHERE is_active = 1
             ORDER BY centre_name"
        );
        $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $centreIds = array_values(array_unique(array_map(
        'intval',
        array_column($centres, 'centre_id')
    )));
    $stats['centres'] = count($centreIds);

    if ($centreIds) {
        /*
         * Worker and Helper monitoring.
         * The roles table connects users.role_id to roles.role_id.
         */
        $staffParams = [];
        $staffListA = smMakeInList($centreIds, 'staff_a_', $staffParams);
        $staffListB = smMakeInList($centreIds, 'staff_b_', $staffParams);

        $stmt = $pdo->prepare(
            "SELECT DISTINCT
                    u.user_id,
                    u.full_name,
                    LOWER(r.role_name) AS role,
                    COALESCE(c.centre_name, 'Not assigned') AS centre_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN centre_assignments ca
               ON ca.user_id = u.user_id AND ca.is_active = 1
             LEFT JOIN anganwadi_centres c
               ON c.centre_id = COALESCE(ca.centre_id, u.centre_id)
             WHERE UPPER(r.role_name) IN ('WORKER', 'HELPER')
               AND u.is_active = 1
               AND (
                    c.centre_id IN ($staffListA)
                    OR u.centre_id IN ($staffListB)
               )
             ORDER BY r.role_name, u.full_name"
        );
        $stmt->execute($staffParams);
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($staff as $person) {
            if ($person['role'] === 'worker') {
                $stats['workers']++;
            } elseif ($person['role'] === 'helper') {
                $stats['helpers']++;
            }
        }

        // Build a fresh parameter list for each query to avoid HY093.
        $p = [];
        $in = smMakeInList($centreIds, 'pending_count_', $p);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM stock_transactions
             WHERE centre_id IN ($in)
               AND UPPER(TRIM(status)) = 'PENDING'"
        );
        $stmt->execute($p);
        $stats['pending'] = (int)$stmt->fetchColumn();

        $p = [];
        $in = smMakeInList($centreIds, 'pending_rows_', $p);
        $stmt = $pdo->prepare(
            "SELECT t.transaction_id, t.transaction_type, t.quantity, t.status,
                    t.transaction_date, f.item_name_en, f.item_name_gu, f.unit,
                    NULL AS user_name, c.centre_name
             FROM stock_transactions t
             INNER JOIN food_items f ON f.item_id = t.item_id
             LEFT JOIN anganwadi_centres c ON c.centre_id = t.centre_id
             WHERE t.centre_id IN ($in)
               AND UPPER(TRIM(t.status)) = 'PENDING'
             ORDER BY t.transaction_date ASC, t.transaction_id ASC
             LIMIT 10"
        );
        $stmt->execute($p);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $p = [];
        $in = smMakeInList($centreIds, 'recent_rows_', $p);
        $stmt = $pdo->prepare(
            "SELECT t.transaction_id, t.transaction_type, t.quantity, t.status,
                    t.transaction_date, f.item_name_en, f.item_name_gu, f.unit,
                    NULL AS user_name, c.centre_name
             FROM stock_transactions t
             INNER JOIN food_items f ON f.item_id = t.item_id
             LEFT JOIN anganwadi_centres c ON c.centre_id = t.centre_id
             WHERE t.centre_id IN ($in)
             ORDER BY t.transaction_date DESC, t.transaction_id DESC
             LIMIT 10"
        );
        $stmt->execute($p);
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stock from approved receipts minus consumption/distribution.
        $p = [];
        $in = smMakeInList($centreIds, 'low_stock_', $p);
        $stmt = $pdo->prepare(
            "SELECT c.centre_name, f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                    COALESCE(cis.minimum_stock, f.default_min_stock) AS minimum_stock,
                    COALESCE(SUM(
                        CASE
                            WHEN UPPER(TRIM(t.transaction_type)) = 'RECEIPT'
                                THEN t.quantity
                            WHEN UPPER(TRIM(t.transaction_type)) IN ('CONSUMPTION', 'DISTRIBUTION')
                                THEN -t.quantity
                            ELSE 0
                        END
                    ), 0) AS balance
             FROM anganwadi_centres c
             CROSS JOIN food_items f
             LEFT JOIN centre_item_settings cis
               ON cis.centre_id = c.centre_id AND cis.item_id = f.item_id
             LEFT JOIN stock_transactions t
               ON t.centre_id = c.centre_id
              AND t.item_id = f.item_id
              AND UPPER(TRIM(t.status)) = 'APPROVED'
             WHERE c.centre_id IN ($in)
               AND f.is_active = 1
             GROUP BY c.centre_id, c.centre_name, f.item_id,
                      f.item_name_en, f.item_name_gu, f.unit,
                      cis.minimum_stock, f.default_min_stock
             HAVING COALESCE(cis.minimum_stock, f.default_min_stock) > 0
                AND balance <= minimum_stock
             ORDER BY balance ASC
             LIMIT 10"
        );
        $stmt->execute($p);
        $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['low_stock'] = count($lowStock);

        // Count expired batches that still have approved stock remaining.
        $p = [];
        $in = smMakeInList($centreIds, 'expired_', $p);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM (
                 SELECT b.batch_id, b.expiry_date,
                        SUM(
                            CASE
                                WHEN UPPER(TRIM(t.transaction_type)) = 'RECEIPT'
                                    THEN t.quantity
                                WHEN UPPER(TRIM(t.transaction_type)) IN ('CONSUMPTION', 'DISTRIBUTION')
                                    THEN -t.quantity
                                ELSE 0
                            END
                        ) AS remaining
                 FROM stock_batches b
                 LEFT JOIN stock_transactions t
                   ON t.batch_id = b.batch_id
                  AND UPPER(TRIM(t.status)) = 'APPROVED'
                 WHERE b.centre_id IN ($in)
                   AND b.expiry_date < CURDATE()
                 GROUP BY b.batch_id, b.expiry_date
                 HAVING remaining > 0
             ) expired_batches"
        );
        $stmt->execute($p);
        $stats['expired'] = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('Supervisor dashboard error: ' . $e->getMessage());
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="<?= smEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supervisor Dashboard | AnnaSetu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css?v=3">
    <style>
        .monitor-content{padding:24px}
        .monitor-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:16px;margin:20px 0}
        .monitor-card,.monitor-panel{background:#fff;padding:20px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:20px}
        .monitor-card p{margin:0 0 8px;color:#555}
        .monitor-card h3{margin:0;font-size:28px}
        .monitor-panel h3{font-size:20px;margin-bottom:16px}
        .monitor-table th,.monitor-table td{vertical-align:middle}
        .monitor-section{margin-top:24px}
        .role-pill,.status-pill{display:inline-block;padding:5px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#edf2ff;color:#253b80}
        .status-pending{background:#fff0cc;color:#805500}
        .status-approved{background:#d9f7e5;color:#126534}
        .status-rejected{background:#ffe0e0;color:#9b1c1c}
        .empty-message{padding:18px;background:#f7f8fa;border-radius:8px;color:#555}
        @media(max-width:576px){.monitor-content{padding:12px}.monitor-card,.monitor-panel{padding:14px}}
    </style>
</head>
<body>
<div class="dashboard-layout">
    <aside class="sidebar">
        <div class="sidebar-brand"><h3>અન્નસેતુ</h3><p>AnnaSetu</p></div>
        <div class="sidebar-user">
            <div class="user-avatar"><?= smEscape(strtoupper(substr($fullName, 0, 1))) ?></div>
            <div><strong><?= smEscape($fullName) ?></strong><small><?= smEscape(smText('Supervisor', 'સુપરવાઇઝર')) ?></small></div>
        </div>
        <nav class="sidebar-menu">
            <a class="menu-link active" href="<?= APP_URL ?>/supervisor/dashboard.php"><?= smEscape(smText('Dashboard', 'ડેશબોર્ડ')) ?></a>
            <a class="menu-link" href="<?= APP_URL ?>/supervisor/"><?= smEscape(smText('Supervisor Home', 'સુપરવાઇઝર હોમ')) ?></a>
            <a class="menu-link" href="<?= APP_URL ?>/supervisor/approvals.php"><?= smEscape(smText('Pending Approvals', 'બાકી મંજૂરીઓ')) ?></a>
            <a class="menu-link" href="<?= APP_URL ?>/supervisor/reports.php"><?= smEscape(smText('Reports', 'અહેવાલો')) ?></a>
            <a class="menu-link" href="<?= APP_URL ?>/supervisor/stock-history.php"><?= smEscape(smText('Stock History', 'સ્ટોકનો ઇતિહાસ')) ?></a>
        </nav>
        <a class="logout-link" href="<?= APP_URL ?>/logout.php"><?= smEscape(smText('Logout', 'લૉગઆઉટ')) ?></a>
    </aside>

    <main class="dashboard-main">
        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>
        <div class="monitor-content">
            <h2><?= smEscape(smText('Supervisor Monitoring Dashboard', 'સુપરવાઇઝર મોનિટરિંગ ડેશબોર્ડ')) ?></h2>
            <p class="text-muted"><?= smEscape(smText('Monitor workers, helpers, food stock and pending entries.', 'કાર્યકરો, મદદનીશો, ખાદ્ય સ્ટોક અને બાકી નોંધો પર નજર રાખો.')) ?></p>

            <?php if ($dbError !== ''): ?>
                <div class="alert alert-danger">
                    <strong><?= smEscape(smText('Database error', 'ડેટાબેઝમાં ભૂલ')) ?>:</strong>
                    <?= smEscape($dbError) ?>
                    <br><?= smEscape(smText('Please verify the table and column names in your database.', 'કૃપા કરીને ડેટાબેઝમાં ટેબલ અને કોલમનાં નામ તપાસો.')) ?>
                </div>
            <?php endif; ?>

            <div class="monitor-panel">
                <strong><?= smEscape(smText('Monitoring centres', 'મોનિટરિંગ કેન્દ્રો')) ?>:</strong>
                <?php if ($centres): ?>
                    <?php foreach ($centres as $i => $centre): ?><?= $i ? ' · ' : '' ?><?= smEscape($centre['centre_name']) ?><?php endforeach; ?>
                <?php else: ?>
                    <?= smEscape(smText('No active centres found.', 'કોઈ સક્રિય કેન્દ્ર મળ્યું નથી.')) ?>
                <?php endif; ?>
            </div>

            <section class="monitor-grid">
                <?php
                $cards = [
                    ['Active Centres', 'સક્રિય કેન્દ્રો', 'centres'],
                    ['Workers', 'કાર્યકરો', 'workers'],
                    ['Helpers', 'મદદનીશો', 'helpers'],
                    ['Pending Entries', 'બાકી નોંધો', 'pending'],
                    ['Low-Stock Items', 'ઓછા સ્ટોકની વસ્તુઓ', 'low_stock'],
                    ['Expired Batches', 'સમાપ્ત બેચ', 'expired']
                ];
                foreach ($cards as $card):
                ?>
                    <div class="monitor-card">
                        <p><?= smEscape(smText($card[0], $card[1])) ?></p>
                        <h3><?= (int)$stats[$card[2]] ?></h3>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="monitor-panel monitor-section">
                <h3><?= smEscape(smText('Worker and Helper Monitoring', 'કાર્યકર અને મદદનીશ મોનિટરિંગ')) ?></h3>
                <?php if (!$staff): ?>
                    <div class="empty-message"><?= smEscape(smText('No workers or helpers were found for these centres.', 'આ કેન્દ્રો માટે કોઈ કાર્યકર કે મદદનીશ મળ્યા નથી.')) ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover monitor-table">
                        <thead><tr><th><?= smEscape(smText('Name', 'નામ')) ?></th><th><?= smEscape(smText('Role', 'ભૂમિકા')) ?></th><th><?= smEscape(smText('Centre', 'કેન્દ્ર')) ?></th></tr></thead>
                        <tbody><?php foreach ($staff as $person): ?><tr>
                            <td><?= smEscape($person['full_name']) ?></td>
                            <td><span class="role-pill"><?= smEscape($person['role'] === 'helper' ? smText('Helper', 'મદદનીશ') : smText('Worker', 'કાર્યકર')) ?></span></td>
                            <td><?= smEscape($person['centre_name']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <section class="monitor-panel monitor-section">
                <h3><?= smEscape(smText('Entries Waiting for Approval', 'મંજૂરી માટે બાકી નોંધો')) ?></h3>
                <?php if (!$pending): ?>
                    <div class="empty-message"><?= smEscape(smText('There are no pending entries.', 'કોઈ નોંધ મંજૂરી માટે બાકી નથી.')) ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover monitor-table">
                        <thead><tr><th><?= smEscape(smText('Date', 'તારીખ')) ?></th><th><?= smEscape(smText('Centre', 'કેન્દ્ર')) ?></th><th><?= smEscape(smText('Food', 'ખાદ્ય વસ્તુ')) ?></th><th><?= smEscape(smText('Type', 'પ્રકાર')) ?></th><th><?= smEscape(smText('Quantity', 'જથ્થો')) ?></th><th><?= smEscape(smText('Recorded By', 'નોંધનાર')) ?></th></tr></thead>
                        <tbody><?php foreach ($pending as $row): ?><tr>
                            <td><?= smEscape($row['transaction_date'] ? date('d-m-Y', strtotime($row['transaction_date'])) : '-') ?></td>
                            <td><?= smEscape($row['centre_name'] ?? '-') ?></td><td><?= smEscape(smItemName($row)) ?></td>
                            <td><?= smEscape($row['transaction_type']) ?></td>
                            <td><?= smEscape(smNumber($row['quantity'])) ?> <?= smEscape($row['unit']) ?></td>
                            <td><?= smEscape($row['user_name'] ?? '-') ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                    <a class="btn btn-primary" href="<?= APP_URL ?>/supervisor/approvals.php"><?= smEscape(smText('Review Entries', 'નોંધો તપાસો')) ?></a>
                <?php endif; ?>
            </section>

            <section class="monitor-panel monitor-section">
                <h3><?= smEscape(smText('Low Stock Alerts', 'ઓછા સ્ટોકની ચેતવણીઓ')) ?></h3>
                <?php if (!$lowStock): ?>
                    <div class="empty-message"><?= smEscape(smText('No low-stock items were found.', 'ઓછા સ્ટોકની કોઈ વસ્તુ મળી નથી.')) ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover monitor-table">
                        <thead><tr><th><?= smEscape(smText('Centre', 'કેન્દ્ર')) ?></th><th><?= smEscape(smText('Food', 'ખાદ્ય વસ્તુ')) ?></th><th><?= smEscape(smText('Available', 'ઉપલબ્ધ')) ?></th><th><?= smEscape(smText('Minimum', 'ન્યૂનતમ')) ?></th></tr></thead>
                        <tbody><?php foreach ($lowStock as $row): ?><tr>
                            <td><?= smEscape($row['centre_name']) ?></td><td><?= smEscape(smItemName($row)) ?></td>
                            <td><?= smEscape(smNumber(max(0, (float)$row['balance']))) ?> <?= smEscape($row['unit']) ?></td>
                            <td><?= smEscape(smNumber($row['minimum_stock'])) ?> <?= smEscape($row['unit']) ?></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>

            <section class="monitor-panel monitor-section">
                <h3><?= smEscape(smText('Recent Stock Activity', 'સ્ટોકની તાજેતરની પ્રવૃત્તિ')) ?></h3>
                <?php if (!$recent): ?>
                    <div class="empty-message"><?= smEscape(smText('No stock activity has been recorded.', 'સ્ટોકની કોઈ પ્રવૃત્તિ નોંધાઈ નથી.')) ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-hover monitor-table">
                        <thead><tr><th><?= smEscape(smText('Date', 'તારીખ')) ?></th><th><?= smEscape(smText('Centre', 'કેન્દ્ર')) ?></th><th><?= smEscape(smText('Food', 'ખાદ્ય વસ્તુ')) ?></th><th><?= smEscape(smText('Transaction', 'વ્યવહાર')) ?></th><th><?= smEscape(smText('Quantity', 'જથ્થો')) ?></th><th><?= smEscape(smText('Recorded By', 'નોંધનાર')) ?></th><th><?= smEscape(smText('Status', 'સ્થિતિ')) ?></th></tr></thead>
                        <tbody><?php foreach ($recent as $row): $status = strtoupper(trim((string)$row['status'])); ?><tr>
                            <td><?= smEscape($row['transaction_date'] ? date('d-m-Y', strtotime($row['transaction_date'])) : '-') ?></td>
                            <td><?= smEscape($row['centre_name'] ?? '-') ?></td><td><?= smEscape(smItemName($row)) ?></td>
                            <td><?= smEscape($row['transaction_type']) ?></td>
                            <td><?= smEscape(smNumber($row['quantity'])) ?> <?= smEscape($row['unit']) ?></td>
                            <td><?= smEscape($row['user_name'] ?? '-') ?></td>
                            <td><span class="status-pill status-<?= smEscape(strtolower($status)) ?>"><?= smEscape($status) ?></span></td>
                        </tr><?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
