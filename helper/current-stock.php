<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['centre_id'])) {
    header('Location: ../login.php');
    exit;
}

$centreId = (int) $_SESSION['centre_id'];
$fullName = $_SESSION['full_name'] ?? 'User';
$language = strtolower($_SESSION['language'] ?? 'en');
$language = ($language === 'gu') ? 'gu' : 'en';
$isGujarati = ($language === 'gu');

function stockT(string $key): string
{
    global $isGujarati;
    $text = [
        'page_title' => ['en' => 'Current Stock', 'gu' => 'હાલનો સ્ટોક'],
        'description' => ['en' => 'View food inventory and available quantities at your centre.', 'gu' => 'તમારા કેન્દ્રમાં ખાદ્ય સામગ્રી અને ઉપલબ્ધ જથ્થો જુઓ.'],
        'load_error' => ['en' => 'Unable to load current stock. Please check the database and try again.', 'gu' => 'હાલનો સ્ટોક લોડ કરી શકાયો નથી. કૃપા કરીને ડેટાબેઝ તપાસો અને ફરી પ્રયાસ કરો.'],
        'active_items' => ['en' => 'Active Food Items', 'gu' => 'સક્રિય ખાદ્ય વસ્તુઓ'],
        'in_stock_items' => ['en' => 'Items In Stock', 'gu' => 'સ્ટોકમાં રહેલી વસ્તુઓ'],
        'low_stock_items' => ['en' => 'Low Stock Items', 'gu' => 'ઓછા સ્ટોકવાળી વસ્તુઓ'],
        'out_stock' => ['en' => 'Out of Stock', 'gu' => 'સ્ટોક ખાલી'],
        'inventory' => ['en' => 'Food Inventory', 'gu' => 'ખાદ્ય સામગ્રીનો જથ્થો'],
        'search_placeholder' => ['en' => 'Search food item...', 'gu' => 'ખાદ્ય વસ્તુ શોધો...'],
        'search_label' => ['en' => 'Search food item', 'gu' => 'ખાદ્ય વસ્તુ શોધો'],
        'filter_label' => ['en' => 'Filter stock status', 'gu' => 'સ્ટોકની સ્થિતિ પ્રમાણે ફિલ્ટર કરો'],
        'all_items' => ['en' => 'All Items', 'gu' => 'બધી વસ્તુઓ'],
        'in_stock' => ['en' => 'In Stock', 'gu' => 'સ્ટોકમાં છે'],
        'low_stock' => ['en' => 'Low Stock', 'gu' => 'ઓછો સ્ટોક'],
        'th_number' => ['en' => '#', 'gu' => 'ક્રમાંક'],
        'food_item' => ['en' => 'Food Item', 'gu' => 'ખાદ્ય વસ્તુ'],
        'received' => ['en' => 'Received', 'gu' => 'મળેલ જથ્થો'],
        'consumed' => ['en' => 'Consumed', 'gu' => 'વપરાયેલ જથ્થો'],
        'distributed' => ['en' => 'Distributed', 'gu' => 'વિતરિત જથ્થો'],
        'adjustments' => ['en' => 'Adjustments', 'gu' => 'સ્ટોક સુધારા'],
        'available' => ['en' => 'Available', 'gu' => 'ઉપલબ્ધ જથ્થો'],
        'status' => ['en' => 'Status', 'gu' => 'સ્થિતિ'],
        'empty_items' => ['en' => 'No active food items found.', 'gu' => 'કોઈ સક્રિય ખાદ્ય વસ્તુ મળી નથી.'],
        'no_results' => ['en' => 'No items match your search.', 'gu' => 'તમારી શોધ મુજબ કોઈ વસ્તુ મળી નથી.'],
        'threshold_note' => ['en' => "Low stock is defined as 5 units or less. Adjust this threshold to match your centre's inventory rules. Quantities use the food item's configured unit.", 'gu' => '5 એકમ અથવા તેનાથી ઓછો જથ્થો ઓછો સ્ટોક ગણાય છે. તમારા કેન્દ્રના નિયમો મુજબ આ મર્યાદા બદલો. જથ્થો ખાદ્ય વસ્તુના નિર્ધારિત એકમમાં દર્શાવેલો છે.'],
    ];
    return $text[$key][$isGujarati ? 'gu' : 'en'] ?? $key;
}

