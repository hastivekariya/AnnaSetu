<?php
/*
 * worker/current-stock.php
 * For helpers, create helper/current-stock.php containing only:
 *     <?php require __DIR__ . '/../worker/current-stock.php';
 *
 * Read-only page. Stock = approved receipts - approved consumption/distribution.
 * Minimum stock = centre_item_settings.minimum_stock if set,
 *                 otherwise food_items.default_min_stock.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker', 'helper']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

$errors = [];
$centres = [];
$stockRows = [];
$centreId = 0;

const CS_NEAR_EXPIRY_DAYS = 30;

/* -------------------- Display Helpers -------------------- */

function csEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function csNumber($value): string
{
    $text = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $text === '' || $text === '-0' ? '0' : $text;
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

    // Selected centre (must be one of the user's centres).
    if (!empty($centres)) {
        $validIds = array_map('intval', array_column($centres, 'centre_id'));
        $requested = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);
        $centreId = in_array($requested, $validIds, true)
            ? $requested
            : $validIds[0];

        // Stock balance per food item for this centre.
        $stmt = $pdo->prepare(
            "SELECT
                f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                COALESCE(cis.minimum_stock, f.default_min_stock) AS min_stock,
                COALESCE(SUM(CASE
                    WHEN UPPER(t.status) = 'APPROVED'
                         AND UPPER(t.transaction_type) = 'RECEIPT'
                        THEN t.quantity
                    WHEN UPPER(t.status) = 'APPROVED'
                         AND UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
                        THEN -t.quantity
                    ELSE 0
                END), 0) AS balance,
                COALESCE(SUM(CASE
                    WHEN UPPER(t.status) = 'PENDING'
                         AND UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
                        THEN t.quantity
                    ELSE 0
                END), 0) AS pending_out
             FROM food_items f
             LEFT JOIN centre_item_settings cis
                ON cis.item_id = f.item_id
               AND cis.centre_id = :centre_a
             LEFT JOIN stock_transactions t
                ON t.item_id = f.item_id
               AND t.centre_id = :centre_b
             WHERE f.is_active = 1
             GROUP BY f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                      cis.minimum_stock, f.default_min_stock
             ORDER BY f.item_name_en"
        );

        $stmt->execute([
            'centre_a' => $centreId,
            'centre_b' => $centreId
        ]);

        $stockRows = $stmt->fetchAll();

        // Nearest expiry of batches that still hold approved stock.
        $stmt = $pdo->prepare(
            "SELECT x.item_id, MIN(x.expiry_date) AS nearest_expiry
             FROM (
                SELECT b.batch_id, b.item_id, b.expiry_date,
                       SUM(CASE
                           WHEN UPPER(t.transaction_type) = 'RECEIPT'
                               THEN t.quantity
                           WHEN UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
                               THEN -t.quantity
                           ELSE 0
                       END) AS balance
                FROM stock_batches b
                LEFT JOIN stock_transactions t
                   ON t.batch_id = b.batch_id
                  AND UPPER(t.status) = 'APPROVED'
                WHERE b.centre_id = :centre_id
                  AND b.expiry_date IS NOT NULL
                GROUP BY b.batch_id, b.item_id, b.expiry_date
                HAVING balance > 0
             ) x
             GROUP BY x.item_id"
        );

        $stmt->execute(['centre_id' => $centreId]);

        $expiryByItem = [];

        foreach ($stmt->fetchAll() as $row) {
            $expiryByItem[(int)$row['item_id']] = $row['nearest_expiry'];
        }
    }

} catch (PDOException $e) {
    error_log('Current stock error: ' . $e->getMessage());
    $errors[] = csText(
        'Unable to load stock. Please check the database.',
        'સ્ટોક લોડ થઈ શક્યો નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* -------------------- Status + Summary -------------------- */

$summary = ['total' => 0, 'in' => 0, 'low' => 0, 'out' => 0, 'expiry' => 0];
$today = new DateTime('today');

foreach ($stockRows as &$row) {
    $balance = (float)$row['balance'];
    $min = (float)$row['min_stock'];

    if ($balance <= 0) {
        $row['status'] = 'out';
    } elseif ($balance <= $min) {
        $row['status'] = 'low';
    } else {
        $row['status'] = 'in';
    }

    $row['expiry_date'] = $expiryByItem[(int)$row['item_id']] ?? null;
    $row['expiry_state'] = '';

    if ($row['expiry_date'] !== null) {
        $days = (int)$today->diff(new DateTime($row['expiry_date']))->format('%r%a');
        $row['expiry_days'] = $days;

        if ($days < 0) {
            $row['expiry_state'] = 'expired';
        } elseif ($days <= CS_NEAR_EXPIRY_DAYS) {
            $row['expiry_state'] = 'near';
        }
    }

    $summary['total']++;
    $summary[$row['status']]++;

    if ($row['expiry_state'] !== '') {
        $summary['expiry']++;
    }
}
unset($row);

$statusLabels = [
    'in' => [csText('In Stock', 'સ્ટોકમાં'), 'status-in'],
    'low' => [csText('Low Stock', 'ઓછો સ્ટોક'), 'status-low'],
    'out' => [csText('Out of Stock', 'સ્ટોક ખતમ'), 'status-out']
];

/* -------------------- Menu -------------------- */

$menus = [
    'worker' => [
        ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
        ['receive-stock.php', 'Receive Stock', 'સ્ટોક મેળવો'],
        ['daily-consumption.php', 'Daily Consumption', 'દૈનિક વપરાશ'],
        ['distribution.php', 'Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ'],
        ['current-stock.php', 'Current Stock', 'હાલનો સ્ટોક'],
        ['stock-history.php', 'Stock History', 'સ્ટોકનો ઇતિહાસ'],
        ['reports.php', 'Reports', 'અહેવાલો'],
        ['notifications.php', 'Notifications', 'સૂચનાઓ'],
        ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
    ],
    'helper' => [
        ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
        ['daily-consumption.php', 'Daily Consumption', 'દૈનિક વપરાશ'],
        ['distribution.php', 'Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ'],
        ['current-stock.php', 'Current Stock', 'હાલનો સ્ટોક'],
        ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
    ]
];

$menuItems = $menus[$role] ?? $menus['worker'];
$roleLabel = $role === 'helper'
    ? csText('Helper', 'મદદનીશ')
    : csText('Worker', 'કાર્યકર');
?>
<!DOCTYPE html>
<html lang="<?= csEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= csEscape(csText('Current Stock', 'હાલનો સ્ટોક')) ?> | AnnaSetu
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
        href="<?= APP_URL ?>/assets/css/current-stock.css?v=1"
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
                <?= csEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= csEscape($fullName) ?></strong>
                <small><?= csEscape($roleLabel) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= csEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'current-stock.php' ? 'active' : '' ?>">
                    <span><?= csEscape(csText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= csEscape(csText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/<?= csEscape($role) ?>/dashboard.php">
                &larr;
                <?= csEscape(csText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= csEscape(csText('Current Stock', 'હાલનો સ્ટોક')) ?>
                    / હાલનો સ્ટોક
                </h1>

                <p>
                    <?= csEscape(csText(
                        'Check which food items are available, running low or out of stock at your centre.',
                        'તમારા કેન્દ્રમાં કઈ ખાદ્ય વસ્તુઓ ઉપલબ્ધ છે, ઓછી છે કે ખતમ થઈ ગઈ છે તે જુઓ.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= csEscape($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($centres)): ?>

                <div class="alert alert-warning">
                    <?= csEscape(csText(
                        'No centre is assigned to your account. Ask your administrator.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                    )) ?>
                </div>

            <?php else: ?>

                <!-- Centre selector (only when more than one centre) -->
                <?php if (count($centres) > 1): ?>
                    <form method="GET" action="" class="centre-switch">
                        <label for="centre_id">
                            <?= csEscape(csText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?>
                        </label>

                        <select name="centre_id" id="centre_id"
                                class="form-select"
                                onchange="this.form.submit()">
                            <?php foreach ($centres as $centre): ?>
                                <option value="<?= (int)$centre['centre_id'] ?>"
                                    <?= (int)$centre['centre_id'] === $centreId ? 'selected' : '' ?>>
                                    <?= csEscape($centre['centre_name']) ?>
                                    (<?= csEscape($centre['centre_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <p class="centre-name">
                        <?= csEscape($centres[0]['centre_name']) ?>
                        (<?= csEscape($centres[0]['centre_code']) ?>)
                    </p>
                <?php endif; ?>

                <!-- Summary cards -->
                <section class="stock-stats">

                    <div class="stat-card">
                        <p><?= csEscape(csText('Total Food Items', 'કુલ ખાદ્ય વસ્તુઓ')) ?></p>
                        <h3><?= (int)$summary['total'] ?></h3>
                    </div>

                    <div class="stat-card stat-in">
                        <p><?= csEscape(csText('In Stock', 'સ્ટોકમાં')) ?></p>
                        <h3><?= (int)$summary['in'] ?></h3>
                    </div>

                    <div class="stat-card stat-low">
                        <p><?= csEscape(csText('Low Stock', 'ઓછો સ્ટોક')) ?></p>
                        <h3><?= (int)$summary['low'] ?></h3>
                    </div>

                    <div class="stat-card stat-out">
                        <p><?= csEscape(csText('Out of Stock', 'સ્ટોક ખતમ')) ?></p>
                        <h3><?= (int)$summary['out'] ?></h3>
                    </div>

                </section>

                <?php if ($summary['expiry'] > 0): ?>
                    <div class="alert alert-warning">
                        <strong><?= csEscape(csText('Expiry alert:', 'સમાપ્તિ ચેતવણી:')) ?></strong>
                        <?= csEscape(csText(
                            $summary['expiry'] . ' item(s) have stock that is expired or expires within ' . CS_NEAR_EXPIRY_DAYS . ' days.',
                            $summary['expiry'] . ' વસ્તુઓનો સ્ટોક સમાપ્ત થઈ ગયો છે અથવા ' . CS_NEAR_EXPIRY_DAYS . ' દિવસમાં સમાપ્ત થશે.'
                        )) ?>
                    </div>
                <?php endif; ?>

                <!-- Stock table -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h3><?= csEscape(csText('Stock Details', 'સ્ટોકની વિગતો')) ?></h3>
                        <span><?= csEscape(csText('Approved stock only', 'ફક્ત મંજૂર થયેલ સ્ટોક')) ?></span>
                    </div>

                    <div class="stock-toolbar">
                        <input type="search" id="stockSearch"
                               class="form-control"
                               placeholder="<?= csEscape(csText('Search food item', 'ખાદ્ય વસ્તુ શોધો')) ?>"
                               autocomplete="off">

                        <div class="filter-buttons" id="stockFilters">
                            <button type="button" class="filter-btn active" data-filter="all">
                                <?= csEscape(csText('All', 'બધું')) ?>
                            </button>
                            <button type="button" class="filter-btn" data-filter="in">
                                <?= csEscape(csText('In Stock', 'સ્ટોકમાં')) ?>
                            </button>
                            <button type="button" class="filter-btn" data-filter="low">
                                <?= csEscape(csText('Low Stock', 'ઓછો સ્ટોક')) ?>
                            </button>
                            <button type="button" class="filter-btn" data-filter="out">
                                <?= csEscape(csText('Out of Stock', 'સ્ટોક ખતમ')) ?>
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="stockTable">

                            <thead>
                                <tr>
                                    <th><?= csEscape(csText('Food Item', 'ખાદ્ય વસ્તુ')) ?></th>
                                    <th><?= csEscape(csText('Available Stock', 'ઉપલબ્ધ સ્ટોક')) ?></th>
                                    <th><?= csEscape(csText('Minimum Stock', 'ન્યૂનતમ સ્ટોક')) ?></th>
                                    <th><?= csEscape(csText('Pending Use', 'બાકી વપરાશ')) ?></th>
                                    <th><?= csEscape(csText('Nearest Expiry', 'નજીકની સમાપ્તિ')) ?></th>
                                    <th><?= csEscape(csText('Status', 'સ્થિતિ')) ?></th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($stockRows as $row):
                                    $label = $statusLabels[$row['status']];
                                    $displayName = $language === 'gu' && !empty($row['item_name_gu'])
                                        ? $row['item_name_gu']
                                        : $row['item_name_en'];
                                    $searchText = mb_strtolower(
                                        $row['item_name_en'] . ' ' . $row['item_name_gu']
                                    );
                                ?>

                                    <tr data-status="<?= csEscape($row['status']) ?>"
                                        data-name="<?= csEscape($searchText) ?>">

                                        <td>
                                            <strong><?= csEscape($displayName) ?></strong>
                                        </td>

                                        <td>
                                            <?= csEscape(csNumber($row['balance'])) ?>
                                            <?= csEscape($row['unit']) ?>
                                        </td>

                                        <td>
                                            <?= csEscape(csNumber($row['min_stock'])) ?>
                                            <?= csEscape($row['unit']) ?>
                                        </td>

                                        <td>
                                            <?php if ((float)$row['pending_out'] > 0): ?>
                                                <?= csEscape(csNumber($row['pending_out'])) ?>
                                                <?= csEscape($row['unit']) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if ($row['expiry_date'] !== null): ?>
                                                <span class="expiry-<?= csEscape($row['expiry_state'] ?: 'ok') ?>">
                                                    <?= csEscape(date('d-m-Y', strtotime($row['expiry_date']))) ?>
                                                </span>

                                                <?php if ($row['expiry_state'] === 'expired'): ?>
                                                    <small class="expiry-tag expired">
                                                        <?= csEscape(csText('Expired', 'સમાપ્ત')) ?>
                                                    </small>
                                                <?php elseif ($row['expiry_state'] === 'near'): ?>
                                                    <small class="expiry-tag near">
                                                        <?= csEscape(csText('Soon', 'ટૂંક સમયમાં')) ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="status-badge <?= csEscape($label[1]) ?>">
                                                <?= csEscape($label[0]) ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                <tr id="noResults" style="display: none;">
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <?= csEscape(csText('No matching food items.', 'કોઈ મેળ ખાતી ખાદ્ય વસ્તુ મળી નથી.')) ?>
                                    </td>
                                </tr>

                            </tbody>

                        </table>
                    </div>

                    <p class="stock-footnote">
                        <?= csEscape(csText(
                            'Available stock counts approved entries only. “Pending Use” is consumption or distribution waiting for supervisor approval.',
                            'ઉપલબ્ધ સ્ટોકમાં ફક્ત મંજૂર થયેલ નોંધો ગણાય છે. “બાકી વપરાશ” સુપરવાઇઝરની મંજૂરીની રાહ જોતો વપરાશ અથવા વિતરણ છે.'
                        )) ?>
                    </p>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

<script>
    const searchInput = document.getElementById('stockSearch');
    const filterBox = document.getElementById('stockFilters');
    const noResults = document.getElementById('noResults');

    let activeFilter = 'all';

    function applyFilters() {
        const rows = document.querySelectorAll('#stockTable tbody tr[data-status]');
        const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        let visible = 0;

        rows.forEach(function (row) {
            const matchesStatus = activeFilter === 'all' || row.dataset.status === activeFilter;
            const matchesName = query === '' || row.dataset.name.indexOf(query) !== -1;
            const show = matchesStatus && matchesName;

            row.style.display = show ? '' : 'none';

            if (show) visible++;
        });

        if (noResults) {
            noResults.style.display = visible === 0 ? '' : 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    if (filterBox) {
        filterBox.addEventListener('click', function (event) {
            const button = event.target.closest('.filter-btn');

            if (!button) return;

            activeFilter = button.dataset.filter;

            filterBox.querySelectorAll('.filter-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn === button);
            });

            applyFilters();
        });
    }
</script>

</body>
</html>