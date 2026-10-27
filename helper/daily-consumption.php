<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

$role = strtolower($_SESSION['role'] ?? 'helper');
$fullName = $_SESSION['full_name'] ?? 'User';
$language = $_SESSION['language'] ?? 'gu';


/* Page translations: keep this page bilingual even if a key is missing
   from the application's main translation dictionary. */
function consumptionText($key)
{
    $language = $_SESSION['language'] ?? 'gu';

    $translations = [
        'en' => [
            'daily_consumption' => 'Daily Consumption',
            'daily_consumption_desc' => 'Record daily food consumption and monitor remaining stock.',
            'record_consumption' => 'Record Consumption',
            'food_item' => 'Food Item',
            'select_food_item' => 'Select food item',
            'consumption_date' => 'Consumption Date',
            'quantity_consumed' => 'Quantity Consumed',
            'beneficiary_type' => 'Beneficiary Type',
            'select_beneficiary' => 'Select beneficiary type',
            'child' => 'Child',
            'pregnant_woman' => 'Pregnant Woman',
            'lactating_mother' => 'Lactating Mother',
            'other' => 'Other',
            'notes_optional' => 'Notes (Optional)',
            'available_stock' => 'Available Stock:',
            'save_consumption' => 'Save Consumption',
            'recent_history' => 'Recent Consumption History',
            'date' => 'Date',
            'quantity' => 'Quantity',
            'status' => 'Status',
            'no_records' => 'No consumption records found.',
            'enter_quantity' => 'Enter a quantity greater than zero.',
            'exceeds_stock' => 'Consumption exceeds available stock.',
            'role_worker' => 'Worker',
            'role_helper' => 'Helper',
            'role_supervisor' => 'Supervisor',
            'role_cdpo' => 'CDPO',
            'role_admin' => 'Admin',
            'approved' => 'Approved',
            'pending' => 'Pending',
            'rejected' => 'Rejected',
            'unknown' => 'Unknown'
        ],
        'gu' => [
            'daily_consumption' => 'દૈનિક વપરાશ',
            'daily_consumption_desc' => 'દૈનિક ખાદ્ય વપરાશની નોંધ કરો અને બાકી જથ્થો તપાસો.',
            'record_consumption' => 'વપરાશની નોંધ કરો',
            'food_item' => 'ખાદ્ય વસ્તુ',
            'select_food_item' => 'ખાદ્ય વસ્તુ પસંદ કરો',
            'consumption_date' => 'વપરાશની તારીખ',
            'quantity_consumed' => 'વપરાયેલ જથ્થો',
            'beneficiary_type' => 'લાભાર્થીનો પ્રકાર',
            'select_beneficiary' => 'લાભાર્થીનો પ્રકાર પસંદ કરો',
            'child' => 'બાળક',
            'pregnant_woman' => 'ગર્ભવતી મહિલા',
            'lactating_mother' => 'સ્તનપાન કરાવતી માતા',
            'other' => 'અન્ય',
            'notes_optional' => 'નોંધ (વૈકલ્પિક)',
            'available_stock' => 'ઉપલબ્ધ જથ્થો:',
            'save_consumption' => 'વપરાશ સાચવો',
            'recent_history' => 'તાજેતરના વપરાશનો ઇતિહાસ',
            'date' => 'તારીખ',
            'quantity' => 'જથ્થો',
            'status' => 'સ્થિતિ',
            'no_records' => 'વપરાશની કોઈ નોંધ મળી નથી.',
            'enter_quantity' => 'શૂન્ય કરતાં વધુ જથ્થો દાખલ કરો.',
            'exceeds_stock' => 'વપરાશનો જથ્થો ઉપલબ્ધ જથ્થા કરતાં વધુ છે.',
            'enter_quantity' => 'શૂન્ય કરતાં વધુ જથ્થો દાખલ કરો.',
            'exceeds_stock' => 'વપરાશનો જથ્થો ઉપલબ્ધ જથ્થા કરતાં વધુ છે.',
            'role_worker' => 'કાર્યકર',
            'role_helper' => 'સહાયક',
            'role_supervisor' => 'સુપરવાઇઝર',
            'role_cdpo' => 'CDPO',
            'role_admin' => 'એડમિન',
            'approved' => 'મંજૂર',
            'pending' => 'બાકી',
            'rejected' => 'નામંજૂર',
            'unknown' => 'અજ્ઞાત'
        ]
    ];

    return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
}

