
<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

$role = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($role !== 'cdpo') {
    http_response_code(403);
    exit('Access denied.');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('Database connection not found. Check config/database.php.');
}

function reportEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$defaultStart = date('Y-m-01');

$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate = $_GET['end_date'] ?? $today;
$itemFilter = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
$centreFilter = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
    || $startDate > $endDate
    || $endDate > $today) {
    $startDate = $defaultStart;
    $endDate = $today;
}

try {
    // Find the project assigned to this CDPO.
    $stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.project_code,
               p.district, p.taluka
        FROM users u
        INNER JOIN projects p ON p.project_id = u.project_id
        WHERE u.user_id = ? AND u.role_id = 4
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        exit('No project is assigned to this CDPO account.');
    }

    $projectId = (int)$project['project_id'];

    // Filters for active centres and food items.
    $stmt = $pdo->prepare("
        SELECT centre_id, centre_name, centre_code
        FROM anganwadi_centres
        WHERE project_id = ? AND is_active = 1
        ORDER BY centre_name
    ");
    $stmt->execute([$projectId]);
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT item_id, item_name_en, unit
        FROM food_items
        WHERE is_active = 1
        ORDER BY item_name_en
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Summary of transactions for the selected period.
     * Receipt, consumption and distribution totals include approved
     * transactions only. Pending transactions are shown separately.
     */
    $summarySql = "
        SELECT
            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'RECEIPT'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS receipts,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'CONSUMPTION'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS consumption,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'DISTRIBUTION'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS distributions,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'ADJUSTMENT'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS adjustments,

            COALESCE(SUM(CASE
                WHEN t.status = 'PENDING'
                THEN 1 ELSE 0 END), 0) AS pending_count,

            COUNT(*) AS transaction_count
        FROM stock_transactions t
        INNER JOIN anganwadi_centres c
            ON c.centre_id = t.centre_id
        WHERE c.project_id = ?
          AND c.is_active = 1
          AND t.transaction_date >= ?
          AND t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
    ";

    $summaryParams = [$projectId, $startDate, $endDate];

    if ($centreFilter && $centreFilter > 0) {
        $summarySql .= " AND t.centre_id = ?";
        $summaryParams[] = $centreFilter;
    }

    if ($itemFilter && $itemFilter > 0) {
        $summarySql .= " AND t.item_id = ?";
        $summaryParams[] = $itemFilter;
    }

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($summaryParams);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Centre-wise report for the selected period.
    
$centreSql = "
    SELECT
        c.centre_id,
        c.centre_name,
        c.centre_code,

        COALESCE(SUM(CASE
            WHEN t.transaction_type = 'RECEIPT'
             AND t.status = 'APPROVED'
            THEN t.quantity ELSE 0 END), 0) AS receipts,

        COALESCE(SUM(CASE
            WHEN t.transaction_type = 'CONSUMPTION'
             AND t.status = 'APPROVED'
            THEN t.quantity ELSE 0 END), 0) AS consumption,

        COALESCE(SUM(CASE
            WHEN t.transaction_type = 'DISTRIBUTION'
             AND t.status = 'APPROVED'
            THEN t.quantity ELSE 0 END), 0) AS distributions,

        COUNT(t.transaction_id) AS transaction_count

    FROM anganwadi_centres c

    LEFT JOIN stock_transactions t
        ON t.centre_id = c.centre_id
        AND t.transaction_date >= ?
        AND t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
";

$centreParams = [$startDate, $endDate];

if ($itemFilter && $itemFilter > 0) {
    $centreSql .= " AND t.item_id = ?";
    $centreParams[] = $itemFilter;
}

$centreSql .= "
    WHERE c.project_id = ?
      AND c.is_active = 1
";

$centreParams[] = $projectId;

if ($centreFilter && $centreFilter > 0) {
    $centreSql .= " AND c.centre_id = ?";
    $centreParams[] = $centreFilter;
}

$centreSql .= "
    GROUP BY c.centre_id, c.centre_name, c.centre_code
    ORDER BY c.centre_name
";

$stmt = $pdo->prepare($centreSql);
$stmt->execute($centreParams);
$centreReports = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Food-item-wise activity report.
    $itemSql = "
        SELECT
            f.item_id,
            f.item_name_en,
            f.unit,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'RECEIPT'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS receipts,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'CONSUMPTION'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS consumption,

            COALESCE(SUM(CASE
                WHEN t.transaction_type = 'DISTRIBUTION'
                 AND t.status = 'APPROVED'
                THEN t.quantity ELSE 0 END), 0) AS distributions

        FROM food_items f
        LEFT JOIN stock_transactions t
            ON t.item_id = f.item_id
            AND t.transaction_date >= ?
            AND t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
            AND t.status = 'APPROVED'
        LEFT JOIN anganwadi_centres c
            ON c.centre_id = t.centre_id
            AND c.project_id = ?
            AND c.is_active = 1
        WHERE f.is_active = 1
          AND (t.transaction_id IS NULL OR c.centre_id IS NOT NULL)
    ";

    $itemParams = [$startDate, $endDate, $projectId];

    if ($centreFilter && $centreFilter > 0) {
        $itemSql .= " AND (t.transaction_id IS NULL OR t.centre_id = ?)";
        $itemParams[] = $centreFilter;
    }

    if ($itemFilter && $itemFilter > 0) {
        $itemSql .= " AND f.item_id = ?";
        $itemParams[] = $itemFilter;
    }

    $itemSql .= "
        GROUP BY f.item_id, f.item_name_en, f.unit
        ORDER BY f.item_name_en
    ";

    $stmt = $pdo->prepare($itemSql);
    $stmt->execute($itemParams);
    $itemReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Food Item Analysis Pagination: 5 rows per page
$itemsPerPage = 5;

$itemPage = filter_input(INPUT_GET, 'item_page', FILTER_VALIDATE_INT);
$itemPage = ($itemPage && $itemPage > 0) ? $itemPage : 1;

$itemTotal = count($itemReports);
$itemTotalPages = max(1, (int) ceil($itemTotal / $itemsPerPage));

if ($itemPage > $itemTotalPages) {
    $itemPage = $itemTotalPages;
}

$itemOffset = ($itemPage - 1) * $itemsPerPage;

$paginatedItemReports = array_slice(
    $itemReports,
    $itemOffset,
    $itemsPerPage
);

// Preserve the selected report filters in pagination links
$paginationParams = [
    'start_date' => $startDate,
    'end_date'   => $endDate,
    'centre_id'  => $centreFilter ?: '',
    'item_id'    => $itemFilter ?: ''
];

}   catch (PDOException $ex) {
    error_log('CDPO reports error: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to load reports. Check the server error log.');
}
$fullName = reportEscape($_SESSION['full_name'] ?? 'CDPO');

