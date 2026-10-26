<?php
require_once __DIR__ . '/../includes/init.php';

function consumptionRedirect(string $type, string $message): never
{
    $_SESSION[$type] = $message;
    header('Location: ../helper/daily-consumption.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../helper/daily-consumption.php');
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['centre_id'])) {
    header('Location: ../login.php');
    exit;
}

// Validate CSRF token.
if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_POST['csrf_token'] ?? null) ||
    !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))
) {
    consumptionRedirect('error_message', 'Invalid request. Please try again.');
}

$userId   = (int) $_SESSION['user_id'];
$centreId = (int) $_SESSION['centre_id'];

$itemId = filter_input(INPUT_POST, 'food_item_id', FILTER_VALIDATE_INT);
$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_FLOAT);

// The form uses beneficiary_type; the database uses beneficiary_count.
$beneficiaryType = $_POST['beneficiary_type'] ?? '';
$beneficiaryCount = filter_input(
    INPUT_POST,
    'beneficiaries_count',
    FILTER_VALIDATE_INT
);

// Support the singular form name if the HTML form uses it.
if ($beneficiaryCount === null) {
    $beneficiaryCount = filter_input(
        INPUT_POST,
        'beneficiary_count',
        FILTER_VALIDATE_INT
    );
}

$date = trim($_POST['consumption_date'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedTypes = [
    'CHILD',
    'PREGNANT_WOMAN',
    'LACTATING_MOTHER',
    'OTHER'
];

if (
    !$itemId ||
    $itemId < 1 ||
    $quantity === false ||
    $quantity === null ||
    !is_finite((float)$quantity) ||
    $quantity <= 0 ||
    !in_array($beneficiaryType, $allowedTypes, true) ||
    strlen($notes) > 500
) {
    consumptionRedirect(
        'error_message',
        'Enter valid consumption details.'
    );
}

// Beneficiary count is optional because the form may not include it.
if ($beneficiaryCount === false) {
    consumptionRedirect(
        'error_message',
        'Enter a valid beneficiary count.'
    );
}

if ($beneficiaryCount !== null && $beneficiaryCount < 0) {
    consumptionRedirect(
        'error_message',
        'Beneficiary count cannot be negative.'
    );
}

$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$today = new DateTimeImmutable('today');

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $date ||
    $dateObject > $today
) {
    consumptionRedirect(
        'error_message',
        'Select a valid consumption date.'
    );
}

try {
    $pdo->beginTransaction();

    // Lock the batches for this centre and item.
    $stmt = $pdo->prepare("
        SELECT batch_id, expiry_date
        FROM stock_batches
        WHERE centre_id = ?
          AND item_id = ?
        ORDER BY expiry_date ASC, batch_id ASC
        FOR UPDATE
    ");
    $stmt->execute([$centreId, $itemId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$batches) {
        throw new RuntimeException(
            'No stock batches exist for this food item.'
        );
    }

    // Calculate the available balance for each batch.
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

    // Use the actual database column names.
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
        VALUES (?, ?, ?, ?, 'CONSUMPTION', ?, ?, ?, ?, ?, 'PENDING')
    ");

    $remaining = (float)$quantity;

    foreach ($batches as $batch) {
        // Do not consume from batches expired on the consumption date.
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

        $remaining = round($remaining - $take, 6);

        if ($remaining <= 0.000001) {
            $remaining = 0;
            break;
        }
    }

    if ($remaining > 0.000001) {
        throw new RuntimeException(
            'Insufficient available stock. Receive and approve stock before recording consumption.'
        );
    }

    $pdo->commit();

    consumptionRedirect(
        'success_message',
        'Consumption submitted successfully for verification.'
    );

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    consumptionRedirect('error_message', $e->getMessage());

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Consumption save error: ' . $e->getMessage());

    consumptionRedirect(
        'error_message',
        'Unable to save consumption. Check the PHP error log.'
    );
}