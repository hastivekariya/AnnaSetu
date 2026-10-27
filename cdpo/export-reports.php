
<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

$role = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($role !== 'cdpo') {
    http_response_code(403);
    exit('પ્રવેશ નકારવામાં આવ્યો. આ પેજ ફક્ત CDPO માટે છે.');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('ડેટાબેઝ કનેક્શન મળ્યું નથી. config/database.php તપાસો.');
}

function exportEscape($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function exportText(string $english, string $gujarati): string
{
    $language = $_SESSION['language'] ?? 'gu';

    return $language === 'en' ? $english : $gujarati;
}

$today = date('Y-m-d');
$defaultStart = date('Y-m-01');

$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate = $_GET['end_date'] ?? $today;
$itemFilter = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
$centreFilter = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);
$reportType = $_GET['report_type'] ?? 'transactions';

if (!in_array($reportType, ['transactions', 'centres', 'items'], true)) {
    $reportType = 'transactions';
}

if (
    !is_string($startDate) ||
    !is_string($endDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ||
    $startDate > $endDate ||
    $endDate > $today
) {
    $startDate = $defaultStart;
    $endDate = $today;
}

try {
    // CDPO ને ફાળવેલ પ્રોજેક્ટ શોધો.
    $stmt = $pdo->prepare("
        SELECT
            p.project_id,
            p.project_name,
            p.project_code,
            p.district,
            p.taluka
        FROM users u
        INNER JOIN projects p ON p.project_id = u.project_id
        WHERE u.user_id = ?
          AND u.role_id = 4
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$project) {
        exit('આ CDPO એકાઉન્ટને કોઈ પ્રોજેક્ટ ફાળવવામાં આવ્યો નથી.');
    }

    $projectId = (int)$project['project_id'];

    // ફિલ્ટર માટે કેન્દ્રોની યાદી.
    $stmt = $pdo->prepare("
        SELECT centre_id, centre_name, centre_code
        FROM anganwadi_centres
        WHERE project_id = ?
          AND is_active = 1
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

    $where = "
        WHERE c.project_id = ?
          AND c.is_active = 1
          AND t.transaction_date >= ?
          AND t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
    ";

    $params = [$projectId, $startDate, $endDate];

    if ($centreFilter && $centreFilter > 0) {
        $where .= " AND t.centre_id = ?";
        $params[] = $centreFilter;
    }

    if ($itemFilter && $itemFilter > 0) {
        $where .= " AND t.item_id = ?";
        $params[] = $itemFilter;
    }

    if ($reportType === 'transactions') {
        $sql = "
            SELECT
                t.transaction_id,
                t.transaction_date,
                c.centre_name,
                c.centre_code,
                f.item_name_en,
                f.unit,
                t.transaction_type,
                t.quantity,
                t.status,
                t.beneficiary_type,
                t.beneficiary_count,
                t.notes,
                t.remarks
            FROM stock_transactions t
            INNER JOIN anganwadi_centres c
                ON c.centre_id = t.centre_id
            INNER JOIN food_items f
                ON f.item_id = t.item_id
            $where
            ORDER BY t.transaction_date DESC, t.transaction_id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = [
            'વ્યવહાર નંબર',
            'વ્યવહારની તારીખ',
            'આંગણવાડી કેન્દ્ર',
            'કેન્દ્ર કોડ',
            'ખાદ્ય સામગ્રી',
            'એકમ',
            'વ્યવહારનો પ્રકાર',
            'જથ્થો',
            'સ્થિતિ',
            'લાભાર્થીનો પ્રકાર',
            'લાભાર્થીઓની સંખ્યા',
            'નોંધ',
            'ટિપ્પણી'
        ];

        $filename = 'transactions-report-' . $startDate . '-to-' . $endDate . '.csv';

    } elseif ($reportType === 'centres') {
        $sql = "
            SELECT
                c.centre_name,
                c.centre_code,
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
                    THEN t.quantity ELSE 0 END), 0) AS distributions,
                COUNT(t.transaction_id) AS transaction_count
            FROM anganwadi_centres c
            CROSS JOIN food_items f
            LEFT JOIN stock_transactions t
                ON t.centre_id = c.centre_id
                AND t.item_id = f.item_id
                AND t.transaction_date >= ?
                AND t.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)
            WHERE c.project_id = ?
              AND c.is_active = 1
              AND f.is_active = 1
        ";

        $centreParams = [$startDate, $endDate, $projectId];

        if ($itemFilter && $itemFilter > 0) {
            $sql .= " AND f.item_id = ?";
            $centreParams[] = $itemFilter;
        }

        if ($centreFilter && $centreFilter > 0) {
            $sql .= " AND c.centre_id = ?";
            $centreParams[] = $centreFilter;
        }

        $sql .= "
            GROUP BY
                c.centre_id, c.centre_name, c.centre_code,
                f.item_id, f.item_name_en, f.unit
            ORDER BY c.centre_name, f.item_name_en
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($centreParams);
        $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = [
            'આંગણવાડી કેન્દ્ર',
            'કેન્દ્ર કોડ',
            'ખાદ્ય સામગ્રી',
            'એકમ',
            'મંજૂર થયેલ આવક',
            'મંજૂર થયેલ વપરાશ',
            'મંજૂર થયેલ વિતરણ',
            'વ્યવહારોની સંખ્યા'
        ];

        $filename = 'centre-analysis-' . $startDate . '-to-' . $endDate . '.csv';

    } else {
        $sql = "
            SELECT
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
                    THEN t.quantity ELSE 0 END), 0) AS distributions,
                COALESCE(SUM(CASE
                    WHEN t.transaction_type = 'ADJUSTMENT'
                     AND t.status = 'APPROVED'
                    THEN t.quantity ELSE 0 END), 0) AS adjustments,
                COUNT(t.transaction_id) AS transaction_count
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
            $sql .= " AND (t.transaction_id IS NULL OR t.centre_id = ?)";
            $itemParams[] = $centreFilter;
        }

        if ($itemFilter && $itemFilter > 0) {
            $sql .= " AND f.item_id = ?";
            $itemParams[] = $itemFilter;
        }

        $sql .= "
            GROUP BY f.item_id, f.item_name_en, f.unit
            ORDER BY f.item_name_en
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($itemParams);
        $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $headers = [
            'ખાદ્ય સામગ્રી',
            'એકમ',
            'મંજૂર થયેલ આવક',
            'મંજૂર થયેલ વપરાશ',
            'મંજૂર થયેલ વિતરણ',
            'મંજૂર થયેલ જથ્થા સુધારા',
            'વ્યવહારોની સંખ્યા'
        ];

        $filename = 'food-item-analysis-' . $startDate . '-to-' . $endDate . '.csv';
    }

    // CSV ફાઇલ ડાઉનલોડ કરો.
    if (isset($_GET['download']) && $_GET['download'] === 'csv') {

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ini_set('display_errors', '0');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');

        if ($output === false) {
            exit('CSV ફાઇલ બનાવી શકાઈ નથી.');
        }

        // Excel માટે UTF-8 BOM.
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, $headers, ',', '"', '');

        foreach ($reportRows as $row) {
            $csvRow = [];

            foreach (array_values($row) as $value) {
                $value = (string)($value ?? '');

                // સ્પ્રેડશીટ ફોર્મ્યુલા ઇન્જેક્શન અટકાવો.
                if ($value !== '' && preg_match('/^[\s]*[=+\-@]/', $value)) {
                    $value = "'" . $value;
                }

                $csvRow[] = $value;
            }

            fputcsv($output, $csvRow, ',', '"', '');
        }

        fclose($output);
        exit;
    }

} catch (PDOException $ex) {
    error_log('CDPO export reports error: ' . $ex->getMessage());
    http_response_code(500);
    exit('રિપોર્ટ તૈયાર કરી શકાયો નથી. સર્વરની એરર લોગ તપાસો.');
}

