<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['WORKER', 'HELPER']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'User';
$language = $_SESSION['language'] ?? 'gu';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

// Translate a page label.
function consumptionText($english, $gujarati)
{
    $language = $_SESSION['language'] ?? 'gu';
    return $language === 'gu' ? $gujarati : $english;
}

// Escape output.
function consumptionEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Find centres the logged-in user can access.
function getConsumptionCentres($pdo, $userId)
{
    $sql = "
        SELECT DISTINCT c.centre_id, c.centre_name, c.centre_code
        FROM anganwadi_centres c
        LEFT JOIN users u ON u.centre_id = c.centre_id
        LEFT JOIN centre_assignments ca
            ON ca.centre_id = c.centre_id
            AND ca.user_id = :assignment_user
            AND ca.is_active = 1
        WHERE c.is_active = 1
          AND (
              u.user_id = :direct_user
              OR ca.user_id = :assigned_user
          )
        ORDER BY c.centre_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'assignment_user' => $userId,
        'direct_user' => $userId,
        'assigned_user' => $userId
    ]);

    return $stmt->fetchAll();
}

try {
    $centres = getConsumptionCentres($pdo, $userId);

    // Submit daily consumption.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception('Your session expired. Refresh the page and try again.');
        }

        $centreId = filter_input(INPUT_POST, 'centre_id', FILTER_VALIDATE_INT);
        $batchId = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_FLOAT);
        $transactionDate = trim($_POST['transaction_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$centreId || !$batchId || $quantity === false || $quantity <= 0) {
            throw new Exception('Please enter a valid centre, batch and quantity.');
        }

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transactionDate) ||
            !checkdate(
                (int) substr($transactionDate, 5, 2),
                (int) substr($transactionDate, 8, 2),
                (int) substr($transactionDate, 0, 4)
            )
        ) {
            throw new Exception('Please select a valid transaction date.');
        }

        if ($transactionDate > date('Y-m-d')) {
            throw new Exception('The consumption date cannot be in the future.');
        }

        // Check whether the selected centre belongs to this user.
        $allowedCentre = false;

        foreach ($centres as $centre) {
            if ((int) $centre['centre_id'] === (int) $centreId) {
                $allowedCentre = true;
                break;
            }
        }

        if (!$allowedCentre) {
            throw new Exception('You do not have permission to use this centre.');
        }

        // Verify that the selected batch belongs to the selected centre.
        $stmt = $pdo->prepare("
            SELECT
                b.batch_id,
                b.item_id,
                b.expiry_date,
                f.item_name_en,
                f.item_name_gu,
                f.unit
            FROM stock_batches b
            JOIN food_items f ON f.item_id = b.item_id
            WHERE b.batch_id = :batch_id
              AND b.centre_id = :centre_id
              AND f.is_active = 1
            LIMIT 1
        ");

        $stmt->execute([
            'batch_id' => $batchId,
            'centre_id' => $centreId
        ]);

        $batch = $stmt->fetch();

        if (!$batch) {
            throw new Exception('The selected batch was not found for this centre.');
        }

        if (
            !empty($batch['expiry_date']) &&
            $batch['expiry_date'] < $transactionDate
        ) {
            throw new Exception('This batch was expired on the selected consumption date.');
        }

        // Check approved stock and pending consumption.
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(
                    CASE
                        WHEN transaction_type = 'RECEIPT'
                            THEN quantity
                        WHEN transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                            THEN -quantity
                        WHEN transaction_type = 'ADJUSTMENT'
                            THEN quantity
                        ELSE 0
                    END
                ), 0) AS approved_balance
            FROM stock_transactions
            WHERE centre_id = :centre_id
              AND batch_id = :batch_id
              AND status = 'APPROVED'
            FOR UPDATE
        ");

        $stmt->execute([
            'centre_id' => $centreId,
            'batch_id' => $batchId
        ]);

        $approvedBalance = (float) $stmt->fetchColumn();

        // Reserve quantities in pending consumption requests to avoid
        // allowing several requests to exceed the current stock.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0)
            FROM stock_transactions
            WHERE centre_id = :centre_id
              AND batch_id = :batch_id
              AND transaction_type = 'CONSUMPTION'
              AND status = 'PENDING'
        ");

        $stmt->execute([
            'centre_id' => $centreId,
            'batch_id' => $batchId
        ]);

        $pendingConsumption = (float) $stmt->fetchColumn();
        $availableForRequest = $approvedBalance - $pendingConsumption;

        if ($quantity > $availableForRequest) {
            $pdo->rollBack();

            throw new Exception(
                'Insufficient stock. Available for a new request: ' .
                number_format(max(0, $availableForRequest), 3) .
                ' ' . $batch['unit']
            );
        }

        // Store the request as pending. It does not reduce approved stock yet.
        $clientTransactionId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 16383) | 0x8000,
            random_int(0, 32767) | 0x4000,
            random_int(0, 65535),
            random_int(0, 65535),
            random_int(0, 65535)
        );

        $stmt = $pdo->prepare("
            INSERT INTO stock_transactions (
                client_transaction_id,
                centre_id,
                item_id,
                batch_id,
                created_by,
                transaction_type,
                quantity,
                transaction_date,
                notes,
                status
            ) VALUES (
                :client_id,
                :centre_id,
                :item_id,
                :batch_id,
                :created_by,
                'CONSUMPTION',
                :quantity,
                :transaction_date,
                :notes,
                'PENDING'
            )
        ");

        $stmt->execute([
            'client_id' => $clientTransactionId,
            'centre_id' => $centreId,
            'item_id' => $batch['item_id'],
            'batch_id' => $batchId,
            'created_by' => $userId,
            'quantity' => $quantity,
            'transaction_date' => $transactionDate . ' ' . date('H:i:s'),
            'notes' => $notes !== '' ? $notes : null
        ]);

        $transactionId = (int) $pdo->lastInsertId();

        // Create an audit record.
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id,
                action,
                table_name,
                record_id,
                old_values,
                new_values
            ) VALUES (
                :user_id,
                'CREATE_CONSUMPTION',
                'stock_transactions',
                :record_id,
                NULL,
                :new_values
            )
        ");

        $stmt->execute([
            'user_id' => $userId,
            'record_id' => (string) $transactionId,
            'new_values' => json_encode([
                'centre_id' => $centreId,
                'item_id' => (int) $batch['item_id'],
                'batch_id' => $batchId,
                'quantity' => $quantity,
                'status' => 'PENDING'
            ], JSON_UNESCAPED_UNICODE)
        ]);

        $pdo->commit();

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: daily-consumption.php?success=1');
        exit;
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = $e->getMessage();
        $messageType = 'danger';
    } else {
        error_log('Daily consumption page error: ' . $e->getMessage());
        $centres = [];
        $message = 'Unable to load the page data. Check your database settings.';
        $messageType = 'danger';
    }
}

