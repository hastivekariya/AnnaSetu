<?php
/*
 * supervisor/low-stock.php
 * Low-stock and out-of-stock alerts for the supervisor's assigned centres.
 *   Out of stock : approved balance <= 0
 *   Low stock    : approved balance > 0 and <= minimum stock
 * Minimum stock = centre_item_settings.minimum_stock when set,
 *                 otherwise food_items.default_min_stock.
 * Items with a minimum of 0 are never flagged.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['supervisor']);

$role = strtolower($_SESSION['role'] ?? 'supervisor');
$fullName = $_SESSION['full_name'] ?? 'Supervisor';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];


const LS_MAX_ROWS = 1000;
const LS_HISTORY_ROWS = 10;

$errors = [];
$centres = [];
$validCentreIds = [];
$alerts = [];
$summary = ['total' => 0, 'out' => 0, 'low' => 0, 'centres' => 0];

/* -------------------- Display Helpers -------------------- */

function lsEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lsText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function lsNumber($value): string
{
    $text = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $text === '' || $text === '-0' ? '0' : $text;
}

/* Centre scope as named placeholders */
function lsCentreScope(array $ids, int $single, string $column): array
{
    $use = $single > 0 ? [$single] : $ids;
    $names = [];
    $params = [];

    foreach (array_values($use) as $i => $id) {
        $names[] = ':cs' . $i;
        $params['cs' . $i] = $id;
    }

    return [$column . ' IN (' . implode(',', $names) . ')', $params];
}

$typeLabels = [
    'RECEIPT' => [lsText('Received', 'મળેલ'), 'type-receipt'],
    'CONSUMPTION' => [lsText('Consumed', 'વપરાયેલ'), 'type-consumption'],
    'DISTRIBUTION' => [lsText('Distributed', 'વિતરિત'), 'type-distribution'],
    'ADJUSTMENT' => [lsText('Adjustment', 'સુધારો'), 'type-adjustment']
];

$statusLabels = [
    'PENDING' => [lsText('Pending', 'બાકી'), 'status-pending'],
    'APPROVED' => [lsText('Approved', 'મંજૂર'), 'status-approved'],
    'REJECTED' => [lsText('Rejected', 'નામંજૂર'), 'status-rejected']
];

/* -------------------- Load supervisor's centres -------------------- */