$fullName = exportEscape($_SESSION['full_name'] ?? 'CDPO');

$reportLabels = [
    'transactions' => 'વ્યવહારની વિગતો',
    'centres' => 'કેન્દ્રવાર વિશ્લેષણ',
    'items' => 'ખાદ્ય સામગ્રીનું વિશ્લેષણ'
];

$queryParams = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'report_type' => $reportType
];


// Preview pagination: 5 rows per page
// Pagination settings
$rowsPerPage = 5;

$currentPage = isset($_GET['page']) ? (int) $_GET['page'] : 1;

if ($currentPage < 1) {
    $currentPage = 1;
}

$totalRows = count($reportRows);
$totalPages = max(1, (int) ceil($totalRows / $rowsPerPage));

if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

// Calculate the starting row
$offset = ($currentPage - 1) * $rowsPerPage;

// Get only the rows for the current page
$previewRows = array_slice(
    $reportRows,
    $offset,
    $rowsPerPage
);

if ($centreFilter && $centreFilter > 0) {
    $queryParams['centre_id'] = $centreFilter;
}

if ($itemFilter && $itemFilter > 0) {
    $queryParams['item_id'] = $itemFilter;
}

$downloadUrl = 'export-reports.php?' .
    http_build_query($queryParams + ['download' => 'csv']);
