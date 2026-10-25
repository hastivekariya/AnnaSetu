<?php
/*
 * worker/reports.php
 * Read-only reports for the worker's own assigned centre(s):
 *   summary      - opening / received / consumed / distributed / closing stock
 *   consumption  - daily consumption
 *   distribution - beneficiary distribution
 *   receipts     - stock received
 * Actual stock totals use APPROVED transactions only. Pending and rejected
 * entries are shown separately in the transaction reports.
 */
require_once __DIR__ . '/../includes/auth.php';

requireRole(['worker']);

$role = strtolower($_SESSION['role'] ?? 'worker');
$fullName = $_SESSION['full_name'] ?? 'Worker';
$language = $_SESSION['language'] ?? 'gu';
$userId = (int) $_SESSION['user_id'];

const RP_PER_PAGE = 100;
const RP_EXPORT_LIMIT = 5000;
const RP_GROUP_LIMIT = 1000;

$errors = [];
$centres = [];
$foodItems = [];
$centreId = 0;

$tableHead = [];
$tableRows = [];
$cards = [];
$totalRows = 0;
$page = 1;
$totalPages = 1;

/* -------------------- Display Helpers -------------------- */

function rpEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function rpText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

function rpNumber($value): string
{
    $text = rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    return $text === '' || $text === '-0' ? '0' : $text;
}

function rpDate($value): string
{
    $value = trim((string)$value);
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
}

function rpShowDate(string $ymd): string
{
    return date('d-m-Y', strtotime($ymd));
}

/* "150 kg · 5 litre" */
function rpUnitsText(array $perUnit): string
{
    if (empty($perUnit)) {
        return '0';
    }

    $parts = [];

    foreach ($perUnit as $unit => $qty) {
        $parts[] = rpNumber($qty) . ' ' . $unit;
    }

    return implode(' · ', $parts);
}

function rpItemName(array $row): string
{
    if (($_SESSION['language'] ?? 'gu') === 'gu' && !empty($row['item_name_gu'])) {
        return $row['item_name_gu'];
    }

    return $row['item_name_en'] ?? '';
}

/* -------------------- Labels -------------------- */

$reportTypes = [
    'summary' => [rpText('Stock Summary', 'સ્ટોક સારાંશ'), rpText('Opening, closing and remaining stock', 'શરૂઆતનો, અંતિમ અને બાકી સ્ટોક')],
    'consumption' => [rpText('Daily Consumption', 'દૈનિક વપરાશ'), rpText('Food used at the centre', 'કેન્દ્રમાં વપરાયેલ અનાજ')],
    'distribution' => [rpText('Beneficiary Distribution', 'લાભાર્થી વિતરણ'), rpText('Beneficiaries and quantities', 'લાભાર્થીઓ અને જથ્થો')],
    'receipts' => [rpText('Stock Received', 'મળેલ સ્ટોક'), rpText('Receipt history', 'સ્ટોક મળ્યાનો ઇતિહાસ')]
];

$typeMap = [
    'consumption' => 'CONSUMPTION',
    'distribution' => 'DISTRIBUTION',
    'receipts' => 'RECEIPT'
];

$statusLabels = [
    'PENDING' => [rpText('Pending', 'બાકી'), 'status-pending'],
    'APPROVED' => [rpText('Approved', 'મંજૂર'), 'status-approved'],
    'REJECTED' => [rpText('Rejected', 'નામંજૂર'), 'status-rejected']
];

$beneficiaryLabels = [
    'CHILD' => rpText('Children', 'બાળકો'),
    'PREGNANT_WOMAN' => rpText('Pregnant Women', 'સગર્ભા મહિલાઓ'),
    'LACTATING_MOTHER' => rpText('Lactating Mothers', 'ધાત્રી માતાઓ'),
    'OTHER' => rpText('Other', 'અન્ય')
];

$periodLabels = [
    'today' => rpText('Today', 'આજે'),
    'week' => rpText('This Week', 'આ અઠવાડિયું'),
    'month' => rpText('This Month', 'આ મહિનો'),
    'custom' => rpText('Custom date range', 'પસંદગીની તારીખો')
];

$groupLabels = [
    'entries' => rpText('Each entry', 'દરેક નોંધ'),
    'daily' => rpText('Daily totals', 'દૈનિક કુલ'),
    'monthly' => rpText('Monthly totals', 'માસિક કુલ')
];

/* -------------------- Read Filters -------------------- */

$report = (string)($_GET['report'] ?? 'summary');
if (!isset($reportTypes[$report])) {
    $report = 'summary';
}

$period = (string)($_GET['period'] ?? 'month');
if (!isset($periodLabels[$period])) {
    $period = 'month';
}

$today = date('Y-m-d');
$dateFrom = rpDate($_GET['from'] ?? '');
$dateTo = rpDate($_GET['to'] ?? '');

