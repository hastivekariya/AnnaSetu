
<?php
declare(strict_types=1);

/*
 * File: supervisor/pending-verification.php
 *
 * Uses:
 *   init.php  - database connection ($pdo) and APP_URL
 *   auth.php  - supervisor authentication
 *
 * Known stock_transactions schema does NOT contain created_by.
 */

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection is unavailable. Check init.php.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (strtolower((string)($_SESSION['role'] ?? '')) !== 'supervisor') {
    http_response_code(403);
    exit('Access denied. Supervisor access is required.');
}

$fullName = (string)($_SESSION['full_name'] ?? 'Supervisor');
$role = 'supervisor';
$language = $_SESSION['language'] ?? 'en';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$errors = [];
$success = '';

/* -------------------------------------------------------
   Helpers
------------------------------------------------------- */

function pvEscape(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function pvTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function pvColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function pvOptionalInsert(
    PDO $pdo,
    string $table,
    array $values
): void {
    if (!pvTableExists($pdo, $table)) {
        return;
    }

    $allowed = [];

    foreach ($values as $column => $value) {
        if (pvColumnExists($pdo, $table, $column)) {
            $allowed[$column] = $value;
        }
    }

    if (!$allowed) {
        return;
    }

    // Table and column names originate from this file's fixed arrays
    // and are checked against information_schema.
    $columns = array_keys($allowed);
    $quotedColumns = array_map(
        static fn($column) => "`$column`",
        $columns
    );
    $placeholders = array_map(
        static fn($column) => ':' . $column,
        $columns
    );

    $sql = 'INSERT INTO `' . $table . '` (' .
        implode(', ', $quotedColumns) .
        ') VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($allowed);
}

function pvSupervisorCentreIds(PDO $pdo, int $userId): array
{
    $ids = [];

    // Preferred assignment-table approach, if the table and columns exist.
    if (
        pvTableExists($pdo, 'centre_assignments') &&
        pvColumnExists($pdo, 'centre_assignments', 'user_id') &&
        pvColumnExists($pdo, 'centre_assignments', 'centre_id')
    ) {
        $sql = 'SELECT DISTINCT centre_id
                FROM centre_assignments
                WHERE user_id = :user_id';

        if (pvColumnExists($pdo, 'centre_assignments', 'is_active')) {
            $sql .= ' AND is_active = 1';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // Also support the single-centre users.centre_id arrangement.
    if (
        !$ids &&
        pvTableExists($pdo, 'users') &&
        pvColumnExists($pdo, 'users', 'centre_id')
    ) {
        $stmt = $pdo->prepare(
            'SELECT centre_id FROM users WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        $centreId = (int)$stmt->fetchColumn();

        if ($centreId > 0) {
            $ids[] = $centreId;
        }
    }

    $ids = array_values(array_unique(array_filter($ids)));

    // Do not treat an unassigned supervisor as having access to all centres.
    return $ids;
}

function pvWriteAudit(
    PDO $pdo,
    int $userId,
    int $transactionId,
    string $action,
    string $details
): void {
    if (!pvTableExists($pdo, 'audit_logs')) {
        return;
    }

    $values = [
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'action' => $action,
        'action_type' => $action,
        'description' => $details,
        'details' => $details,
        'created_at' => date('Y-m-d H:i:s'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    pvOptionalInsert($pdo, 'audit_logs', $values);
}

/* -------------------------------------------------------
   Current supervisor and centre access
------------------------------------------------------- */

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    exit('Your session has expired. Please sign in again.');
}

$centreIds = pvSupervisorCentreIds($pdo, $userId);

if (!$centreIds) {
    $errors[] = 'No Anganwadi centre is assigned to your supervisor account.';
}

/* -------------------------------------------------------
   Centre scope SQL
------------------------------------------------------- */

$centrePlaceholders = [];
$centreParams = [];

foreach ($centreIds as $index => $centreId) {
    $key = ':centre_' . $index;
    $centrePlaceholders[] = $key;
    $centreParams[$key] = $centreId;
}

$centreWhere = $centrePlaceholders
    ? 't.centre_id IN (' . implode(', ', $centrePlaceholders) . ')'
    : '1 = 0';

/* -------------------------------------------------------
   Approve / reject
------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $transactionId = filter_input(
        INPUT_POST,
        'transaction_id',
        FILTER_VALIDATE_INT
    );
    $action = (string)($_POST['action'] ?? '');
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $errors[] = 'Security token is invalid. Refresh the page and try again.';
    } elseif (!$transactionId || $transactionId <= 0) {
        $errors[] = 'Invalid transaction selected.';
    } elseif (!in_array($action, ['approve', 'reject'], true)) {
        $errors[] = 'Invalid action.';
    } elseif ($action === 'reject' && $comment === '') {
        $errors[] = 'Please enter a reason for rejecting this transaction.';
    } elseif (!$centreIds) {
        $errors[] = 'You cannot verify transactions because no centre is assigned.';
    } else {
        try {
            $pdo->beginTransaction();

            /*
             * Lock the transaction row and verify it belongs to a centre
             * assigned to this supervisor.
             */
            $stmt = $pdo->prepare(
                'SELECT transaction_id, centre_id, status
                 FROM stock_transactions
                 WHERE transaction_id = :transaction_id
                   AND ' . $centreWhere . '
                 FOR UPDATE'
            );

            $params = [':transaction_id' => $transactionId];

            foreach ($centreParams as $key => $value) {
                $params[$key] = $value;
            }

            $stmt->execute($params);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transaction) {
                throw new RuntimeException(
                    'Transaction not found or you do not have permission to verify it.'
                );
            }

            if (strtoupper((string)$transaction['status']) !== 'PENDING') {
                throw new RuntimeException(
                    'This transaction has already been processed.'
                );
            }

            $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';

            $update = $pdo->prepare(
                'UPDATE stock_transactions
                 SET status = :status,
                     remarks = :remarks,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE transaction_id = :transaction_id
                   AND status = :old_status'
            );

            $update->execute([
                ':status' => $newStatus,
                ':remarks' => $comment !== '' ? $comment : null,
                ':transaction_id' => $transactionId,
                ':old_status' => 'PENDING',
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'The transaction status changed before your action could be saved. Refresh and try again.'
                );
            }

            /*
             * Add a verification record only for columns that exist.
             * If your verification table has required columns with no
             * defaults, adjust the values here to match its actual schema.
             */
            pvOptionalInsert($pdo, 'stock_verifications', [
                'transaction_id' => $transactionId,
                'verified_by' => $userId,
                'user_id' => $userId,
                'decision' => $newStatus,
                'status' => $newStatus,
                'comments' => $comment !== '' ? $comment : null,
                'remarks' => $comment !== '' ? $comment : null,
                'verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            pvWriteAudit(
                $pdo,
                $userId,
                $transactionId,
                $newStatus,
                $comment !== ''
                    ? $comment
                    : 'Transaction ' . strtolower($newStatus) . ' by supervisor.'
            );

            $pdo->commit();

            $_SESSION['pv_success'] = $newStatus === 'APPROVED'
                ? 'Transaction approved successfully.'
                : 'Transaction rejected successfully.';

            header('Location: ' . APP_URL . '/supervisor/pending-verification.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Pending verification error: ' . $e->getMessage());

            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The transaction could not be processed. Check the server error log and database schema.';
        }
    }
}

if (!empty($_SESSION['pv_success'])) {
    $success = (string)$_SESSION['pv_success'];
    unset($_SESSION['pv_success']);
}

/* -------------------------------------------------------
   Filters and pagination
------------------------------------------------------- */

$search = trim((string)($_GET['search'] ?? ''));
$typeFilter = strtoupper(trim((string)($_GET['type'] ?? '')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$validTypes = [
    'RECEIPT',
    'CONSUMPTION',
    'DISTRIBUTION',
    'ADJUSTMENT',
];

if (!in_array($typeFilter, $validTypes, true)) {
    $typeFilter = '';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

/* -------------------------------------------------------
   Food and batch joins are optional
------------------------------------------------------- */

$foodJoin = pvTableExists($pdo, 'food_items')
    ? ' LEFT JOIN food_items f ON f.item_id = t.item_id '
    : '';

$itemNameSelect = pvTableExists($pdo, 'food_items')
    ? (pvColumnExists($pdo, 'food_items', 'item_name_en')
        ? 'f.item_name_en'
        : (pvColumnExists($pdo, 'food_items', 'item_name')
            ? 'f.item_name'
            : "CONCAT('Item #', t.item_id)"))
    : "CONCAT('Item #', t.item_id)";

$batchJoin = pvTableExists($pdo, 'stock_batches')
    ? ' LEFT JOIN stock_batches b ON b.batch_id = t.batch_id '
    : '';

$batchNumberSelect = (
    pvTableExists($pdo, 'stock_batches') &&
    pvColumnExists($pdo, 'stock_batches', 'batch_number')
)
    ? 'b.batch_number'
    : "''";

$centreJoin = pvTableExists($pdo, 'anganwadi_centres')
    ? ' LEFT JOIN anganwadi_centres c ON c.centre_id = t.centre_id '
    : '';

$centreNameSelect = (
    pvTableExists($pdo, 'anganwadi_centres') &&
    pvColumnExists($pdo, 'anganwadi_centres', 'centre_name')
)
    ? 'c.centre_name'
    : "CONCAT('Centre #', t.centre_id)";

/* -------------------------------------------------------
   Summary counts
------------------------------------------------------- */

$summary = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'today' => 0,
];

try {
    $summarySql = "
        SELECT
            SUM(CASE WHEN t.status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN t.status = 'APPROVED' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN t.status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN DATE(t.created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count
        FROM stock_transactions t
        WHERE $centreWhere
    ";

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($centreParams);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $summary['pending'] = (int)($row['pending_count'] ?? 0);
    $summary['approved'] = (int)($row['approved_count'] ?? 0);
    $summary['rejected'] = (int)($row['rejected_count'] ?? 0);
    $summary['today'] = (int)($row['today_count'] ?? 0);
} catch (Throwable $e) {
    error_log('Pending verification summary error: ' . $e->getMessage());
    $errors[] = 'Could not load summary counts. Please check the database schema.';
}

/* -------------------------------------------------------
   Pending transactions query
------------------------------------------------------- */

$transactions = [];
$totalRows = 0;
$totalPages = 1;

try {
    $where = [
        $centreWhere,
        "t.status = 'PENDING'",
    ];

    $params = $centreParams;

    if ($search !== '') {
        $where[] = "(
            CAST(t.transaction_id AS CHAR) LIKE :search_id
            OR CAST(t.item_id AS CHAR) LIKE :search_item
            OR CAST(t.centre_id AS CHAR) LIKE :search_centre
            OR COALESCE(t.notes, '') LIKE :search_notes
        )";

        $searchValue = '%' . $search . '%';
        $params[':search_id'] = $searchValue;
        $params[':search_item'] = $searchValue;
        $params[':search_centre'] = $searchValue;
        $params[':search_notes'] = $searchValue;
    }

    if ($typeFilter !== '') {
        $where[] = 't.transaction_type = :type_filter';
        $params[':type_filter'] = $typeFilter;
    }

    if ($dateFrom !== '') {
        $where[] = 'DATE(t.transaction_date) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = 'DATE(t.transaction_date) <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*)
        FROM stock_transactions t
        WHERE $whereSql
    ";

    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $totalRows = (int)$stmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $listSql = "
        SELECT
            t.transaction_id,
            t.centre_id,
            t.item_id,
            t.batch_id,
            t.transaction_type,
            t.quantity,
            t.status,
            t.transaction_date,
            t.beneficiary_type,
            t.notes,
            t.created_at,
            t.remarks,
            $itemNameSelect AS item_name,
            $batchNumberSelect AS batch_number,
            $centreNameSelect AS centre_name,
            '-' AS submitted_by
        FROM stock_transactions t
        $foodJoin
        $batchJoin
        $centreJoin
        WHERE $whereSql
        ORDER BY t.created_at DESC, t.transaction_id DESC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Pending verification list error: ' . $e->getMessage());
    $errors[] = 'Could not load pending transactions. Check your database table and column names.';
}

/* -------------------------------------------------------
   Original supervisor menu items
------------------------------------------------------- */

$menuItems = [
    [
        'label' => 'Dashboard',
        'icon' => 'fa-solid fa-gauge-high',
        'url' => APP_URL . '/supervisor/dashboard.php',
        'file' => 'dashboard.php',
    ],
    [
        'label' => 'Pending Verification',
        'icon' => 'fa-solid fa-clipboard-check',
        'url' => APP_URL . '/supervisor/pending-verification.php',
        'file' => 'pending-verification.php',
    ],
    [
        'label' => 'Low Stock Alerts',
        'icon' => 'fa-solid fa-triangle-exclamation',
        'url' => APP_URL . '/supervisor/low-stock.php',
        'file' => 'low-stock.php',
    ],
    [
        'label' => 'Expiry Monitoring',
        'icon' => 'fa-solid fa-calendar-xmark',
        'url' => APP_URL . '/supervisor/expiry-monitoring.php',
        'file' => 'expiry-monitoring.php',
    ],
    [
        'label' => 'Workers and Helpers',
        'icon' => 'fa-solid fa-users',
        'url' => APP_URL . '/supervisor/workers.php',
        'file' => 'workers.php',
    ],
    [
        'label' => 'Anganwadi Centres',
        'icon' => 'fa-solid fa-school',
        'url' => APP_URL . '/supervisor/centres.php',
        'file' => 'centres.php',
    ],
    [
        'label' => 'Reports and Analytics',
        'icon' => 'fa-solid fa-chart-line',
        'url' => APP_URL . '/supervisor/reports.php',
        'file' => 'reports.php',
    ],
    [
        'label' => 'My Profile',
        'icon' => 'fa-solid fa-user',
        'url' => APP_URL . '/supervisor/profile.php',
        'file' => 'profile.php',
    ],
];

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= $language === 'gu' ? 'gu' : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Verification | AnnaSetu</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7fb;
            color: #243047;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-right: 1px solid #e4e8ef;
            z-index: 20;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 22px 18px;
            border-bottom: 1px solid #edf0f5;
            font-size: 20px;
            font-weight: 700;
            color: #176b48;
        }

        .sidebar-brand i {
            font-size: 25px;
        }

        .sidebar-user {
            padding: 18px;
            border-bottom: 1px solid #edf0f5;
        }

        .sidebar-user strong {
            display: block;
            margin-bottom: 5px;
        }

        .sidebar-user small {
            color: #778197;
        }

        .sidebar-menu {
            flex: 1;
            padding: 14px 10px;
            overflow-y: auto;
        }

        .menu-heading {
            padding: 10px 12px;
            color: #8992a3;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            margin-bottom: 4px;
            color: #596579;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
        }

        .menu-link i {
            width: 18px;
            text-align: center;
        }

        .menu-link:hover,
        .menu-link.active {
            background: #eaf6ef;
            color: #176b48;
        }

        .logout-link {
            display: block;
            padding: 17px 22px;
            color: #c0392b;
            text-decoration: none;
            border-top: 1px solid #edf0f5;
        }

        .main-content {
            margin-left: 250px;
            padding: 28px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .page-header h1 {
            margin: 0 0 7px;
            font-size: 26px;
        }

        .muted {
            color: #7a8497;
            font-size: 13px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card,
        .panel {
            background: #fff;
            border: 1px solid #e6eaf0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 35, 60, .035);
        }

        .stat-card {
            padding: 20px;
        }

        .stat-card .stat-label {
            color: #778197;
            font-size: 13px;
        }

        .stat-card .stat-number {
            display: block;
            font-size: 28px;
            font-weight: 700;
            margin-top: 10px;
        }

        .panel {
            margin-bottom: 22px;
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid #edf0f5;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 17px;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 10px;
            padding: 18px 20px;
        }

        input,
        select,
        textarea {
            width: 100%;
            min-width: 0;
            padding: 10px 11px;
            border: 1px solid #d9dfE8;
            border-radius: 7px;
            background: #fff;
            color: #243047;
            font: inherit;
            font-size: 13px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 13px;
            border: 0;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none;
            font: inherit;
            font-size: 13px;
            white-space: nowrap;
        }

        .btn-primary {
            background: #176b48;
            color: #fff;
        }

        .btn-secondary {
            background: #edf1f6;
            color: #38445a;
        }

        .btn-approve {
            background: #e5f7eb;
            color: #187543;
        }

        .btn-reject {
            background: #ffeded;
            color: #b42318;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th,
        td {
            padding: 14px 15px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: middle;
            font-size: 13px;
        }

        th {
            background: #f9fafc;
            color: #68748a;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #fff1d6;
            color: #8a5a00;
            font-size: 11px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .alert {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .alert-success {
            color: #176b48;
            background: #e8f7ee;
        }

        .alert-error {
            color: #a52323;
            background: #fff0f0;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 16px 20px;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(20, 30, 45, .55);
            padding: 20px;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-box {
            width: 100%;
            max-width: 480px;
            background: #fff;
            border-radius: 12px;
            padding: 24px;
        }

        .modal-box h2 {
            margin-top: 0;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .detail-row {
            padding: 10px 0;
            border-bottom: 1px solid #edf0f5;
            font-size: 14px;
        }

        .detail-row strong {
            display: inline-block;
            min-width: 140px;
        }

        @media (max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, minmax(140px, 1fr));
            }

            .filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .sidebar {
                position: static;
                width: 100%;
                height: auto;
            }

            .sidebar-menu {
                max-height: 260px;
            }

            .main-content {
                margin-left: 0;
                padding: 16px;
            }

            .cards {
                grid-template-columns: 1fr 1fr;
            }

            .filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <i class="fa-solid fa-leaf"></i>
        <span>AnnaSetu</span>
    </div>

    <div class="sidebar-user">
        <strong><?= pvEscape($fullName) ?></strong>
        <small>Supervisor</small>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-heading">Menu</div>

        <?php foreach ($menuItems as $item): ?>
            <a
                class="menu-link <?= $currentFile === $item['file'] ? 'active' : '' ?>"
                href="<?= pvEscape($item['url']) ?>"
            >
                <i class="<?= pvEscape($item['icon']) ?>"></i>
                <span><?= pvEscape($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <a class="logout-link" href="<?= pvEscape(APP_URL . '/logout.php') ?>">
        <i class="fa-solid fa-right-from-bracket"></i>
        Logout
    </a>
</aside>

<main class="main-content">

    <header class="page-header">
        <div>
            <h1>Pending Verification</h1>
            <div class="muted">
                Review and verify stock transactions submitted by your centres.
            </div>
        </div>
        <div class="muted">
            <i class="fa-regular fa-user"></i>
            <?= pvEscape($fullName) ?>
        </div>
    </header>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <?= pvEscape($success) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= pvEscape($error) ?>
        </div>
    <?php endforeach; ?>

    <section class="cards">
        <div class="stat-card">
            <span class="stat-label">Pending Verification</span>
            <span class="stat-number"><?= $summary['pending'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Approved Transactions</span>
            <span class="stat-number"><?= $summary['approved'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Rejected Transactions</span>
            <span class="stat-number"><?= $summary['rejected'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Transactions Today</span>
            <span class="stat-number"><?= $summary['today'] ?></span>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2><i class="fa-solid fa-filter"></i> Filter Transactions</h2>
        </div>

        <form class="filters" method="GET">
            <input
                type="search"
                name="search"
                placeholder="Search transaction, item, centre or notes"
                value="<?= pvEscape($search) ?>"
            >

            <select name="type">
                <option value="">All transaction types</option>
                <?php foreach ($validTypes as $type): ?>
                    <option
                        value="<?= pvEscape($type) ?>"
                        <?= $typeFilter === $type ? 'selected' : '' ?>
                    >
                        <?= pvEscape(ucfirst(strtolower($type))) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input
                type="date"
                name="date_from"
                aria-label="From date"
                value="<?= pvEscape($dateFrom) ?>"
            >

            <input
                type="date"
                name="date_to"
                aria-label="To date"
                value="<?= pvEscape($dateTo) ?>"
            >

            <button class="btn btn-primary" type="submit">
                <i class="fa-solid fa-magnifying-glass"></i> Search
            </button>

            <a class="btn btn-secondary"
               href="<?= pvEscape(APP_URL . '/supervisor/pending-verification.php') ?>">
                Reset
            </a>
        </form>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>
                Pending Transactions
                <span class="muted">(<?= $totalRows ?> found)</span>
            </h2>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Centre</th>
                    <th>Food Item</th>
                    <th>Batch</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>

                <tbody>
                <?php if (!$transactions): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:35px;">
                            <i class="fa-regular fa-folder-open"></i>
                            <p>No pending transactions found.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td>#<?= (int)$transaction['transaction_id'] ?></td>
                            <td><?= pvEscape($transaction['centre_name']) ?></td>
                            <td><?= pvEscape($transaction['item_name']) ?></td>
                            <td><?= pvEscape($transaction['batch_number'] ?: '-') ?></td>
                            <td><?= pvEscape(ucfirst(strtolower($transaction['transaction_type']))) ?></td>
                            <td><?= pvEscape($transaction['quantity']) ?></td>
                            <td><?= pvEscape($transaction['created_at']) ?></td>
                            <td><span class="badge">Pending</span></td>
                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        onclick='showDetails(<?= json_encode([
                                            'id' => (int)$transaction['transaction_id'],
                                            'centre' => $transaction['centre_name'],
                                            'item' => $transaction['item_name'],
                                            'batch' => $transaction['batch_number'],
                                            'type' => $transaction['transaction_type'],
                                            'quantity' => $transaction['quantity'],
                                            'date' => $transaction['transaction_date'],
                                            'beneficiary' => $transaction['beneficiary_type'],
                                            'notes' => $transaction['notes'],
                                            'remarks' => $transaction['remarks'],
                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        Details
                                    </button>

                                    <form method="POST">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= pvEscape($csrfToken) ?>">
                                        <input type="hidden" name="transaction_id"
                                               value="<?= (int)$transaction['transaction_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-approve"
                                                onclick="return confirm('Approve this transaction?')">
                                            Approve
                                        </button>
                                    </form>

                                    <button
                                        type="button"
                                        class="btn btn-reject"
                                        onclick="openReject(<?= (int)$transaction['transaction_id'] ?>)"
                                    >
                                        Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <span class="muted">
                Page <?= $page ?> of <?= $totalPages ?>
            </span>

            <div class="actions">
                <?php
                $queryParams = $_GET;
                $queryParams['page'] = max(1, $page - 1);
                $previousUrl = APP_URL . '/supervisor/pending-verification.php?' .
                    http_build_query($queryParams);

                $queryParams['page'] = min($totalPages, $page + 1);
                $nextUrl = APP_URL . '/supervisor/pending-verification.php?' .
                    http_build_query($queryParams);
                ?>

                <a class="btn btn-secondary"
                   href="<?= pvEscape($previousUrl) ?>"
                   <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>
                    Previous
                </a>

                <a class="btn btn-secondary"
                   href="<?= pvEscape($nextUrl) ?>"
                   <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>
                    Next
                </a>
            </div>
        </div>
    </section>

</main>

<!-- Transaction details modal -->
<div class="modal" id="detailsModal" role="dialog" aria-modal="true"
     aria-labelledby="detailsTitle">
    <div class="modal-box">
        <h2 id="detailsTitle">Transaction Details</h2>
        <div id="detailsContent"></div>
        <div class="modal-actions">
            <button class="btn btn-secondary" type="button"
                    onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Reject modal -->
<div class="modal" id="rejectModal" role="dialog" aria-modal="true"
     aria-labelledby="rejectTitle">
    <div class="modal-box">
        <h2 id="rejectTitle">Reject Transaction</h2>
        <p class="muted">Enter a reason so the worker can understand the rejection.</p>

        <form method="POST" id="rejectForm">
            <input type="hidden" name="csrf_token"
                   value="<?= pvEscape($csrfToken) ?>">
            <input type="hidden" name="transaction_id" id="rejectTransactionId">
            <input type="hidden" name="action" value="reject">

            <label for="rejectComment">Reason for rejection *</label>
            <textarea id="rejectComment" name="comment" rows="4"
                      maxlength="2000" required></textarea>

            <div class="modal-actions">
                <button class="btn btn-secondary" type="button"
                        onclick="closeModal('rejectModal')">Cancel</button>
                <button class="btn btn-reject" type="submit">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
function safeText(value) {
    return String(value ?? '');
}

function showDetails(data) {
    const content = document.getElementById('detailsContent');
    const fields = [
        ['Transaction ID', '#' + safeText(data.id)],
        ['Centre', data.centre],
        ['Food Item', data.item],
        ['Batch Number', data.batch || '-'],
        ['Transaction Type', data.type],
        ['Quantity', data.quantity],
        ['Transaction Date', data.date],
        ['Beneficiary Type', data.beneficiary || '-'],
        ['Notes', data.notes || '-'],
        ['Existing Remarks', data.remarks || '-']
    ];

    content.replaceChildren();

    fields.forEach(function (field) {
        const row = document.createElement('div');
        row.className = 'detail-row';

        const label = document.createElement('strong');
        label.textContent = field[0];

        const value = document.createElement('span');
        value.textContent = safeText(field[1]);

        row.append(label, value);
        content.appendChild(row);
    });

    document.getElementById('detailsModal').classList.add('show');
}

function openReject(transactionId) {
    document.getElementById('rejectTransactionId').value = transactionId;
    document.getElementById('rejectComment').value = '';
    document.getElementById('rejectModal').classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.show').forEach(function (modal) {
            modal.classList.remove('show');
        });
    }
});
</script>

</body>
</html>