?>

<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>રિપોર્ટ નિકાસ | AnnaSetu</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">

    <style>
        .export-content { padding: 8px 0 30px; }
        .export-heading { margin-bottom: 22px; }
        .export-heading h1 { margin: 0 0 8px; font-size: 28px; }
        .export-muted { color: #687386; font-size: 14px; line-height: 1.6; }

        .export-panel {
            background: #fff;
            border: 1px solid #e5eaf0;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 22px;
        }

        .export-panel h2 { margin: 0 0 12px; font-size: 19px; }

        .export-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
            margin-top: 18px;
        }

        .export-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            flex: 1;
            min-width: 165px;
        }

        .export-field label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
        }

        .export-field input,
        .export-field select {
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

        .export-btn {
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
            line-height: 1.5;
        }

        .export-btn:hover {
            background: #f1f6ff;
            border-color: #b7cbed;
        }

        .export-btn.primary {
            background: #2459a9;
            border-color: #2459a9;
            color: #fff;
        }

        .export-btn.primary:hover { background: #1c4788; }

        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 18px;
        }

        .export-option {
            border: 1px solid #e5eaf0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
        }

        .export-option h3 {
            margin: 0 0 8px;
            font-size: 16px;
            color: #203047;
        }

        .export-option p {
            margin: 0 0 16px;
            color: #687386;
            font-size: 13px;
            line-height: 1.6;
        }

        .export-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            margin: 18px 0;
        }

        .export-summary-card {
            background: #f8fafc;
            border: 1px solid #e5eaf0;
            border-radius: 10px;
            padding: 16px;
        }

        .export-summary-card span {
            display: block;
            color: #687386;
            font-size: 13px;
        }

        .export-summary-card strong {
            display: block;
            margin-top: 8px;
            font-size: 25px;
            color: #203047;
        }

        .export-table-wrap { width: 100%; overflow-x: auto; }

        .export-table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
        }

        .export-table th,
        .export-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #edf0f4;
            font-size: 13px;
        }

        .export-table th {
            background: #f8fafc;
            color: #596579;
            white-space: nowrap;
        }

        .export-empty {
            padding: 30px 12px;
            text-align: center;
            color: #687386;
        }

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
        }

        .project-links a:hover {
            background: #f1f6ff;
            border-color: #b7cbed;
        }

        @media (max-width: 600px) {
            .export-heading h1 { font-size: 23px; }
            .export-panel { padding: 14px; }
            .export-filters { align-items: stretch; }
            .export-filters .export-btn { width: 100%; }
            .project-links { gap: 10px; }
            .project-links a {
                width: 100%;
                justify-content: flex-start;
            }
        }
        .export-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid #edf0f4;
}

.export-page-info {
    color: #687386;
    font-size: 13px;
}

.export-page-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.export-page-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 38px;
    min-height: 38px;
    padding: 8px 12px;
    border: 1px solid #dfe5ed;
    border-radius: 8px;
    color: #2459a9;
    background: #fff;
    text-decoration: none;
    font-size: 13px;
}

.export-page-btn.active {
    color: #fff;
    background: #2459a9;
    border-color: #2459a9;
}

.export-page-btn.disabled {
    color: #a0a8b5;
    background: #f8fafc;
    pointer-events: none;
}

@media (max-width: 600px) {
    .export-pagination {
        align-items: flex-start;
        flex-direction: column;
    }
}
    </style>
</head>

