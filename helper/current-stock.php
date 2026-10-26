<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['centre_id'])) {
    header('Location: ../login.php');
    exit;
}

$centreId = (int) $_SESSION['centre_id'];
$fullName = $_SESSION['full_name'] ?? 'User';

$foodItems = [];
$errorMessage = '';

try {
    /*
     * Calculate stock for this centre.
     *
     * APPROVED receipts add stock.
     * PENDING/APPROVED consumption and distribution deduct stock.
     * APPROVED adjustments affect stock by their signed quantity.
     * REJECTED transactions do not affect the balance.
     */
    $stmt = $pdo->prepare("
        SELECT
            f.item_id,
            f.item_name_en,
            f.unit,

            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'RECEIPT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity
                    ELSE 0
                END
            ), 0) AS total_received,

            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'CONSUMPTION'
                         AND st.status IN ('PENDING', 'APPROVED')
                        THEN st.quantity
                    ELSE 0
                END
            ), 0) AS total_consumed,

            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'DISTRIBUTION'
                         AND st.status IN ('PENDING', 'APPROVED')
                        THEN st.quantity
                    ELSE 0
                END
            ), 0) AS total_distributed,

            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'ADJUSTMENT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity
                    ELSE 0
                END
            ), 0) AS total_adjustments,

            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'RECEIPT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity

                    WHEN st.transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                         AND st.status IN ('PENDING', 'APPROVED')
                        THEN -st.quantity

                    WHEN st.transaction_type = 'ADJUSTMENT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity

                    ELSE 0
                END
            ), 0) AS available_stock

        FROM food_items f

        LEFT JOIN stock_transactions st
            ON st.item_id = f.item_id
           AND st.centre_id = ?

        WHERE f.is_active = 1

        GROUP BY
            f.item_id,
            f.item_name_en,
            f.unit

        ORDER BY f.item_name_en ASC
    ");

    $stmt->execute([$centreId]);
    $foodItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Current stock page error: ' . $e->getMessage());
    $errorMessage = 'Unable to load current stock. Please check the database and try again.';
}

$totalItems = count($foodItems);
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$totalAvailable = 0;

