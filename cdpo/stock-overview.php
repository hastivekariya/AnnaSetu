<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$language = strtolower($_SESSION['language'] ?? 'gu');
if (!in_array($language, ['en', 'gu'], true)) {
    $language = 'gu';
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function st(string $english, string $gujarati): string
{
    global $language;
    return $language === 'gu' ? $gujarati : $english;
}

function getStockStatus(float $stock, float $minimum): array
{
    if ($stock <= 0) {
        return ['Out of Stock', 'out'];
    }
    if ($stock < $minimum) {
        return ['Low Stock', 'low'];
    }
    return ['Available', 'available'];
}

if ($role !== 'cdpo') {
    http_response_code(403);
    exit(e(st('Access denied. This page is for CDPO users only.', 'પ્રવેશ નકારવામાં આવ્યો છે. આ પેજ ફક્ત CDPO વપરાશકર્તાઓ માટે છે.')));
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit(e(st('Database connection not found. Check config/database.php.', 'ડેટાબેઝ કનેક્શન મળ્યું નથી. config/database.php તપાસો.')));
}

try {
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
        exit(e(st('No project is assigned to this CDPO account.', 'આ CDPO એકાઉન્ટને કોઈ પ્રોજેક્ટ સોંપવામાં આવ્યો નથી.')));
    }

    $projectId = (int)$project['project_id'];
    $search = trim((string)($_GET['search'] ?? ''));
    $centreFilter = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);
    $statusFilter = (string)($_GET['stock_status'] ?? 'all');

    if (!in_array($statusFilter, ['all', 'available', 'low', 'out'], true)) {
        $statusFilter = 'all';
    }

    $stmt = $pdo->prepare("
        SELECT centre_id, centre_name, centre_code
        FROM anganwadi_centres
        WHERE project_id = ? AND is_active = 1
        ORDER BY centre_name
    ");
    $stmt->execute([$projectId]);
    $centreOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stockExpression = "
        COALESCE(SUM(
            CASE
                WHEN t.transaction_type = 'RECEIPT'
                     AND t.status = 'APPROVED'
                    THEN t.quantity
                WHEN t.transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                     AND t.status IN ('PENDING', 'APPROVED')
                    THEN -t.quantity
                WHEN t.transaction_type = 'ADJUSTMENT'
                     AND t.status = 'APPROVED'
                    THEN t.quantity
                ELSE 0
            END
        ), 0)
    ";

    $sql = "
        SELECT
            c.centre_id,
            c.centre_name,
            c.centre_code,
            c.village,
            f.item_id,
            f.item_code,
            f.item_name_en,
            f.unit,
            f.default_min_stock,
            COALESCE(MAX(s.minimum_stock), f.default_min_stock) AS minimum_stock,
            $stockExpression AS available_stock
        FROM anganwadi_centres c
        CROSS JOIN food_items f
        LEFT JOIN centre_item_settings s
            ON s.centre_id = c.centre_id
            AND s.item_id = f.item_id
        LEFT JOIN stock_transactions t
            ON t.centre_id = c.centre_id
            AND t.item_id = f.item_id
        WHERE c.project_id = ?
          AND c.is_active = 1
          AND f.is_active = 1
    ";

    $params = [$projectId];

    if ($centreFilter && $centreFilter > 0) {
        $sql .= " AND c.centre_id = ?";
        $params[] = $centreFilter;
    }

    if ($search !== '') {
        $sql .= " AND (
            f.item_name_en LIKE ?
            OR f.item_code LIKE ?
            OR c.centre_name LIKE ?
            OR c.centre_code LIKE ?
            OR c.village LIKE ?
        )";
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }

    $sql .= "
        GROUP BY
            c.centre_id, c.centre_name, c.centre_code, c.village,
            f.item_id, f.item_code, f.item_name_en, f.unit,
            f.default_min_stock
        ORDER BY c.centre_name, f.item_name_en
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allStockRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRows = count($allStockRows);
    $lowCount = 0;
    $outCount = 0;
    $availableCount = 0;
    $totalQuantity = 0.0;
    $stockRows = [];

    foreach ($allStockRows as $row) {
        $quantity = (float)$row['available_stock'];
        $minimum = (float)$row['minimum_stock'];
        [$label, $class] = getStockStatus($quantity, $minimum);
        $row['status_label'] = $label;
        $row['status_class'] = $class;

        if ($class === 'out') {
            $outCount++;
        } elseif ($class === 'low') {
            $lowCount++;
        } else {
            $availableCount++;
        }

        $totalQuantity += $quantity;
        $stockRows[] = $row;
    }

    if ($statusFilter !== 'all') {
        $stockRows = array_values(array_filter(
            $stockRows,
            static function ($row) use ($statusFilter) {
                return $row['status_class'] === $statusFilter;
            }
        ));
    }

    $fullName = $_SESSION['full_name'] ?? 'CDPO';

} catch (PDOException $ex) {
    error_log('CDPO stock overview error: ' . $ex->getMessage());
    http_response_code(500);
    exit(e(st('Unable to load stock overview. Check the server error log.', 'સ્ટોકની માહિતી લોડ થઈ શકી નથી. સર્વર એરર લોગ તપાસો.')));
}
?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(st('Stock Overview | AnnaSetu', 'સ્ટોકની ઝાંખી | અન્નાસેતુ')) ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stock-content{padding:8px 0 30px}
        .stock-heading{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px;margin-bottom:22px}
        .stock-heading h1{margin:0 0 8px;font-size:28px}
        .stock-muted{color:#687386;font-size:14px;line-height:1.6}
        .stock-project,.stock-panel{background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:20px;margin-bottom:22px}
        .stock-project h2,.stock-panel h2{margin:0 0 10px;font-size:19px}
        .stock-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px;margin:20px 0}
        .stock-stat{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:19px;box-shadow:0 3px 12px rgba(0,0,0,.035)}
        .stock-stat-label{display:block;color:#687386;font-size:14px}
        .stock-stat-number{display:block;margin:10px 0 5px;color:#203047;font-size:29px;font-weight:700}
        .stock-stat.low-card{border-left:4px solid #e5a000}
        .stock-stat.out-card{border-left:4px solid #dc3545}
        .stock-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin:18px 0}
        .stock-field{display:flex;flex-direction:column;gap:7px;flex:1;min-width:180px}
        .stock-field label{font-size:13px;font-weight:600;color:#475569}
        .stock-field input,.stock-field select{width:100%;min-height:42px;padding:10px 12px;border:1px solid #d8dee8;border-radius:8px;background:#fff;color:#253247;font-size:14px;box-sizing:border-box}
        .stock-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;border:1px solid #dfe5ed;border-radius:9px;background:#fff;color:#2459a9;font-size:14px;text-decoration:none;cursor:pointer;box-sizing:border-box}
        .stock-btn:hover{background:#f1f6ff;border-color:#b7cbed}
        .stock-table-wrap{width:100%;overflow-x:auto}
        .stock-table{width:100%;border-collapse:collapse;min-width:850px}
        .stock-table th,.stock-table td{padding:13px 12px;text-align:left;border-bottom:1px solid #edf0f4;font-size:14px;vertical-align:middle}
        .stock-table th{background:#f8fafc;color:#596579;white-space:nowrap}
        .stock-table tbody tr:hover{background:#fafcff}
        .stock-item-name{color:#203047;font-weight:600}
        .stock-item-code{margin-top:5px;color:#687386;font-size:12px}
        .stock-quantity{font-weight:600;color:#203047;white-space:nowrap}
        .stock-badge{display:inline-block;padding:5px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap}
        .stock-badge.available{background:#e6f7ed;color:#197447}
        .stock-badge.low{background:#fff4d6;color:#946200}
        .stock-badge.out{background:#fde8e8;color:#b42318}
        .stock-empty{padding:35px 12px;text-align:center;color:#687386}
        .stock-result-count{margin-bottom:12px;color:#687386;font-size:13px}
        .project-links{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:18px}
        .project-links a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 16px;background:#fff;border:1px solid #dfe5ed;border-radius:9px;color:#2459a9;text-decoration:none;font-size:14px;line-height:1.5;box-sizing:border-box;transition:background .2s ease,border-color .2s ease}
        .project-links a:hover{background:#f1f6ff;border-color:#b7cbed}
        @media(max-width:600px){.stock-heading h1{font-size:23px}.stock-project,.stock-panel{padding:14px}.stock-filters{align-items:stretch}.stock-filters .stock-btn{width:100%}.project-links{gap:10px}.project-links a{width:100%;justify-content:flex-start}}
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php require __DIR__ . '/../includes/sidebar_cdpo.php'; ?>
    <div class="dashboard-main">
        <?php require __DIR__ . '/../includes/page-header.php'; ?>
        <main class="stock-content">
            <div class="stock-heading">
                <div>
                    <h1><?= e(st('Stock Overview', 'સ્ટોકની ઝાંખી')) ?></h1>
                    <p class="stock-muted">
                        <?= e(st('Welcome, ', 'સ્વાગત છે, ')) ?><?= e($fullName) ?>.
                        <?= e(st("Monitor food stock balances across your project's active Anganwadi centres.", 'તમારા પ્રોજેક્ટના સક્રિય આંગણવાડી કેન્દ્રોમાં ખાદ્ય સામગ્રીના સ્ટોકની દેખરેખ રાખો.')) ?>
                    </p>
                </div>
            </div>

            <section class="stock-project">
                <h2><?= e($project['project_name']) ?></h2>
                <div class="stock-muted">
                    <?= e(st('Project Code:', 'પ્રોજેક્ટ કોડ:')) ?> <?= e($project['project_code']) ?>
                    &nbsp; | &nbsp;
                    <?= e(st('District:', 'જિલ્લો:')) ?> <?= e($project['district']) ?>
                    <?php if (!empty($project['taluka'])): ?>
                        &nbsp; | &nbsp;
                        <?= e(st('Taluka:', 'તાલુકો:')) ?> <?= e($project['taluka']) ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="stock-stats">
                <div class="stock-stat">
                    <span class="stock-stat-label"><?= e(st('Centre–Item Records', 'કેન્દ્ર–વસ્તુ નોંધો')) ?></span>
                    <span class="stock-stat-number"><?= (int)$totalRows ?></span>
                    <span class="stock-muted"><?= e(st('Items tracked across centres', 'કેન્દ્રોમાં નોંધાયેલી વસ્તુઓ')) ?></span>
                </div>
                <div class="stock-stat">
                    <span class="stock-stat-label"><?= e(st('Available Records', 'ઉપલબ્ધ સ્ટોકની નોંધો')) ?></span>
                    <span class="stock-stat-number"><?= (int)$availableCount ?></span>
                    <span class="stock-muted"><?= e(st('At or above minimum stock', 'લઘુત્તમ સ્ટોક જેટલો અથવા વધુ')) ?></span>
                </div>
                <div class="stock-stat low-card">
                    <span class="stock-stat-label"><?= e(st('Low Stock', 'ઓછો સ્ટોક')) ?></span>
                    <span class="stock-stat-number"><?= (int)$lowCount ?></span>
                    <span class="stock-muted"><?= e(st('Below minimum stock level', 'લઘુત્તમ સ્ટોક કરતાં ઓછો')) ?></span>
                </div>
                <div class="stock-stat out-card">
                    <span class="stock-stat-label"><?= e(st('Out of Stock', 'સ્ટોક ખલાસ')) ?></span>
                    <span class="stock-stat-number"><?= (int)$outCount ?></span>
                    <span class="stock-muted"><?= e(st('Zero or negative balance', 'સ્ટોક શૂન્ય અથવા ઋણ છે')) ?></span>
                </div>
                <div class="stock-stat">
                    <span class="stock-stat-label"><?= e(st('Net Quantity', 'ચોખ્ખો જથ્થો')) ?></span>
                    <span class="stock-stat-number"><?= number_format($totalQuantity, 3) ?></span>
                    <span class="stock-muted"><?= e(st('Sum of listed item quantities; units vary by item', 'દર્શાવેલી વસ્તુઓના જથ્થાનો સરવાળો; દરેક વસ્તુનું એકમ અલગ હોઈ શકે છે')) ?></span>
                </div>
            </section>

            <section class="stock-panel">
                <h2><?= e(st('Centre-wise Food Stock', 'કેન્દ્ર મુજબ ખાદ્ય સ્ટોક')) ?></h2>
                <form method="GET" action="stock-overview.php" class="stock-filters">
                    <div class="stock-field">
                        <label for="search"><?= e(st('Search', 'શોધો')) ?></label>
                        <input type="search" id="search" name="search"
                               placeholder="<?= e(st('Food item, code, centre or village', 'ખાદ્ય વસ્તુ, કોડ, કેન્દ્ર અથવા ગામ')) ?>"
                               value="<?= e($search) ?>">
                    </div>
                    <div class="stock-field">
                        <label for="centre_id"><?= e(st('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?></label>
                        <select id="centre_id" name="centre_id">
                            <option value=""><?= e(st('All active centres', 'બધા સક્રિય કેન્દ્રો')) ?></option>
                            <?php foreach ($centreOptions as $centre): ?>
                                <option value="<?= (int)$centre['centre_id'] ?>"
                                    <?= (int)$centreFilter === (int)$centre['centre_id'] ? 'selected' : '' ?>>
                                    <?= e($centre['centre_name']) ?> (<?= e($centre['centre_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="stock-field">
                        <label for="stock_status"><?= e(st('Stock Status', 'સ્ટોકની સ્થિતિ')) ?></label>
                        <select id="stock_status" name="stock_status">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>><?= e(st('All statuses', 'બધી સ્થિતિઓ')) ?></option>
                            <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>><?= e(st('Available', 'ઉપલબ્ધ')) ?></option>
                            <option value="low" <?= $statusFilter === 'low' ? 'selected' : '' ?>><?= e(st('Low stock', 'ઓછો સ્ટોક')) ?></option>
                            <option value="out" <?= $statusFilter === 'out' ? 'selected' : '' ?>><?= e(st('Out of stock', 'સ્ટોક ખલાસ')) ?></option>
                        </select>
                    </div>
                    <button type="submit" class="stock-btn"><?= e(st('Apply Filters', 'ફિલ્ટર લાગુ કરો')) ?></button>
                    <a href="stock-overview.php" class="stock-btn"><?= e(st('Reset', 'રીસેટ')) ?></a>
                </form>

                <div class="stock-result-count">
                    <?= e(st('Showing ', 'દર્શાવવામાં આવે છે: ')) ?><?= count($stockRows) ?>
                    <?= e(st(' of ', ' માંથી ')) ?><?= (int)$totalRows ?>
                    <?= e(st(' centre-item records', ' કેન્દ્ર-વસ્તુની નોંધો')) ?>
                </div>

                <?php if (empty($stockRows)): ?>
                    <div class="stock-empty">
                        <h3><?= e(st('No stock records found', 'સ્ટોકની કોઈ નોંધ મળી નથી')) ?></h3>
                        <p><?= e(st('Try changing the search text or filters.', 'શોધ લખાણ અથવા ફિલ્ટર બદલીને જુઓ.')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="stock-table-wrap">
                        <table class="stock-table">
                            <thead>
                                <tr>
                                    <th><?= e(st('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?></th>
                                    <th><?= e(st('Food Item', 'ખાદ્ય વસ્તુ')) ?></th>
                                    <th><?= e(st('Available Stock', 'ઉપલબ્ધ સ્ટોક')) ?></th>
                                    <th><?= e(st('Minimum Stock', 'લઘુત્તમ સ્ટોક')) ?></th>
                                    <th><?= e(st('Status', 'સ્થિતિ')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stockRows as $row): ?>
                                <tr>
                                    <td>
                                        <div class="stock-item-name"><?= e($row['centre_name']) ?></div>
                                        <div class="stock-item-code">
                                            <?= e($row['centre_code']) ?>
                                            <?php if (!empty($row['village'])): ?> · <?= e($row['village']) ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stock-item-name"><?= e($row['item_name_en']) ?></div>
                                        <div class="stock-item-code"><?= e($row['item_code']) ?></div>
                                    </td>
                                    <td class="stock-quantity"><?= number_format((float)$row['available_stock'], 3) ?> <?= e($row['unit']) ?></td>
                                    <td><?= number_format((float)$row['minimum_stock'], 3) ?> <?= e($row['unit']) ?></td>
                                    <td>
                                        <span class="stock-badge <?= e($row['status_class']) ?>">
                                            <?= e(st($row['status_label'], $row['status_class'] === 'out' ? 'સ્ટોક ખલાસ' : ($row['status_class'] === 'low' ? 'ઓછો સ્ટોક' : 'ઉપલબ્ધ'))) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="stock-panel">
                <h2><?= e(st('Quick Access', 'ઝડપી ઍક્સેસ')) ?></h2>
                <div class="project-links">
                    <a href="projects.php"><?= e(st('Project Overview', 'પ્રોજેક્ટની ઝાંખી')) ?></a>
                    <a href="centres.php"><?= e(st('Anganwadi Centres', 'આંગણવાડી કેન્દ્રો')) ?></a>
                    <a href="alerts.php"><?= e(st('Shortage & Expiry Alerts', 'અછત અને સમાપ્તિની ચેતવણીઓ')) ?></a>
                    <a href="reports.php"><?= e(st('Reports & Analysis', 'અહેવાલો અને વિશ્લેષણ')) ?></a>
                    <a href="export-reports.php"><?= e(st('Export Reports', 'અહેવાલો નિકાસ કરો')) ?></a>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
