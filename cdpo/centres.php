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

function ct(string $english, string $gujarati): string
{
    global $language;
    return $language === 'gu' ? $gujarati : $english;
}

if ($role !== 'cdpo') {
    http_response_code(403);
    exit(e(ct('Access denied. This page is for CDPO users only.', 'પ્રવેશ નકારવામાં આવ્યો છે. આ પેજ ફક્ત CDPO વપરાશકર્તાઓ માટે છે.')));
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit(e(ct('Database connection not found. Check config/database.php.', 'ડેટાબેઝ કનેક્શન મળ્યું નથી. config/database.php તપાસો.')));
}

try {
    // Load the project assigned to this CDPO.
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
        exit(e(ct('No project is assigned to this CDPO account.', 'આ CDPO એકાઉન્ટને કોઈ પ્રોજેક્ટ સોંપવામાં આવ્યો નથી.')));
    }

    $projectId = (int)$project['project_id'];

    // Read optional search and status filters.
    $search = trim((string)($_GET['search'] ?? ''));
    $status = (string)($_GET['status'] ?? 'all');

    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'all';
    }

    $where = ['c.project_id = ?'];
    $params = [$projectId];

    if ($search !== '') {
        $where[] = "(
            c.centre_name LIKE ?
            OR c.centre_code LIKE ?
            OR c.village LIKE ?
            OR c.address LIKE ?
        )";

        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term);
    }

    if ($status === 'active') {
        $where[] = 'c.is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'c.is_active = 0';
    }

    $whereSql = implode(' AND ', $where);

    // Project-wide centre counts.
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(is_active = 1), 0) AS active,
            COALESCE(SUM(is_active = 0), 0) AS inactive
        FROM anganwadi_centres
        WHERE project_id = ?
    ");
    $stmt->execute([$projectId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0, 'inactive' => 0];

    // Filtered centre list.
    $stmt = $pdo->prepare("
        SELECT
            c.centre_id,
            c.centre_code,
            c.centre_name,
            c.village,
            c.address,
            c.contact_number,
            c.is_active,
            c.created_at,
            (
                SELECT COUNT(*)
                FROM users u
                WHERE u.centre_id = c.centre_id
                  AND u.is_active = 1
            ) AS active_users
        FROM anganwadi_centres c
        WHERE $whereSql
        ORDER BY c.centre_name ASC
    ");
    $stmt->execute($params);
    $centres = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $ex) {
    error_log('CDPO centres page error: ' . $ex->getMessage());
    http_response_code(500);
    exit(e(ct('Unable to load Anganwadi centres. Check the server error log.', 'આંગણવાડી કેન્દ્રો લોડ થઈ શક્યા નથી. સર્વર એરર લોગ તપાસો.')));
}

$fullName = $_SESSION['full_name'] ?? 'CDPO';
?>
<!DOCTYPE html>
<html lang="<?= e($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(ct('Anganwadi Centres | AnnaSetu', 'આંગણવાડી કેન્દ્રો | અન્નાસેતુ')) ?></title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .centres-content { padding: 8px 0 30px; }
        .centres-heading { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
        .centres-heading h1 { margin:0 0 8px; font-size:28px; }
        .centres-muted { color:#687386; font-size:14px; line-height:1.6; }
        .centres-project { background:#fff; border:1px solid #e5eaf0; border-radius:12px; padding:16px 18px; margin-bottom:20px; }
        .centres-project h2 { margin:0 0 6px; font-size:18px; }
        .centres-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(175px,1fr)); gap:16px; margin:20px 0; }
        .centres-stat { background:#fff; border:1px solid #e5eaf0; border-radius:12px; padding:19px; box-shadow:0 3px 12px rgba(0,0,0,.035); }
        .centres-stat-label { display:block; color:#687386; font-size:14px; }
        .centres-stat-number { display:block; font-size:29px; font-weight:700; color:#203047; margin-top:10px; }
        .centres-panel { background:#fff; border:1px solid #e5eaf0; border-radius:14px; padding:20px; margin-bottom:22px; }
        .centres-panel h2 { font-size:19px; margin:0 0 16px; }
        .centres-filters { display:flex; align-items:end; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
        .centres-field { display:flex; flex-direction:column; gap:7px; flex:1; min-width:180px; }
        .centres-field label { font-size:13px; font-weight:600; color:#475569; }
        .centres-field input,.centres-field select { width:100%; min-height:42px; padding:10px 12px; border:1px solid #d8dee8; border-radius:8px; background:#fff; color:#253247; font-size:14px; box-sizing:border-box; }
        .centres-btn { min-height:42px; padding:10px 17px; border:0; border-radius:8px; background:#2459a9; color:#fff; cursor:pointer; font-size:14px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; }
        .centres-btn.secondary { background:#eef2f7; color:#344256; }
        .centres-table-wrap { width:100%; overflow-x:auto; }
        .centres-table { width:100%; border-collapse:collapse; min-width:850px; }
        .centres-table th,.centres-table td { padding:13px 12px; text-align:left; border-bottom:1px solid #edf0f4; font-size:14px; vertical-align:top; }
        .centres-table th { background:#f8fafc; color:#596579; white-space:nowrap; }
        .centres-table tbody tr:hover { background:#fafcff; }
        .centre-name { color:#203047; font-weight:600; }
        .centre-code { color:#687386; font-size:12px; margin-top:5px; }
        .centre-badge { display:inline-block; padding:5px 10px; border-radius:20px; font-size:12px; font-weight:600; white-space:nowrap; }
        .centre-badge.active { background:#e6f7ed; color:#197447; }
        .centre-badge.inactive { background:#f1f3f5; color:#596579; }
        .centres-empty { text-align:center; padding:35px 12px; color:#687386; }
        .centres-result-count { color:#687386; font-size:13px; margin-bottom:12px; }
        .project-links { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-top:18px; }
        .project-links a { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:10px 16px; background:#fff; border:1px solid #dfe5ed; border-radius:9px; color:#2459a9; text-decoration:none; font-size:14px; font-weight:400; line-height:1.5; box-sizing:border-box; transition:background .2s ease,border-color .2s ease; }
        .project-links a:hover { background:#f1f6ff; border-color:#b7cbed; }
        @media(max-width:600px) {
            .centres-heading h1 { font-size:23px; }
            .centres-panel { padding:14px; }
            .centres-filters { align-items:stretch; }
            .centres-filters .centres-btn { width:100%; }
            .project-links { gap:10px; }
            .project-links a { width:100%; justify-content:flex-start; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php require __DIR__ . '/../includes/sidebar_cdpo.php'; ?>
    <div class="dashboard-main">
        <?php require __DIR__ . '/../includes/page-header.php'; ?>
        <main class="centres-content">
            <div class="centres-heading">
                <div>
                    <h1><?= e(ct('Anganwadi Centres', 'આંગણવાડી કેન્દ્રો')) ?></h1>
                    <p class="centres-muted">
                        <?= e(ct('Welcome, ', 'સ્વાગત છે, ')) ?><?= e($fullName) ?>.
                        <?= e(ct('View and monitor the centres assigned to your project.', 'તમારા પ્રોજેક્ટને સોંપાયેલા કેન્દ્રો જુઓ અને તેમની દેખરેખ રાખો.')) ?>
                    </p>
                </div>
            </div>

            <section class="centres-project">
                <h2><?= e($project['project_name']) ?></h2>
                <div class="centres-muted">
                    <?= e(ct('Project Code:', 'પ્રોજેક્ટ કોડ:')) ?> <?= e($project['project_code']) ?>
                    &nbsp; | &nbsp;
                    <?= e(ct('District:', 'જિલ્લો:')) ?> <?= e($project['district']) ?>
                    <?php if (!empty($project['taluka'])): ?>
                        &nbsp; | &nbsp;
                        <?= e(ct('Taluka:', 'તાલુકો:')) ?> <?= e($project['taluka']) ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="centres-stats">
                <div class="centres-stat">
                    <span class="centres-stat-label"><?= e(ct('Total Centres', 'કુલ કેન્દ્રો')) ?></span>
                    <span class="centres-stat-number"><?= (int)$stats['total'] ?></span>
                </div>
                <div class="centres-stat">
                    <span class="centres-stat-label"><?= e(ct('Active Centres', 'સક્રિય કેન્દ્રો')) ?></span>
                    <span class="centres-stat-number"><?= (int)$stats['active'] ?></span>
                </div>
                <div class="centres-stat">
                    <span class="centres-stat-label"><?= e(ct('Inactive Centres', 'નિષ્ક્રિય કેન્દ્રો')) ?></span>
                    <span class="centres-stat-number"><?= (int)$stats['inactive'] ?></span>
                </div>
            </section>

            <section class="centres-panel">
                <h2><?= e(ct('Centre Directory', 'કેન્દ્રોની યાદી')) ?></h2>
                <form method="GET" action="centres.php" class="centres-filters">
                    <div class="centres-field">
                        <label for="search"><?= e(ct('Search centres', 'કેન્દ્રો શોધો')) ?></label>
                        <input type="search" id="search" name="search"
                               placeholder="<?= e(ct('Name, code, village...', 'નામ, કોડ, ગામ...')) ?>"
                               value="<?= e($search) ?>">
                    </div>
                    <div class="centres-field" style="max-width:220px">
                        <label for="status"><?= e(ct('Centre status', 'કેન્દ્રની સ્થિતિ')) ?></label>
                        <select id="status" name="status">
                            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>><?= e(ct('All centres', 'બધા કેન્દ્રો')) ?></option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= e(ct('Active only', 'ફક્ત સક્રિય')) ?></option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>><?= e(ct('Inactive only', 'ફક્ત નિષ્ક્રિય')) ?></option>
                        </select>
                    </div>
                    <button type="submit" class="centres-btn"><?= e(ct('Apply Filters', 'ફિલ્ટર લાગુ કરો')) ?></button>
                    <a href="centres.php" class="centres-btn secondary"><?= e(ct('Reset', 'રીસેટ')) ?></a>
                </form>

                <div class="centres-result-count">
                    <?= e(ct('Showing ', 'દર્શાવવામાં આવે છે: ')) ?><?= count($centres) ?>
                    <?= e(ct(count($centres) === 1 ? ' centre' : ' centres', ' કેન્દ્રો')) ?>
                </div>

                <?php if (empty($centres)): ?>
                    <div class="centres-empty">
                        <h3><?= e(ct('No centres found', 'કોઈ કેન્દ્ર મળ્યું નથી')) ?></h3>
                        <p><?= e(ct('Try changing your search text or status filter.', 'શોધ લખાણ અથવા સ્થિતિનું ફિલ્ટર બદલીને જુઓ.')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="centres-table-wrap">
                        <table class="centres-table">
                            <thead>
                                <tr>
                                    <th><?= e(ct('Centre', 'કેન્દ્ર')) ?></th>
                                    <th><?= e(ct('Village', 'ગામ')) ?></th>
                                    <th><?= e(ct('Address', 'સરનામું')) ?></th>
                                    <th><?= e(ct('Contact Number', 'સંપર્ક નંબર')) ?></th>
                                    <th><?= e(ct('Active Users', 'સક્રિય વપરાશકર્તાઓ')) ?></th>
                                    <th><?= e(ct('Status', 'સ્થિતિ')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($centres as $centre): ?>
                                <tr>
                                    <td>
                                        <div class="centre-name"><?= e($centre['centre_name']) ?></div>
                                        <div class="centre-code"><?= e(ct('Code:', 'કોડ:')) ?> <?= e($centre['centre_code']) ?></div>
                                    </td>
                                    <td><?= e($centre['village']) ?></td>
                                    <td><?= !empty($centre['address']) ? e($centre['address']) : '<span class="centres-muted">' . e(ct('Not provided', 'આપેલ નથી')) . '</span>' ?></td>
                                    <td><?= !empty($centre['contact_number']) ? e($centre['contact_number']) : '<span class="centres-muted">' . e(ct('Not provided', 'આપેલ નથી')) . '</span>' ?></td>
                                    <td><?= (int)$centre['active_users'] ?></td>
                                    <td>
                                        <span class="centre-badge <?= (int)$centre['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                            <?= e(ct((int)$centre['is_active'] === 1 ? 'Active' : 'Inactive', (int)$centre['is_active'] === 1 ? 'સક્રિય' : 'નિષ્ક્રિય')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="centres-panel">
                <h2><?= e(ct('Other CDPO Modules', 'CDPO ના અન્ય વિભાગો')) ?></h2>
                <div class="project-links">
                    <a href="projects.php"><?= e(ct('Project Overview', 'પ્રોજેક્ટની ઝાંખી')) ?></a>
                    <a href="stock-overview.php"><?= e(ct('Stock Overview', 'સ્ટોકની ઝાંખી')) ?></a>
                    <a href="alerts.php"><?= e(ct('Shortage & Expiry Alerts', 'અછત અને સમાપ્તિની ચેતવણીઓ')) ?></a>
                    <a href="reports.php"><?= e(ct('Reports & Analysis', 'અહેવાલો અને વિશ્લેષણ')) ?></a>
                    <a href="export-reports.php"><?= e(ct('Export Reports', 'અહેવાલો નિકાસ કરો')) ?></a>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