function stockE($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function stockFoodName(array $food): string
{
    global $isGujarati;
    if ($isGujarati && !empty($food['item_name_gu'])) {
        return (string)$food['item_name_gu'];
    }
    return (string)($food['item_name_en'] ?? '');
}

$foodItems = [];
$errorMessage = '';

try {
    $stmt = $pdo->prepare("
        SELECT
            f.item_id,
            f.item_name_en,
            f.item_name_gu,
            f.unit,
            COALESCE(SUM(CASE WHEN st.transaction_type = 'RECEIPT' AND st.status = 'APPROVED' THEN st.quantity ELSE 0 END), 0) AS total_received,
            COALESCE(SUM(CASE WHEN st.transaction_type = 'CONSUMPTION' AND st.status IN ('PENDING', 'APPROVED') THEN st.quantity ELSE 0 END), 0) AS total_consumed,
            COALESCE(SUM(CASE WHEN st.transaction_type = 'DISTRIBUTION' AND st.status IN ('PENDING', 'APPROVED') THEN st.quantity ELSE 0 END), 0) AS total_distributed,
            COALESCE(SUM(CASE WHEN st.transaction_type = 'ADJUSTMENT' AND st.status = 'APPROVED' THEN st.quantity ELSE 0 END), 0) AS total_adjustments,
            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'RECEIPT' AND st.status = 'APPROVED' THEN st.quantity
                    WHEN st.transaction_type IN ('CONSUMPTION', 'DISTRIBUTION') AND st.status IN ('PENDING', 'APPROVED') THEN -st.quantity
                    WHEN st.transaction_type = 'ADJUSTMENT' AND st.status = 'APPROVED' THEN st.quantity
                    ELSE 0
                END
            ), 0) AS available_stock
        FROM food_items f
        LEFT JOIN stock_transactions st ON st.item_id = f.item_id AND st.centre_id = ?
        WHERE f.is_active = 1
        GROUP BY f.item_id, f.item_name_en, f.item_name_gu, f.unit
        ORDER BY f.item_name_en ASC
    ");
    $stmt->execute([$centreId]);
    $foodItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Current stock page error: ' . $e->getMessage());
    $errorMessage = stockT('load_error');
}

$totalItems = count($foodItems);
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;

