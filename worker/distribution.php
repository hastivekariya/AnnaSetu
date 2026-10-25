<?php
/*
 * worker/distribution.php
 * For helpers, create helper/distribution.php containing only:
 *     <?php require __DIR__ . '/../worker/distribution.php';
 *
 * Requires one extra column: stock_transactions.beneficiary_count
 * (see add_beneficiary_count.sql). Uses existing columns beneficiary_type and notes.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker', 'helper']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

$errors = [];
$success = '';

$centres = [];
$foodItems = [];
$batches = [];

/* -------------------- Display Helpers -------------------- */

function bdEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bdText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function bdUuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 65535),
        random_int(0, 65535),
        random_int(0, 65535),
        random_int(16384, 20479),
        random_int(32768, 49151),
        random_int(0, 65535),
        random_int(0, 65535),
        random_int(0, 65535)
    );
}

/* Beneficiary categories: code => [English, Gujarati] */
$beneficiaryTypes = [
    'CHILD' => ['Children', 'બાળકો'],
    'PREGNANT_WOMAN' => ['Pregnant Women', 'સગર્ભા મહિલાઓ'],
    'LACTATING_MOTHER' => ['Lactating Mothers', 'ધાત્રી માતાઓ']
];

/*
 * Available quantity of one batch:
 *   approved receipts
 *   - approved AND pending consumption / distribution
 * (pending outflows are reserved so the same stock cannot be used twice)
 */
const BD_AVAILABLE_SQL = "
    COALESCE(SUM(CASE
        WHEN UPPER(t.transaction_type) = 'RECEIPT'
             AND UPPER(t.status) = 'APPROVED'
            THEN t.quantity
        WHEN UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION')
             AND UPPER(t.status) IN ('APPROVED', 'PENDING')
            THEN -t.quantity
        ELSE 0
    END), 0)";