try {
    $stmt = $pdo->prepare(
        "SELECT c.centre_id, c.centre_name, c.centre_code
         FROM anganwadi_centres c
         INNER JOIN centre_assignments ca
            ON ca.centre_id = c.centre_id
         WHERE ca.user_id = :user_id
           AND ca.is_active = 1
           AND c.is_active = 1
         ORDER BY c.centre_name"
    );

    $stmt->execute(['user_id' => $userId]);
    $centres = $stmt->fetchAll();
    $validCentreIds = array_map('intval', array_column($centres, 'centre_id'));

} catch (PDOException $e) {
    error_log('Low stock centre load error: ' . $e->getMessage());
    $errors[] = lsText(
        'Unable to load your centres. Please check the database.',
        'તમારા કેન્દ્રો લોડ થઈ શક્યા નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* -------------------- Read Filters -------------------- */

$centreFilter = (int)($_GET['centre_id'] ?? 0);
if (!in_array($centreFilter, $validCentreIds, true)) {
    $centreFilter = 0;
}

$statusFilter = (string)($_GET['status'] ?? '');
if (!in_array($statusFilter, ['out', 'low'], true)) {
    $statusFilter = '';
}

$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

function lsQuery(array $extra = []): string
{
    global $centreFilter, $statusFilter, $search;

    return http_build_query(array_filter(array_merge([
        'centre_id' => $centreFilter ?: '',
        'status' => $statusFilter,
        'q' => $search
    ], $extra), static function ($v) {
        return $v !== '' && $v !== null;
    }));
}

/* -------------------- JSON: stock history for one item -------------------- */

if (isset($_GET['history']) && !empty($validCentreIds)) {
    header('Content-Type: application/json; charset=UTF-8');

    $hCentre = (int)($_GET['centre_id'] ?? 0);
    $hItem = (int)($_GET['item_id'] ?? 0);

    if (!in_array($hCentre, $validCentreIds, true) || $hItem <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT t.transaction_date, t.transaction_type, t.quantity,
                    t.status, f.unit, u.full_name AS user_name
             FROM stock_transactions t
             INNER JOIN food_items f ON f.item_id = t.item_id
             LEFT JOIN users u ON u.user_id = t.created_by
             WHERE t.centre_id = :centre_id
               AND t.item_id = :item_id
               AND t.status IN ('APPROVED', 'PENDING')
             ORDER BY t.transaction_date DESC, t.transaction_id DESC
             LIMIT " . (int)LS_HISTORY_ROWS
        );
        $stmt->execute(['centre_id' => $hCentre, 'item_id' => $hItem]);

        $out = [];

        foreach ($stmt->fetchAll() as $r) {
            $type = strtoupper($r['transaction_type']);
            $status = strtoupper($r['status']);

            $out[] = [
                'date' => date('d-m-Y', strtotime($r['transaction_date'])),
                'type' => ($typeLabels[$type] ?? [$type, 'type-adjustment'])[0],
                'type_class' => ($typeLabels[$type] ?? [$type, 'type-adjustment'])[1],
                'quantity' => lsNumber($r['quantity']) . ' ' . $r['unit'],
                'status' => ($statusLabels[$status] ?? [$status, 'status-pending'])[0],
                'status_class' => ($statusLabels[$status] ?? [$status, 'status-pending'])[1],
                'by' => $r['user_name'] ?? '-'
            ];
        }

        echo json_encode(['rows' => $out], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('Low stock history error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }

    exit;
}

/* -------------------- Load alerts -------------------- */

if (!empty($validCentreIds)) {
    try {
        [$scopeSql, $params] = lsCentreScope($validCentreIds, $centreFilter, 'c.centre_id');

        $sql =
            "SELECT c.centre_id, c.centre_name, c.centre_code,
                    f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                    COALESCE(cis.minimum_stock, f.default_min_stock) AS min_stock,
                    COALESCE(SUM(CASE
                        WHEN UPPER(t.transaction_type) = 'RECEIPT' THEN t.quantity
                        WHEN UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION') THEN -t.quantity
                        ELSE 0
                    END), 0) AS balance,
                    MAX(CASE
                        WHEN UPPER(t.transaction_type) = 'RECEIPT' THEN t.transaction_date
                    END) AS last_received
             FROM anganwadi_centres c
             CROSS JOIN food_items f
             LEFT JOIN centre_item_settings cis
                ON cis.centre_id = c.centre_id
               AND cis.item_id = f.item_id
             LEFT JOIN stock_transactions t
    ON t.centre_id = c.centre_id
   AND t.item_id = f.item_id
   AND UPPER(TRIM(t.status)) = 'APPROVED'
             WHERE " . $scopeSql . "
               AND f.is_active = 1";

        if ($search !== '') {
            $sql .= " AND (f.item_name_en LIKE :q1 OR f.item_name_gu LIKE :q2)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        $sql .= " GROUP BY c.centre_id, c.centre_name, c.centre_code,
                           f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                           cis.minimum_stock, f.default_min_stock
                  HAVING
    COALESCE(cis.minimum_stock, f.default_min_stock) > 0
    AND
    COALESCE(SUM(
        CASE
            WHEN UPPER(t.transaction_type) = 'RECEIPT'
                THEN t.quantity
            WHEN UPPER(t.transaction_type)
                IN ('CONSUMPTION', 'DISTRIBUTION')
                THEN -t.quantity
            ELSE 0
        END
    ), 0) <= COALESCE(cis.minimum_stock, f.default_min_stock)

ORDER BY
    (
        COALESCE(SUM(
            CASE
                WHEN UPPER(t.transaction_type) = 'RECEIPT'
                    THEN t.quantity
                WHEN UPPER(t.transaction_type)
                    IN ('CONSUMPTION', 'DISTRIBUTION')
                    THEN -t.quantity
                ELSE 0
            END
        ), 0) <= 0
    ) DESC,
    c.centre_name,
    f.item_name_en";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $centresAffected = [];


        foreach ($stmt->fetchAll() as $row) {
            $balance = (float)$row['balance'];
            $min = (float)$row['min_stock'];

            $row['status'] = $balance <= 0 ? 'out' : 'low';
            $row['shortage'] = max($min - max($balance, 0), 0);

            // Summary cards cover the centre scope + search, before the status filter.
            $summary['total']++;
            $summary[$row['status']]++;
            $centresAffected[(int)$row['centre_id']] = true;

            if ($statusFilter === '' || $statusFilter === $row['status']) {
                $alerts[] = $row;
            }
        }

        $summary['centres'] = count($centresAffected);

        /* ---------- CSV export ---------- */
        if (isset($_GET['export'])) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="low-stock-' . date('Y-m-d') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Gujarati

            fputcsv($out, [
                'Centre', 'Food Item (English)', 'Food Item (Gujarati)',
                'Available', 'Minimum', 'Shortage', 'Unit', 'Status', 'Last Received'
            ]);

            // Prevent spreadsheet formula injection.
            $safe = static function ($value) {
                $value = (string)$value;
                return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false)
                    ? "'" . $value
                    : $value;
            };

            foreach ($alerts as $r) {
                fputcsv($out, [
                    $safe($r['centre_name']),
                    $safe($r['item_name_en']),
                    $safe($r['item_name_gu']),
                    lsNumber($r['balance']),
                    lsNumber($r['min_stock']),
                    lsNumber($r['shortage']),
                    $r['unit'],
                    $r['status'] === 'out' ? 'Out of stock' : 'Low stock',
                    $r['last_received'] ? date('d-m-Y', strtotime($r['last_received'])) : ''
                ]);
            }

            fclose($out);
            exit;
        }

    } catch (PDOException $e) {
        error_log('Low stock load error: ' . $e->getMessage());
        $errors[] = lsText(
            'Unable to load stock alerts. Please check the database.',
            'સ્ટોક ચેતવણીઓ લોડ થઈ શકી નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
        );
        echo "Database Error: " . htmlspecialchars($e->getMessage());
    }
}

/* -------------------- Menu -------------------- */

$menuItems = [
    ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
    ['pending-verification.php', 'Pending Verification', 'બાકી ચકાસણી'],
    ['low-stock.php', 'Low Stock Alerts', 'ઓછા સ્ટોકની ચેતવણી'],
    ['expiry-monitoring.php', 'Expiry Monitoring', 'સમાપ્તિ દેખરેખ'],
    ['workers.php', 'Workers and Helpers', 'કાર્યકરો અને મદદનીશો'],
    ['centres.php', 'Anganwadi Centres', 'આંગણવાડી કેન્દ્રો'],
    ['reports.php', 'Reports and Analytics', 'અહેવાલો અને વિશ્લેષણ'],
    ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
];

$hasFilters = ($centreFilter > 0 || $statusFilter !== '' || $search !== '');

$selectedCentreName = lsText('All my centres', 'મારા બધા કેન્દ્રો');

foreach ($centres as $c) {
    if ((int)$c['centre_id'] === $centreFilter) {
        $selectedCentreName = $c['centre_name'] . ' (' . $c['centre_code'] . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= lsEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= lsEscape(lsText('Low Stock Alerts', 'ઓછા સ્ટોકની ચેતવણી')) ?> | AnnaSetu
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
        href="<?= APP_URL ?>/assets/css/low-stock.css?v=1"
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
                <?= lsEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= lsEscape($fullName) ?></strong>
                <small><?= lsEscape(lsText('Supervisor', 'સુપરવાઇઝર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= lsEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'low-stock.php' ? 'active' : '' ?>">
                    <span><?= lsEscape(lsText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= lsEscape(lsText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content alerts-content">

            <a class="back-dashboard no-print"
               href="<?= APP_URL ?>/<?= lsEscape($role) ?>/dashboard.php">
                &larr;
                <?= lsEscape(lsText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= lsEscape(lsText('Low Stock Alerts', 'ઓછા સ્ટોકની ચેતવણી')) ?>
                    / ઓછા સ્ટોકની ચેતવણી
                </h1>

                <p>
                    <?= lsEscape(lsText(
                        'Food items at your assigned centres that are running low or have run out.',
                        'તમારા નિયુક્ત કેન્દ્રોમાં ઓછી થઈ રહેલી અથવા ખતમ થઈ ગયેલી ખાદ્ય વસ્તુઓ.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= lsEscape($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($validCentreIds)): ?>

                <div class="alert alert-warning">
                    <?= lsEscape(lsText(
                        'No centre is assigned to your account. Ask your administrator.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                    )) ?>
                </div>

            <?php else: ?>

                <!-- Summary cards -->
                <section class="alert-stats">

                    <div class="stat-card card-total">
                        <p><?= lsEscape(lsText('Total Alerts', 'કુલ ચેતવણીઓ')) ?></p>
                        <h3><?= (int)$summary['total'] ?></h3>
                    </div>

                    <div class="stat-card card-out">
                        <p><?= lsEscape(lsText('Out of Stock', 'સ્ટોક ખતમ')) ?></p>
                        <h3><?= (int)$summary['out'] ?></h3>
                    </div>

                    <div class="stat-card card-low">
                        <p><?= lsEscape(lsText('Low Stock', 'ઓછો સ્ટોક')) ?></p>
                        <h3><?= (int)$summary['low'] ?></h3>
                    </div>

                    <div class="stat-card card-centres">
                        <p><?= lsEscape(lsText('Centres Affected', 'અસરગ્રસ્ત કેન્દ્રો')) ?></p>
                        <h3><?= (int)$summary['centres'] ?></h3>
                    </div>

                </section>

                <section class="dashboard-section">

                    <div class="section-heading">
                        <h3><?= lsEscape(lsText('Food Stock Status', 'ખાદ્ય સ્ટોકની સ્થિતિ')) ?></h3>
                        <span><?= lsEscape($selectedCentreName) ?> · <?= count($alerts) ?> <?= lsEscape(lsText('alerts', 'ચેતવણીઓ')) ?></span>
                    </div>

                    <!-- Filters -->
                    <form method="GET" action="" class="alert-filters no-print">

                        <?php if (count($centres) > 1): ?>
                            <div class="filter-field">
                                <label for="centre_id"><?= lsEscape(lsText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?></label>
                                <select name="centre_id" id="centre_id" class="form-select">
                                    <option value=""><?= lsEscape(lsText('All my centres', 'મારા બધા કેન્દ્રો')) ?></option>
                                    <?php foreach ($centres as $centre): ?>
                                        <option value="<?= (int)$centre['centre_id'] ?>"
                                            <?= $centreFilter === (int)$centre['centre_id'] ? 'selected' : '' ?>>
                                            <?= lsEscape($centre['centre_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-field filter-wide">
                            <label for="q"><?= lsEscape(lsText('Food Item', 'ખાદ્ય વસ્તુ')) ?></label>
                            <input type="search" name="q" id="q" class="form-control"
                                   maxlength="100" autocomplete="off"
                                   placeholder="<?= lsEscape(lsText('Search rice, wheat, dal, oil…', 'ચોખા, ઘઉં, દાળ, તેલ શોધો…')) ?>"
                                   value="<?= lsEscape($search) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="status"><?= lsEscape(lsText('Status', 'સ્થિતિ')) ?></label>
                            <select name="status" id="status" class="form-select">
                                <option value=""><?= lsEscape(lsText('All alerts', 'બધી ચેતવણીઓ')) ?></option>
                                <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>>
                                    <?= lsEscape(lsText('Out of Stock', 'સ્ટોક ખતમ')) ?>
                                </option>
                                <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>>
                                    <?= lsEscape(lsText('Low Stock', 'ઓછો સ્ટોક')) ?>
                                </option>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn submit-button">
                                <?= lsEscape(lsText('Apply', 'લાગુ કરો')) ?>
                            </button>

                            <?php if ($hasFilters): ?>
                                <a href="low-stock.php" class="btn btn-light">
                                    <?= lsEscape(lsText('Clear', 'સાફ કરો')) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    </form>

                    <div class="alert-tools no-print">
                        <a href="?<?= lsEscape(lsQuery(['export' => 1])) ?>" class="btn btn-light">
                            <?= lsEscape(lsText('Download CSV', 'CSV ડાઉનલોડ')) ?>
                        </a>

                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <?= lsEscape(lsText('Print', 'પ્રિન્ટ')) ?>
                        </button>
                    </div>

                    <?php if (empty($alerts)): ?>

                        <div class="empty-state">
                            <?= lsEscape($hasFilters
                                ? lsText('No alerts match your filters.', 'તમારા ફિલ્ટર સાથે કોઈ ચેતવણી મેળ ખાતી નથી.')
                                : lsText('No low-stock items. All food items are above their minimum level.', 'ઓછા સ્ટોકની કોઈ વસ્તુ નથી. બધી ખાદ્ય વસ્તુઓ ન્યૂનતમ સ્તરથી ઉપર છે.')) ?>
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table align-middle alert-table">

                                <thead>
                                    <tr>
                                        <th><?= lsEscape(lsText('Centre', 'કેન્દ્ર')) ?></th>
                                        <th><?= lsEscape(lsText('Food Item', 'ખાદ્ય વસ્તુ')) ?></th>
                                        <th><?= lsEscape(lsText('Available', 'ઉપલબ્ધ')) ?></th>
                                        <th><?= lsEscape(lsText('Minimum', 'ન્યૂનતમ')) ?></th>
                                        <th><?= lsEscape(lsText('Shortage', 'ઘટ')) ?></th>
                                        <th><?= lsEscape(lsText('Last Received', 'છેલ્લે મળ્યું')) ?></th>
                                        <th><?= lsEscape(lsText('Status', 'સ્થિતિ')) ?></th>
                                        <th class="no-print"><?= lsEscape(lsText('History', 'ઇતિહાસ')) ?></th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($alerts as $r):
                                        $isOut = $r['status'] === 'out';
                                        $primary = $language === 'gu' && !empty($r['item_name_gu'])
                                            ? $r['item_name_gu'] : $r['item_name_en'];
                                        $secondary = $language === 'gu'
                                            ? $r['item_name_en'] : $r['item_name_gu'];
                                    ?>

                                        <tr class="<?= $isOut ? 'row-out' : 'row-low' ?>">

                                            <td><?= lsEscape($r['centre_name']) ?></td>

                                            <td>
                                                <strong><?= lsEscape($primary) ?></strong>
                                                <?php if ($secondary !== '' && $secondary !== $primary): ?>
                                                    <small class="item-alt">/ <?= lsEscape($secondary) ?></small>
                                                <?php endif; ?>
                                            </td>

                                            <td class="nowrap">
                                                <?= lsEscape(lsNumber($r['balance'])) ?> <?= lsEscape($r['unit']) ?>
                                            </td>

                                            <td class="nowrap">
                                                <?= lsEscape(lsNumber($r['min_stock'])) ?> <?= lsEscape($r['unit']) ?>
                                            </td>

                                            <td class="nowrap shortage">
                                                <?= lsEscape(lsNumber($r['shortage'])) ?> <?= lsEscape($r['unit']) ?>
                                            </td>

                                            <td class="nowrap">
                                                <?= $r['last_received']
                                                    ? lsEscape(date('d-m-Y', strtotime($r['last_received'])))
                                                    : lsEscape(lsText('Never', 'ક્યારેય નહીં')) ?>
                                            </td>

                                            <td>
                                                <span class="status-badge <?= $isOut ? 'status-out' : 'status-low' ?>">
                                                    <?= lsEscape($isOut
                                                        ? lsText('Out of Stock', 'સ્ટોક ખતમ')
                                                        : lsText('Low Stock', 'ઓછો સ્ટોક')) ?>
                                                </span>
                                            </td>

                                            <td class="no-print">
                                                <button type="button" class="btn btn-sm btn-history"
                                                        data-centre="<?= (int)$r['centre_id'] ?>"
                                                        data-item="<?= (int)$r['item_id'] ?>"
                                                        data-title="<?= lsEscape($primary . ' · ' . $r['centre_name']) ?>">
                                                    <?= lsEscape(lsText('View', 'જુઓ')) ?>
                                                </button>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>
                        </div>

                    <?php endif; ?>

                    <p class="alert-footnote">
                        <?= lsEscape(lsText(
                            'Available stock counts approved transactions only. Items with a minimum stock of 0 are not flagged.',
                            'ઉપલબ્ધ સ્ટોકમાં ફક્ત મંજૂર થયેલ વ્યવહારો ગણાય છે. જેનો ન્યૂનતમ સ્ટોક 0 છે તેવી વસ્તુઓ બતાવાતી નથી.'
                        )) ?>
                    </p>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

<!-- History modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="historyTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted mb-3">
                    <?= lsEscape(lsText(
                        'Most recent approved and pending transactions for this item at this centre.',
                        'આ કેન્દ્રમાં આ વસ્તુના તાજેતરના મંજૂર અને બાકી વ્યવહારો.'
                    )) ?>
                </p>

                <div id="historyBody"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <?= lsEscape(lsText('Close', 'બંધ કરો')) ?>
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const text = {
        loading: <?= json_encode(lsText('Loading…', 'લોડ થઈ રહ્યું છે…'), JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode(lsText('Could not load the history.', 'ઇતિહાસ લોડ થઈ શક્યો નથી.'), JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(lsText('No transactions found for this item.', 'આ વસ્તુ માટે કોઈ વ્યવહાર મળ્યો નથી.'), JSON_UNESCAPED_UNICODE) ?>,
        date: <?= json_encode(lsText('Date', 'તારીખ'), JSON_UNESCAPED_UNICODE) ?>,
        type: <?= json_encode(lsText('Transaction', 'વ્યવહાર'), JSON_UNESCAPED_UNICODE) ?>,
        quantity: <?= json_encode(lsText('Quantity', 'જથ્થો'), JSON_UNESCAPED_UNICODE) ?>,
        status: <?= json_encode(lsText('Status', 'સ્થિતિ'), JSON_UNESCAPED_UNICODE) ?>,
        by: <?= json_encode(lsText('Recorded By', 'નોંધનાર'), JSON_UNESCAPED_UNICODE) ?>
    };

    const historyModal = new bootstrap.Modal(document.getElementById('historyModal'));
    const historyBody = document.getElementById('historyBody');

    function cell(content, className) {
        const td = document.createElement('td');
        if (className) {
            const span = document.createElement('span');
            span.className = className;
            span.textContent = content;
            td.appendChild(span);
        } else {
            td.textContent = content;
        }
        return td;
    }

    function renderHistory(rows) {
        historyBody.innerHTML = '';

        if (!rows.length) {
            const p = document.createElement('div');
            p.className = 'empty-state';
            p.textContent = text.empty;
            historyBody.appendChild(p);
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'table-responsive';

        const table = document.createElement('table');
        table.className = 'table align-middle history-table';

        const head = document.createElement('thead');
        const headRow = document.createElement('tr');

        [text.date, text.type, text.quantity, text.status, text.by].forEach(function (label) {
            const th = document.createElement('th');
            th.textContent = label;
            headRow.appendChild(th);
        });

        head.appendChild(headRow);
        table.appendChild(head);

        const body = document.createElement('tbody');

        rows.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.appendChild(cell(r.date));
            tr.appendChild(cell(r.type, 'type-badge ' + r.type_class));
            tr.appendChild(cell(r.quantity));
            tr.appendChild(cell(r.status, 'status-badge ' + r.status_class));
            tr.appendChild(cell(r.by));
            body.appendChild(tr);
        });

        table.appendChild(body);
        wrap.appendChild(table);
        historyBody.appendChild(wrap);
    }

    document.querySelectorAll('.btn-history').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById('historyTitle').textContent = button.dataset.title;
            historyBody.textContent = text.loading;
            historyModal.show();

            const url = '?history=1&centre_id=' + encodeURIComponent(button.dataset.centre)
                + '&item_id=' + encodeURIComponent(button.dataset.item);

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) throw new Error('Request failed');
                    return response.json();
                })
                .then(function (data) {
                    renderHistory(data.rows || []);
                })
                .catch(function () {
                    historyBody.textContent = text.error;
                });
        });
    });
</script>

</body>
</html>