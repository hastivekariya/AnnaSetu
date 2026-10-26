<?php
/*
 * supervisor/expiry-monitoring.php
 * Batch-level expiry monitoring for the supervisor's assigned centres.
 *
 * stock_batches has no quantity column, so the remaining quantity of a batch
 * is calculated from APPROVED transactions:
 *     approved receipts - approved consumption - approved distribution
 * Fully used batches (remaining <= 0) and batches without an expiry date
 * are not listed.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['supervisor']);

$role = strtolower($_SESSION['role'] ?? 'supervisor');
$fullName = $_SESSION['full_name'] ?? 'Supervisor';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

const EX_MAX_ROWS = 1000;

$errors = [];
$centres = [];
$validCentreIds = [];
$batches = [];
$counts = ['expired' => 0, 'soon' => 0, 'safe' => 0];
$expiredQty = [];

/* -------------------- Display Helpers -------------------- */

function exEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function exNumber($value): string
{
    $text = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $text === '' || $text === '-0' ? '0' : $text;
}

/* Centre scope as named placeholders */
function exCentreScope(array $ids, int $single, string $column): array
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
    error_log('Expiry centre load error: ' . $e->getMessage());
    $errors[] = exText(
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
if (!in_array($statusFilter, ['expired', 'soon', 'safe'], true)) {
    $statusFilter = '';
}

$window = (int)($_GET['window'] ?? 30);
if (!in_array($window, [7, 30], true)) {
    $window = 30;
}

$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

function exQuery(array $extra = []): string
{
    global $centreFilter, $statusFilter, $window, $search;

    return http_build_query(array_filter(array_merge([
        'centre_id' => $centreFilter ?: '',
        'status' => $statusFilter,
        'window' => $window,
        'q' => $search
    ], $extra), static function ($v) {
        return $v !== '' && $v !== null;
    }));
}

/* -------------------- Load batches -------------------- */

if (!empty($validCentreIds)) {
    try {
        [$scopeSql, $params] = exCentreScope($validCentreIds, $centreFilter, 'b.centre_id');

        $sql =
            "SELECT b.batch_id, b.batch_number, b.expiry_date, b.received_date,
                    b.supplier_name,
                    c.centre_id, c.centre_name, c.centre_code,
                    f.item_name_en, f.item_name_gu, f.unit,
                    COALESCE(SUM(CASE
                        WHEN UPPER(t.status) = 'APPROVED'
                             AND UPPER(t.transaction_type) = 'RECEIPT'
                            THEN t.quantity
                        WHEN UPPER(t.status) = 'APPROVED'
                             AND UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
                            THEN -t.quantity
                        ELSE 0
                    END), 0) AS remaining,
                    COALESCE(SUM(CASE
                        WHEN UPPER(t.status) = 'PENDING'
                             AND UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
                            THEN t.quantity
                        ELSE 0
                    END), 0) AS pending_use
             FROM stock_batches b
             INNER JOIN anganwadi_centres c ON c.centre_id = b.centre_id
             INNER JOIN food_items f ON f.item_id = b.item_id
             LEFT JOIN stock_transactions t ON t.batch_id = b.batch_id
             WHERE " . $scopeSql . "
               AND b.expiry_date IS NOT NULL";

        if ($search !== '') {
            $sql .= " AND (f.item_name_en LIKE :q1 OR f.item_name_gu LIKE :q2
                           OR b.batch_number LIKE :q3)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $sql .= " GROUP BY b.batch_id, b.batch_number, b.expiry_date, b.received_date,
                           b.supplier_name, c.centre_id, c.centre_name, c.centre_code,
                           f.item_id, f.item_name_en, f.item_name_gu, f.unit
                  HAVING remaining > 0
                  ORDER BY b.expiry_date ASC, c.centre_name, f.item_name_en
                  LIMIT " . (int)EX_MAX_ROWS;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $today = new DateTime('today');

        foreach ($stmt->fetchAll() as $row) {
            $days = (int)$today->diff(new DateTime($row['expiry_date']))->format('%r%a');

            if ($days < 0) {
                $row['status'] = 'expired';
            } elseif ($days <= $window) {
                $row['status'] = 'soon';
            } else {
                $row['status'] = 'safe';
            }

            $row['days_left'] = $days;

            // Summary cards cover the centre scope + search, before the status filter.
            $counts[$row['status']]++;

            if ($row['status'] === 'expired') {
                $expiredQty[$row['unit']] = ($expiredQty[$row['unit']] ?? 0) + (float)$row['remaining'];
            }

            if ($statusFilter === '' || $statusFilter === $row['status']) {
                $batches[] = $row;
            }
        }

        /* ---------- CSV export ---------- */
        if (isset($_GET['export'])) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="expiry-monitoring-' . date('Y-m-d') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Gujarati

            fputcsv($out, [
                'Centre', 'Food Item (English)', 'Food Item (Gujarati)', 'Batch',
                'Remaining', 'Unit', 'Expiry Date', 'Days Left', 'Status'
            ]);

            // Prevent spreadsheet formula injection.
            $safe = static function ($value) {
                $value = (string)$value;
                return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false)
                    ? "'" . $value
                    : $value;
            };

            $statusNames = ['expired' => 'Expired', 'soon' => 'Expiring soon', 'safe' => 'Safe'];

            foreach ($batches as $r) {
                fputcsv($out, [
                    $safe($r['centre_name']),
                    $safe($r['item_name_en']),
                    $safe($r['item_name_gu']),
                    $safe($r['batch_number']),
                    exNumber($r['remaining']),
                    $r['unit'],
                    date('d-m-Y', strtotime($r['expiry_date'])),
                    $r['days_left'],
                    $statusNames[$r['status']]
                ]);
            }

            fclose($out);
            exit;
        }

    } catch (PDOException $e) {
        error_log('Expiry monitoring load error: ' . $e->getMessage());
        $errors[] = exText(
            'Unable to load batches. Please check the database.',
            'બેચ લોડ થઈ શક્યા નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
        );
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

$statusInfo = [
    'expired' => [exText('Expired', 'સમાપ્ત'), 'status-expired'],
    'soon' => [exText('Expiring Soon', 'ટૂંક સમયમાં સમાપ્ત'), 'status-soon'],
    'safe' => [exText('Safe', 'સુરક્ષિત'), 'status-safe']
];

$hasFilters = ($centreFilter > 0 || $statusFilter !== '' || $search !== '' || $window !== 30);

$expiredUnitsText = [];

foreach ($expiredQty as $unit => $qty) {
    $expiredUnitsText[] = exNumber($qty) . ' ' . $unit;
}
?>
<!DOCTYPE html>
<html lang="<?= exEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= exEscape(exText('Expiry Monitoring', 'સમાપ્તિ દેખરેખ')) ?> | AnnaSetu
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
        href="<?= APP_URL ?>/assets/css/expiry-monitoring.css?v=1"
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
                <?= exEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= exEscape($fullName) ?></strong>
                <small><?= exEscape(exText('Supervisor', 'સુપરવાઇઝર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= exEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'expiry-monitoring.php' ? 'active' : '' ?>">
                    <span><?= exEscape(exText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= exEscape(exText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content expiry-content">

            <a class="back-dashboard no-print"
               href="<?= APP_URL ?>/<?= exEscape($role) ?>/dashboard.php">
                &larr;
                <?= exEscape(exText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= exEscape(exText('Expiry Monitoring', 'સમાપ્તિ દેખરેખ')) ?>
                    / સમાપ્તિ દેખરેખ
                </h1>

                <p>
                    <?= exEscape(exText(
                        'Monitor food batches that are expired or close to expiry across your assigned centres.',
                        'તમારા નિયુક્ત કેન્દ્રોમાં સમાપ્ત થયેલ અથવા સમાપ્તિની નજીકના ખાદ્ય બેચ પર નજર રાખો.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= exEscape($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($validCentreIds)): ?>

                <div class="alert alert-warning">
                    <?= exEscape(exText(
                        'No centre is assigned to your account. Ask your administrator.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                    )) ?>
                </div>

            <?php else: ?>

                <?php if ($counts['expired'] > 0): ?>
                    <div class="alert alert-danger expired-banner">
                        <strong><?= exEscape(exText('Action needed:', 'કાર્યવાહી જરૂરી:')) ?></strong>
                        <?= exEscape(exText(
                            $counts['expired'] . ' expired batch(es) still hold stock (' . implode(' · ', $expiredUnitsText) . '). Remove them from usable stock and do not distribute them to beneficiaries.',
                            $counts['expired'] . ' સમાપ્ત થયેલ બેચમાં હજુ સ્ટોક છે (' . implode(' · ', $expiredUnitsText) . '). તેમને વાપરી શકાય તેવા સ્ટોકમાંથી દૂર કરો અને લાભાર્થીઓને વિતરણ કરશો નહીં.'
                        )) ?>
                    </div>
                <?php endif; ?>

                <!-- Summary cards -->
                <section class="expiry-stats">

                    <div class="stat-card card-expired">
                        <p><?= exEscape(exText('Expired', 'સમાપ્ત')) ?></p>
                        <h3><?= (int)$counts['expired'] ?></h3>
                    </div>

                    <div class="stat-card card-soon">
                        <p>
                            <?= exEscape(exText('Expiring Soon', 'ટૂંક સમયમાં સમાપ્ત')) ?>
                            <small>(<?= (int)$window ?> <?= exEscape(exText('days', 'દિવસ')) ?>)</small>
                        </p>
                        <h3><?= (int)$counts['soon'] ?></h3>
                    </div>

                    <div class="stat-card card-safe">
                        <p><?= exEscape(exText('Safe Batches', 'સુરક્ષિત બેચ')) ?></p>
                        <h3><?= (int)$counts['safe'] ?></h3>
                    </div>

                </section>

                <section class="dashboard-section">

                    <div class="section-heading">
                        <h3><?= exEscape(exText('Food Batch Details', 'ખાદ્ય બેચની વિગતો')) ?></h3>
                        <span><?= count($batches) ?> <?= exEscape(exText('batches', 'બેચ')) ?></span>
                    </div>

                    <!-- Filters -->
                    <form method="GET" action="" class="expiry-filters no-print">

                        <?php if (count($centres) > 1): ?>
                            <div class="filter-field">
                                <label for="centre_id"><?= exEscape(exText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?></label>
                                <select name="centre_id" id="centre_id" class="form-select">
                                    <option value=""><?= exEscape(exText('All centres', 'બધા કેન્દ્રો')) ?></option>
                                    <?php foreach ($centres as $centre): ?>
                                        <option value="<?= (int)$centre['centre_id'] ?>"
                                            <?= $centreFilter === (int)$centre['centre_id'] ? 'selected' : '' ?>>
                                            <?= exEscape($centre['centre_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-field">
                            <label for="status"><?= exEscape(exText('Status', 'સ્થિતિ')) ?></label>
                            <select name="status" id="status" class="form-select">
                                <option value=""><?= exEscape(exText('All statuses', 'બધી સ્થિતિ')) ?></option>
                                <?php foreach ($statusInfo as $code => $info): ?>
                                    <option value="<?= exEscape($code) ?>" <?= $statusFilter === $code ? 'selected' : '' ?>>
                                        <?= exEscape($info[0]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field">
                            <label for="window"><?= exEscape(exText('Warning period', 'ચેતવણી સમયગાળો')) ?></label>
                            <select name="window" id="window" class="form-select">
                                <option value="7" <?= $window === 7 ? 'selected' : '' ?>>
                                    <?= exEscape(exText('Next 7 days', 'આગામી ૭ દિવસ')) ?>
                                </option>
                                <option value="30" <?= $window === 30 ? 'selected' : '' ?>>
                                    <?= exEscape(exText('Next 30 days', 'આગામી ૩૦ દિવસ')) ?>
                                </option>
                            </select>
                        </div>

                        <div class="filter-field filter-wide">
                            <label for="q"><?= exEscape(exText('Food item or batch', 'ખાદ્ય વસ્તુ અથવા બેચ')) ?></label>
                            <input type="search" name="q" id="q" class="form-control"
                                   maxlength="100" autocomplete="off"
                                   placeholder="<?= exEscape(exText('Search rice, wheat, B001…', 'ચોખા, ઘઉં, B001 શોધો…')) ?>"
                                   value="<?= exEscape($search) ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn submit-button">
                                <?= exEscape(exText('Apply', 'લાગુ કરો')) ?>
                            </button>

                            <?php if ($hasFilters): ?>
                                <a href="expiry-monitoring.php" class="btn btn-light">
                                    <?= exEscape(exText('Clear', 'સાફ કરો')) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    </form>

                    <div class="expiry-tools no-print">
                        <a href="?<?= exEscape(exQuery(['export' => 1])) ?>" class="btn btn-light">
                            <?= exEscape(exText('Download CSV', 'CSV ડાઉનલોડ')) ?>
                        </a>

                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <?= exEscape(exText('Print', 'પ્રિન્ટ')) ?>
                        </button>
                    </div>

                    <?php if (empty($batches)): ?>

                        <div class="empty-state">
                            <?= exEscape($hasFilters
                                ? exText('No batches match your filters.', 'તમારા ફિલ્ટર સાથે કોઈ બેચ મેળ ખાતો નથી.')
                                : exText('No batches with remaining stock and an expiry date.', 'બાકી સ્ટોક અને સમાપ્તિ તારીખ ધરાવતો કોઈ બેચ નથી.')) ?>
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table align-middle expiry-table">

                                <thead>
                                    <tr>
                                        <th><?= exEscape(exText('Centre', 'કેન્દ્ર')) ?></th>
                                        <th><?= exEscape(exText('Food Item', 'ખાદ્ય વસ્તુ')) ?></th>
                                        <th><?= exEscape(exText('Batch', 'બેચ')) ?></th>
                                        <th><?= exEscape(exText('Quantity Remaining', 'બાકી જથ્થો')) ?></th>
                                        <th><?= exEscape(exText('Expiry Date', 'સમાપ્તિ તારીખ')) ?></th>
                                        <th><?= exEscape(exText('Days Left', 'બાકી દિવસ')) ?></th>
                                        <th><?= exEscape(exText('Status', 'સ્થિતિ')) ?></th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($batches as $r):
                                        $info = $statusInfo[$r['status']];
                                        $primary = $language === 'gu' && !empty($r['item_name_gu'])
                                            ? $r['item_name_gu'] : $r['item_name_en'];
                                        $secondary = $language === 'gu'
                                            ? $r['item_name_en'] : $r['item_name_gu'];
                                        $days = (int)$r['days_left'];
                                    ?>

                                        <tr class="row-<?= exEscape($r['status']) ?>">

                                            <td><?= exEscape($r['centre_name']) ?></td>

                                            <td>
                                                <strong><?= exEscape($primary) ?></strong>
                                                <?php if ($secondary !== '' && $secondary !== $primary): ?>
                                                    <small class="item-alt">/ <?= exEscape($secondary) ?></small>
                                                <?php endif; ?>
                                            </td>

                                            <td><?= exEscape($r['batch_number']) ?></td>

                                            <td class="nowrap">
                                                <?= exEscape(exNumber($r['remaining'])) ?> <?= exEscape($r['unit']) ?>
                                            </td>

                                            <td class="nowrap">
                                                <?= exEscape(date('d-m-Y', strtotime($r['expiry_date']))) ?>
                                            </td>

                                            <td class="nowrap days-cell">
                                                <?php if ($days < 0): ?>
                                                    <?= exEscape(exText(abs($days) . ' days ago', abs($days) . ' દિવસ પહેલાં')) ?>
                                                <?php elseif ($days === 0): ?>
                                                    <?= exEscape(exText('Today', 'આજે')) ?>
                                                <?php else: ?>
                                                    <?= exEscape(exText($days . ' days', $days . ' દિવસ')) ?>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="status-badge <?= exEscape($info[1]) ?>">
                                                    <?= exEscape($info[0]) ?>
                                                </span>

                                                <?php if ($r['status'] === 'expired'): ?>
                                                    <small class="remove-note">
                                                        <?= exEscape(exText('Remove from stock', 'સ્ટોકમાંથી દૂર કરો')) ?>
                                                    </small>
                                                <?php endif; ?>

                                                <?php if ($r['status'] === 'expired' && (float)$r['pending_use'] > 0): ?>
                                                    <a class="pending-link no-print"
                                                       href="<?= APP_URL ?>/<?= exEscape($role) ?>/pending-verification.php">
                                                        <?= exEscape(exText(
                                                            'Pending entries use this batch – reject them',
                                                            'બાકી નોંધો આ બેચ વાપરે છે – તેમને નામંજૂર કરો'
                                                        )) ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>
                        </div>

                    <?php endif; ?>

                    <p class="expiry-footnote">
                        <?= exEscape(exText(
                            'Remaining quantity counts approved transactions only. Fully used batches and items without an expiry date are not listed.',
                            'બાકી જથ્થામાં ફક્ત મંજૂર થયેલ વ્યવહારો ગણાય છે. સંપૂર્ણ વપરાઈ ગયેલા બેચ અને સમાપ્તિ તારીખ વિનાની વસ્તુઓ બતાવાતી નથી.'
                        )) ?>
                    </p>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>