function consumptionEscape($key)
{
    return htmlspecialchars(consumptionText($key), ENT_QUOTES, 'UTF-8');
}

function consumptionBeneficiary($value)
{
    $value = strtoupper((string) $value);
    $keys = [
        'CHILD' => 'child',
        'PREGNANT_WOMAN' => 'pregnant_woman',
        'LACTATING_MOTHER' => 'lactating_mother',
        'OTHER' => 'other'
    ];
    return consumptionText($keys[$value] ?? 'unknown');
}

function consumptionStatus($value)
{
    $value = strtolower((string) $value);
    $language = $_SESSION['language'] ?? 'gu';
    $status = [
        'approved' => ['en' => 'Approved', 'gu' => 'મંજૂર'],
        'pending' => ['en' => 'Pending', 'gu' => 'બાકી'],
        'rejected' => ['en' => 'Rejected', 'gu' => 'નામંજૂર']
    ];
    return $status[$value][$language] ?? ($status[$value]['en'] ?? consumptionText('unknown'));
}

/* -------------------------------------------------
   1. Check login and retrieve assigned centre
------------------------------------------------- */

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    "SELECT centre_id FROM users WHERE user_id = ?"
);
$stmt->execute([$userId]);

$centreId = (int) $stmt->fetchColumn();

if ($centreId <= 0) {
    exit('No Anganwadi centre is assigned to this account.');
}

$_SESSION['centre_id'] = $centreId;

/* -------------------------------------------------
   2. CSRF token and flash messages
------------------------------------------------- */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['success_message'] ?? '';
$error = $_SESSION['error_message'] ?? '';

unset(
    $_SESSION['success_message'],
    $_SESSION['error_message']
);

/* -------------------------------------------------
   3. Load food items, stock and consumption history
------------------------------------------------- */

try {
    $sql = "
        SELECT
            f.item_id AS id,
            f.item_name_en,
            f.item_name_gu,
            f.unit,
            COALESCE(
                SUM(
                    CASE
                        WHEN st.transaction_type = 'RECEIPT'
                             AND st.status = 'APPROVED'
                            THEN st.quantity

                        WHEN st.transaction_type IN (
                            'CONSUMPTION',
                            'DISTRIBUTION'
                        )
                             AND st.status IN (
                                 'APPROVED',
                                 'PENDING'
                             )
                            THEN -st.quantity

                        WHEN st.transaction_type = 'ADJUSTMENT'
                             AND st.status = 'APPROVED'
                            THEN st.quantity

                        ELSE 0
                    END
                ),
                0
            ) AS available_stock
        FROM food_items f
        LEFT JOIN stock_batches b
            ON b.item_id = f.item_id
           AND b.centre_id = ?
        LEFT JOIN stock_transactions st
            ON st.batch_id = b.batch_id
           AND st.centre_id = b.centre_id
           AND st.item_id = f.item_id
        WHERE f.is_active = 1
        GROUP BY
            f.item_id,
            f.item_name_en,
            f.item_name_gu,
            f.unit
        ORDER BY f.item_name_en
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$centreId]);
    $items = $stmt->fetchAll();

    /* Recent consumption history */
    $sql = "
        SELECT
            f.item_name_en,
            f.item_name_gu,
            f.unit,
            st.quantity,
            st.beneficiary_type,
            st.transaction_date,
            st.status,
            st.notes
        FROM stock_transactions st
        JOIN food_items f
            ON f.item_id = st.item_id
        WHERE st.centre_id = ?
          AND st.transaction_type = 'CONSUMPTION'
        ORDER BY st.transaction_id DESC
        LIMIT 20
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$centreId]);
    $history = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log(
        'Daily consumption page: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Unable to load consumption data. ' .
        'Please check the database configuration.'
    );
}
?>