$receiptTotal = (float)$summary['receipts'];
$consumptionTotal = (float)$summary['consumption'];
$distributionTotal = (float)$summary['distributions'];
$adjustmentTotal = (float)$summary['adjustments'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Analysis | AnnaSetu</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">

    <style>
        .report-content { padding: 8px 0 30px; }
        .report-heading { margin-bottom: 22px; }
        .report-heading h1 { margin: 0 0 8px; font-size: 28px; }
        .report-muted { color: #687386; font-size: 14px; line-height: 1.6; }

        .report-panel {
            background: #fff;
            border: 1px solid #e5eaf0;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 22px;
        }
        .report-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #edf0f4;
}

.report-page-info {
    color: #687386;
    font-size: 13px;
}

.report-page-buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.report-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid #dfe5ed;
    border-radius: 8px;
    background: #fff;
    color: #2459a9;
    text-decoration: none;
    font-size: 13px;
    box-sizing: border-box;
}

a.report-page-btn:hover {
    background: #f1f6ff;
    border-color: #b7cbed;
}

.report-page-btn.active {
    background: #2459a9;
    color: #fff;
    border-color: #2459a9;
}

.report-page-btn.disabled {
    color: #a0a8b5;
    background: #f8fafc;
    cursor: not-allowed;
}