/* -------------------- CSRF + flash -------------------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['distribution_saved'])) {
    unset($_SESSION['distribution_saved']);
    $success = bdText(
        'Distribution submitted successfully. It is waiting for supervisor verification.',
        'વિતરણની નોંધ સફળતાપૂર્વક મોકલાઈ. તે સુપરવાઇઝરની ચકાસણી માટે બાકી છે.'
    );
}

/* -------------------- Load Centres, Items, Batches -------------------- */

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

    // Food items.
    $stmt = $pdo->query(
        "SELECT item_id, item_name_en, item_name_gu, unit
         FROM food_items
         WHERE is_active = 1
         ORDER BY item_name_en"
    );

    $foodItems = $stmt->fetchAll();

    // Batches that still have stock (earliest expiry first).
    if (!empty($centres)) {
        $centreIds = array_map('intval', array_column($centres, 'centre_id'));
        $placeholders = implode(',', array_fill(0, count($centreIds), '?'));

        $stmt = $pdo->prepare(
            "SELECT b.batch_id, b.centre_id, b.item_id,
                    b.batch_number, b.expiry_date,
                    " . BD_AVAILABLE_SQL . " AS available
             FROM stock_batches b
             LEFT JOIN stock_transactions t
                ON t.batch_id = b.batch_id
             WHERE b.centre_id IN ($placeholders)
               AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
             GROUP BY b.batch_id, b.centre_id, b.item_id,
                      b.batch_number, b.expiry_date, b.received_date
             HAVING available > 0
             ORDER BY b.expiry_date IS NULL, b.expiry_date, b.received_date"
        );

        $stmt->execute($centreIds);
        $batches = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    error_log('Distribution loading error: ' . $e->getMessage());
    $errors[] = bdText(
        'Unable to load centres, food items or stock. Please check the database.',
        'કેન્દ્રો, ખાદ્ય વસ્તુઓ અથવા સ્ટોક લોડ થઈ શક્યા નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* -------------------- Process Form -------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = bdText(
            'Invalid request. Please refresh the page and try again.',
            'અમાન્ય વિનંતી. કૃપા કરીને પેજ રિફ્રેશ કરીને ફરી પ્રયત્ન કરો.'
        );
    }

    $centreId = filter_input(INPUT_POST, 'centre_id', FILTER_VALIDATE_INT);
    $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
    $batchId = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);

    $beneficiaryType = trim($_POST['beneficiary_type'] ?? '');
    $quantityInput = trim($_POST['quantity'] ?? '');
    $countInput = trim($_POST['beneficiary_count'] ?? '');
    $distributionDate = trim($_POST['distribution_date'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$centreId) {
        $errors[] = bdText('Please select an Anganwadi centre.', 'કૃપા કરીને આંગણવાડી કેન્દ્ર પસંદ કરો.');
    }

    if (!$itemId) {
        $errors[] = bdText('Please select a food item.', 'કૃપા કરીને ખાદ્ય વસ્તુ પસંદ કરો.');
    }

    if (!$batchId) {
        $errors[] = bdText('Please select a batch.', 'કૃપા કરીને બેચ પસંદ કરો.');
    }

    if (!isset($beneficiaryTypes[$beneficiaryType])) {
        $errors[] = bdText('Please select a beneficiary category.', 'કૃપા કરીને લાભાર્થીનો પ્રકાર પસંદ કરો.');
    }

    if (
        $quantityInput === '' ||
        !is_numeric($quantityInput) ||
        (float)$quantityInput <= 0
    ) {
        $errors[] = bdText('Quantity must be greater than zero.', 'જથ્થો શૂન્યથી વધુ હોવો જોઈએ.');
    }

    $beneficiaryCount = filter_var(
        $countInput,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100000]]
    );

    if ($beneficiaryCount === false) {
        $errors[] = bdText(
            'Number of beneficiaries must be a whole number greater than zero.',
            'લાભાર્થીઓની સંખ્યા શૂન્યથી વધુ પૂર્ણ સંખ્યા હોવી જોઈએ.'
        );
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $distributionDate);

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $distributionDate
    ) {
        $errors[] = bdText('Please enter a valid distribution date.', 'કૃપા કરીને માન્ય વિતરણ તારીખ દાખલ કરો.');
    } elseif ($distributionDate > date('Y-m-d')) {
        $errors[] = bdText('Distribution date cannot be in the future.', 'વિતરણની તારીખ ભવિષ્યની હોઈ શકે નહીં.');
    }

    if (mb_strlen($remarks) > 255) {
        $errors[] = bdText('Remarks must be 255 characters or fewer.', 'ટિપ્પણી ૨૫૫ અક્ષરથી વધુ ન હોવી જોઈએ.');
    }

    // Verify the centre belongs to the logged-in user.
    $validCentreIds = array_map('intval', array_column($centres, 'centre_id'));

    if ($centreId && !in_array($centreId, $validCentreIds, true)) {
        $errors[] = bdText('You are not assigned to the selected centre.', 'તમે પસંદ કરેલા કેન્દ્રમાં નિયુક્ત નથી.');
    }

    // Verify the food item exists and is active.
    $itemFound = false;

    foreach ($foodItems as $foodItem) {
        if ((int)$foodItem['item_id'] === (int)$itemId) {
            $itemFound = true;
            break;
        }
    }

    if ($itemId && !$itemFound) {
        $errors[] = bdText('The selected food item is not available.', 'પસંદ કરેલી ખાદ્ય વસ્તુ ઉપલબ્ધ નથી.');
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock the batch row so two users cannot over-distribute it.
            $stmt = $pdo->prepare(
                "SELECT batch_id, expiry_date
                 FROM stock_batches
                 WHERE batch_id = :batch_id
                   AND centre_id = :centre_id
                   AND item_id = :item_id
                 FOR UPDATE"
            );

            $stmt->execute([
                'batch_id' => $batchId,
                'centre_id' => $centreId,
                'item_id' => $itemId
            ]);

            $batch = $stmt->fetch();

            if (!$batch) {
                $pdo->rollBack();
                $errors[] = bdText(
                    'The selected batch does not belong to this centre and food item.',
                    'પસંદ કરેલો બેચ આ કેન્દ્ર અને ખાદ્ય વસ્તુનો નથી.'
                );
            } elseif (
                $batch['expiry_date'] !== null &&
                $batch['expiry_date'] < $distributionDate
            ) {
                $pdo->rollBack();
                $errors[] = bdText(
                    'This batch had already expired on the distribution date.',
                    'વિતરણની તારીખે આ બેચની સમાપ્તિ થઈ ચૂકી હતી.'
                );
            } else {
                $stmt = $pdo->prepare(
                    "SELECT " . BD_AVAILABLE_SQL . " AS available
                     FROM stock_transactions t
                     WHERE t.batch_id = :batch_id"
                );

                $stmt->execute(['batch_id' => $batchId]);
                $available = (float)$stmt->fetchColumn();
                $quantity = (float)$quantityInput;

                if (round($quantity, 3) > round($available, 3)) {
                    $pdo->rollBack();
                    $errors[] = bdText(
                        'Quantity is more than the available stock in this batch (' . $available . ').',
                        'જથ્થો આ બેચના ઉપલબ્ધ સ્ટોક (' . $available . ') કરતાં વધુ છે.'
                    );
                } else {
                    // Pending distribution transaction (category, people served
                    // and remarks are stored on the same row).
                    $stmt = $pdo->prepare(
                        "INSERT INTO stock_transactions
                            (client_transaction_id, centre_id, item_id, batch_id,
                             created_by, transaction_type, quantity,
                             transaction_date, beneficiary_type,
                             beneficiary_count, notes, status)
                         VALUES
                            (:client_id, :centre_id, :item_id, :batch_id,
                             :created_by, 'DISTRIBUTION', :quantity,
                             :transaction_date, :beneficiary_type,
                             :beneficiary_count, :notes, 'PENDING')"
                    );

                    $stmt->execute([
                        'client_id' => bdUuid(),
                        'centre_id' => $centreId,
                        'item_id' => $itemId,
                        'batch_id' => $batchId,
                        'created_by' => $userId,
                        'quantity' => $quantity,
                        'transaction_date' => $distributionDate . ' ' . date('H:i:s'),
                        'beneficiary_type' => $beneficiaryType,
                        'beneficiary_count' => $beneficiaryCount,
                        'notes' => $remarks !== '' ? $remarks : null
                    ]);

                    $transactionId = (int)$pdo->lastInsertId();

                    // Record the action in audit_logs.
                    $stmt = $pdo->prepare(
                        "INSERT INTO audit_logs
                            (user_id, action, table_name, record_id, new_values)
                         VALUES
                            (:user_id, 'CREATE', 'stock_transactions',
                             :record_id, :new_values)"
                    );

                    $stmt->execute([
                        'user_id' => $userId,
                        'record_id' => (string)$transactionId,
                        'new_values' => json_encode([
                            'transaction_type' => 'DISTRIBUTION',
                            'quantity' => $quantity,
                            'batch_id' => $batchId,
                            'beneficiary_type' => $beneficiaryType,
                            'beneficiary_count' => $beneficiaryCount,
                            'status' => 'PENDING'
                        ], JSON_UNESCAPED_UNICODE)
                    ]);

                    $pdo->commit();

                    // Post/Redirect/Get so a refresh cannot resubmit.
                    $_SESSION['distribution_saved'] = true;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: distribution.php');
                    exit;
                }
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Distribution save error: ' . $e->getMessage());

            $errors[] = bdText(
                'Unable to save the distribution entry. Please try again.',
                'વિતરણની નોંધ સાચવી શકાઈ નથી. કૃપા કરીને ફરી પ્રયત્ન કરો.'
            );
        }
    }
}

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
    ? bdText('Helper', 'મદદનીશ')
    : bdText('Worker', 'કાર્યકર');