<!DOCTYPE html>
<html lang="<?= htmlspecialchars(
    $language,
    ENT_QUOTES,
    'UTF-8'
) ?>">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= consumptionEscape('daily_consumption') ?>
        | <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= APP_URL ?>/assets/css/dashboard.css?v=2"
    >

    <style>
        .consumption-page {
            padding: 24px 28px;
            color: #123f35;
            min-width: 0;
        }

        .consumption-page .page-heading {
            margin-bottom: 24px;
        }

        .consumption-page h2 {
            margin-top: 0;
            font-size: 30px;
        }

        .consumption-page .subtitle {
            color: #64748b;
            margin-bottom: 0;
        }

        .consumption-page .panel {
            background: #fff;
            padding: 24px;
            border: 1px solid #e3eaf0;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(15, 50, 40, .04);
        }

        .consumption-page .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .consumption-page .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .consumption-page label {
            font-weight: 600;
        }

        .consumption-page input,
        .consumption-page select,
        .consumption-page textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
        }

        .consumption-page .stock-box {
            margin: 20px 0;
            padding: 14px;
            border-radius: 8px;
            background: #f0f8f4;
        }

        .consumption-page .primary-btn {
            background: #236c55;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 13px 20px;
            cursor: pointer;
            font-weight: 600;
        }

        .consumption-page .primary-btn:hover {
            background: #174f3e;
        }

        .consumption-page .notice {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 8px;
        }

        .consumption-page .success {
            background: #e7f7ec;
            color: #17603b;
        }

        .consumption-page .error {
            background: #fff0f0;
            color: #a52828;
        }

        .consumption-page .table-wrap {
            overflow-x: auto;
        }

        .consumption-page table {
            width: 100%;
            border-collapse: collapse;
        }

        .consumption-page th,
        .consumption-page td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .consumption-page th {
            background: #f5f9f7;
        }

        @media (max-width: 700px) {
            .consumption-page {
                padding: 16px;
            }

            .consumption-page .form-grid {
                grid-template-columns: 1fr;
            }

            .consumption-page .field.full-width {
                grid-column: auto !important;
            }
        }
    </style>
</head>

<body>