@media (max-width: 600px) {
    .report-pagination {
        align-items: flex-start;
        flex-direction: column;
    }

    .report-page-buttons {
        width: 100%;
    }
}

        .report-panel h2 { margin: 0 0 12px; font-size: 19px; }

        .report-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }

        .report-stat {
            background: #fff;
            border: 1px solid #e5eaf0;
            border-radius: 12px;
            padding: 19px;
            box-shadow: 0 3px 12px rgba(0,0,0,.035);
        }

        .report-stat-label { color: #687386; font-size: 14px; }
        .report-stat-number {
            display: block;
            margin: 10px 0 5px;
            color: #203047;
            font-size: 27px;
            font-weight: 700;
        }

        .report-filters {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .report-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            flex: 1;
            min-width: 160px;
        }

        .report-field label { font-size: 13px; font-weight: 600; color: #475569; }

        .report-field input,
        .report-field select {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #d8dee8;
            border-radius: 8px;
            background: #fff;
            color: #253247;
            box-sizing: border-box;
            font-size: 14px;
        }

        .report-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            border: 1px solid #dfe5ed;
            border-radius: 9px;
            background: #fff;
            color: #2459a9;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            box-sizing: border-box;
        }

        .report-btn:hover { background: #f1f6ff; border-color: #b7cbed; }

        .report-table-wrap { width: 100%; overflow-x: auto; }
        .report-table { width: 100%; min-width: 700px; border-collapse: collapse; }

        .report-table th,
        .report-table td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0f4;
            font-size: 14px;
        }

        .report-table th { background: #f8fafc; color: #596579; white-space: nowrap; }
        .report-table tbody tr:hover { background: #fafcff; }
        .report-primary { font-weight: 600; color: #203047; }
        .report-secondary { margin-top: 4px; color: #687386; font-size: 12px; }

        .report-empty { padding: 30px 12px; text-align: center; color: #687386; }

        .report-bars { display: grid; gap: 16px; margin-top: 18px; }
        .report-bar-row { display: grid; gap: 7px; }
        .report-bar-label { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; }
        .report-bar-track { height: 10px; background: #edf1f7; border-radius: 20px; overflow: hidden; }
        .report-bar-fill { height: 100%; background: #4779c7; border-radius: 20px; }

        .project-links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 18px;
        }

        .project-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 16px;
            background: #fff;
            border: 1px solid #dfe5ed;
            border-radius: 9px;
            color: #2459a9;
            text-decoration: none;
            font-size: 14px;
            line-height: 1.5;
            box-sizing: border-box;
            transition: background .2s ease, border-color .2s ease;
        }

        .project-links a:hover { background: #f1f6ff; border-color: #b7cbed; }

        @media (max-width: 600px) {
            .report-heading h1 { font-size: 23px; }
            .report-panel { padding: 14px; }
            .report-filters { align-items: stretch; }
            .report-filters .report-btn { width: 100%; }
            .project-links { gap: 10px; }
            .project-links a { width: 100%; justify-content: flex-start; }
        }
    </style>
</head>

<body>
<div class="dashboard-layout">
    <?php require __DIR__ . '/../includes/sidebar_cdpo.php'; ?>

    <div class="dashboard-main">
        <?php require __DIR__ . '/../includes/page-header.php'; ?>

        <main class="report-content">

            <div class="report-heading">
                <h1>Reports &amp; Analysis</h1>
                <p class="report-muted">
                    Welcome, <?= $fullName ?>. Review stock movement and centre-wise
                    activity for your assigned project.
                </p>
            </div>

            <section class="report-panel">
                <h2><?= reportEscape($project['project_name']) ?></h2>
                <p class="report-muted">
                    Project Code: <?= reportEscape($project['project_code']) ?>
                    &nbsp; | &nbsp; District: <?= reportEscape($project['district']) ?>
                    <?php if (!empty($project['taluka'])): ?>
                        &nbsp; | &nbsp; Taluka: <?= reportEscape($project['taluka']) ?>
                    <?php endif; ?>
                </p>

                <form method="GET" action="reports.php" class="report-filters">
                    <div class="report-field">
                        <label for="start_date">From Date</label>
                        <input type="date" id="start_date" name="start_date"
                               value="<?= reportEscape($startDate) ?>" max="<?= $today ?>" required>
                    </div>

                    <div class="report-field">
                        <label for="end_date">To Date</label>
                        <input type="date" id="end_date" name="end_date"
                               value="<?= reportEscape($endDate) ?>" max="<?= $today ?>" required>
                    </div>

                    <div class="report-field">
                        <label for="centre_id">Anganwadi Centre</label>
                        <select name="centre_id" id="centre_id">
                            <option value="">All centres</option>
                            <?php foreach ($centres as $centre): ?>
                                <option value="<?= (int)$centre['centre_id'] ?>"
                                    <?= (int)$centreFilter === (int)$centre['centre_id'] ? 'selected' : '' ?>>
                                    <?= reportEscape($centre['centre_name']) ?>
                                    (<?= reportEscape($centre['centre_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="report-field">
                        <label for="item_id">Food Item</label>
                        <select name="item_id" id="item_id">
                            <option value="">All food items</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['item_id'] ?>"
                                    <?= (int)$itemFilter === (int)$item['item_id'] ? 'selected' : '' ?>>
                                    <?= reportEscape($item['item_name_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button class="report-btn" type="submit">Generate Report</button>
                    <a class="report-btn" href="reports.php">Reset</a>
                </form>
            </section>

            <section class="report-stats">
                <div class="report-stat">
                    <span class="report-stat-label">Approved Receipts</span>
                    <span class="report-stat-number"><?= number_format($receiptTotal, 3) ?></span>
                    <span class="report-muted">Quantity received</span>
                </div>

                <div class="report-stat">
                    <span class="report-stat-label">Approved Consumption</span>
                    <span class="report-stat-number"><?= number_format($consumptionTotal, 3) ?></span>
                    <span class="report-muted">Quantity consumed</span>
                </div>

                <div class="report-stat">
                    <span class="report-stat-label">Approved Distribution</span>
                    <span class="report-stat-number"><?= number_format($distributionTotal, 3) ?></span>
                    <span class="report-muted">Quantity distributed</span>
                </div>

                <div class="report-stat">
                    <span class="report-stat-label">Pending Transactions</span>
                    <span class="report-stat-number"><?= (int)$summary['pending_count'] ?></span>
                    <span class="report-muted">Awaiting approval</span>
                </div>

                <div class="report-stat">
                    <span class="report-stat-label">Total Transactions</span>
                    <span class="report-stat-number"><?= (int)$summary['transaction_count'] ?></span>
                    <span class="report-muted">All statuses in date range</span>
                </div>
            </section>

            <section class="report-panel">
                <h2>Stock Movement Analysis</h2>
                <p class="report-muted">
                    Approved quantity totals for the selected period.
                    Quantities may use different units depending on the food item.
                </p>

                <?php
                $maxMovement = max($receiptTotal, $consumptionTotal, $distributionTotal, 1);
                $movements = [
                    ['label' => 'Receipts', 'value' => $receiptTotal],
                    ['label' => 'Consumption', 'value' => $consumptionTotal],
                    ['label' => 'Distribution', 'value' => $distributionTotal],
                ];
                ?>

                <div class="report-bars">
                    <?php foreach ($movements as $movement): ?>
                        <div class="report-bar-row">
                            <div class="report-bar-label">
                                <span><?= reportEscape($movement['label']) ?></span>
                                <strong><?= number_format($movement['value'], 3) ?></strong>
                            </div>
                            <div class="report-bar-track">
                                <div class="report-bar-fill"
                                     style="width: <?= min(100, ($movement['value'] / $maxMovement) * 100) ?>%">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="report-panel">
                <h2>Centre-wise Analysis</h2>

                <?php if (!$centreReports): ?>
                    <div class="report-empty">No centre activity found for this period.</div>
                <?php else: ?>
                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Anganwadi Centre</th>
                                    <th>Receipts</th>
                                    <th>Consumption</th>
                                    <th>Distribution</th>
                                    <th>Transactions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($centreReports as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="report-primary"><?= reportEscape($row['centre_name']) ?></div>
                                            <div class="report-secondary"><?= reportEscape($row['centre_code']) ?></div>
                                        </td>
                                        <td><?= number_format((float)$row['receipts'], 3) ?></td>
                                        <td><?= number_format((float)$row['consumption'], 3) ?></td>
                                        <td><?= number_format((float)$row['distributions'], 3) ?></td>
                                        <td><?= (int)$row['transaction_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            
<section class="report-panel">
    <h2>Food Item Analysis</h2>

    <?php if (!$itemReports): ?>
        <div class="report-empty">
            No food-item activity found for this period.
        </div>
    <?php else: ?>

        <div class="report-table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Item ID</th>
                        <th>Food Item</th>
                        <th>Receipts</th>
                        <th>Consumption</th>
                        <th>Distribution</th>
                        <th>Unit</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($paginatedItemReports as $row): ?>
                        <tr>
                            <td>
    <?= (int)$row['item_id'] ?>
</td>

<td class="report-primary">
    <?= reportEscape($row['item_name_en']) ?>
</td>

                            <td>
                                <?= number_format((float)$row['receipts'], 3) ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['consumption'], 3) ?>
                            </td>

                            <td>
                                <?= number_format((float)$row['distributions'], 3) ?>
                            </td>

                            <td>
                                <?= reportEscape($row['unit']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($itemTotalPages > 1): ?>
            <div class="report-pagination">

                <span class="report-page-info">
                    Showing <?= $itemOffset + 1 ?>–<?= min(
                        $itemOffset + $itemsPerPage,
                        $itemTotal
                    ) ?> of <?= $itemTotal ?> food items
                </span>

                <div class="report-page-buttons">

                    <?php if ($itemPage > 1): ?>
                        <?php
                        $prevParams = $paginationParams;
                        $prevParams['item_page'] = $itemPage - 1;
                        ?>
                        <a class="report-page-btn"
                           href="?<?= reportEscape(http_build_query($prevParams)) ?>">
                            &laquo; Previous
                        </a>
                    <?php else: ?>
                        <span class="report-page-btn disabled">
                            &laquo; Previous
                        </span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $itemTotalPages; $i++): ?>
                        <?php
                        $pageParams = $paginationParams;
                        $pageParams['item_page'] = $i;
                        ?>

                        <a class="report-page-btn <?= $i === $itemPage ? 'active' : '' ?>"
                           href="?<?= reportEscape(http_build_query($pageParams)) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($itemPage < $itemTotalPages): ?>
                        <?php
                        $nextParams = $paginationParams;
                        $nextParams['item_page'] = $itemPage + 1;
                        ?>
                        <a class="report-page-btn"
                           href="?<?= reportEscape(http_build_query($nextParams)) ?>">
                            Next &raquo;
                        </a>
                    <?php else: ?>
                        <span class="report-page-btn disabled">
                            Next &raquo;
                        </span>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</section>


            <section class="report-panel">
                <h2>Quick Access</h2>
                <div class="project-links">
                    <a href="projects.php">Project Overview</a>
                    <a href="centres.php">Anganwadi Centres</a>
                    <a href="stock-overview.php">Stock Overview</a>
                    <a href="alerts.php">Shortage &amp; Expiry Alerts</a>
                    <a href="export-reports.php">Export Reports</a>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
document.getElementById('start_date').addEventListener('change', function () {
    document.getElementById('end_date').min = this.value;
});

document.getElementById('end_date').addEventListener('change', function () {
    document.getElementById('start_date').max = this.value;
});
</script>
</body>
</html>
