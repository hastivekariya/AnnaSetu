<?php
/*
 * worker/stock-history.php
 * Read-only list of every stock transaction (pending and rejected included).
 * Available stock is NOT calculated here - see current-stock.php.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

const SH_PER_PAGE = 25;
const SH_EXPORT_LIMIT = 5000;

$errors = [];
$centres = [];
$centreId = 0;
$rows = [];
$totalRows = 0;
$page = 1;
$totalPages = 1;
$totals = [
    'RECEIPT' => [], 'CONSUMPTION' => [], 'DISTRIBUTION' => [], 'ADJUSTMENT' => []
];
$pendingCount = 0;

/* -------------------- Display Helpers -------------------- */

function shEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function shText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function shNumber($value): string
{
    $text = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $text === '' || $text === '-0' ? '0' : $text;
}

$typeLabels = [
    'RECEIPT' => [shText('Received', 'મળેલ'), 'type-receipt'],
    'CONSUMPTION' => [shText('Consumed', 'વપરાયેલ'), 'type-consumption'],
    'DISTRIBUTION' => [shText('Distributed', 'વિતરિત'), 'type-distribution'],
    'ADJUSTMENT' => [shText('Adjustment', 'સુધારો'), 'type-adjustment']
];

$statusLabels = [
    'PENDING' => [shText('Pending', 'બાકી'), 'status-pending'],
    'APPROVED' => [shText('Approved', 'મંજૂર'), 'status-approved'],
    'REJECTED' => [shText('Rejected', 'નામંજૂર'), 'status-rejected']
];

$beneficiaryLabels = [
    'CHILD' => shText('Children', 'બાળકો'),
    'PREGNANT_WOMAN' => shText('Pregnant Women', 'સગર્ભા મહિલાઓ'),
    'LACTATING_MOTHER' => shText('Lactating Mothers', 'ધાત્રી માતાઓ'),
    'OTHER' => shText('Other', 'અન્ય')
];

/* -------------------- Read Filters -------------------- */