<div class="dashboard-layout">

    <!-- Shared sidebar -->
    <?php
    require_once dirname(__DIR__) . '/includes/sidebar.php';
    ?>

    <!-- Main content -->
    <main class="dashboard-main">

        <!-- Shared header with the single language selector -->
        <?php
        require_once dirname(__DIR__) . '/includes/page-header.php';
        ?>

        <div class="consumption-page">

            <div class="page-heading">
                <div>
                    <h2>
                        <?= consumptionEscape('daily_consumption') ?>
                    </h2>

                    <p class="subtitle">
                        <?= consumptionEscape('daily_consumption_desc') ?>
                    </p>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="notice success">
                    <?= htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="notice error">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <!-- Record Consumption -->
            <section class="panel">

                <h3>
                    <?= consumptionEscape('record_consumption') ?>
                </h3>

                <form
                    action="<?= APP_URL ?>/actions/save-consumption.php"
                    method="POST"
                    id="consumption-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $_SESSION['csrf_token'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                    <div class="form-grid">

                        <div class="field">
                            <label for="food_item_id">
                                <?= consumptionEscape('food_item') ?>
                            </label>

                            <select
                                name="food_item_id"
                                id="food_item_id"
                                required
                            >
                                <option value="">
                                    <?= consumptionEscape('select_food_item') ?>
                                </option>

                                <?php foreach ($items as $item): ?>
                                    <option
                                        value="<?= (int) $item['id'] ?>"
                                        data-stock="<?= htmlspecialchars(
                                            (string) $item['available_stock'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                        data-unit="<?= htmlspecialchars(
                                            $item['unit'] ?? '',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            ($language === 'gu' && !empty($item['item_name_gu']))
                                                ? $item['item_name_gu']
                                                : $item['item_name_en'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="consumption_date">
                                <?= consumptionEscape('consumption_date') ?>
                            </label>

                            <input
                                type="date"
                                name="consumption_date"
                                id="consumption_date"
                                value="<?= date('Y-m-d') ?>"
                                max="<?= date('Y-m-d') ?>"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="quantity">
                                <?= consumptionEscape('quantity_consumed') ?>
                            </label>

                            <input
                                type="number"
                                name="quantity"
                                id="quantity"
                                min="0.001"
                                step="0.001"
                                required
                            >
                        </div>

                        <div class="field">
                            <label for="beneficiary_type">
                                <?= consumptionEscape('beneficiary_type') ?>
                            </label>

                            <select
                                name="beneficiary_type"
                                id="beneficiary_type"
                                required
                            >
                                <option value="">
                                    <?= consumptionEscape('select_beneficiary') ?>
                                </option>

                                <option value="CHILD">
                                    <?= consumptionEscape('child') ?>
                                </option>

                                <option value="PREGNANT_WOMAN">
                                    <?= consumptionEscape('pregnant_woman') ?>
                                </option>

                                <option value="LACTATING_MOTHER">
                                    <?= consumptionEscape('lactating_mother') ?>
                                </option>

                                <option value="OTHER">
                                    <?= consumptionEscape('other') ?>
                                </option>
                            </select>
                        </div>

                        <div
                            class="field full-width"
                            style="grid-column: 1 / -1"
                        >
                            <label for="notes">
                                <?= consumptionEscape('notes_optional') ?>
                            </label>

                            <textarea
                                name="notes"
                                id="notes"
                                rows="3"
                                maxlength="500"
                            ></textarea>
                        </div>

                    </div>

                    <div class="stock-box" aria-live="polite">
                        <strong><?= consumptionEscape('available_stock') ?></strong>

                        <span id="available-stock">—</span>

                        <span id="stock-unit"></span>
                    </div>

                    <button
                        type="submit"
                        class="primary-btn"
                        id="save-consumption"
                    >
                        <?= consumptionEscape('save_consumption') ?>
                    </button>

                </form>
            </section>

            <!-- Recent Consumption History -->
            <section class="panel">

                <h3><?= consumptionEscape('recent_history') ?></h3>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th><?= consumptionEscape('date') ?></th>
                                <th><?= consumptionEscape('food_item') ?></th>
                                <th><?= consumptionEscape('quantity') ?></th>
                                <th><?= consumptionEscape('beneficiary_type') ?></th>
                                <th><?= consumptionEscape('status') ?></th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr>
                                    <td colspan="5">
                                        <?= consumptionEscape('no_records') ?>
                                    </td>
                                </tr>
                            <?php else: ?>

                                <?php foreach ($history as $record): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars(
                                                $record['transaction_date'] ?? '',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (($language === 'gu' && !empty($record['item_name_gu'])) ? $record['item_name_gu'] : ($record['item_name_en'] ?? '')),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(
                                                (string) $record['quantity'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>

                                            <?= htmlspecialchars(
                                                $record['unit'] ?? '',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(consumptionBeneficiary($record['beneficiary_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars(consumptionStatus($record['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div><!-- consumption-page -->

    </main><!-- dashboard-main -->

</div><!-- dashboard-layout -->

<script>
(function () {
    const foodSelect = document.getElementById('food_item_id');
    const quantityInput = document.getElementById('quantity');
    const stockDisplay = document.getElementById('available-stock');
    const unitDisplay = document.getElementById('stock-unit');
    const form = document.getElementById('consumption-form');

    if (
        !foodSelect ||
        !quantityInput ||
        !stockDisplay ||
        !unitDisplay ||
        !form
    ) {
        return;
    }

    function updateStock() {
        const option = foodSelect.options[foodSelect.selectedIndex];

        if (!option || !option.value) {
            stockDisplay.textContent = '—';
            unitDisplay.textContent = '';
            quantityInput.removeAttribute('max');
            quantityInput.setCustomValidity('');
            return;
        }

        const stock = Number(option.dataset.stock || 0);

        stockDisplay.textContent = stock.toLocaleString(
            undefined,
            { maximumFractionDigits: 3 }
        );

        unitDisplay.textContent = option.dataset.unit || '';
        quantityInput.max = String(Math.max(0, stock));

        validateQuantity();
    }

    function validateQuantity() {
        const option = foodSelect.options[foodSelect.selectedIndex];
        const stock = Number(option?.dataset.stock || 0);
        const quantity = Number(quantityInput.value);

        if (
            quantityInput.value !== '' &&
            quantity <= 0
        ) {
            quantityInput.setCustomValidity(
                <?= json_encode(consumptionText('enter_quantity'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            );
        } else if (
            quantityInput.value !== '' &&
            quantity > stock
        ) {
            quantityInput.setCustomValidity(
                <?= json_encode(consumptionText('exceeds_stock'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
            );
        } else {
            quantityInput.setCustomValidity('');
        }
    }

    foodSelect.addEventListener('change', updateStock);

    quantityInput.addEventListener('input', validateQuantity);

    form.addEventListener('submit', function (event) {
        updateStock();
        validateQuantity();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    updateStock();
})();
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>

</body>
</html>