foreach ($foodItems as $food) {
    $available = (float)$food['available_stock'];
    if ($available <= 0) {
        $outOfStockCount++;
    } elseif ($available <= 5) {
        $lowStockCount++;
    } else {
        $inStockCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $language ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= stockE(stockT('page_title')) ?> | AnnaSetu</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .stock-content{padding:24px;min-width:0}.stock-heading{margin:0 0 6px;color:var(--text-primary,#183d32)}.stock-description{margin:0 0 24px;color:var(--text-secondary,#6b7280)}
        .stock-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:24px}.stock-stat{padding:20px;border-radius:12px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e5e7eb);min-width:0}.stock-stat-label{display:block;font-size:13px;color:var(--text-secondary,#6b7280);margin-bottom:10px}.stock-stat-value{font-size:27px;font-weight:700;color:var(--text-primary,#183d32);overflow-wrap:anywhere}
        .stock-card{padding:22px;border-radius:12px;background:var(--card-bg,#fff);border:1px solid var(--border-color,#e5e7eb);min-width:0}.stock-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px}.stock-toolbar h2{margin:0;font-size:19px}.stock-filters{display:flex;gap:10px;flex-wrap:wrap}.stock-filters input,.stock-filters select{padding:10px 12px;border:1px solid var(--border-color,#d1d5db);border-radius:8px;background:var(--input-bg,#fff);color:var(--text-primary,#111827);font:inherit;max-width:100%}
        .stock-table-wrap{width:100%;overflow-x:auto}.stock-table{width:100%;min-width:850px;border-collapse:collapse}.stock-table th,.stock-table td{padding:14px 12px;text-align:left;border-bottom:1px solid var(--border-color,#e5e7eb);font-size:14px}.stock-table th{background:var(--table-header-bg,#f8fafc);font-weight:600;white-space:nowrap}.stock-table tbody tr:hover{background:var(--table-hover-bg,#f8fafc)}.stock-status{display:inline-block;padding:5px 10px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}.stock-status.available{background:#dcfce7;color:#166534}.stock-status.low{background:#fef3c7;color:#92400e}.stock-status.empty{background:#fee2e2;color:#991b1b}.stock-empty{padding:28px 12px!important;text-align:center!important;color:var(--text-secondary,#6b7280)}.stock-alert{padding:14px 16px;margin-bottom:20px;border-radius:8px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        @media(max-width:1000px){.stock-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.stock-content{padding:16px}.stock-stat-grid{grid-template-columns:1fr 1fr;gap:10px}.stock-stat{padding:14px}.stock-stat-value{font-size:22px}.stock-card{padding:14px}.stock-filters,.stock-filters input,.stock-filters select{width:100%;box-sizing:border-box}}
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="dashboard-main">
        <?php require __DIR__ . '/../includes/page-header.php'; ?>
        <main class="stock-content">
            <h1 class="stock-heading"><?= stockE(stockT('page_title')) ?></h1>
            <p class="stock-description"><?= stockE(stockT('description')) ?></p>

            <?php if ($errorMessage !== ''): ?>
                <div class="stock-alert" role="alert"><?= stockE($errorMessage) ?></div>
            <?php endif; ?>

            <section class="stock-stat-grid">
                <div class="stock-stat"><span class="stock-stat-label"><?= stockE(stockT('active_items')) ?></span><div class="stock-stat-value"><?= number_format($totalItems) ?></div></div>
                <div class="stock-stat"><span class="stock-stat-label"><?= stockE(stockT('in_stock_items')) ?></span><div class="stock-stat-value"><?= number_format($inStockCount) ?></div></div>
                <div class="stock-stat"><span class="stock-stat-label"><?= stockE(stockT('low_stock_items')) ?></span><div class="stock-stat-value"><?= number_format($lowStockCount) ?></div></div>
                <div class="stock-stat"><span class="stock-stat-label"><?= stockE(stockT('out_stock')) ?></span><div class="stock-stat-value"><?= number_format($outOfStockCount) ?></div></div>
            </section>

            <section class="stock-card">
                <div class="stock-toolbar">
                    <h2><?= stockE(stockT('inventory')) ?></h2>
                    <div class="stock-filters">
                        <input type="search" id="stock-search" placeholder="<?= stockE(stockT('search_placeholder')) ?>" aria-label="<?= stockE(stockT('search_label')) ?>">
                        <select id="stock-filter" aria-label="<?= stockE(stockT('filter_label')) ?>">
                            <option value="ALL"><?= stockE(stockT('all_items')) ?></option>
                            <option value="AVAILABLE"><?= stockE(stockT('in_stock')) ?></option>
                            <option value="LOW"><?= stockE(stockT('low_stock')) ?></option>
                            <option value="EMPTY"><?= stockE(stockT('out_stock')) ?></option>
                        </select>
                    </div>
                </div>

                <div class="stock-table-wrap">
                    <table class="stock-table">
                        <thead><tr>
                            <th><?= stockE(stockT('th_number')) ?></th><th><?= stockE(stockT('food_item')) ?></th><th><?= stockE(stockT('received')) ?></th><th><?= stockE(stockT('consumed')) ?></th><th><?= stockE(stockT('distributed')) ?></th><th><?= stockE(stockT('adjustments')) ?></th><th><?= stockE(stockT('available')) ?></th><th><?= stockE(stockT('status')) ?></th>
                        </tr></thead>
                        <tbody id="stock-table-body">
                        <?php if (!$foodItems): ?>
                            <tr><td colspan="8" class="stock-empty"><?= stockE(stockT('empty_items')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($foodItems as $index => $food): ?>
                                <?php
                                $available = (float)$food['available_stock'];
                                if ($available <= 0) {
                                    $status = 'EMPTY'; $statusLabel = stockT('out_stock'); $statusClass = 'empty';
                                } elseif ($available <= 5) {
                                    $status = 'LOW'; $statusLabel = stockT('low_stock'); $statusClass = 'low';
                                } else {
                                    $status = 'AVAILABLE'; $statusLabel = stockT('in_stock'); $statusClass = 'available';
                                }
                                $unit = $food['unit'] ?? '';
                                $foodName = stockFoodName($food);
                                ?>
                                <tr data-name="<?= stockE(mb_strtolower($foodName, 'UTF-8')) ?>" data-status="<?= stockE($status) ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td><strong><?= stockE($foodName) ?></strong></td>
                                    <td><?= number_format((float)$food['total_received'], 3) ?> <?= stockE($unit) ?></td>
                                    <td><?= number_format((float)$food['total_consumed'], 3) ?> <?= stockE($unit) ?></td>
                                    <td><?= number_format((float)$food['total_distributed'], 3) ?> <?= stockE($unit) ?></td>
                                    <td><?= number_format((float)$food['total_adjustments'], 3) ?> <?= stockE($unit) ?></td>
                                    <td><strong><?= number_format($available, 3) ?> <?= stockE($unit) ?></strong></td>
                                    <td><span class="stock-status <?= stockE($statusClass) ?>"><?= stockE($statusLabel) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="stock-no-results" hidden><td colspan="8" class="stock-empty"><?= stockE(stockT('no_results')) ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="stock-description" style="margin:18px 0 0;"><?= stockE(stockT('threshold_note')) ?></p>
            </section>
        </main>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
<script>
(() => {
    const searchInput = document.getElementById('stock-search');
    const filterSelect = document.getElementById('stock-filter');
    const tableBody = document.getElementById('stock-table-body');
    const noResults = document.getElementById('stock-no-results');
    if (!searchInput || !filterSelect || !tableBody) return;

    function filterStock() {
        const search = searchInput.value.trim().toLocaleLowerCase();
        const filter = filterSelect.value;
        const rows = tableBody.querySelectorAll('tr[data-name]');
        let visibleCount = 0;
        rows.forEach(row => {
            const name = (row.dataset.name || '').toLocaleLowerCase();
            const status = row.dataset.status || '';
            const visible = name.includes(search) && (filter === 'ALL' || status === filter);
            row.hidden = !visible;
            if (visible) visibleCount++;
        });
        if (noResults) noResults.hidden = visibleCount > 0;
    }
    searchInput.addEventListener('input', filterStock);
    filterSelect.addEventListener('change', filterStock);
})();
</script>
</body>
</html>
