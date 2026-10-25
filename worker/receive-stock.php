
<?php
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

$errors = [];
$success = '';

$centres = [];
$foodItems = [];

/* -------------------- Load Centres -------------------- */

try {
    // Load only centres assigned to the logged-in worker.
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

    /* -------------------- Load Food Items -------------------- */

    $stmt = $pdo->query(
        "SELECT item_id, item_name_en, item_name_gu, unit,
                expiry_tracking
         FROM food_items
         WHERE is_active = 1
         ORDER BY item_name_en"
    );

    $foodItems = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Receive stock loading error: ' . $e->getMessage());
    $errors[] = 'Unable to load centres or food items. Please check the database.';
}

/* -------------------- Process Form -------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $centreId = filter_input(INPUT_POST, 'centre_id', FILTER_VALIDATE_INT);
    $itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);

    $batchNumber = trim($_POST['batch_number'] ?? '');
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $quantityInput = trim($_POST['quantity'] ?? '');
    $receivedDate = trim($_POST['received_date'] ?? '');
    $expiryDate = trim($_POST['expiry_date'] ?? '');

    if (!$centreId) {
        $errors[] = 'Please select an Anganwadi centre.';
    }

    if (!$itemId) {
        $errors[] = 'Please select a food item.';
    }

    if ($batchNumber === '') {
        $errors[] = 'Please enter the batch number.';
    }

    if ($supplierName === '') {
        $errors[] = 'Please enter the supplier name.';
    }

    if (
        $quantityInput === '' ||
        !is_numeric($quantityInput) ||
        (float)$quantityInput <= 0
    ) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $receivedDate);

    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $receivedDate
    ) {
        $errors[] = 'Please enter a valid received date.';
    }

    if ($expiryDate !== '') {
        $expiryObject = DateTime::createFromFormat('!Y-m-d', $expiryDate);

        if (
            !$expiryObject ||
            $expiryObject->format('Y-m-d') !== $expiryDate
        ) {
            $errors[] = 'Please enter a valid expiry date.';
        } elseif (
            isset($dateObject) &&
            $expiryDate < $receivedDate
        ) {
            $errors[] = 'Expiry date cannot be before the received date.';
        }
    }

    // Verify the centre belongs to the logged-in worker.
    $validCentreIds = array_map(
        'intval',
        array_column($centres, 'centre_id')
    );

    if ($centreId && !in_array($centreId, $validCentreIds, true)) {
        $errors[] = 'You are not assigned to the selected centre.';
    }

    // Verify the food item exists and is active.
    $selectedItem = null;

    foreach ($foodItems as $foodItem) {
        if ((int)$foodItem['item_id'] === (int)$itemId) {
            $selectedItem = $foodItem;
            break;
        }
    }

    if (!$selectedItem && $itemId) {
        $errors[] = 'The selected food item is not available.';
    }

    if (
        $selectedItem &&
        (int)$selectedItem['expiry_tracking'] === 1 &&
        $expiryDate === ''
    ) {
        $errors[] = 'Please enter the expiry date for this food item.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Create the batch.
            $stmt = $pdo->prepare(
                "INSERT INTO stock_batches
                    (centre_id, item_id, batch_number,
                     received_date, expiry_date, supplier_name, created_by)
                 VALUES
                    (:centre_id, :item_id, :batch_number,
                     :received_date, :expiry_date, :supplier_name, :created_by)"
            );

            $stmt->execute([
                'centre_id' => $centreId,
                'item_id' => $itemId,
                'batch_number' => $batchNumber,
                'received_date' => $receivedDate,
                'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                'supplier_name' => $supplierName,
                'created_by' => $userId
            ]);

            $batchId = (int)$pdo->lastInsertId();

            // Create a pending receipt transaction.
            $clientTransactionId = sprintf(
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

            $stmt = $pdo->prepare(
                "INSERT INTO stock_transactions
                    (client_transaction_id, centre_id, item_id, batch_id,
                     created_by, transaction_type, quantity,
                     transaction_date, status)
                 VALUES
                    (:client_id, :centre_id, :item_id, :batch_id,
                     :created_by, 'RECEIPT', :quantity,
                     :transaction_date, 'PENDING')"
            );

            $stmt->execute([
                'client_id' => $clientTransactionId,
                'centre_id' => $centreId,
                'item_id' => $itemId,
                'batch_id' => $batchId,
                'created_by' => $userId,
                'quantity' => (float)$quantityInput,
                'transaction_date' => $receivedDate . ' ' . date('H:i:s')
            ]);

            $transactionId = (int)$pdo->lastInsertId();

            // Record the action in audit_logs.
            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs
                    (user_id, action, table_name, record_id, new_values)
                 VALUES
                    (:user_id, 'CREATE', 'STOCK_TRANSACTION',
                     :record_id, :new_values)"
            );

            $stmt->execute([
                'user_id' => $userId,
                'record_id' => (string)$transactionId,
                'new_values' => json_encode([
                    'transaction_type' => 'RECEIPT',
                    'quantity' => (float)$quantityInput,
                    'batch_id' => $batchId,
                    'status' => 'PENDING'
                ], JSON_UNESCAPED_UNICODE)
            ]);

            $pdo->commit();

            $success = 'Stock receipt submitted successfully. It is waiting for supervisor verification.';

            // Clear submitted values after successful save.
            $_POST = [];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Receive stock save error: ' . $e->getMessage());

            $errors[] = 'Unable to save the stock receipt. Check the batch number and try again.';
        }
    }
}

/* -------------------- Display Helpers -------------------- */

function rsEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rsText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}
?>
<!DOCTYPE html>
<html lang="<?= rsEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= rsEscape(rsText('Receive Stock', 'સ્ટોક મેળવો')) ?> | AnnaSetu
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=3"
    >

    <style>
        .receive-content {
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
        }

        .receive-heading {
            margin-bottom: 26px;
        }

        .receive-heading h1 {
            color: #123e34;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .receive-heading p {
            color: #64748b;
            font-size: 1.05rem;
        }

        .receive-card {
            background: #fff;
            border: 1px solid #edf1f6;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 8px 28px rgba(22, 50, 70, 0.06);
        }

        .receive-card label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #123e34;
        }

        .receive-card .form-control,
        .receive-card .form-select {
            min-height: 52px;
            border-radius: 11px;
            border-color: #d9e0e8;
            font-size: 1rem;
        }

        .receive-card .form-control:focus,
        .receive-card .form-select:focus {
            border-color: #218568;
            box-shadow: 0 0 0 0.2rem rgba(33, 133, 104, 0.12);
        }

        .back-dashboard {
            display: inline-block;
            color: #16765e;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 24px;
        }

        .back-dashboard:hover {
            text-decoration: underline;
        }

        .receive-note {
            border-radius: 10px;
            padding: 18px 20px;
            margin-top: 24px;
            background: #d2f5fb;
            border: 1px solid #94e9f7;
            color: #07556a;
        }

        .submit-button {
            background: #176f56;
            color: #fff;
            border: 0;
            border-radius: 10px;
            padding: 14px 22px;
            font-weight: 700;
        }

        .submit-button:hover {
            background: #105640;
            color: #fff;
        }

        .form-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        @media (max-width: 768px) {
            .receive-card {
                padding: 20px;
            }

            .receive-heading h1 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>

<body>
   <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

<div class="dashboard-layout">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <?= rsEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= rsEscape($fullName) ?></strong>
                <small><?= rsEscape(rsText('Worker', 'કાર્યકર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <a href="<?= APP_URL ?>/worker/dashboard.php" class="menu-link">
                <span><?= rsEscape(rsText('Dashboard', 'ડેશબોર્ડ')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/receive-stock.php"
               class="menu-link active">
                <span><?= rsEscape(rsText('Receive Stock', 'સ્ટોક મેળવો')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/daily-consumption.php" class="menu-link">
                <span><?= rsEscape(rsText('Daily Consumption', 'દૈનિક વપરાશ')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/distribution.php" class="menu-link">
                <span><?= rsEscape(rsText('Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/current-stock.php" class="menu-link">
                <span><?= rsEscape(rsText('Current Stock', 'હાલનો સ્ટોક')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/stock-history.php" class="menu-link">
                <span><?= rsEscape(rsText('Stock History', 'સ્ટોકનો ઇતિહાસ')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/reports.php" class="menu-link">
                <span><?= rsEscape(rsText('Reports', 'અહેવાલો')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/notifications.php" class="menu-link">
                <span><?= rsEscape(rsText('Notifications', 'સૂચનાઓ')) ?></span>
            </a>

            <a href="<?= APP_URL ?>/worker/profile.php" class="menu-link">
                <span><?= rsEscape(rsText('My Profile', 'મારી પ્રોફાઇલ')) ?></span>
            </a>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= rsEscape(rsText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <div class="receive-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/worker/dashboard.php">
                &larr;
                <?= rsEscape(rsText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= rsEscape(rsText('Receive Stock', 'સ્ટોક મેળવો')) ?>
                    / સ્ટોક મેળવો
                </h1>

                <p>
                    <?= rsEscape(rsText(
                        'Enter the received food stock details. The receipt will be sent to your Supervisor for verification.',
                        'મળેલા અનાજની વિગતો દાખલ કરો. ચકાસણી માટે વિગતો સુપરવાઇઝરને મોકલવામાં આવશે.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong><?= rsEscape(rsText('Please correct these issues:', 'કૃપા કરીને આ ભૂલો સુધારો:')) ?></strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= rsEscape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success">
                    <strong><?= rsEscape(rsText('Success!', 'સફળતા!')) ?></strong>
                    <?= rsEscape($success) ?>
                    <div class="mt-2">
                        <a href="<?= APP_URL ?>/worker/dashboard.php">
                            <?= rsEscape(rsText('Return to Dashboard', 'ડેશબોર્ડ પર જાઓ')) ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="receive-card">

                <form method="POST" action="" autocomplete="off">

                    <div class="row g-4">

                        <div class="col-md-6">
                            <label for="centre_id">
                                <?= rsEscape(rsText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?> *
                            </label>

                            <select name="centre_id" id="centre_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= rsEscape(rsText('Select centre', 'કેન્દ્ર પસંદ કરો')) ?>
                                </option>

                                <?php foreach ($centres as $centre): ?>
                                    <option
                                        value="<?= (int)$centre['centre_id'] ?>"
                                        <?= (string)($_POST['centre_id'] ?? '') === (string)$centre['centre_id'] ? 'selected' : '' ?>
                                    >
                                        <?= rsEscape($centre['centre_name']) ?>
                                        (<?= rsEscape($centre['centre_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (empty($centres)): ?>
                                <small class="text-danger">
                                    <?= rsEscape(rsText(
                                        'No centre is assigned to your account. Ask your administrator.',
                                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                                    )) ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label for="item_id">
                                <?= rsEscape(rsText('Food Item', 'ખાદ્ય વસ્તુ')) ?> *
                            </label>

                            <select name="item_id" id="item_id"
                                    class="form-select" required>
                                <option value="">
                                    <?= rsEscape(rsText('Select food item', 'ખાદ્ય વસ્તુ પસંદ કરો')) ?>
                                </option>

                                <?php foreach ($foodItems as $item): ?>
                                    <option
                                        value="<?= (int)$item['item_id'] ?>"
                                        data-unit="<?= rsEscape($item['unit']) ?>"
                                        data-expiry="<?= (int)$item['expiry_tracking'] ?>"
                                        <?= (string)($_POST['item_id'] ?? '') === (string)$item['item_id'] ? 'selected' : '' ?>
                                    >
                                        <?= rsEscape(
                                            $language === 'gu'
                                                ? $item['item_name_gu']
                                                : $item['item_name_en']
                                        ) ?>
                                        (<?= rsEscape($item['unit']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="batch_number">
                                <?= rsEscape(rsText('Batch Number', 'બેચ નંબર')) ?> *
                            </label>

                            <input type="text" name="batch_number"
                                   id="batch_number" class="form-control"
                                   placeholder="Example: RICE002"
                                   maxlength="100" required
                                   value="<?= rsEscape($_POST['batch_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_name">
                                <?= rsEscape(rsText('Supplier Name', 'સપ્લાયરનું નામ')) ?> *
                            </label>

                            <input type="text" name="supplier_name"
                                   id="supplier_name" class="form-control"
                                   placeholder="<?= rsEscape(rsText('Enter supplier name', 'સપ્લાયરનું નામ દાખલ કરો')) ?>"
                                   maxlength="150" required
                                   value="<?= rsEscape($_POST['supplier_name'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="quantity">
                                <?= rsEscape(rsText('Received Quantity', 'મળેલ જથ્થો')) ?> *
                            </label>

                            <input type="number" name="quantity"
                                   id="quantity" class="form-control"
                                   min="0.001" step="0.001"
                                   placeholder="<?= rsEscape(rsText('Enter quantity', 'જથ્થો દાખલ કરો')) ?>"
                                   required
                                   value="<?= rsEscape($_POST['quantity'] ?? '') ?>">

                            <small id="unitHelp" class="text-muted">
                                <?= rsEscape(rsText(
                                    'Select a food item to check its unit.',
                                    'એકમ જોવા માટે ખાદ્ય વસ્તુ પસંદ કરો.'
                                )) ?>
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label for="received_date">
                                <?= rsEscape(rsText('Received Date', 'મળ્યાની તારીખ')) ?> *
                            </label>

                            <input type="date" name="received_date"
                                   id="received_date" class="form-control"
                                   max="<?= date('Y-m-d') ?>"
                                   required
                                   value="<?= rsEscape($_POST['received_date'] ?? date('Y-m-d')) ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="expiry_date">
                                <?= rsEscape(rsText('Expiry Date', 'સમાપ્તિ તારીખ')) ?>
                                <span id="expiryRequired">*</span>
                            </label>

                            <input type="date" name="expiry_date"
                                   id="expiry_date" class="form-control"
                                   value="<?= rsEscape($_POST['expiry_date'] ?? '') ?>">
                        </div>

                    </div>

                    <div class="receive-note">
                        <strong><?= rsEscape(rsText('Note:', 'નોંધ:')) ?></strong>
                        <?= rsEscape(rsText(
                            'This receipt will remain pending until a Supervisor verifies it. Pending quantities are not added to available stock.',
                            'સુપરવાઇઝર ચકાસણી કરે ત્યાં સુધી આ નોંધ બાકી રહેશે. બાકી જથ્થો ઉપલબ્ધ સ્ટોકમાં ઉમેરાશે નહીં.'
                        )) ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn submit-button">
                            <?= rsEscape(rsText('Submit Stock Receipt', 'સ્ટોકની નોંધ મોકલો')) ?>
                        </button>

                        <a href="<?= APP_URL ?>/worker/dashboard.php"
                           class="btn btn-light">
                            <?= rsEscape(rsText('Cancel', 'રદ કરો')) ?>
                        </a>
                    </div>

                </form>

            </div>

        </div>

    </main>

</div>

<script>
    const itemSelect = document.getElementById('item_id');
    const unitHelp = document.getElementById('unitHelp');
    const expiryDate = document.getElementById('expiry_date');
    const expiryRequired = document.getElementById('expiryRequired');

    function updateFoodItemDetails() {
        const option = itemSelect.options[itemSelect.selectedIndex];

        if (!option || !option.value) {
            unitHelp.textContent =
                'Select a food item to check its unit.';
            expiryDate.required = false;
            expiryRequired.style.display = 'none';
            return;
        }

        const unit = option.dataset.unit || '';
        const tracksExpiry = option.dataset.expiry === '1';

        unitHelp.textContent = 'Unit: ' + unit;

        expiryDate.required = tracksExpiry;
        expiryRequired.style.display = tracksExpiry ? 'inline' : 'none';
    }

    itemSelect.addEventListener('change', updateFoodItemDetails);
    updateFoodItemDetails();
</script>

</body>
</html>
