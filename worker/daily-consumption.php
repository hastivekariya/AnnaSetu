
<?php
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$centreId = (int) ($_SESSION['centre_id'] ?? 0);


require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

$role = strtolower($_SESSION['role'] ?? 'worker');

if (!in_array($role, ['worker', 'helper'], true)) {
    header('Location: ' . APP_URL . '/' . $role . '/dashboard.php');
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'User';
$language = $_SESSION['language'] ?? 'gu';
$userId   = (int) ($_SESSION['user_id'] ?? 0);
$centreId = (int) ($_SESSION['centre_id'] ?? 0);

/* ---------- Helpers ---------- */

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Uses translate(); falls back to English if the key is missing.
function t($key, $fallback)
{
    $text = translate($key);
    return e(($text === '' || $text === $key) ? $fallback : $text);
}

function itemName($row)
{
    global $language;
    if ($language === 'gu' && !empty($row['item_name_gu'])) {
        return $row['item_name_gu'];
    }
    return $row['item_name_en'] ?? 'Food item';
}

/* ---------- Menus (same as dashboard) ---------- */

$menus = [
    'worker' => [
        ['dashboard', 'Dashboard', 'dashboard.php'],
        ['receive_stock', 'Receive Stock', 'receive-stock.php'],
        ['daily_consumption', 'Daily Consumption', 'daily-consumption.php'],
        ['beneficiary_distribution', 'Beneficiary Distribution', 'distribution.php'],
        ['current_stock', 'Current Stock', 'current-stock.php'],
        ['stock_history', 'Stock History', 'stock-history.php'],
        ['reports', 'Reports', 'reports.php'],
        ['notifications', 'Notifications', 'notifications.php'],
        ['my_profile', 'My Profile', 'profile.php'],
    ],
    'helper' => [
        ['dashboard', 'Dashboard', 'dashboard.php'],
        ['daily_consumption', 'Daily Consumption', 'daily-consumption.php'],
        ['beneficiary_distribution', 'Beneficiary Distribution', 'distribution.php'],
        ['current_stock', 'Current Stock', 'current-stock.php'],
        ['my_profile', 'My Profile', 'profile.php'],
    ],
];
$menuItems = $menus[$role];

/* ---------- CSRF + flash ---------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$errors = [];
$old = ['item_id' => '', 'quantity' => '', 'date' => date('Y-m-d'), 'remarks' => ''];

/* ---------- Balance query (approved stock only) ---------- */

function getBalances(PDO $pdo, int $centreId, ?int $itemId = null): array
{
    $sql = "SELECT f.item_id, f.item_name_en, f.item_name_gu,
                COALESCE(SUM(CASE
                    WHEN UPPER(t.transaction_type) = 'RECEIPT' THEN t.quantity
                    WHEN UPPER(t.transaction_type) IN ('CONSUMPTION','DISTRIBUTION') THEN -t.quantity
                    ELSE 0 END), 0) AS balance
            FROM food_items f
            LEFT JOIN stock_transactions t
                ON t.item_id = f.item_id
               AND t.centre_id = :centre";
    if ($itemId !== null) {
        $sql .= " WHERE f.item_id = :item";
    }
    $sql .= " GROUP BY f.item_id, f.item_name_en, f.item_name_gu
              ORDER BY f.item_name_en";

    $stmt = $pdo->prepare($sql);
    $params = [':centre' => $centreId];
    if ($itemId !== null) {
        $params[':item'] = $itemId;
    }
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ---------- Handle form submit ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = t('invalid_request', 'Invalid request. Please try again.');
    }

    $old['item_id']  = (int) ($_POST['item_id'] ?? 0);
    $old['quantity'] = trim($_POST['quantity'] ?? '');
    $old['date']     = trim($_POST['consumption_date'] ?? '');
    $old['remarks']  = trim($_POST['remarks'] ?? '');

    $quantity = filter_var($old['quantity'], FILTER_VALIDATE_FLOAT);
    $dateObj  = DateTime::createFromFormat('Y-m-d', $old['date']);
    $dateOk   = $dateObj && $dateObj->format('Y-m-d') === $old['date'];

    if ($old['item_id'] <= 0) {
        $errors[] = t('err_select_item', 'Please select a food item.');
    }
    if ($quantity === false || $quantity <= 0) {
        $errors[] = t('err_quantity', 'Enter a quantity greater than zero.');
    }
    if (!$dateOk || $old['date'] > date('Y-m-d')) {
        $errors[] = t('err_date', 'Enter a valid date (not in the future).');
    }
    if (mb_strlen($old['remarks']) > 255) {
        $errors[] = t('err_remarks', 'Remarks must be 255 characters or fewer.');
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Re-check balance inside the transaction
            $rows = getBalances($pdo, $centreId, $old['item_id']);

            if (!$rows) {
                $errors[] = t('err_select_item', 'Please select a food item.');
                $pdo->rollBack();
            } elseif ($quantity > (float) $rows[0]['balance']) {
                $errors[] = t('err_insufficient', 'Quantity is more than the available stock.');
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO stock_transactions (
    item_id,
    centre_id,
    transaction_type,
    quantity,
    status
) VALUES (
    :item_id,
    :centre_id,
    'CONSUMPTION',
    :quantity,
    'PENDING'
)"
                );
                $stmt->execute([
    ':item_id'   => $old['item_id'],
    ':centre_id' => $centreId,
    ':quantity'  => $quantity,
]);
                $pdo->commit();

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'text' => translate('consumption_saved') !== 'consumption_saved'
                        ? translate('consumption_saved')
                        : 'Consumption recorded. It is awaiting supervisor verification.',
                ];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: daily-consumption.php');
                exit;
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Daily consumption error: ' . $ex->getMessage());
            $errors[] = 'Database error: ' . $ex->getMessage();
        }
    }
}