if (isset($_GET['success'])) {
    $message = 'Consumption submitted successfully. It is waiting for supervisor verification.';
    $messageType = 'success';
}

// Load food batches and their balances for each accessible centre.
$batches = [];

try {
    if (!empty($centres)) {
        $centreIds = array_map(
            fn($centre) => (int) $centre['centre_id'],
            $centres
        );

        $placeholders = implode(',', array_fill(0, count($centreIds), '?'));

        $sql = "
            SELECT
                b.batch_id,
                b.centre_id,
                b.item_id,
                b.batch_number,
                b.expiry_date,
                c.centre_name,
                f.item_name_en,
                f.item_name_gu,
                f.unit,
                COALESCE(SUM(
                    CASE
                        WHEN t.status = 'APPROVED'
                         AND t.transaction_type = 'RECEIPT'
                            THEN t.quantity
                        WHEN t.status = 'APPROVED'
                         AND t.transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                            THEN -t.quantity
                        WHEN t.status = 'APPROVED'
                         AND t.transaction_type = 'ADJUSTMENT'
                            THEN t.quantity
                        ELSE 0
                    END
                ), 0) AS balance,
                COALESCE(SUM(
                    CASE
                        WHEN t.status = 'PENDING'
                         AND t.transaction_type = 'CONSUMPTION'
                            THEN t.quantity
                        ELSE 0
                    END
                ), 0) AS pending_consumption
            FROM stock_batches b
            JOIN anganwadi_centres c ON c.centre_id = b.centre_id
            JOIN food_items f ON f.item_id = b.item_id
            LEFT JOIN stock_transactions t ON t.batch_id = b.batch_id
            WHERE b.centre_id IN ($placeholders)
              AND f.is_active = 1
            GROUP BY
                b.batch_id,
                b.centre_id,
                b.item_id,
                b.batch_number,
                b.expiry_date,
                c.centre_name,
                f.item_name_en,
                f.item_name_gu,
                f.unit
            ORDER BY c.centre_name, f.item_name_en, b.expiry_date
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($centreIds);
        $batches = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('Daily consumption batch query error: ' . $e->getMessage());
    $message = 'Unable to load stock batches. Please check the database structure.';
    $messageType = 'danger';
}
?>

<!DOCTYPE html>
<html lang="<?= consumptionEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= consumptionEscape(consumptionText('Daily Consumption', 'દૈનિક વપરાશ')) ?>
        | AnnaSetu
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=2"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f6fb;
            color: #153f35;
            font-family: Arial, Helvetica, sans-serif;
        }

        .consumption-layout {
            display: flex;
            min-height: 100vh;
        }

        .consumption-sidebar {
            width: 280px;
            flex-shrink: 0;
            background: #103d32;
            color: #fff;
            padding: 24px 19px;
        }

        .brand {
            text-align: center;
            padding: 0 0 24px;
            border-bottom: 1px solid rgba(255,255,255,.17);
        }

        .brand h2 {
            font-size: 30px;
            margin: 0;
            font-weight: 700;
        }

        .brand p {
            margin: 4px 0 0;
            letter-spacing: 2px;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 24px 7px;
            overflow-wrap: anywhere;
        }

        .avatar {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #d7f4e2;
            color: #103d32;
            font-size: 20px;
            font-weight: bold;
        }

        .sidebar-user small {
            display: block;
            margin-top: 4px;
            color: #c8e4dc;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-menu a,
        .logout-link {
            display: block;
            padding: 13px 15px;
            border-radius: 9px;
            color: #fff;
            text-decoration: none;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #256e58;
        }

        .logout-link {
            margin-top: 20px;
            background: rgba(255,255,255,.09);
        }

        .consumption-main {
            flex: 1;
            min-width: 0;
            padding: 35px;
        }

        .page-heading {
            margin-bottom: 24px;
        }

        .page-heading h1 {
            font-size: clamp(26px, 3vw, 36px);
            font-weight: 700;
        }

        .page-heading p {
            color: #64748b;
        }

        .form-card {
            max-width: 1000px;
            background: #fff;
            padding: 32px;
            border-radius: 18px;
            box-shadow: 0 5px 25px rgba(22, 50, 75, .06);
        }

        .form-label {
            font-weight: 700;
            margin-bottom: 9px;
        }

        .form-control,
        .form-select {
            min-height: 52px;
            border-radius: 11px;
        }

        textarea.form-control {
            min-height: 100px;
        }

        .stock-info {
            background: #eaf7f0;
            border: 1px solid #d1e9db;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
            color: #155c45;
        }

        .submit-button {
            background: #176b52;
            border: 0;
            border-radius: 10px;
            color: #fff;
            font-weight: 700;
            padding: 14px 24px;
        }

        .submit-button:hover {
            background: #10543f;
            color: #fff;
        }

        .back-link {
            display: inline-block;
            color: #176b52;
            text-decoration: none;
            font-weight: 700;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .consumption-layout {
                display: block;
            }

            .consumption-sidebar {
                width: 100%;
                padding: 16px;
            }

            .brand {
                text-align: left;
            }

            .sidebar-menu {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .consumption-main {
                padding: 22px 16px;
            }

            .form-card {
                padding: 22px;
            }
        }

        @media (max-width: 480px) {
            .sidebar-menu {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<?php require_once __DIR__ . '/../includes/page-header.php'; ?>
<div class="consumption-layout">

    <!-- Sidebar -->
    <aside class="consumption-sidebar">

        <div class="brand">
            <h2>અન્નસેતુ</h2>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="avatar">
                <?= consumptionEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= consumptionEscape($fullName) ?></strong>
                <small><?= consumptionEscape(ucfirst($role)) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">
            <a href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/dashboard.php">
                <?= consumptionText('Dashboard', 'ડેશબોર્ડ') ?>
            </a>

            <?php if ($role === 'worker'): ?>
                <a href="<?= APP_URL ?>/worker/receive-stock.php">
                    <?= consumptionText('Receive Stock', 'સ્ટોક મેળવો') ?>
                </a>
            <?php endif; ?>

            <a class="active"
               href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/daily-consumption.php">
                <?= consumptionText('Daily Consumption', 'દૈનિક વપરાશ') ?>
            </a>

            <a href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/distribution.php">
                <?= consumptionText('Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ') ?>
            </a>

            <a href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/current-stock.php">
                <?= consumptionText('Current Stock', 'હાલનો સ્ટોક') ?>
            </a>

            <?php if ($role === 'worker'): ?>
                <a href="<?= APP_URL ?>/worker/stock-history.php">
                    <?= consumptionText('Stock History', 'સ્ટોકનો ઇતિહાસ') ?>
                </a>
                <a href="<?= APP_URL ?>/worker/reports.php">
                    <?= consumptionText('Reports', 'અહેવાલો') ?>
                </a>
                <a href="<?= APP_URL ?>/worker/notifications.php">
                    <?= consumptionText('Notifications', 'સૂચનાઓ') ?>
                </a>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/profile.php">
                <?= consumptionText('My Profile', 'મારી પ્રોફાઇલ') ?>
            </a>
        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= consumptionText('Logout', 'લોગઆઉટ') ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="consumption-main">

        <a class="back-link"
           href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/dashboard.php">
            &larr; <?= consumptionText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ') ?>
        </a>

        <div class="page-heading">
            <h1>
                <?= consumptionText('Daily Consumption', 'દૈનિક વપરાશ') ?>
                / <?= consumptionText('દૈનિક વપરાશ', 'દૈનિક વપરાશ') ?>
            </h1>

            <p>
                <?= consumptionText(
                    'Record the food consumed at your Anganwadi centre.',
                    'તમારા આંગણવાડી કેન્દ્રમાં વપરાયેલ ખોરાકની નોંધ કરો.'
                ) ?>
            </p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= consumptionEscape($messageType) ?>">
                <?= consumptionEscape($message) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($centres)): ?>

            <div class="alert alert-warning">
                <?= consumptionText(
                    'No centre is assigned to your account. Ask your administrator to assign a centre.',
                    'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. સંચાલકને કેન્દ્ર સોંપવા કહો.'
                ) ?>
            </div>

        <?php else: ?>

            <div class="form-card">

                <form method="POST" action="daily-consumption.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= consumptionEscape($_SESSION['csrf_token']) ?>"
                    >

                    <div class="row g-4">

                        <div class="col-md-6">
                            <label for="centre_id" class="form-label">
                                <?= consumptionText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર') ?> *
                            </label>

                            <select name="centre_id" id="centre_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= consumptionText('Select centre', 'કેન્દ્ર પસંદ કરો') ?>
                                </option>

                                <?php foreach ($centres as $centre): ?>
                                    <option value="<?= (int) $centre['centre_id'] ?>">
                                        <?= consumptionEscape($centre['centre_name']) ?>
                                        (<?= consumptionEscape($centre['centre_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="batch_id" class="form-label">
                                <?= consumptionText('Food Batch', 'ખાદ્ય બેચ') ?> *
                            </label>

                            <select name="batch_id" id="batch_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= consumptionText('Select centre first', 'પહેલા કેન્દ્ર પસંદ કરો') ?>
                                </option>

                                <?php foreach ($batches as $batch): ?>
                                    <?php
                                    $itemName = $language === 'gu'
                                        ? $batch['item_name_gu']
                                        : $batch['item_name_en'];

                                    $available = (float) $batch['balance']
                                        - (float) $batch['pending_consumption'];
                                    ?>
                                    <option
                                        value="<?= (int) $batch['batch_id'] ?>"
                                        data-centre="<?= (int) $batch['centre_id'] ?>"
                                        data-available="<?= consumptionEscape(max(0, $available)) ?>"
                                        data-unit="<?= consumptionEscape($batch['unit']) ?>"
                                        data-item="<?= consumptionEscape($itemName) ?>"
                                    >
                                        <?= consumptionEscape($itemName) ?>
                                        - <?= consumptionEscape($batch['batch_number']) ?>
                                        (<?= consumptionEscape($batch['centre_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="quantity" class="form-label">
                                <?= consumptionText('Consumed Quantity', 'વપરાયેલ જથ્થો') ?> *
                            </label>

                            <input
                                type="number"
                                name="quantity"
                                id="quantity"
                                class="form-control"
                                min="0.001"
                                step="0.001"
                                placeholder="<?= consumptionText('Enter quantity', 'જથ્થો દાખલ કરો') ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label for="transaction_date" class="form-label">
                                <?= consumptionText('Consumption Date', 'વપરાશની તારીખ') ?> *
                            </label>

                            <input
                                type="date"
                                name="transaction_date"
                                id="transaction_date"
                                class="form-control"
                                value="<?= date('Y-m-d') ?>"
                                max="<?= date('Y-m-d') ?>"
                                required
                            >
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label">
                                <?= consumptionText('Notes (optional)', 'નોંધ (વૈકલ્પિક)') ?>
                            </label>

                            <textarea
                                name="notes"
                                id="notes"
                                class="form-control"
                                maxlength="2000"
                                placeholder="<?= consumptionText(
                                    'Enter a short note, if required',
                                    'જરૂર હોય તો ટૂંકી નોંધ લખો'
                                ) ?>"
                            ></textarea>
                        </div>

                    </div>

                    <div class="stock-info" id="stock-info">
                        <strong>
                            <?= consumptionText('Available stock:', 'ઉપલબ્ધ સ્ટોક:') ?>
                        </strong>
                        <span id="available-stock">
                            <?= consumptionText('Select a batch to view stock', 'સ્ટોક જોવા માટે બેચ પસંદ કરો') ?>
                        </span>
                        <div id="stock-warning" class="small mt-2"></div>
                    </div>

                    <div class="alert alert-info mt-4">
                        <strong><?= consumptionText('Note:', 'નોંધ:') ?></strong>
                        <?= consumptionText(
                            'Your consumption entry will remain pending until the supervisor verifies it.',
                            'સુપરવાઇઝર ચકાસણી કરે ત્યાં સુધી તમારી નોંધ બાકી રહેશે.'
                        ) ?>
                    </div>

                    <div class="d-flex flex-wrap gap-3 mt-4">
                        <button type="submit" class="btn submit-button" id="submit-button">
                            <?= consumptionText('Submit Consumption', 'વપરાશ નોંધ સબમિટ કરો') ?>
                        </button>

                        <a class="btn btn-light py-3 px-4"
                           href="<?= APP_URL ?>/<?= consumptionEscape($role) ?>/dashboard.php">
                            <?= consumptionText('Cancel', 'રદ કરો') ?>
                        </a>
                    </div>

                </form>

            </div>

        <?php endif; ?>

    </main>
</div>

<script>
const centreSelect = document.getElementById('centre_id');
const batchSelect = document.getElementById('batch_id');
const quantityInput = document.getElementById('quantity');
const availableStock = document.getElementById('available-stock');
const stockWarning = document.getElementById('stock-warning');
const submitButton = document.getElementById('submit-button');

function updateBatches() {
    if (!centreSelect || !batchSelect) {
        return;
    }

    const selectedCentre = centreSelect.value;
    let visibleCount = 0;

    for (let i = 0; i < batchSelect.options.length; i++) {
        const option = batchSelect.options[i];

        if (!option.value) {
            option.hidden = false;
            continue;
        }

        const matches = option.dataset.centre === selectedCentre;
        option.hidden = !matches;

        if (matches) {
            visibleCount++;
        }
    }

    batchSelect.value = '';
    updateAvailableStock();

    if (selectedCentre && visibleCount === 0) {
        stockWarning.textContent =
            'No batch is available for this centre. Add a stock receipt first.';
    }
}

function updateAvailableStock() {
    if (!batchSelect || !availableStock) {
        return;
    }

    const option = batchSelect.options[batchSelect.selectedIndex];

    if (!option || !option.value) {
        availableStock.textContent = 'Select a batch to view stock';
        stockWarning.textContent = '';
        return;
    }

    const quantity = parseFloat(option.dataset.available || '0');
    const unit = option.dataset.unit || '';
    const item = option.dataset.item || '';

    availableStock.textContent =
        item + ': ' + quantity.toFixed(3) + ' ' + unit;

    checkQuantity();
}

function checkQuantity() {
    if (!batchSelect || !quantityInput || !submitButton) {
        return;
    }

    const option = batchSelect.options[batchSelect.selectedIndex];

    if (!option || !option.value) {
        submitButton.disabled = true;
        return;
    }

    const available = parseFloat(option.dataset.available || '0');
    const quantity = parseFloat(quantityInput.value || '0');

    if (available <= 0) {
        stockWarning.textContent = 'No available stock in this batch.';
        submitButton.disabled = true;
    } else if (quantity > available) {
        stockWarning.textContent = 'Quantity exceeds available stock.';
        submitButton.disabled = true;
    } else {
        stockWarning.textContent = '';
        submitButton.disabled = false;
    }
}

if (centreSelect) {
    centreSelect.addEventListener('change', updateBatches);
}

if (batchSelect) {
    batchSelect.addEventListener('change', updateAvailableStock);
}

if (quantityInput) {
    quantityInput.addEventListener('input', checkQuantity);
}

updateBatches();
</script>

</body>
</html>