foreach ($foodItems as $food) {
    $available = (float) $food['available_stock'];
    $totalAvailable += $available;

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Current Stock | AnnaSetu</title>

    <link rel="stylesheet" href="../assets/css/dashboard.css">

    <style>
        .stock-content {
            padding: 24px;
            min-width: 0;
        }

        .stock-heading {
            margin: 0 0 6px;
            color: var(--text-primary, #183d32);
        }

        .stock-description {
            margin: 0 0 24px;
            color: var(--text-secondary, #6b7280);
        }

        .stock-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stock-stat {
            padding: 20px;
            border-radius: 12px;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e5e7eb);
            min-width: 0;
        }

        .stock-stat-label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary, #6b7280);
            margin-bottom: 10px;
        }

        .stock-stat-value {
            font-size: 27px;
            font-weight: 700;
            color: var(--text-primary, #183d32);
            overflow-wrap: anywhere;
        }

        .stock-card {
            padding: 22px;
            border-radius: 12px;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e5e7eb);
            min-width: 0;
        }

        .stock-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .stock-toolbar h2 {
            margin: 0;
            font-size: 19px;
        }

        .stock-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stock-filters input,
        .stock-filters select {
            padding: 10px 12px;
            border: 1px solid var(--border-color, #d1d5db);
            border-radius: 8px;
            background: var(--input-bg, #ffffff);
            color: var(--text-primary, #111827);
            font: inherit;
            max-width: 100%;
        }

        .stock-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .stock-table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        .stock-table th,
        .stock-table td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            font-size: 14px;
        }

        .stock-table th {
            background: var(--table-header-bg, #f8fafc);
            font-weight: 600;
            white-space: nowrap;
        }

        .stock-table tbody tr:hover {
            background: var(--table-hover-bg, #f8fafc);
        }

        .stock-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .stock-status.available {
            background: #dcfce7;
            color: #166534;
        }

        .stock-status.low {
            background: #fef3c7;
            color: #92400e;
        }

        .stock-status.empty {
            background: #fee2e2;
            color: #991b1b;
        }

        .stock-empty {
            padding: 28px 12px !important;
            text-align: center !important;
            color: var(--text-secondary, #6b7280);
        }

        .stock-alert {
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @media (max-width: 1000px) {
            .stock-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .stock-content {
                padding: 16px;
            }

            .stock-stat-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stock-stat {
                padding: 14px;
            }

            .stock-stat-value {
                font-size: 22px;
            }

            .stock-card {
                padding: 14px;
            }

            .stock-filters,
            .stock-filters input,
            .stock-filters select {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-layout">

    <?php require __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="dashboard-main">

        <?php require __DIR__ . '/../includes/page-header.php'; ?>

        <main class="stock-content">

            <h1 class="stock-heading">Current Stock</h1>
            <p class="stock-description">
                View food inventory and available quantities at your centre.
            </p>

            <?php if ($errorMessage !== ''): ?>
                <div class="stock-alert" role="alert">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <section class="stock-stat-grid">

                <div class="stock-stat">
                    <span class="stock-stat-label">Active Food Items</span>
                    <div class="stock-stat-value">
                        <?= number_format($totalItems) ?>
                    </div>
                </div>

                <div class="stock-stat">
                    <span class="stock-stat-label">Items In Stock</span>
                    <div class="stock-stat-value">
                        <?= number_format($inStockCount) ?>
                    </div>
                </div>

                <div class="stock-stat">
                    <span class="stock-stat-label">Low Stock Items</span>
                    <div class="stock-stat-value">
                        <?= number_format($lowStockCount) ?>
                    </div>
                </div>

                <div class="stock-stat">
                    <span class="stock-stat-label">Out of Stock</span>
                    <div class="stock-stat-value">
                        <?= number_format($outOfStockCount) ?>
                    </div>
                </div>

            </section>

            <section class="stock-card">

                <div class="stock-toolbar">
                    <h2>Food Inventory</h2>

                    <div class="stock-filters">
                        <input
                            type="search"
                            id="stock-search"
                            placeholder="Search food item..."
                            aria-label="Search food item"
                        >

                        <select id="stock-filter" aria-label="Filter stock status">
                            <option value="ALL">All Items</option>
                            <option value="AVAILABLE">In Stock</option>
                            <option value="LOW">Low Stock</option>
                            <option value="EMPTY">Out of Stock</option>
                        </select>
                    </div>
                </div>

                <div class="stock-table-wrap">
                    <table class="stock-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Food Item</th>
                                <th>Received</th>
                                <th>Consumed</th>
                                <th>Distributed</th>
                                <th>Adjustments</th>
                                <th>Available</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody id="stock-table-body">

                        <?php if (!$foodItems): ?>

                            <tr>
                                <td colspan="8" class="stock-empty">
                                    No active food items found.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($foodItems as $index => $food): ?>

                                <?php
                                $available = (float) $food['available_stock'];

                                if ($available <= 0) {
                                    $status = 'EMPTY';
                                    $statusLabel = 'Out of Stock';
                                    $statusClass = 'empty';
                                } elseif ($available <= 5) {
                                    $status = 'LOW';
                                    $statusLabel = 'Low Stock';
                                    $statusClass = 'low';
                                } else {
                                    $status = 'AVAILABLE';
                                    $statusLabel = 'In Stock';
                                    $statusClass = 'available';
                                }

                                $unit = $food['unit'] ?? '';
                                ?>

                                <tr
                                    data-name="<?= htmlspecialchars(
                                        strtolower($food['item_name_en']),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    data-status="<?= $status ?>"
                                >
                                    <td><?= $index + 1 ?></td>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                $food['item_name_en'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= number_format((float)$food['total_received'], 3) ?>
                                        <?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <?= number_format((float)$food['total_consumed'], 3) ?>
                                        <?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <?= number_format((float)$food['total_distributed'], 3) ?>
                                        <?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <?= number_format((float)$food['total_adjustments'], 3) ?>
                                        <?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= number_format($available, 3) ?>
                                            <?= htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span class="stock-status <?= $statusClass ?>">
                                            <?= $statusLabel ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endforeach; ?>

                            <tr id="stock-no-results" hidden>
                                <td colspan="8" class="stock-empty">
                                    No items match your search.
                                </td>
                            </tr>

                        <?php endif; ?>

                        </tbody>
                    </table>
                </div>

                <p class="stock-description" style="margin:18px 0 0;">
                    Low stock is defined as 5 units or less. Adjust this threshold
                    to match your centre's inventory rules. Quantities use the
                    food item's configured unit.
                </p>

            </section>

        </main>

    </div><!-- end dashboard-main -->

</div><!-- end dashboard-layout -->

<?php require __DIR__ . '/../includes/footer.php'; ?>

<script>
(() => {
    const searchInput = document.getElementById('stock-search');
    const filterSelect = document.getElementById('stock-filter');
    const tableBody = document.getElementById('stock-table-body');
    const noResults = document.getElementById('stock-no-results');

    if (!searchInput || !filterSelect || !tableBody) {
        return;
    }

    function filterStock() {
        const search = searchInput.value.trim().toLowerCase();
        const filter = filterSelect.value;
        const rows = tableBody.querySelectorAll('tr[data-name]');
        let visibleCount = 0;

        rows.forEach(row => {
            const name = row.dataset.name || '';
            const status = row.dataset.status || '';

            const matchesName = name.includes(search);
            const matchesStatus = filter === 'ALL' || status === filter;
            const visible = matchesName && matchesStatus;

            row.hidden = !visible;

            if (visible) {
                visibleCount++;
            }
        });

        if (noResults) {
            noResults.hidden = visibleCount > 0;
        }
    }

    searchInput.addEventListener('input', filterStock);
    filterSelect.addEventListener('change', filterStock);
})();
</script>

</body>
</html>