// Data for the batch drop-down (filtered in JavaScript).
$batchData = array_map(static function ($b) {
    return [
        'id' => (int)$b['batch_id'],
        'centre' => (int)$b['centre_id'],
        'item' => (int)$b['item_id'],
        'number' => $b['batch_number'],
        'expiry' => $b['expiry_date'],
        'available' => (float)$b['available']
    ];
}, $batches);
?>
<!DOCTYPE html>
<html lang="<?= bdEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= bdEscape(bdText('Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ')) ?> | AnnaSetu
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
                <?= bdEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= bdEscape($fullName) ?></strong>
                <small><?= bdEscape($roleLabel) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= bdEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'distribution.php' ? 'active' : '' ?>">
                    <span><?= bdEscape(bdText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= bdEscape(bdText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/<?= bdEscape($role) ?>/dashboard.php">
                &larr;
                <?= bdEscape(bdText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= bdEscape(bdText('Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ')) ?>
                    / લાભાર્થીઓને વિતરણ
                </h1>

                <p>
                    <?= bdEscape(bdText(
                        'Record the food given to children, pregnant women and lactating mothers. The entry will be sent to your Supervisor for verification.',
                        'બાળકો, સગર્ભા મહિલાઓ અને ધાત્રી માતાઓને આપેલા અનાજની નોંધ કરો. ચકાસણી માટે વિગતો સુપરવાઇઝરને મોકલવામાં આવશે.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong><?= bdEscape(bdText('Please correct these issues:', 'કૃપા કરીને આ ભૂલો સુધારો:')) ?></strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= bdEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success">
                    <strong><?= bdEscape(bdText('Success!', 'સફળતા!')) ?></strong>
                    <?= bdEscape($success) ?>
                    <div class="mt-2">
                        <a href="<?= APP_URL ?>/<?= bdEscape($role) ?>/dashboard.php">
                            <?= bdEscape(bdText('Return to Dashboard', 'ડેશબોર્ડ પર જાઓ')) ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="receive-card">

                <form method="POST" action="" autocomplete="off" id="distributionForm">

                    <input type="hidden" name="csrf_token"
                           value="<?= bdEscape($_SESSION['csrf_token']) ?>">

                    <div class="row g-4">

                        <div class="col-md-6">
                            <label for="centre_id">
                                <?= bdEscape(bdText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?> *
                            </label>

                            <select name="centre_id" id="centre_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= bdEscape(bdText('Select centre', 'કેન્દ્ર પસંદ કરો')) ?>
                                </option>

                                <?php foreach ($centres as $centre): ?>
                                    <option
                                        value="<?= (int)$centre['centre_id'] ?>"
                                        <?= (string)($_POST['centre_id'] ?? '') === (string)$centre['centre_id'] ? 'selected' : '' ?>
                                    >
                                        <?= bdEscape($centre['centre_name']) ?>
                                        (<?= bdEscape($centre['centre_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (empty($centres)): ?>
                                <small class="text-danger">
                                    <?= bdEscape(bdText(
                                        'No centre is assigned to your account. Ask your administrator.',
                                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                                    )) ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label for="item_id">
                                <?= bdEscape(bdText('Food Item', 'ખાદ્ય વસ્તુ')) ?> *
                            </label>

                            <select name="item_id" id="item_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= bdEscape(bdText('Select food item', 'ખાદ્ય વસ્તુ પસંદ કરો')) ?>
                                </option>

                                <?php foreach ($foodItems as $item): ?>
                                    <option
                                        value="<?= (int)$item['item_id'] ?>"
                                        data-unit="<?= bdEscape($item['unit']) ?>"
                                        <?= (string)($_POST['item_id'] ?? '') === (string)$item['item_id'] ? 'selected' : '' ?>
                                    >
                                        <?= bdEscape(
                                            $language === 'gu'
                                                ? $item['item_name_gu']
                                                : $item['item_name_en']
                                        ) ?>
                                        (<?= bdEscape($item['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="batch_id">
                                <?= bdEscape(bdText('Batch', 'બેચ')) ?> *
                            </label>

                            <select name="batch_id" id="batch_id"
                                    class="form-select" required>
                            </select>

                            <small id="batchHint" class="stock-hint"></small>
                        </div>

                        <div class="col-md-6">
                            <label for="beneficiary_type">
                                <?= bdEscape(bdText('Beneficiary Category', 'લાભાર્થીનો પ્રકાર')) ?> *
                            </label>

                            <select name="beneficiary_type" id="beneficiary_type"
                                    class="form-select" required>
                                <option value="">
                                    <?= bdEscape(bdText('Select category', 'પ્રકાર પસંદ કરો')) ?>
                                </option>

                                <?php foreach ($beneficiaryTypes as $code => $labels): ?>
                                    <option
                                        value="<?= bdEscape($code) ?>"
                                        <?= (string)($_POST['beneficiary_type'] ?? '') === $code ? 'selected' : '' ?>
                                    >
                                        <?= bdEscape(bdText($labels[0], $labels[1])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="quantity">
                                <?= bdEscape(bdText('Quantity Distributed', 'વિતરિત જથ્થો')) ?> *
                            </label>

                            <input type="number" name="quantity"
                                   id="quantity" class="form-control"
                                   min="0.001" step="0.001"
                                   placeholder="<?= bdEscape(bdText('Enter quantity', 'જથ્થો દાખલ કરો')) ?>"
                                   required
                                   value="<?= bdEscape($_POST['quantity'] ?? '') ?>">

                            <small id="unitHelp" class="text-muted">
                                <?= bdEscape(bdText(
                                    'Select a food item to check its unit.',
                                    'એકમ જોવા માટે ખાદ્ય વસ્તુ પસંદ કરો.'
                                )) ?>
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label for="beneficiary_count">
                                <?= bdEscape(bdText('Number of Beneficiaries', 'લાભાર્થીઓની સંખ્યા')) ?> *
                            </label>

                            <input type="number" name="beneficiary_count"
                                   id="beneficiary_count" class="form-control"
                                   min="1" step="1"
                                   placeholder="<?= bdEscape(bdText('People served', 'લાભ મેળવનાર વ્યક્તિઓ')) ?>"
                                   required
                                   value="<?= bdEscape($_POST['beneficiary_count'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="distribution_date">
                                <?= bdEscape(bdText('Distribution Date', 'વિતરણની તારીખ')) ?> *
                            </label>

                            <input type="date" name="distribution_date"
                                   id="distribution_date" class="form-control"
                                   max="<?= date('Y-m-d') ?>"
                                   required
                                   value="<?= bdEscape($_POST['distribution_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="remarks">
                                <?= bdEscape(bdText('Remarks (optional)', 'ટિપ્પણી (વૈકલ્પિક)')) ?>
                            </label>

                            <input type="text" name="remarks"
                                   id="remarks" class="form-control"
                                   maxlength="255"
                                   placeholder="<?= bdEscape(bdText('Any notes', 'કોઈ નોંધ')) ?>"
                                   value="<?= bdEscape($_POST['remarks'] ?? '') ?>">
                        </div>

                    </div>

                    <div class="receive-note">
                        <strong><?= bdEscape(bdText('Note:', 'નોંધ:')) ?></strong>
                        <?= bdEscape(bdText(
                            'This entry will remain pending until a Supervisor verifies it. The quantity is reserved immediately, so it cannot be used again. Do not enter names of individual beneficiaries.',
                            'સુપરવાઇઝર ચકાસણી કરે ત્યાં સુધી આ નોંધ બાકી રહેશે. જથ્થો તરત અલગ રાખવામાં આવે છે, તેથી તેનો ફરી ઉપયોગ થઈ શકશે નહીં. વ્યક્તિગત લાભાર્થીઓનાં નામ દાખલ કરશો નહીં.'
                        )) ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn submit-button">
                            <?= bdEscape(bdText('Submit Distribution', 'વિતરણની નોંધ મોકલો')) ?>
                        </button>

                        <a href="<?= APP_URL ?>/<?= bdEscape($role) ?>/dashboard.php"
                           class="btn btn-light">
                            <?= bdEscape(bdText('Cancel', 'રદ કરો')) ?>
                        </a>
                    </div>

                </form>

            </div>

        </div>

    </main>

</div>

<script>
    const batches = <?= json_encode($batchData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const selectedBatch = <?= json_encode((string)($_POST['batch_id'] ?? '')) ?>;

    const text = {
        selectFirst: <?= json_encode(bdText('Select centre and food item first', 'પહેલા કેન્દ્ર અને ખાદ્ય વસ્તુ પસંદ કરો')) ?>,
        selectBatch: <?= json_encode(bdText('Select batch', 'બેચ પસંદ કરો')) ?>,
        noStock: <?= json_encode(bdText('No stock available for this item at this centre.', 'આ કેન્દ્રમાં આ વસ્તુનો સ્ટોક ઉપલબ્ધ નથી.')) ?>,
        available: <?= json_encode(bdText('Available', 'ઉપલબ્ધ')) ?>,
        expires: <?= json_encode(bdText('Expires', 'સમાપ્તિ')) ?>,
        unitCheck: <?= json_encode(bdText('Select a food item to check its unit.', 'એકમ જોવા માટે ખાદ્ય વસ્તુ પસંદ કરો.')) ?>,
        unit: <?= json_encode(bdText('Unit', 'એકમ')) ?>,
        tooMuch: <?= json_encode(bdText('Quantity is more than the available stock in this batch.', 'જથ્થો આ બેચના ઉપલબ્ધ સ્ટોક કરતાં વધુ છે.')) ?>
    };

    const centreSelect = document.getElementById('centre_id');
    const itemSelect = document.getElementById('item_id');
    const batchSelect = document.getElementById('batch_id');
    const batchHint = document.getElementById('batchHint');
    const quantityInput = document.getElementById('quantity');
    const unitHelp = document.getElementById('unitHelp');

    function formatDate(value) {
        if (!value) return '';
        const parts = value.split('-');
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }

    function currentBatch() {
        return batches.find(function (b) {
            return String(b.id) === batchSelect.value;
        });
    }

    function updateQuantityLimit() {
        const batch = currentBatch();

        if (batch) {
            quantityInput.max = batch.available;
            batchHint.textContent = text.available + ': ' + batch.available;
            batchHint.classList.remove('is-empty');
        } else {
            quantityInput.removeAttribute('max');
        }

        const value = parseFloat(quantityInput.value);

        if (batch && !isNaN(value) && value > batch.available) {
            quantityInput.setCustomValidity(text.tooMuch);
        } else {
            quantityInput.setCustomValidity('');
        }
    }

    function updateBatches(keepSelection) {
        const centreId = centreSelect.value;
        const itemId = itemSelect.value;

        batchSelect.innerHTML = '';
        batchHint.textContent = '';
        batchHint.classList.remove('is-empty');

        if (!centreId || !itemId) {
            batchSelect.add(new Option(text.selectFirst, ''));
            updateQuantityLimit();
            return;
        }

        const matches = batches.filter(function (b) {
            return String(b.centre) === centreId && String(b.item) === itemId;
        });

        if (matches.length === 0) {
            batchSelect.add(new Option(text.noStock, ''));
            batchHint.textContent = text.noStock;
            batchHint.classList.add('is-empty');
            updateQuantityLimit();
            return;
        }

        batchSelect.add(new Option(text.selectBatch, ''));

        matches.forEach(function (b) {
            let label = b.number + ' — ' + text.available + ': ' + b.available;

            if (b.expiry) {
                label += ' — ' + text.expires + ': ' + formatDate(b.expiry);
            }

            const option = new Option(label, b.id);

            if (keepSelection && String(b.id) === selectedBatch) {
                option.selected = true;
            }

            batchSelect.add(option);
        });

        updateQuantityLimit();
    }

    function updateUnit() {
        const option = itemSelect.options[itemSelect.selectedIndex];

        unitHelp.textContent = option && option.value
            ? text.unit + ': ' + (option.dataset.unit || '')
            : text.unitCheck;
    }

    centreSelect.addEventListener('change', function () { updateBatches(false); });

    itemSelect.addEventListener('change', function () {
        updateUnit();
        updateBatches(false);
    });

    batchSelect.addEventListener('change', updateQuantityLimit);
    quantityInput.addEventListener('input', updateQuantityLimit);

    updateUnit();
    updateBatches(true);
</script>

</body>
</html>