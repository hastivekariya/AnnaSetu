<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = strtolower($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$language = ($_SESSION['language'] ?? 'gu') === 'en' ? 'en' : 'gu';

if ($role !== 'cdpo') {
    http_response_code(403);
    exit($language === 'gu'
        ? 'પ્રવેશ નકારવામાં આવ્યો. આ પેજ ફક્ત CDPO વપરાશકર્તાઓ માટે છે.'
        : 'Access denied. This page is for CDPO users only.');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit($language === 'gu'
        ? 'ડેટાબેઝ કનેક્શન મળ્યું નથી. config/database.php તપાસો.'
        : 'Database connection not found. Check config/database.php.');
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function projectText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'en' ? $english : $gujarati;
}

function fetchOne(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: [];
}

try {
    // Get the project assigned to the logged-in CDPO (role_id = 4).
    $project = fetchOne(
        $pdo,
        "SELECT p.project_id, p.project_name, p.project_code,
                p.district, p.taluka, p.is_active
         FROM users u
         INNER JOIN projects p ON p.project_id = u.project_id
         WHERE u.user_id = ? AND u.role_id = 4
         LIMIT 1",
        [$userId]
    );

    if (!$project) {
        http_response_code(404);
        exit(projectText(
            'No project is assigned to this CDPO account.',
            'આ CDPO ખાતાને કોઈ પ્રોજેક્ટ સોંપાયેલો નથી.'
        ));
    }

    $projectId = (int)$project['project_id'];

    $centreStats = fetchOne(
        $pdo,
        "SELECT COUNT(*) AS total_centres,
                COALESCE(SUM(is_active = 1), 0) AS active_centres
         FROM anganwadi_centres
         WHERE project_id = ?",
        [$projectId]
    );

    $itemStats = fetchOne(
        $pdo,
        "SELECT COUNT(*) AS total FROM food_items WHERE is_active = 1"
    );
    $totalItems = (int)($itemStats['total'] ?? 0);

    $pendingStats = fetchOne(
        $pdo,
        "SELECT COUNT(*) AS total
         FROM stock_transactions t
         INNER JOIN anganwadi_centres c ON c.centre_id = t.centre_id
         WHERE c.project_id = ? AND t.status = 'PENDING'",
        [$projectId]
    );
    $pendingTransactions = (int)($pendingStats['total'] ?? 0);

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

    $shortageStats = fetchOne(
        $pdo,
        "SELECT COUNT(*) AS total
         FROM (
             SELECT
                 c.centre_id,
                 f.item_id,
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
             GROUP BY c.centre_id, f.item_id, f.default_min_stock
         ) AS stock_summary
         WHERE available_stock < minimum_stock",
        [$projectId]
    );
    $shortageAlerts = (int)($shortageStats['total'] ?? 0);

    $expiryStats = fetchOne(
        $pdo,
        "SELECT
             COALESCE(SUM(b.expiry_date < CURDATE()), 0) AS expired,
             COALESCE(SUM(
                 b.expiry_date >= CURDATE()
                 AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ), 0) AS near_expiry
         FROM stock_batches b
         INNER JOIN anganwadi_centres c ON c.centre_id = b.centre_id
         INNER JOIN food_items f ON f.item_id = b.item_id
         WHERE c.project_id = ?
           AND f.expiry_tracking = 1
           AND b.expiry_date IS NOT NULL",
        [$projectId]
    );

    $expiredBatches = (int)($expiryStats['expired'] ?? 0);
    $nearExpiryBatches = (int)($expiryStats['near_expiry'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT centre_id, centre_code, centre_name, village, is_active
         FROM anganwadi_centres
         WHERE project_id = ?
         ORDER BY centre_name"
    );
    $stmt->execute([$projectId]);
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log('CDPO project overview error: ' . $ex->getMessage());
    http_response_code(500);
    exit(projectText(
        'Unable to load the project overview. Check the server error log.',
        'પ્રોજેક્ટની માહિતી લોડ થઈ શકી નથી. સર્વર એરર લોગ તપાસો.'
    ));
}

$fullName = $_SESSION['full_name'] ?? 'CDPO';
$isProjectActive = (int)($project['is_active'] ?? 0) === 1;
?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(projectText('Project Overview', 'પ્રોજેક્ટની માહિતી')) ?> | AnnaSetu</title>

    <link rel="stylesheet" href="../assets/css/dashboard.css">

    <style>
        .project-content { padding: 8px 0 30px; min-width: 0; }
        .project-heading { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
        .project-heading h1 { margin:0 0 8px; font-size:28px; }
        .project-muted { color:#687386; font-size:14px; line-height:1.6; }
        .project-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin:22px 0; }
        .project-card { background:#fff; border:1px solid #e5eaf0; border-radius:14px; padding:20px; box-shadow:0 3px 12px rgba(0,0,0,.035); min-width:0; }
        .project-card .card-label { color:#687386; font-size:14px; }
        .project-card .card-number { display:block; font-size:30px; font-weight:700; margin:12px 0 5px; color:#203047; }
        .project-panel { background:#fff; border:1px solid #e5eaf0; border-radius:14px; padding:20px; margin-bottom:22px; overflow-x:auto; }
        .project-panel h2 { margin:0 0 15px; font-size:19px; }
        .project-status { display:inline-block; padding:5px 10px; border-radius:20px; font-size:12px; font-weight:600; white-space:nowrap; }
        .status-active { background:#e6f7ed; color:#197447; }
        .status-inactive { background:#f1f3f5; color:#596579; }
        .project-table { width:100%; border-collapse:collapse; min-width:600px; }
        .project-table th,.project-table td { text-align:left; padding:13px 12px; border-bottom:1px solid #edf0f4; font-size:14px; }
        .project-table th { color:#596579; background:#f8fafc; }
        .project-links { display:flex; flex-wrap:wrap; gap:10px; }
        .project-links a { display:inline-block; padding:10px 13px; border:1px solid #dfe5ed; border-radius:8px; color:#2459a9; text-decoration:none; font-size:14px; }
        .project-links a:hover { background:#f1f6ff; }
        .alert-card { border-left:4px solid #e5a000; }
        .danger-card { border-left:4px solid #dc3545; }
        @media(max-width:600px) {
            .project-heading h1 { font-size:23px; }
            .project-panel { padding:14px; }
            .project-card { padding:16px; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">

    <?php require __DIR__ . '/../includes/sidebar_cdpo.php'; ?>

    <div class="dashboard-main">
        <?php require __DIR__ . '/../includes/page-header.php'; ?>

        <main class="project-content">
            <div class="project-heading">
                <div>
                    <h1><?= e(projectText('Project Overview', 'પ્રોજેક્ટની માહિતી')) ?></h1>
                    <div class="project-muted">
                        <?= e(projectText('Welcome, ', 'સ્વાગત છે, ')) ?><?= e($fullName) ?>.
                        <?= e(projectText(
                            'Monitor project performance, Anganwadi centres and food stock.',
                            'પ્રોજેક્ટની કામગીરી, આંગણવાડી કેન્દ્રો અને ખાદ્ય સ્ટોકની દેખરેખ રાખો.'
                        )) ?>
                    </div>
                </div>

                <span class="project-status <?= $isProjectActive ? 'status-active' : 'status-inactive' ?>">
                    <?= e($isProjectActive
                        ? projectText('Project Active', 'પ્રોજેક્ટ સક્રિય છે')
                        : projectText('Project Inactive', 'પ્રોજેક્ટ નિષ્ક્રિય છે')) ?>
                </span>
            </div>

            <section class="project-panel">
                <h2><?= e($project['project_name'] ?? '') ?></h2>
                <div class="project-muted">
                    <?= e(projectText('Project Code: ', 'પ્રોજેક્ટ કોડ: ')) ?><?= e($project['project_code'] ?? '') ?>
                    &nbsp; | &nbsp;
                    <?= e(projectText('District: ', 'જિલ્લો: ')) ?><?= e($project['district'] ?? '') ?>

                    <?php if (!empty($project['taluka'])): ?>
                        &nbsp; | &nbsp;
                        <?= e(projectText('Taluka: ', 'તાલુકો: ')) ?><?= e($project['taluka']) ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="project-grid">
                <div class="project-card">
                    <span class="card-label"><?= e(projectText('Total Anganwadi Centres', 'કુલ આંગણવાડી કેન્દ્રો')) ?></span>
                    <span class="card-number"><?= (int)($centreStats['total_centres'] ?? 0) ?></span>
                    <span class="project-muted"><?= e(projectText('Centres in this project', 'આ પ્રોજેક્ટમાં કેન્દ્રો')) ?></span>
                </div>

                <div class="project-card">
                    <span class="card-label"><?= e(projectText('Active Centres', 'સક્રિય કેન્દ્રો')) ?></span>
                    <span class="card-number"><?= (int)($centreStats['active_centres'] ?? 0) ?></span>
                    <span class="project-muted"><?= e(projectText('Currently active centres', 'હાલમાં સક્રિય કેન્દ્રો')) ?></span>
                </div>

                <div class="project-card">
                    <span class="card-label"><?= e(projectText('Active Food Items', 'સક્રિય ખાદ્ય વસ્તુઓ')) ?></span>
                    <span class="card-number"><?= $totalItems ?></span>
                    <span class="project-muted"><?= e(projectText('Items tracked by AnnaSetu', 'અન્નસેતુ દ્વારા નોંધાયેલી વસ્તુઓ')) ?></span>
                </div>

                <div class="project-card">
                    <span class="card-label"><?= e(projectText('Pending Transactions', 'બાકી વ્યવહારો')) ?></span>
                    <span class="card-number"><?= $pendingTransactions ?></span>
                    <span class="project-muted"><?= e(projectText('Awaiting verification', 'ચકાસણીની રાહમાં')) ?></span>
                </div>

                <div class="project-card alert-card">
                    <span class="card-label"><?= e(projectText('Stock Shortages', 'સ્ટોકની અછત')) ?></span>
                    <span class="card-number"><?= $shortageAlerts ?></span>
                    <span class="project-muted"><?= e(projectText(
                        'Centre-item combinations below minimum',
                        'ન્યૂનતમ મર્યાદાથી ઓછો સ્ટોક ધરાવતા કેન્દ્ર-વસ્તુ જોડાણો'
                    )) ?></span>
                </div>

                <div class="project-card alert-card">
                    <span class="card-label"><?= e(projectText('Near-expiry Batches', 'સમાપ્તિ નજીકની બેચ')) ?></span>
                    <span class="card-number"><?= $nearExpiryBatches ?></span>
                    <span class="project-muted"><?= e(projectText('Expiring within 30 days', '30 દિવસમાં સમાપ્ત થનાર')) ?></span>
                </div>

                <div class="project-card danger-card">
                    <span class="card-label"><?= e(projectText('Expired Batches', 'સમાપ્ત થયેલી બેચ')) ?></span>
                    <span class="card-number"><?= $expiredBatches ?></span>
                    <span class="project-muted"><?= e(projectText('Require immediate attention', 'તાત્કાલિક ધ્યાન આપવું જરૂરી')) ?></span>
                </div>
            </section>

            <section class="project-panel">
                <h2><?= e(projectText('Anganwadi Centre Summary', 'આંગણવાડી કેન્દ્રોની વિગતો')) ?></h2>

                <?php if (empty($centres)): ?>
                    <p class="project-muted">
                        <?= e(projectText(
                            'No Anganwadi centres are assigned to this project.',
                            'આ પ્રોજેક્ટને કોઈ આંગણવાડી કેન્દ્ર સોંપાયેલું નથી.'
                        )) ?>
                    </p>
                <?php else: ?>
                    <table class="project-table">
                        <thead>
                            <tr>
                                <th><?= e(projectText('Centre Code', 'કેન્દ્ર કોડ')) ?></th>
                                <th><?= e(projectText('Centre Name', 'કેન્દ્રનું નામ')) ?></th>
                                <th><?= e(projectText('Village', 'ગામ')) ?></th>
                                <th><?= e(projectText('Status', 'સ્થિતિ')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($centres as $centre): ?>
                                <?php $centreIsActive = (int)($centre['is_active'] ?? 0) === 1; ?>
                                <tr>
                                    <td><?= e($centre['centre_code'] ?? '') ?></td>
                                    <td><?= e($centre['centre_name'] ?? '') ?></td>
                                    <td><?= e($centre['village'] ?? '') ?></td>
                                    <td>
                                        <span class="project-status <?= $centreIsActive ? 'status-active' : 'status-inactive' ?>">
                                            <?= e($centreIsActive
                                                ? projectText('Active', 'સક્રિય')
                                                : projectText('Inactive', 'નિષ્ક્રિય')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="project-panel">
                <h2><?= e(projectText('Quick Access', 'ઝડપી પ્રવેશ')) ?></h2>
                <div class="project-links">
                    <a href="centres.php"><?= e(projectText('Anganwadi Centres', 'આંગણવાડી કેન્દ્રો')) ?></a>
                    <a href="stock-overview.php"><?= e(projectText('Stock Overview', 'સ્ટોકની માહિતી')) ?></a>
                    <a href="alerts.php"><?= e(projectText('Shortage & Expiry Alerts', 'અછત અને સમાપ્તિ ચેતવણીઓ')) ?></a>
                    <a href="reports.php"><?= e(projectText('Reports & Analysis', 'અહેવાલો અને વિશ્લેષણ')) ?></a>
                    <a href="export-reports.php"><?= e(projectText('Export Reports', 'અહેવાલો એક્સપોર્ટ કરો')) ?></a>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