<body>
<div class="dashboard-layout">

    <?php require __DIR__ . '/../includes/sidebar_cdpo.php'; ?>

    <div class="dashboard-main">

        <?php require __DIR__ . '/../includes/page-header.php'; ?>

        <main class="export-content">

            <div class="export-heading">
                <h1>રિપોર્ટ નિકાસ</h1>
                <p class="export-muted">
                    સ્વાગત છે, <?= $fullName ?>.
                    રિપોર્ટ માટેની વિગતો પસંદ કરો, રિપોર્ટ જુઓ અને CSV ફાઇલ ડાઉનલોડ કરો.
                </p>
            </div>

            <section class="export-panel">
                <h2>રિપોર્ટ સેટિંગ્સ</h2>
                <p class="export-muted">
                    પ્રોજેક્ટ: <?= exportEscape($project['project_name']) ?>
                    &nbsp; | &nbsp;
                    પ્રોજેક્ટ કોડ: <?= exportEscape($project['project_code']) ?>
                </p>

                <form method="GET" action="export-reports.php" class="export-filters">

                    <div class="export-field">
                        <label for="report_type">રિપોર્ટનો પ્રકાર</label>
                        <select name="report_type" id="report_type">
                            <?php foreach ($reportLabels as $key => $label): ?>
                                <option value="<?= exportEscape($key) ?>"
                                    <?= $reportType === $key ? 'selected' : '' ?>>
                                    <?= exportEscape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="export-field">
                        <label for="start_date">શરૂઆતની તારીખ</label>
                        <input type="date" name="start_date" id="start_date"
                               value="<?= exportEscape($startDate) ?>"
                               max="<?= $today ?>" required>
                    </div>

                    <div class="export-field">
                        <label for="end_date">અંતિમ તારીખ</label>
                        <input type="date" name="end_date" id="end_date"
                               value="<?= exportEscape($endDate) ?>"
                               max="<?= $today ?>" required>
                    </div>

                    <div class="export-field">
                        <label for="centre_id">આંગણવાડી કેન્દ્ર</label>
                        <select name="centre_id" id="centre_id">
                            <option value="">બધા કેન્દ્રો</option>
                            <?php foreach ($centres as $centre): ?>
                                <option value="<?= (int)$centre['centre_id'] ?>"
                                    <?= (int)$centreFilter === (int)$centre['centre_id'] ? 'selected' : '' ?>>
                                    <?= exportEscape($centre['centre_name']) ?>
                                    (<?= exportEscape($centre['centre_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="export-field">
                        <label for="item_id">ખાદ્ય સામગ્રી</label>
                        <select name="item_id" id="item_id">
                            <option value="">બધી ખાદ્ય સામગ્રી</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= (int)$item['item_id'] ?>"
                                    <?= (int)$itemFilter === (int)$item['item_id'] ? 'selected' : '' ?>>
                                    <?= exportEscape($item['item_name_en']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="export-btn">રિપોર્ટ જુઓ</button>
                    <a href="export-reports.php" class="export-btn">ફરીથી સેટ કરો</a>

                </form>
            </section>

            <section class="export-panel">
                <h2>રિપોર્ટ ડાઉનલોડ કરો</h2>
                <p class="export-muted">
                    CSV ફાઇલ Microsoft Excel, Google Sheets અને અન્ય સ્પ્રેડશીટ એપ્લિકેશનમાં ખોલી શકાય છે.
                </p>

                <div class="export-options">
                    <div class="export-option">
                        <h3>વ્યવહારની વિગતો</h3>
                        <p>
                            વ્યવહારની તારીખ, કેન્દ્રનું નામ, ખાદ્ય સામગ્રી,
                            જથ્થો, લાભાર્થીની વિગતો અને મંજૂરીની સ્થિતિ ડાઉનલોડ કરો.
                        </p>
                        <a class="export-btn primary"
                           href="<?= exportEscape('export-reports.php?' . http_build_query(array_merge($queryParams, ['report_type' => 'transactions', 'download' => 'csv']))) ?>">
                            CSV ડાઉનલોડ કરો
                        </a>
                    </div>

                    <div class="export-option">
                        <h3>કેન્દ્રવાર વિશ્લેષણ</h3>
                        <p>
                            દરેક કેન્દ્ર અને ખાદ્ય સામગ્રી માટે આવક, વપરાશ,
                            વિતરણ અને વ્યવહારોની સંખ્યા ડાઉનલોડ કરો.
                        </p>
                        <a class="export-btn primary"
                           href="<?= exportEscape('export-reports.php?' . http_build_query(array_merge($queryParams, ['report_type' => 'centres', 'download' => 'csv']))) ?>">
                            CSV ડાઉનલોડ કરો
                        </a>
                    </div>

                    <div class="export-option">
                        <h3>ખાદ્ય સામગ્રીનું વિશ્લેષણ</h3>
                        <p>
                            દરેક ખાદ્ય સામગ્રીની મંજૂર આવક, વપરાશ,
                            વિતરણ, જથ્થા સુધારા અને વ્યવહારોની સંખ્યા ડાઉનલોડ કરો.
                        </p>
                        <a class="export-btn primary"
                           href="<?= exportEscape('export-reports.php?' . http_build_query(array_merge($queryParams, ['report_type' => 'items', 'download' => 'csv']))) ?>">
                            CSV ડાઉનલોડ કરો
                        </a>
                    </div>
                </div>
            </section>

            <section class="export-panel">
                <h2><?= exportEscape($reportLabels[$reportType]) ?> - રિપોર્ટની ઝલક</h2>
                <p class="export-muted">
                    સમયગાળો: <?= exportEscape($startDate) ?> થી <?= exportEscape($endDate) ?>
                </p>

                <div class="export-summary">
                    <div class="export-summary-card">
                        <span>કુલ રેકોર્ડ</span>
                        <strong><?= count($reportRows) ?></strong>
                    </div>
                    <div class="export-summary-card">
                        <span>રિપોર્ટનો પ્રકાર</span>
                        <strong style="font-size:17px">
                            <?= exportEscape($reportLabels[$reportType]) ?>
                        </strong>
                    </div>
                </div>

                <?php if (!$reportRows): ?>
                    <div class="export-empty">
                        <h3>કોઈ રેકોર્ડ મળ્યો નથી</h3>
                        <p>અલગ તારીખ પસંદ કરો અથવા પસંદ કરેલા ફિલ્ટરમાં ફેરફાર કરો.</p>
                    </div>
                <?php else: ?>
                    <div class="export-table-wrap">
                        <table class="export-table">
                            <thead>
                                <tr>
                                    <?php foreach ($headers as $heading): ?>
                                        <th><?= exportEscape($heading) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $value): ?>
                                            <td><?= exportEscape($value) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
    <div class="export-pagination">
    <span class="export-page-info">
        <?php if ($totalRows > 0): ?>
            <?= exportText('Showing', 'બતાવી રહ્યા છીએ') ?>
            <?= (int)($offset + 1) ?>–<?= (int)min(
                $offset + $rowsPerPage,
                $totalRows
            ) ?>
            <?= exportText('of', 'માંથી') ?>
            <?= (int)$totalRows ?>
            <?= exportText('records', 'રેકોર્ડ') ?>
        <?php else: ?>
            <?= exportText('No records found', 'કોઈ રેકોર્ડ મળ્યો નથી') ?>
        <?php endif; ?>
    </span>

    <?php if ($totalPages > 1): ?>
        <div class="export-page-buttons">
            <?php
            $previousParams = $queryParams;
            $previousParams['page'] = $currentPage - 1;
            ?>

            <?php if ($currentPage > 1): ?>
                <a class="export-page-btn"
                   href="?<?= exportEscape(http_build_query($previousParams)) ?>">
                    &laquo; <?= exportText('Previous', 'પાછળ') ?>
                </a>
            <?php else: ?>
                <span class="export-page-btn disabled">
                    &laquo; <?= exportText('Previous', 'પાછળ') ?>
                </span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php
                $pageParams = $queryParams;
                $pageParams['page'] = $i;
                ?>

                <a class="export-page-btn <?= $i === $currentPage ? 'active' : '' ?>"
                   href="?<?= exportEscape(http_build_query($pageParams)) ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php
            $nextParams = $queryParams;
            $nextParams['page'] = $currentPage + 1;
            ?>

            <?php if ($currentPage < $totalPages): ?>
                <a class="export-page-btn"
                   href="?<?= exportEscape(http_build_query($nextParams)) ?>">
                    <?= exportText('Next', 'આગળ') ?> &raquo;
                </a>
            <?php else: ?>
                <span class="export-page-btn disabled">
                    <?= exportText('Next', 'આગળ') ?> &raquo;
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

                    <?php if (count($reportRows) > 10): ?>
                        <p class="export-muted" style="margin-top:12px">
                            કુલ <?= count($reportRows) ?> માંથી પ્રથમ 10 રેકોર્ડ બતાવવામાં આવ્યા છે.
                            સંપૂર્ણ રિપોર્ટ માટે CSV ડાઉનલોડ કરો.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <div style="margin-top:18px">
                    <a class="export-btn primary" href="<?= exportEscape($downloadUrl) ?>">
                        હાલનો રિપોર્ટ ડાઉનલોડ કરો (CSV)
                    </a>
                </div>
            </section>

            <section class="export-panel">
                <h2>ઝડપી લિંક્સ</h2>
                <div class="project-links">
                    <a href="projects.php">પ્રોજેક્ટની વિગતો</a>
                    <a href="centres.php">આંગણવાડી કેન્દ્રો</a>
                    <a href="stock-overview.php">સ્ટોકની વિગતો</a>
                    <a href="alerts.php">અછત અને સમાપ્તિ ચેતવણીઓ</a>
                    <a href="reports.php">રિપોર્ટ અને વિશ્લેષણ</a>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
const startInput = document.getElementById('start_date');
const endInput = document.getElementById('end_date');

startInput.addEventListener('change', function () {
    endInput.min = this.value;

    if (endInput.value && endInput.value < this.value) {
        endInput.value = this.value;
    }
});

endInput.addEventListener('change', function () {
    startInput.max = this.value;

    if (startInput.value && startInput.value > this.value) {
        startInput.value = this.value;
    }
});
</script>
</body>
</html>