if ($period === 'today') {
    $dateFrom = $dateTo = $today;
} elseif ($period === 'week') {
    $dateFrom = date('Y-m-d', strtotime('monday this week'));
    $dateTo = $today;
} elseif ($period === 'month') {
    $dateFrom = date('Y-m-01');
    $dateTo = $today;
} else { // custom
    if ($dateFrom === '' && $dateTo === '') {
        $dateFrom = date('Y-m-01');
        $dateTo = $today;
    } elseif ($dateFrom === '') {
        $dateFrom = $dateTo;
    } elseif ($dateTo === '') {
        $dateTo = $today;
    }

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
}

$fromDt = $dateFrom . ' 00:00:00';
$toDt = date('Y-m-d', strtotime($dateTo . ' +1 day')) . ' 00:00:00';

$itemFilter = (int)($_GET['item_id'] ?? 0);

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!isset($statusLabels[$statusFilter])) {
    $statusFilter = '';
}

$group = (string)($_GET['group'] ?? 'entries');
if (!isset($groupLabels[$group]) || !isset($typeMap[$report]) || $report === 'receipts') {
    $group = 'entries';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$isExport = isset($_GET['export']);

/* Query string for links */
function rpQuery(array $extra = []): string
{
    global $report, $centreId, $period, $dateFrom, $dateTo, $itemFilter,
           $statusFilter, $group;

    $base = [
        'report' => $report,
        'centre_id' => $centreId,
        'period' => $period,
        'item_id' => $itemFilter ?: '',
        'status' => $statusFilter,
        'group' => $group === 'entries' ? '' : $group
    ];

    if ($period === 'custom') {
        $base['from'] = $dateFrom;
        $base['to'] = $dateTo;
    }

    return http_build_query(array_filter(array_merge($base, $extra), static function ($v) {
        return $v !== '' && $v !== null;
    }));
}

/* Transaction WHERE clause (unique parameter names). */
function rpTxWhere(
    int $centreId, string $type, string $fromDt, string $toDt,
    int $itemId, string $status
): array {
    $sql = " WHERE t.centre_id = :centre_id
               AND t.transaction_type = :ttype
               AND t.transaction_date >= :from_dt
               AND t.transaction_date < :to_dt";

    $params = [
        'centre_id' => $centreId,
        'ttype' => $type,
        'from_dt' => $fromDt,
        'to_dt' => $toDt
    ];

    if ($itemId > 0) {
        $sql .= " AND t.item_id = :item_id";
        $params['item_id'] = $itemId;
    }

    if ($status !== '') {
        $sql .= " AND t.status = :status";
        $params['status'] = $status;
    }

    return [$sql, $params];
}

/* -------------------- Load Data -------------------- */

try {
    // Centres assigned to the logged-in user.
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

    $stmt = $pdo->query(
        "SELECT item_id, item_name_en, item_name_gu, unit
         FROM food_items
         WHERE is_active = 1
         ORDER BY item_name_en"
    );
    $foodItems = $stmt->fetchAll();

    $validItemIds = array_map('intval', array_column($foodItems, 'item_id'));
    if ($itemFilter > 0 && !in_array($itemFilter, $validItemIds, true)) {
        $itemFilter = 0;
    }

    if (!empty($centres)) {
        $validIds = array_map('intval', array_column($centres, 'centre_id'));
        $requested = filter_input(INPUT_GET, 'centre_id', FILTER_VALIDATE_INT);
        $centreId = in_array($requested, $validIds, true)
            ? $requested
            : $validIds[0];

        // beneficiary_count may not exist until the ALTER has been run.
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_transactions LIKE 'beneficiary_count'");
        $hasCount = (bool)$stmt->fetch();

        /* ================= STOCK SUMMARY ================= */
        if ($report === 'summary') {

            $sql =
                "SELECT f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                    COALESCE(cis.minimum_stock, f.default_min_stock) AS min_stock,
                    COALESCE(SUM(CASE
                        WHEN t.transaction_date < :from_open
                            THEN CASE
                                WHEN UPPER(t.transaction_type) = 'RECEIPT' THEN t.quantity
                                WHEN UPPER(t.transaction_type) IN ('CONSUMPTION', 'DISTRIBUTION') THEN -t.quantity
                                ELSE 0
                            END
                        ELSE 0
                    END), 0) AS opening,
                    COALESCE(SUM(CASE
                        WHEN t.transaction_date >= :from_rec
                             AND UPPER(t.transaction_type) = 'RECEIPT'
                            THEN t.quantity ELSE 0
                    END), 0) AS received,
                    COALESCE(SUM(CASE
                        WHEN t.transaction_date >= :from_con
                             AND UPPER(t.transaction_type) = 'CONSUMPTION'
                            THEN t.quantity ELSE 0
                    END), 0) AS consumed,
                    COALESCE(SUM(CASE
                        WHEN t.transaction_date >= :from_dis
                             AND UPPER(t.transaction_type) = 'DISTRIBUTION'
                            THEN t.quantity ELSE 0
                    END), 0) AS distributed
                 FROM food_items f
                 LEFT JOIN centre_item_settings cis
                    ON cis.item_id = f.item_id
                   AND cis.centre_id = :centre_a
                 LEFT JOIN stock_transactions t
                    ON t.item_id = f.item_id
                   AND t.centre_id = :centre_b
                   AND UPPER(t.status) = 'APPROVED'
                   AND t.transaction_date < :to_dt
                 WHERE f.is_active = 1";

            $params = [
                'from_open' => $fromDt,
                'from_rec' => $fromDt,
                'from_con' => $fromDt,
                'from_dis' => $fromDt,
                'centre_a' => $centreId,
                'centre_b' => $centreId,
                'to_dt' => $toDt
            ];

            if ($itemFilter > 0) {
                $sql .= " AND f.item_id = :item_id";
                $params['item_id'] = $itemFilter;
            }

            $sql .= " GROUP BY f.item_id, f.item_name_en, f.item_name_gu, f.unit,
                               cis.minimum_stock, f.default_min_stock
                      ORDER BY f.item_name_en";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $recv = $cons = $dist = [];
            $lowCount = $outCount = 0;

            $tableHead = [
                rpText('Food Item', 'ખાદ્ય વસ્તુ'),
                rpText('Opening Stock', 'શરૂઆતનો સ્ટોક'),
                rpText('Received', 'મળેલ'),
                rpText('Consumed', 'વપરાયેલ'),
                rpText('Distributed', 'વિતરિત'),
                rpText('Closing Stock', 'અંતિમ સ્ટોક'),
                rpText('Minimum Stock', 'ન્યૂનતમ સ્ટોક'),
                rpText('Status', 'સ્થિતિ')
            ];

            foreach ($stmt->fetchAll() as $row) {
                $closing = (float)$row['opening'] + (float)$row['received']
                    - (float)$row['consumed'] - (float)$row['distributed'];
                $min = (float)$row['min_stock'];
                $unit = $row['unit'];

                if ($closing <= 0) {
                    $status = [rpText('Out of Stock', 'સ્ટોક ખતમ'), 'status-out'];
                    $outCount++;
                } elseif ($closing <= $min) {
                    $status = [rpText('Low Stock', 'ઓછો સ્ટોક'), 'status-low'];
                    $lowCount++;
                } else {
                    $status = [rpText('In Stock', 'સ્ટોકમાં'), 'status-in'];
                }

                $recv[$unit] = ($recv[$unit] ?? 0) + (float)$row['received'];
                $cons[$unit] = ($cons[$unit] ?? 0) + (float)$row['consumed'];
                $dist[$unit] = ($dist[$unit] ?? 0) + (float)$row['distributed'];

                $tableRows[] = [
                    ['t' => rpItemName($row), 'bold' => true],
                    ['t' => rpNumber($row['opening']) . ' ' . $unit],
                    ['t' => rpNumber($row['received']) . ' ' . $unit],
                    ['t' => rpNumber($row['consumed']) . ' ' . $unit],
                    ['t' => rpNumber($row['distributed']) . ' ' . $unit],
                    ['t' => rpNumber($closing) . ' ' . $unit, 'bold' => true],
                    ['t' => rpNumber($min) . ' ' . $unit],
                    ['t' => $status[0], 'c' => $status[1]]
                ];
            }

            $cards = [
                ['label' => rpText('Received', 'મળેલ'), 'value' => rpUnitsText(array_filter($recv)), 'class' => 'card-receipt'],
                ['label' => rpText('Consumed', 'વપરાયેલ'), 'value' => rpUnitsText(array_filter($cons)), 'class' => 'card-consumption'],
                ['label' => rpText('Distributed', 'વિતરિત'), 'value' => rpUnitsText(array_filter($dist)), 'class' => 'card-distribution'],
                ['label' => rpText('Low / Out of Stock', 'ઓછો / ખતમ સ્ટોક'), 'value' => $lowCount . ' / ' . $outCount, 'class' => 'card-pending']
            ];

            $totalRows = count($tableRows);

        /* ============ CONSUMPTION / DISTRIBUTION / RECEIPTS ============ */
        } else {

            $txType = $typeMap[$report];

            // Cards: totals per status (ignores the status filter).
            [$cardWhere, $cardParams] = rpTxWhere(
                $centreId, $txType, $fromDt, $toDt, $itemFilter, ''
            );

            $countSelect = ($hasCount && $report === 'distribution')
                ? ", COALESCE(SUM(t.beneficiary_count), 0) AS people"
                : ", 0 AS people";

            $stmt = $pdo->prepare(
                "SELECT t.status, t.beneficiary_type, f.unit,
                        SUM(t.quantity) AS qty, COUNT(*) AS cnt" . $countSelect . "
                 FROM stock_transactions t
                 INNER JOIN food_items f ON f.item_id = t.item_id" .
                $cardWhere .
                " GROUP BY t.status, t.beneficiary_type, f.unit"
            );
            $stmt->execute($cardParams);

            $byStatus = ['APPROVED' => [], 'PENDING' => [], 'REJECTED' => []];
            $cntStatus = ['APPROVED' => 0, 'PENDING' => 0, 'REJECTED' => 0];
            $byBeneficiary = [];
            $peopleByType = [];

            foreach ($stmt->fetchAll() as $row) {
                $st = strtoupper($row['status']);
                $unit = $row['unit'];

                if (!isset($byStatus[$st])) {
                    continue;
                }

                $byStatus[$st][$unit] = ($byStatus[$st][$unit] ?? 0) + (float)$row['qty'];
                $cntStatus[$st] += (int)$row['cnt'];

                if ($st === 'APPROVED') {
                    $bt = $row['beneficiary_type'] ?? 'OTHER';
                    $byBeneficiary[$bt][$unit] = ($byBeneficiary[$bt][$unit] ?? 0) + (float)$row['qty'];
                    $peopleByType[$bt] = ($peopleByType[$bt] ?? 0) + (int)$row['people'];
                }
            }

            if ($report === 'distribution') {
                foreach (['CHILD', 'PREGNANT_WOMAN', 'LACTATING_MOTHER'] as $bt) {
                    $cards[] = [
                        'label' => $beneficiaryLabels[$bt],
                        'value' => rpUnitsText($byBeneficiary[$bt] ?? []),
                        'sub' => ($hasCount && !empty($peopleByType[$bt]))
                            ? rpText('People served', 'લાભાર્થીઓ') . ': ' . $peopleByType[$bt]
                            : '',
                        'class' => 'card-distribution'
                    ];
                }

                $cards[] = [
                    'label' => rpText('Pending / Rejected entries', 'બાકી / નામંજૂર નોંધો'),
                    'value' => $cntStatus['PENDING'] . ' / ' . $cntStatus['REJECTED'],
                    'class' => 'card-pending'
                ];
            } else {
                $cards = [
                    ['label' => rpText('Approved', 'મંજૂર'), 'value' => rpUnitsText($byStatus['APPROVED']),
                     'sub' => $cntStatus['APPROVED'] . ' ' . rpText('entries', 'નોંધો'), 'class' => 'card-receipt'],
                    ['label' => rpText('Pending', 'બાકી'), 'value' => rpUnitsText($byStatus['PENDING']),
                     'sub' => $cntStatus['PENDING'] . ' ' . rpText('entries', 'નોંધો'), 'class' => 'card-pending'],
                    ['label' => rpText('Rejected', 'નામંજૂર'), 'value' => rpUnitsText($byStatus['REJECTED']),
                     'sub' => $cntStatus['REJECTED'] . ' ' . rpText('entries', 'નોંધો'), 'class' => 'card-rejected'],
                    ['label' => rpText('Total entries', 'કુલ નોંધો'),
                     'value' => (string)array_sum($cntStatus), 'class' => 'card-consumption']
                ];
            }

            // Table rows.
            [$where, $params] = rpTxWhere(
                $centreId, $txType, $fromDt, $toDt, $itemFilter, $statusFilter
            );

            $statusCell = static function (string $status) use ($statusLabels): array {
                $info = $statusLabels[strtoupper($status)] ?? [$status, 'status-pending'];
                return ['t' => $info[0], 'c' => $info[1]];
            };

            if ($group === 'entries') {

                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM stock_transactions t" . $where
                );
                $stmt->execute($params);
                $totalRows = (int)$stmt->fetchColumn();

                $totalPages = max(1, (int)ceil($totalRows / RP_PER_PAGE));
                $page = min($page, $totalPages);

                $limit = $isExport ? RP_EXPORT_LIMIT : RP_PER_PAGE;
                $offset = $isExport ? 0 : ($page - 1) * RP_PER_PAGE;

                $peopleCol = ($hasCount && $report === 'distribution')
                    ? ", t.beneficiary_count" : "";

                $stmt = $pdo->prepare(
                    "SELECT t.transaction_id, t.transaction_date, t.quantity, t.status,
                            t.beneficiary_type, t.notes" . $peopleCol . ",
                            f.item_name_en, f.item_name_gu, f.unit,
                            u.full_name AS user_name,
                            b.batch_number, b.expiry_date, b.supplier_name
                     FROM stock_transactions t
                     INNER JOIN food_items f ON f.item_id = t.item_id
                     LEFT JOIN users u ON u.user_id = t.created_by
                     LEFT JOIN stock_batches b ON b.batch_id = t.batch_id" .
                    $where .
                    " ORDER BY t.transaction_date DESC, t.transaction_id DESC
                      LIMIT " . (int)$limit . " OFFSET " . (int)$offset
                );
                $stmt->execute($params);
                $data = $stmt->fetchAll();

                if ($report === 'consumption') {
                    $tableHead = [
                        rpText('Date', 'તારીખ'), rpText('Food Item', 'ખાદ્ય વસ્તુ'),
                        rpText('Quantity', 'જથ્થો'), rpText('Status', 'સ્થિતિ'),
                        rpText('Recorded By', 'નોંધનાર'), rpText('Notes', 'નોંધ')
                    ];

                    foreach ($data as $r) {
                        $tableRows[] = [
                            ['t' => rpShowDate($r['transaction_date'])],
                            ['t' => rpItemName($r), 'bold' => true],
                            ['t' => rpNumber($r['quantity']) . ' ' . $r['unit']],
                            $statusCell($r['status']),
                            ['t' => $r['user_name'] ?? '-'],
                            ['t' => $r['notes'] ?: '-']
                        ];
                    }
                } elseif ($report === 'distribution') {
                    $tableHead = [
                        rpText('Date', 'તારીખ'), rpText('Food Item', 'ખાદ્ય વસ્તુ'),
                        rpText('Beneficiary Category', 'લાભાર્થીનો પ્રકાર'),
                        rpText('Quantity', 'જથ્થો')
                    ];

                    if ($hasCount) {
                        $tableHead[] = rpText('Beneficiaries', 'લાભાર્થીઓ');
                    }

                    array_push(
                        $tableHead,
                        rpText('Status', 'સ્થિતિ'),
                        rpText('Recorded By', 'નોંધનાર'),
                        rpText('Notes', 'નોંધ')
                    );

                    foreach ($data as $r) {
                        $line = [
                            ['t' => rpShowDate($r['transaction_date'])],
                            ['t' => rpItemName($r), 'bold' => true],
                            ['t' => $beneficiaryLabels[$r['beneficiary_type']] ?? '-'],
                            ['t' => rpNumber($r['quantity']) . ' ' . $r['unit']]
                        ];

                        if ($hasCount) {
                            $line[] = ['t' => $r['beneficiary_count'] !== null ? (string)$r['beneficiary_count'] : '-'];
                        }

                        $line[] = $statusCell($r['status']);
                        $line[] = ['t' => $r['user_name'] ?? '-'];
                        $line[] = ['t' => $r['notes'] ?: '-'];
                        $tableRows[] = $line;
                    }
                } else { // receipts
                    $tableHead = [
                        rpText('Date', 'તારીખ'), rpText('Food Item', 'ખાદ્ય વસ્તુ'),
                        rpText('Quantity', 'જથ્થો'), rpText('Batch', 'બેચ'),
                        rpText('Supplier', 'સપ્લાયર'), rpText('Expiry Date', 'સમાપ્તિ તારીખ'),
                        rpText('Status', 'સ્થિતિ'), rpText('Recorded By', 'નોંધનાર')
                    ];

                    foreach ($data as $r) {
                        $tableRows[] = [
                            ['t' => rpShowDate($r['transaction_date'])],
                            ['t' => rpItemName($r), 'bold' => true],
                            ['t' => rpNumber($r['quantity']) . ' ' . $r['unit']],
                            ['t' => $r['batch_number'] ?? '-'],
                            ['t' => $r['supplier_name'] ?: '-'],
                            ['t' => $r['expiry_date'] ? rpShowDate($r['expiry_date']) : '-'],
                            $statusCell($r['status']),
                            ['t' => $r['user_name'] ?? '-']
                        ];
                    }
                }

            } else {
                // Daily or monthly totals.
                $periodExpr = $group === 'daily'
                    ? "DATE(t.transaction_date)"
                    : "DATE_FORMAT(t.transaction_date, '%Y-%m')";

                $byCategory = ($report === 'distribution');
                $catSelect = $byCategory ? ", t.beneficiary_type" : "";
                $catGroup = $byCategory ? ", t.beneficiary_type" : "";
                $peopleSelect = ($hasCount && $report === 'distribution')
                    ? ", COALESCE(SUM(t.beneficiary_count), 0) AS people" : "";

                $stmt = $pdo->prepare(
                    "SELECT " . $periodExpr . " AS period_key, t.status" . $catSelect . ",
                            f.item_name_en, f.item_name_gu, f.unit,
                            SUM(t.quantity) AS qty, COUNT(*) AS cnt" . $peopleSelect . "
                     FROM stock_transactions t
                     INNER JOIN food_items f ON f.item_id = t.item_id" .
                    $where .
                    " GROUP BY period_key, t.status" . $catGroup . ",
                               f.item_id, f.item_name_en, f.item_name_gu, f.unit
                      ORDER BY period_key DESC, f.item_name_en
                      LIMIT " . (int)RP_GROUP_LIMIT
                );
                $stmt->execute($params);
                $data = $stmt->fetchAll();

                $tableHead = [
                    $group === 'daily' ? rpText('Date', 'તારીખ') : rpText('Month', 'મહિનો'),
                    rpText('Food Item', 'ખાદ્ય વસ્તુ')
                ];

                if ($byCategory) {
                    $tableHead[] = rpText('Beneficiary Category', 'લાભાર્થીનો પ્રકાર');
                }

                array_push($tableHead, rpText('Total Quantity', 'કુલ જથ્થો'), rpText('Entries', 'નોંધો'));

                if ($hasCount && $report === 'distribution') {
                    $tableHead[] = rpText('Beneficiaries', 'લાભાર્થીઓ');
                }

                $tableHead[] = rpText('Status', 'સ્થિતિ');

                foreach ($data as $r) {
                    $label = $group === 'daily'
                        ? rpShowDate($r['period_key'])
                        : date('m-Y', strtotime($r['period_key'] . '-01'));

                    $line = [
                        ['t' => $label],
                        ['t' => rpItemName($r), 'bold' => true]
                    ];

                    if ($byCategory) {
                        $line[] = ['t' => $beneficiaryLabels[$r['beneficiary_type']] ?? '-'];
                    }

                    $line[] = ['t' => rpNumber($r['qty']) . ' ' . $r['unit'], 'bold' => true];
                    $line[] = ['t' => (string)$r['cnt']];

                    if ($hasCount && $report === 'distribution') {
                        $line[] = ['t' => (string)$r['people']];
                    }

                    $line[] = $statusCell($r['status']);
                    $tableRows[] = $line;
                }

                $totalRows = count($tableRows);
            }
        }

        /* ---------- CSV export ---------- */
        if ($isExport) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="report-' . $report . '-' . date('Y-m-d') . '.csv"');

            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Gujarati

            fputcsv($out, $tableHead);

            // Prevent spreadsheet formula injection.
            $safe = static function ($value) {
                $value = (string)$value;
                return ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false)
                    ? "'" . $value
                    : $value;
            };

            foreach ($tableRows as $line) {
                fputcsv($out, array_map(static function ($cell) use ($safe) {
                    return $safe($cell['t']);
                }, $line));
            }

            fclose($out);
            exit;
        }
    }

} catch (PDOException $e) {
    error_log('Reports error: ' . $e->getMessage());
    $errors[] = rpText(
        'Unable to load the report. Please check the database.',
        'અહેવાલ લોડ થઈ શક્યો નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* -------------------- Menu -------------------- */

$menuItems = [
    ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
    ['receive-stock.php', 'Receive Stock', 'સ્ટોક મેળવો'],
    ['daily-consumption.php', 'Daily Consumption', 'દૈનિક વપરાશ'],
    ['distribution.php', 'Beneficiary Distribution', 'લાભાર્થીઓને વિતરણ'],
    ['current-stock.php', 'Current Stock', 'હાલનો સ્ટોક'],
    ['stock-history.php', 'Stock History', 'સ્ટોકનો ઇતિહાસ'],
    ['reports.php', 'Reports', 'અહેવાલો'],
    ['notifications.php', 'Notifications', 'સૂચનાઓ'],
    ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
];

$centreName = '';

foreach ($centres as $c) {
    if ((int)$c['centre_id'] === $centreId) {
        $centreName = $c['centre_name'] . ' (' . $c['centre_code'] . ')';
    }
}

$hasFilters = ($itemFilter > 0 || $statusFilter !== '' || $group !== 'entries'
    || $period !== 'month');
?>
<!DOCTYPE html>
<html lang="<?= rpEscape($language) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= rpEscape(rpText('Reports', 'અહેવાલો')) ?> | AnnaSetu
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=3"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/receive-stock.css?v=2"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/reports.css?v=1"
    >
</head>

<body>

<div class="dashboard-layout">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <?= rpEscape(strtoupper(substr($fullName, 0, 1))) ?>
            </div>

            <div>
                <strong><?= rpEscape($fullName) ?></strong>
                <small><?= rpEscape(rpText('Worker', 'કાર્યકર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">

            <?php foreach ($menuItems as $menu): ?>
                <a href="<?= rpEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                   class="menu-link <?= $menu[0] === 'reports.php' ? 'active' : '' ?>">
                    <span><?= rpEscape(rpText($menu[1], $menu[2])) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= rpEscape(rpText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <!-- Main Content -->
    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content reports-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/<?= rpEscape($role) ?>/dashboard.php">
                &larr;
                <?= rpEscape(rpText('Back to Dashboard', 'ડેશબોર્ડ પર પાછા જાઓ')) ?>
            </a>

            <div class="receive-heading">
                <h1>
                    <?= rpEscape(rpText('Reports', 'અહેવાલો')) ?>
                    / અહેવાલો
                </h1>

                <p>
                    <?= rpEscape(rpText(
                        'View, filter, print and download reports for your Anganwadi centre.',
                        'તમારા આંગણવાડી કેન્દ્રના અહેવાલો જુઓ, ફિલ્ટર કરો, પ્રિન્ટ કરો અને ડાઉનલોડ કરો.'
                    )) ?>
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= rpEscape($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($centres)): ?>

                <div class="alert alert-warning">
                    <?= rpEscape(rpText(
                        'No centre is assigned to your account. Ask your administrator.',
                        'તમારા ખાતા સાથે કોઈ કેન્દ્ર જોડાયેલું નથી. એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                    )) ?>
                </div>

            <?php else: ?>

                <!-- Report selector -->
                <nav class="report-tabs">
                    <?php foreach ($reportTypes as $code => $info): ?>
                        <a href="?<?= rpEscape(rpQuery(['report' => $code, 'page' => '', 'group' => ($code === 'summary' || $code === 'receipts') ? '' : ($group === 'entries' ? '' : $group)])) ?>"
                           class="report-tab <?= $report === $code ? 'active' : '' ?>">
                            <strong><?= rpEscape($info[0]) ?></strong>
                            <span><?= rpEscape($info[1]) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <!-- Filters -->
                <section class="dashboard-section no-print">

                    <form method="GET" action="" class="report-filters" id="reportFilters">

                        <input type="hidden" name="report" value="<?= rpEscape($report) ?>">

                        <?php if (count($centres) > 1): ?>
                            <div class="filter-field">
                                <label for="centre_id"><?= rpEscape(rpText('Anganwadi Centre', 'આંગણવાડી કેન્દ્ર')) ?></label>
                                <select name="centre_id" id="centre_id" class="form-select">
                                    <?php foreach ($centres as $centre): ?>
                                        <option value="<?= (int)$centre['centre_id'] ?>"
                                            <?= (int)$centre['centre_id'] === $centreId ? 'selected' : '' ?>>
                                            <?= rpEscape($centre['centre_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="centre_id" value="<?= (int)$centreId ?>">
                        <?php endif; ?>

                        <div class="filter-field">
                            <label for="period"><?= rpEscape(rpText('Date range', 'તારીખ શ્રેણી')) ?></label>
                            <select name="period" id="period" class="form-select">
                                <?php foreach ($periodLabels as $code => $label): ?>
                                    <option value="<?= rpEscape($code) ?>" <?= $period === $code ? 'selected' : '' ?>>
                                        <?= rpEscape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-field custom-date">
                            <label for="from"><?= rpEscape(rpText('From', 'થી')) ?></label>
                            <input type="date" name="from" id="from" class="form-control"
                                   max="<?= rpEscape($today) ?>" value="<?= rpEscape($dateFrom) ?>">
                        </div>

                        <div class="filter-field custom-date">
                            <label for="to"><?= rpEscape(rpText('To', 'સુધી')) ?></label>
                            <input type="date" name="to" id="to" class="form-control"
                                   max="<?= rpEscape($today) ?>" value="<?= rpEscape($dateTo) ?>">
                        </div>

                        <div class="filter-field">
                            <label for="item_id"><?= rpEscape(rpText('Food Item', 'ખાદ્ય વસ્તુ')) ?></label>
                            <select name="item_id" id="item_id" class="form-select">
                                <option value=""><?= rpEscape(rpText('All food items', 'બધી ખાદ્ય વસ્તુઓ')) ?></option>
                                <?php foreach ($foodItems as $item): ?>
                                    <option value="<?= (int)$item['item_id'] ?>"
                                        <?= $itemFilter === (int)$item['item_id'] ? 'selected' : '' ?>>
                                        <?= rpEscape(rpItemName($item)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($report !== 'summary'): ?>
                            <div class="filter-field">
                                <label for="status"><?= rpEscape(rpText('Status', 'સ્થિતિ')) ?></label>
                                <select name="status" id="status" class="form-select">
                                    <option value=""><?= rpEscape(rpText('All statuses', 'બધી સ્થિતિ')) ?></option>
                                    <?php foreach ($statusLabels as $code => $label): ?>
                                        <option value="<?= rpEscape($code) ?>"
                                            <?= $statusFilter === $code ? 'selected' : '' ?>>
                                            <?= rpEscape($label[0]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if ($report === 'consumption' || $report === 'distribution'): ?>
                            <div class="filter-field">
                                <label for="group"><?= rpEscape(rpText('Show as', 'આ રીતે બતાવો')) ?></label>
                                <select name="group" id="group" class="form-select">
                                    <?php foreach ($groupLabels as $code => $label): ?>
                                        <option value="<?= rpEscape($code) ?>" <?= $group === $code ? 'selected' : '' ?>>
                                            <?= rpEscape($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="filter-actions">
                            <button type="submit" class="btn submit-button">
                                <?= rpEscape(rpText('Apply', 'લાગુ કરો')) ?>
                            </button>

                            <?php if ($hasFilters): ?>
                                <a href="?report=<?= rpEscape($report) ?>&amp;centre_id=<?= (int)$centreId ?>" class="btn btn-light">
                                    <?= rpEscape(rpText('Clear', 'સાફ કરો')) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    </form>

                </section>

                <!-- Print-only heading -->
                <div class="print-only print-heading">
                    <h2><?= rpEscape($reportTypes[$report][0]) ?></h2>
                    <p>
                        <?= rpEscape($centreName) ?> ·
                        <?= rpEscape(rpShowDate($dateFrom)) ?> – <?= rpEscape(rpShowDate($dateTo)) ?>
                    </p>
                </div>

                <!-- Summary cards -->
                <?php if (!empty($cards)): ?>
                    <section class="report-stats">
                        <?php foreach ($cards as $card): ?>
                            <div class="stat-card <?= rpEscape($card['class']) ?>">
                                <p><?= rpEscape($card['label']) ?></p>
                                <h3><?= rpEscape($card['value']) ?></h3>
                                <?php if (!empty($card['sub'])): ?>
                                    <small><?= rpEscape($card['sub']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <!-- Report table -->
                <section class="dashboard-section">

                    <div class="section-heading">
                        <h3><?= rpEscape($reportTypes[$report][0]) ?></h3>
                        <span>
                            <?= rpEscape(rpShowDate($dateFrom)) ?> – <?= rpEscape(rpShowDate($dateTo)) ?>
                            · <?= (int)$totalRows ?> <?= rpEscape(rpText('records', 'નોંધો')) ?>
                        </span>
                    </div>

                    <div class="report-tools no-print">
                        <a href="?<?= rpEscape(rpQuery(['export' => 1])) ?>" class="btn btn-light">
                            <?= rpEscape(rpText('Download CSV', 'CSV ડાઉનલોડ')) ?>
                        </a>

                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <?= rpEscape(rpText('Print', 'પ્રિન્ટ')) ?>
                        </button>
                    </div>

                    <?php if (empty($tableRows)): ?>

                        <div class="empty-state">
                            <?= rpEscape(rpText('No records found.', 'કોઈ નોંધ મળી નથી.')) ?>
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle report-table">

                                <thead>
                                    <tr>
                                        <?php foreach ($tableHead as $heading): ?>
                                            <th><?= rpEscape($heading) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($tableRows as $line): ?>
                                        <tr>
                                            <?php foreach ($line as $cell): ?>
                                                <td>
                                                    <?php if (!empty($cell['c'])): ?>
                                                        <span class="status-badge <?= rpEscape($cell['c']) ?>">
                                                            <?= rpEscape($cell['t']) ?>
                                                        </span>
                                                    <?php elseif (!empty($cell['bold'])): ?>
                                                        <strong><?= rpEscape($cell['t']) ?></strong>
                                                    <?php else: ?>
                                                        <?= rpEscape($cell['t']) ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                            </table>
                        </div>

                        <?php if ($report !== 'summary' && $group === 'entries' && $totalPages > 1): ?>
                            <nav class="report-pagination no-print" aria-label="Pages">

                                <?php if ($page > 1): ?>
                                    <a href="?<?= rpEscape(rpQuery(['page' => $page - 1])) ?>" class="page-link-btn">
                                        &laquo; <?= rpEscape(rpText('Previous', 'પાછળ')) ?>
                                    </a>
                                <?php endif; ?>

                                <span class="page-info">
                                    <?= rpEscape(rpText('Page', 'પેજ')) ?>
                                    <?= (int)$page ?> / <?= (int)$totalPages ?>
                                </span>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?<?= rpEscape(rpQuery(['page' => $page + 1])) ?>" class="page-link-btn">
                                        <?= rpEscape(rpText('Next', 'આગળ')) ?> &raquo;
                                    </a>
                                <?php endif; ?>

                            </nav>
                        <?php endif; ?>

                    <?php endif; ?>

                    <p class="report-footnote">
                        <?php if ($report === 'summary'): ?>
                            <?= rpEscape(rpText(
                                'Stock figures count approved entries only. Opening stock is the approved balance before the start date.',
                                'સ્ટોકના આંકડામાં ફક્ત મંજૂર થયેલ નોંધો ગણાય છે. શરૂઆતનો સ્ટોક એ શરૂઆતની તારીખ પહેલાંનો મંજૂર બાકી સ્ટોક છે.'
                            )) ?>
                        <?php else: ?>
                            <?= rpEscape(rpText(
                                'Pending and rejected entries are listed but are not part of actual stock.',
                                'બાકી અને નામંજૂર નોંધો અહીં બતાવાય છે, પરંતુ તે વાસ્તવિક સ્ટોકમાં ગણાતી નથી.'
                            )) ?>
                        <?php endif; ?>
                    </p>

                </section>

            <?php endif; ?>

        </div>

    </main>

</div>

<script>
    const periodSelect = document.getElementById('period');
    const customFields = document.querySelectorAll('.custom-date');

    function toggleCustomDates() {
        const show = periodSelect && periodSelect.value === 'custom';

        customFields.forEach(function (field) {
            field.style.display = show ? '' : 'none';

            field.querySelectorAll('input').forEach(function (input) {
                input.disabled = !show;
            });
        });
    }

    if (periodSelect) {
        periodSelect.addEventListener('change', toggleCustomDates);
        toggleCustomDates();
    }
</script>

</body>
</html>