/* ---------- Page data ---------- */

$items = [];
$todayEntries = [];
$loadError = false;

try {
    $items = getBalances($pdo, $centreId);

    $stmt = $pdo->prepare(
        "SELECT t.transaction_id, t.quantity, t.status, t.remarks,
                t.transaction_date, f.item_name_en, f.item_name_gu
         FROM stock_transactions t
         LEFT JOIN food_items f ON f.item_id = t.item_id
         WHERE UPPER(t.transaction_type) = 'CONSUMPTION'
           AND t.centre_id = :centre
           AND t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         ORDER BY t.created_at DESC
         LIMIT 20"
    );
    $stmt->execute([':centre' => $centreId]);
    $todayEntries = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log('Daily consumption load error: ' . $ex->getMessage());

    echo '<div class="alert alert-warning">';
    echo '<strong>Database debug:</strong> ';
    echo htmlspecialchars(
        $ex->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</div>';

    $loadError = true;
}

?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('daily_consumption', 'Daily Consumption') ?> | <?= e(APP_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/dashboard.css?v=2">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/daily-consumption.css?v=1">
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
            <div class="user-avatar"><?= e(strtoupper(substr($fullName, 0, 1))) ?></div>
            <div>
                <strong><?= e($fullName) ?></strong>
                <small><?= t('role_' . $role, ucfirst($role)) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($menuItems as [$key, $label, $file]): ?>
                <a href="<?= e(APP_URL . '/' . $role . '/' . $file) ?>"
                   class="menu-link <?= $file === 'daily-consumption.php' ? 'active' : '' ?>">
                    <span><?= t($key, $label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php"><?= t('logout', 'Logout') ?></a>
    </aside>

    <!-- Main -->
    <main class="dashboard-main">
        <div class="page-content">

            <header class="page-header">
    <div>
        <h2>
            <?= $language === 'gu' ? 'સ્વાગત છે, ' : 'Welcome, ' ?>
            <?= e($fullName) ?>!
        </h2>

        <p>
            <?= t(
                'daily_consumption_desc',
                'Record the food items used at the centre today.'
            ) ?>
        </p>
    </div>

    <form action="<?= APP_URL ?>/change-language.php"
          method="POST"
          class="language-form">

        <input type="hidden"
               name="return_to"
               value="<?= e($_SERVER['REQUEST_URI']) ?>">

        <label for="language">
            <?= t('language', 'Language') ?>
        </label>

        <select name="language"
                id="language"
                onchange="this.form.submit()">
            <option value="gu" <?= $language === 'gu' ? 'selected' : '' ?>>
                ગુજરાતી
            </option>

            <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>
                English
            </option>
        </select>
    </form>
</header>



            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
                    <?= e($flash['text']) ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= $err /* already escaped by t() */ ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($loadError): ?>
                <div class="alert alert-danger"><?= t('database_error', 'Database error. Please try again.') ?></div>
            <?php else: ?>

            <!-- Entry form -->
            <section class="form-card">
                <form method="POST" action="daily-consumption.php" id="consumptionForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="item_id"><?= t('food_item', 'Food Item') ?></label>
                            <select name="item_id" id="item_id" required>
                                <option value=""><?= t('select_item', '-- Select item --') ?></option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= (int) $item['item_id'] ?>"
                                            data-balance="<?= e($item['balance']) ?>"
                                            <?= (int) $old['item_id'] === (int) $item['item_id'] ? 'selected' : '' ?>>
                                        <?= e(itemName($item)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="balance-hint" id="balanceHint" aria-live="polite"></div>
                        </div>

                        <div class="form-group">
                            <label for="quantity"><?= t('quantity', 'Quantity') ?></label>
                            <input type="number" name="quantity" id="quantity"
                                   step="0.01" min="0.01" required
                                   value="<?= e($old['quantity']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="consumption_date"><?= t('date', 'Date') ?></label>
                            <input type="date" name="consumption_date" id="consumption_date"
                                   max="<?= date('Y-m-d') ?>" required
                                   value="<?= e($old['date']) ?>">
                        </div>

                        <div class="form-group">
                            <label for="remarks"><?= t('remarks', 'Remarks (optional)') ?></label>
                            <textarea name="remarks" id="remarks" maxlength="255"><?= e($old['remarks']) ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit"><?= t('save', 'Save') ?></button>
                        <a href="dashboard.php" class="btn btn-secondary"><?= t('cancel', 'Cancel') ?></a>
                    </div>
                </form>
            </section>

            <!-- Recent entries -->
            <section class="dashboard-section">
                <div class="section-heading">
                    <h3><?= t('recent_consumption', 'Recent Consumption') ?></h3>
                    <span><?= t('last_7_days', 'Last 7 days') ?></span>
                </div>

                <?php if (!$todayEntries): ?>
                    <div class="empty-state"><?= t('no_transactions', 'No transactions yet.') ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th><?= t('date', 'Date') ?></th>
                                    <th><?= t('food_item', 'Food Item') ?></th>
                                    <th><?= t('quantity', 'Quantity') ?></th>
                                    <th><?= t('status', 'Status') ?></th>
                                    <th><?= t('remarks', 'Remarks') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayEntries as $row):
                                    $status = strtolower($row['status'] ?? ''); ?>
                                    <tr>
                                        <td><?= e(date('d-m-Y', strtotime($row['transaction_date']))) ?></td>
                                        <td><?= e(itemName($row)) ?></td>
                                        <td><?= e($row['quantity']) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= e($status) ?>">
                                                <?= t('status_' . $status, ucfirst($status)) ?>
                                            </span>
                                        </td>
                                        <td><?= e($row['remarks'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <?php endif; ?>
        </div>
    </main>
</div>

<script>

(function () {
    var select = document.getElementById('item_id');
    var qty = document.getElementById('quantity');
    var hint = document.getElementById('balanceHint');
    var form = document.getElementById('consumptionForm');

    // Stop if the form is not present on the page
    if (!select || !qty || !hint || !form) {
        return;
    }

    var label = <?= json_encode(
        translate('available_stock') !== 'available_stock'
            ? translate('available_stock')
            : 'Available stock',
        JSON_UNESCAPED_UNICODE
    ) ?>;

    var tooMuch = <?= json_encode(
        translate('err_insufficient') !== 'err_insufficient'
            ? translate('err_insufficient')
            : 'Quantity is more than the available stock.',
        JSON_UNESCAPED_UNICODE
    ) ?>;

    function balance() {
        var opt = select.options[select.selectedIndex];
        return opt && opt.value
            ? parseFloat(opt.dataset.balance)
            : null;
    }

    function update() {
        var b = balance();

        hint.textContent =
            b === null ? '' : label + ': ' + b;

        hint.classList.toggle(
            'is-low',
            b !== null && b <= 0
        );

        if (
            b !== null &&
            qty.value &&
            parseFloat(qty.value) > b
        ) {
            qty.setCustomValidity(tooMuch);
        } else {
            qty.setCustomValidity('');
        }
    }

    select.addEventListener('change', update);
    qty.addEventListener('input', update);

    form.addEventListener('submit', function (event) {
        update();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    update();
})();

</script>
</body>
</html>