function shDate($value): string
{
    $value = trim((string)$value);
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

$search = trim((string)($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);

$typeFilter = strtoupper(trim((string)($_GET['type'] ?? '')));
if (!isset($typeLabels[$typeFilter])) {
    $typeFilter = '';
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!isset($statusLabels[$statusFilter])) {
    $statusFilter = '';
}

$dateFrom = shDate($_GET['from'] ?? '');
$dateTo = shDate($_GET['to'] ?? '');

if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$page = max(1, (int)($_GET['page'] ?? 1));

/*
 * Build the WHERE clause.
 * $withTypeStatus = false is used for the summary cards, which always
 * show totals for the whole centre / date range / search.
 */
function shWhere(
    int $centreId, string $search, string $typeFilter, string $statusFilter,
    string $dateFrom, string $dateTo, bool $withTypeStatus
): array {
    $sql = " WHERE t.centre_id = :centre_id";
    $params = ['centre_id' => $centreId];

    if ($search !== '') {
        $sql .= " AND (f.item_name_en LIKE :q1 OR f.item_name_gu LIKE :q2)";
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }

    if ($dateFrom !== '') {
        $sql .= " AND t.transaction_date >= :date_from";
        $params['date_from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $sql .= " AND t.transaction_date < DATE_ADD(:date_to, INTERVAL 1 DAY)";
        $params['date_to'] = $dateTo;
    }

    if ($withTypeStatus) {
        if ($typeFilter !== '') {
            $sql .= " AND t.transaction_type = :type";
            $params['type'] = $typeFilter;
        }

        if ($statusFilter !== '') {
            $sql .= " AND t.status = :status";
            $params['status'] = $statusFilter;
        }
    }

    return [$sql, $params];
}

/* -------------------- Load Data -------------------- */

try {
    // Centres assigned to the logged-in user.
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

    // Also support a directly assigned centre in the users table.
    if (empty($centres)) {
        $stmt = $pdo->prepare(
            "SELECT c.centre_id, c.centre_name, c.centre_code
             FROM users u
             INNER JOIN anganwadi_centres c
                ON c.centre_id = u.centre_id
             WHERE u.user_id = :user_id
               AND c.is_active = 1"
        );

        $stmt->execute(['user_id' => $userId]);
        $centres = $stmt->fetchAll();
    }

    if (!empty($centres)) {
        $validIds = array_map('intval', array_column($centres, 'centre_id'));
        $requested = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);
        $centreId = in_array($requested, $validIds, true)
            ? $requested
            : $validIds[0];

        $baseFrom =
            " FROM stock_transactions t
              INNER JOIN food_items f ON f.item_id = t.item_id
              LEFT JOIN users u ON u.user_id = t.created_by
              LEFT JOIN stock_batches b ON b.batch_id = t.batch_id";

        $selectColumns =
            "SELECT t.transaction_id, t.transaction_type, t.quantity, t.status,
                    t.transaction_date, t.beneficiary_type, t.notes,
                    f.item_name_en, f.item_name_gu, f.unit,
                    u.full_name AS user_name,
                    b.batch_number,
                    (SELECT v.comments
                       FROM stock_verifications v
                      WHERE v.transaction_id = t.transaction_id
                      ORDER BY v.verified_at DESC, v.verification_id DESC
                      LIMIT 1) AS verifier_comment";

        [$where, $params] = shWhere(
            $centreId, $search, $typeFilter, $statusFilter,
            $dateFrom, $dateTo, true
        );

        /* ---------- CSV export ---------- */
        if (isset($_GET['export'])) {
            $stmt = $pdo->prepare(
                $selectColumns . $baseFrom . $where .
                " ORDER BY t.transaction_date DESC, t.transaction_id DESC
                  LIMIT " . SH_EXPORT_LIMIT
            );
            $stmt->execute($params);

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="stock-history-' . date('Y-m-d') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Gujarati

            fputcsv($out, [
                'Transaction ID', 'Date', 'Food Item', 'Type', 'Quantity',
                'Unit', 'Batch', 'Recorded By', 'Status', 'Notes'
            ]);

            // Prevent spreadsheet formula injection.
            $safe = static function ($value) {
                $value = (string)$value;
                return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false)
                    ? "'" . $value
                    : $value;
            };

            while ($r = $stmt->fetch()) {
                fputcsv($out, [
                    $r['transaction_id'],
                    date('d-m-Y', strtotime($r['transaction_date'])),
                    $safe($r['item_name_en']),
                    $r['transaction_type'],
                    shNumber($r['quantity']),
                    $r['unit'],
                    $safe($r['batch_number'] ?? ''),
                    $safe($r['user_name'] ?? ''),
                    $r['status'],
                    $safe($r['notes'] ?? '')
                ]);
            }

            fclose($out);
            exit;
        }

        /* ---------- Summary (approved totals per unit) ---------- */
        [$sumWhere, $sumParams] = shWhere(
            $centreId, $search, '', '', $dateFrom, $dateTo, false
        );

        $stmt = $pdo->prepare(
            "SELECT t.transaction_type, t.status, f.unit,
                    SUM(t.quantity) AS qty, COUNT(*) AS cnt
             FROM stock_transactions t
             INNER JOIN food_items f ON f.item_id = t.item_id" .
            $sumWhere .
            " GROUP BY t.transaction_type, t.status, f.unit"
        );
        $stmt->execute($sumParams);

        foreach ($stmt->fetchAll() as $row) {
            $type = strtoupper($row['transaction_type']);
            $status = strtoupper($row['status']);

            if ($status === 'APPROVED' && isset($totals[$type])) {
                $totals[$type][$row['unit']] = (float)$row['qty'];
            }

            if ($status === 'PENDING') {
                $pendingCount += (int)$row['cnt'];
            }
        }

        /* ---------- Count + page ---------- */
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM stock_transactions t
             INNER JOIN food_items f ON f.item_id = t.item_id" . $where
        );
        $stmt->execute($params);
        $totalRows = (int)$stmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalRows / SH_PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * SH_PER_PAGE;

        $stmt = $pdo->prepare(
            $selectColumns . $baseFrom . $where .
            " ORDER BY t.transaction_date DESC, t.transaction_id DESC
              LIMIT " . (int)SH_PER_PAGE . " OFFSET " . (int)$offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    error_log('Stock history error: ' . $e->getMessage());
    $errors[] = shText(
        'Unable to load stock history. Please check the database.',
        'સ્ટોકનો ઇતિહાસ લોડ થઈ શક્યો નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* "150 kg · 5 litre" style text for a summary card */
function shTotalText(array $perUnit): string
{
    if (empty($perUnit)) {
        return '0';
    }

    $parts = [];

    foreach ($perUnit as $unit => $qty) {
        $parts[] = shNumber($qty) . ' ' . $unit;
    }

    return implode(' · ', $parts);
}

/* Query string for pagination / export links */
function shQuery(array $extra = []): string
{
    global $centreId, $search, $typeFilter, $statusFilter, $dateFrom, $dateTo;

    return http_build_query(array_filter(array_merge([
        'centre_id' => $centreId,
        'q' => $search,
        'type' => $typeFilter,
        'status' => $statusFilter,
        'from' => $dateFrom,
        'to' => $dateTo
    ], $extra), static function ($v) {
        return $v !== '' && $v !== null;
    }));
}

/* -------------------- Menu -------------------- */

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

$hasFilters = ($search !== '' || $typeFilter !== '' || $statusFilter !== ''
    || $dateFrom !== '' || $dateTo !== '');
?>
<!DOCTYPE html>
<html lang="<?= shEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= shEscape(shText('Stock History', 'સ્ટોકનો ઇતિહાસ')) ?> | AnnaSetu
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
        href="<?= APP_URL ?>/assets/css/stock-history.css?v=1"
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
                <?= shEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= shEscape($fullName) ?></strong>
                <small><?= shEscape(shText('Worker', 'કાર્યકર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= shEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'stock-history.php' ? 'active' : '' ?>">
                    <span><?= shEscape(shText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= shEscape(shText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content history-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/<?= shEscape($role) ?>/dashboard.php">
                &larr;
                <?= shEscape(shText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= shEscape(shText('Stock History', 'સ્ટોકનો ઇતિહાસ')) ?>
                    / સ્ટોકનો ઇતિહાસ
                </h1>

                <p>
                    <?= shEscape(shText(
                        'See when stock was received, consumed or distributed at your centre.',
                        'તમારા કેન્દ્રમાં સ્ટોક ક્યારે મળ્યો, વપરાયો કે વિતરિત થયો તે જુઓ.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= shEscape($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($centres)): ?>

                <div class="alert alert-warning">
                    <?= shEscape(shText(
                        'No centre is assigned to your account. Ask your administrator.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                    )) ?>
                </div>

            <?php else: ?>

                <!-- Summary cards -->
                <section class="history-stats">

                    <div class="stat-card stat-receipt">
                        <p><?= shEscape(shText('Received', 'મળેલ')) ?></p>
                        <h3><?= shEscape(shTotalText($totals['RECEIPT'])) ?></h3>
                    </div>

                    <div class="stat-card stat-consumption">
                        <p><?= shEscape(shText('Consumed', 'વપરાયેલ')) ?></p>
                        <h3><?= shEscape(shTotalText($totals['CONSUMPTION'])) ?></h3>
                    </div>

                    <div class="stat-card stat-distribution">
                        <p><?= shEscape(shText('Distributed', 'વિતરિત')) ?></p>
                        <h3><?= shEscape(shTotalText($totals['DISTRIBUTION'])) ?></h3>
                    </div>

                    <div class="stat-card stat-pending">
                        <p><?= shEscape(shText('Pending Approval', 'મંજૂરી બાકી')) ?></p>
                        <h3><?= (int)$pendingCount ?></h3>
                    </div>

                </section>

                <p class="stock-footnote summary-note">
                    <?= shEscape(shText(
                        'Totals count approved entries only, for the selected centre, dates and search.',
                        'કુલ સંખ્યામાં પસંદ કરેલા કેન્દ્ર, તારીખો અને શોધ મુજબ ફક્ત મંજૂર થયેલ નોંધો ગણાય છે.'
                    )) ?>
                </p>

                <!-- Filters + table -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h3><?= shEscape(shText('Transactions', 'વ્યવહારો')) ?></h3>
                        <span>
                            <?= shEscape(shText('Total records', 'કુલ નોંધો')) ?>:
                            <?= (int)$totalRows ?>
                        </span>
                    </div>

                    <form method="GET" action="" class="history-filters">

                        <?php if (count($centres) > 1): ?>
                            <div class="filter-field">
                                <label for="centre_id">
                                    <?= shEscape(shText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?>
                                </label>
                                <select name="centre_id" id="centre_id" class="form-select">
                                    <?php foreach ($centres as $centre): ?>
                                        <option value="<?= (int)$centre['centre_id'] ?>"
                                            <?= (int)$centre['centre_id'] === $centreId ? 'selected' : '' ?>>
                                            <?= shEscape($centre['centre_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="centre_id" value="<?= (int)$centreId ?>">
                        <?php endif; ?>

                        <div class="filter-field filter-wide">
                            <label for="q"><?= shEscape(shText('Food Item', 'ખાદ્ય વસ્તુ')) ?></label>
                            <input type="search" name="q" id="q" class="form-control"
                                   maxlength="100"
                                   placeholder="<?= shEscape(shText('Search food item', 'ખાદ્ય વસ્તુ શોધો')) ?>"
                                   value="<?= shEscape($search) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="type"><?= shEscape(shText('Transaction', 'વ્યવહાર')) ?></label>
                            <select name="type" id="type" class="form-select">
                                <option value=""><?= shEscape(shText('All transactions', 'બધા વ્યવહારો')) ?></option>
                                <?php foreach ($typeLabels as $code => $label): ?>
                                    <option value="<?= shEscape($code) ?>"
                                        <?= $typeFilter === $code ? 'selected' : '' ?>>
                                        <?= shEscape($label[0]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="status"><?= shEscape(shText('Status', 'સ્થિતિ')) ?></label>
                            <select name="status" id="status" class="form-select">
                                <option value=""><?= shEscape(shText('All statuses', 'બધી સ્થિતિ')) ?></option>
                                <?php foreach ($statusLabels as $code => $label): ?>
                                    <option value="<?= shEscape($code) ?>"
                                        <?= $statusFilter === $code ? 'selected' : '' ?>>
                                        <?= shEscape($label[0]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="from"><?= shEscape(shText('From', 'થી')) ?></label>
                            <input type="date" name="from" id="from" class="form-control"
                                   value="<?= shEscape($dateFrom) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="to"><?= shEscape(shText('To', 'સુધી')) ?></label>
                            <input type="date" name="to" id="to" class="form-control"
                                   value="<?= shEscape($dateTo) ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn submit-button">
                                <?= shEscape(shText('Apply', 'લાગુ કરો')) ?>
                            </button>

                            <?php if ($hasFilters): ?>
                                <a href="?centre_id=<?= (int)$centreId ?>" class="btn btn-light">
                                    <?= shEscape(shText('Clear', 'સાફ કરો')) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    </form>

                    <div class="history-tools">
                        <a href="?<?= shEscape(shQuery(['export' => 1])) ?>" class="btn btn-light">
                            <?= shEscape(shText('Download CSV', 'CSV ડાઉનલોડ')) ?>
                        </a>

                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <?= shEscape(shText('Print', 'પ્રિન્ટ')) ?>
                        </button>
                    </div>

                    <?php if (empty($rows)): ?>

                        <div class="empty-state">
                            <?= shEscape(shText('No transactions found.', 'કોઈ વ્યવહાર મળ્યો નથી.')) ?>
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">

                                <thead>
                                    <tr>
                                        <th><?= shEscape(shText('Date', 'તારીખ')) ?></th>
                                        <th><?= shEscape(shText('Food Item', 'ખાદ્ય વસ્તુ')) ?></th>
                                        <th><?= shEscape(shText('Transaction', 'વ્યવહાર')) ?></th>
                                        <th><?= shEscape(shText('Quantity', 'જથ્થો')) ?></th>
                                        <th><?= shEscape(shText('Batch', 'બેચ')) ?></th>
                                        <th><?= shEscape(shText('Recorded By', 'નોંધનાર')) ?></th>
                                        <th><?= shEscape(shText('Status', 'સ્થિતિ')) ?></th>
                                        <th><?= shEscape(shText('Details', 'વિગતો')) ?></th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($rows as $row):
                                        $type = strtoupper($row['transaction_type']);
                                        $status = strtoupper($row['status']);
                                        $typeInfo = $typeLabels[$type] ?? [$type, 'type-adjustment'];
                                        $statusInfo = $statusLabels[$status] ?? [$status, 'status-pending'];
                                        $itemName = $language === 'gu' && !empty($row['item_name_gu'])
                                            ? $row['item_name_gu']
                                            : $row['item_name_en'];

                                        $details = [];

                                        if (!empty($row['beneficiary_type'])) {
                                            $details[] = $beneficiaryLabels[$row['beneficiary_type']]
                                                ?? $row['beneficiary_type'];
                                        }

                                        if (!empty($row['notes'])) {
                                            $details[] = $row['notes'];
                                        }

                                        if ($status === 'REJECTED' && !empty($row['verifier_comment'])) {
                                            $details[] = shText('Reason', 'કારણ') . ': ' . $row['verifier_comment'];
                                        }
                                    ?>

                                        <tr>
                                            <td class="nowrap">
                                                <?= shEscape(date('d-m-Y', strtotime($row['transaction_date']))) ?>
                                            </td>

                                            <td><strong><?= shEscape($itemName) ?></strong></td>

                                            <td>
                                                <span class="type-badge <?= shEscape($typeInfo[1]) ?>">
                                                    <?= shEscape($typeInfo[0]) ?>
                                                </span>
                                            </td>

                                            <td class="nowrap">
                                                <?= shEscape(shNumber($row['quantity'])) ?>
                                                <?= shEscape($row['unit']) ?>
                                            </td>

                                            <td><?= shEscape($row['batch_number'] ?? '-') ?></td>

                                            <td><?= shEscape($row['user_name'] ?? '-') ?></td>

                                            <td>
                                                <span class="status-badge <?= shEscape($statusInfo[1]) ?>">
                                                    <?= shEscape($statusInfo[0]) ?>
                                                </span>
                                            </td>

                                            <td class="details-cell">
                                                <?= $details
                                                    ? shEscape(implode(' · ', $details))
                                                    : '-' ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <nav class="history-pagination" aria-label="Pages">

                                <?php if ($page > 1): ?>
                                    <a href="?<?= shEscape(shQuery(['page' => $page - 1])) ?>" class="page-link-btn">
                                        &laquo; <?= shEscape(shText('Previous', 'પાછળ')) ?>
                                    </a>
                                <?php endif; ?>

                                <span class="page-info">
                                    <?= shEscape(shText('Page', 'પેજ')) ?>
                                    <?= (int)$page ?> / <?= (int)$totalPages ?>
                                </span>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?= shEscape(shQuery(['page' => $page + 1])) ?>" class="page-link-btn">
                                        <?= shEscape(shText('Next', 'આગળ')) ?> &raquo;
                                    </a>
                                <?php endif; ?>

                            </nav>
                        <?php endif; ?>

                    <?php endif; ?>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>