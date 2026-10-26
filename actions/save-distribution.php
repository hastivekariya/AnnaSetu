<?php
require_once __DIR__ . '/../includes/init.php';

function distributionRedirect(string $type, string $message): never
{
    $_SESSION[$type] = $message;
    header('Location: ../helper/beneficiary-distribution.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../helper/beneficiary-distribution.php');
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['centre_id'])) {
    header('Location: ../login.php');
    exit;
}

// CSRF protection.
if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_POST['csrf_token'] ?? null) ||
    !hash_equals(
        (string)$_SESSION['csrf_token'],
        (string)($_POST['csrf_token'] ?? '')
    )
) {
    distributionRedirect(
        'error_message',
        'Invalid request. Please refresh the page and try again.'
    );
}

$userId = (int)$_SESSION['user_id'];
$centreId = (int)$_SESSION['centre_id'];

$itemId = filter_input(INPUT_POST, 'food_item_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_FLOAT);
$beneficiaryCount = filter_input(
    INPUT_POST,
    'beneficiary_count',
    FILTER_VALIDATE_INT
);

$beneficiaryType = trim($_POST['beneficiary_type'] ?? '');
$date = trim($_POST['distribution_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedTypes = [
    'CHILD',
    'PREGNANT_WOMAN',
    'LACTATING_MOTHER',
    'OTHER'
];

if (
    $itemId === false || $itemId === null || $itemId < 1 ||
    $quantity === false || $quantity === null ||
    !is_finite((float)$quantity) || $quantity <= 0 ||
    $beneficiaryCount === false ||
    $beneficiaryCount === null ||
    $beneficiaryCount < 1 ||
    !in_array($beneficiaryType, $allowedTypes, true) ||
    strlen($notes) > 500
) {
    distributionRedirect(
        'error_message',
        'Enter valid distribution details.'
    );
}

$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$today = new DateTimeImmutable('today');

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $date ||
    $dateObject > $today
) {
    distributionRedirect(
        'error_message',
        'Select a valid distribution date.'
    );
}

// The database stores quantity to three decimal places.
if (round((float)$quantity, 3) <= 0) {
    distributionRedirect(
        'error_message',
        'Quantity must be at least 0.001.'
    );
}

try {
    $pdo->beginTransaction();

    // Verify the selected food item is active.
    $itemStmt = $pdo->prepare("
        SELECT item_id
        FROM food_items
        WHERE item_id = ?
          AND is_active = 1
        LIMIT 1
    ");
    $itemStmt->execute([$itemId]);

    if (!$itemStmt->fetchColumn()) {
        throw new RuntimeException(
            'The selected food item is not available.'
        );
    }

    // Lock this centre's batches for the selected item.
    // Actual stock_batches columns: batch_id, item_id, centre_id.
    $batchStmt = $pdo->prepare("
        SELECT batch_id, expiry_date
        FROM stock_batches
        WHERE centre_id = ?
          AND item_id = ?
        ORDER BY expiry_date ASC, batch_id ASC
        FOR UPDATE
    ");
    $batchStmt->execute([$centreId, $itemId]);
    $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$batches) {
        throw new RuntimeException(
            'No stock batches exist for this food item at your centre.'
        );
    }

    $balanceStmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN transaction_type = 'RECEIPT'
                     AND status = 'APPROVED'
                    THEN quantity

                WHEN transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                     AND status IN ('APPROVED', 'PENDING')
                    THEN -quantity

                WHEN transaction_type = 'ADJUSTMENT'
                     AND status = 'APPROVED'
                    THEN quantity

                ELSE 0
            END
        ), 0)
        FROM stock_transactions
        WHERE batch_id = ?
          AND centre_id = ?
          AND item_id = ?
    ");

    // Actual stock_transactions column names.
    $insertStmt = $pdo->prepare("
        INSERT INTO stock_transactions
        (
            batch_id,
            centre_id,
            item_id,
            created_by,
            transaction_type,
            quantity,
            transaction_date,
            beneficiary_type,
            beneficiary_count,
            notes,
            status
        )
        VALUES (?, ?, ?, ?, 'DISTRIBUTION', ?, ?, ?, ?, ?, 'PENDING')
    ");

    $remaining = round((float)$quantity, 3);

    foreach ($batches as $batch) {
        // Skip expired batches for this distribution date.
        if (
            !empty($batch['expiry_date']) &&
            $batch['expiry_date'] < $date
        ) {
            continue;
        }

        $batchId = (int)$batch['batch_id'];

        $balanceStmt->execute([
            $batchId,
            $centreId,
            $itemId
        ]);

        $balance = max(0, (float)$balanceStmt->fetchColumn());

        if ($balance <= 0) {
            continue;
        }

        $take = min($remaining, $balance);
        $take = round($take, 3);

        if ($take <= 0) {
            continue;
        }

        $insertStmt->execute([
            $batchId,
            $centreId,
            $itemId,
            $userId,
            $take,
            $date . ' 00:00:00',
            $beneficiaryType,
            $beneficiaryCount,
            $notes !== '' ? $notes : null
        ]);

        $remaining = round($remaining - $take, 3);

        if ($remaining <= 0) {
            $remaining = 0;
            break;
        }
    }

    if ($remaining > 0) {
        throw new RuntimeException(
            'Insufficient available stock. Reduce the quantity or receive and approve more stock.'
        );
    }

    $pdo->commit();

    distributionRedirect(
        'success_message',
        'Beneficiary distribution submitted successfully for verification.'
    );

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    distributionRedirect('error_message', $e->getMessage());

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Distribution save error: ' . $e->getMessage());

    distributionRedirect(
        'error_message',
        'Unable to save distribution. Check the PHP error log and database structure.'
    );
}