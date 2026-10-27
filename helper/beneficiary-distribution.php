<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (empty($_SESSION['user_id']) || empty($_SESSION['centre_id'])) {
    header('Location: ../login.php');
    exit;
}

$centreId = (int) $_SESSION['centre_id'];
$fullName = $_SESSION['full_name'] ?? 'User';

$language = (($_SESSION['language'] ?? 'gu') === 'en') ? 'en' : 'gu';

function distributionText($key)
{
    $language = (($_SESSION['language'] ?? 'gu') === 'en') ? 'en' : 'gu';
    $text = [
        'en' => [
            'no_centre'=>'No Anganwadi centre is assigned to this account.','page_title'=>'Beneficiary Distribution','page_description'=>'Record food distribution and track beneficiary-wise distribution history.',
            'record_distribution'=>'Record Distribution','food_item'=>'Food Item','select_food_item'=>'Select food item',
            'available'=>'Available','select_item_hint'=>'Select a food item to view available stock.',
            'quantity_to_distribute'=>'Quantity to Distribute','enter_quantity'=>'Enter quantity','distribution_date'=>'Distribution Date',
            'beneficiary_type'=>'Beneficiary Type','select_beneficiary'=>'Select beneficiary','child'=>'Child',
            'pregnant_woman'=>'Pregnant Woman','lactating_mother'=>'Lactating Mother','other'=>'Other',
            'beneficiary_count'=>'Number of Beneficiaries','example_25'=>'e.g. 25','notes_optional'=>'Notes (Optional)',
            'enter_details'=>'Enter distribution details','submit_distribution'=>'Submit Distribution','distribution_history'=>'Distribution History',
            'date'=>'Date','quantity'=>'Quantity','beneficiary'=>'Beneficiary','people'=>'People','status'=>'Status','notes'=>'Notes',
            'no_records'=>'No distribution records found.','available_stock'=>'Available stock',
            'quantity_positive'=>'Quantity must be greater than zero.','quantity_exceeds'=>'Quantity exceeds available stock.',
            'database_error'=>'Unable to load distribution data. Please check the database.',
            'status_pending'=>'Pending','status_approved'=>'Approved','status_rejected'=>'Rejected','status_other'=>'Unknown'
        ],
        'gu' => [
            'no_centre'=>'આ એકાઉન્ટ સાથે કોઈ આંગણવાડી કેન્દ્ર જોડાયેલું નથી.','page_title'=>'લાભાર્થી વિતરણ','page_description'=>'ખાદ્ય વિતરણની નોંધ કરો અને લાભાર્થી મુજબનો ઇતિહાસ જુઓ.',
            'record_distribution'=>'વિતરણની નોંધ કરો','food_item'=>'ખાદ્ય વસ્તુ','select_food_item'=>'ખાદ્ય વસ્તુ પસંદ કરો',
            'available'=>'ઉપલબ્ધ','select_item_hint'=>'ઉપલબ્ધ જથ્થો જોવા માટે ખાદ્ય વસ્તુ પસંદ કરો.',
            'quantity_to_distribute'=>'વિતરણ કરવાનો જથ્થો','enter_quantity'=>'જથ્થો દાખલ કરો','distribution_date'=>'વિતરણની તારીખ',
            'beneficiary_type'=>'લાભાર્થીનો પ્રકાર','select_beneficiary'=>'લાભાર્થી પસંદ કરો','child'=>'બાળક',
            'pregnant_woman'=>'ગર્ભવતી મહિલા','lactating_mother'=>'સ્તનપાન કરાવતી માતા','other'=>'અન્ય',
            'beneficiary_count'=>'લાભાર્થીઓની સંખ્યા','example_25'=>'દા.ત. 25','notes_optional'=>'નોંધ (વૈકલ્પિક)',
            'enter_details'=>'વિતરણની વિગતો દાખલ કરો','submit_distribution'=>'વિતરણ સબમિટ કરો','distribution_history'=>'વિતરણનો ઇતિહાસ',
            'date'=>'તારીખ','quantity'=>'જથ્થો','beneficiary'=>'લાભાર્થી','people'=>'લોકો','status'=>'સ્થિતિ','notes'=>'નોંધ',
            'no_records'=>'વિતરણની કોઈ નોંધ મળી નથી.','available_stock'=>'ઉપલબ્ધ જથ્થો',
            'quantity_positive'=>'જથ્થો શૂન્ય કરતાં વધુ હોવો જોઈએ.','quantity_exceeds'=>'જથ્થો ઉપલબ્ધ જથ્થા કરતાં વધારે છે.',
            'database_error'=>'વિતરણની માહિતી લોડ થઈ શકી નથી. કૃપા કરીને ડેટાબેઝ તપાસો.',
            'status_pending'=>'બાકી','status_approved'=>'મંજૂર','status_rejected'=>'નામંજૂર','status_other'=>'અજ્ઞાત'
        ]
    ];
    return $text[$language][$key] ?? $text['en'][$key] ?? $key;
}
function distributionEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function distributionFoodName($row, $language) {
    return ($language === 'gu' && !empty($row['item_name_gu']))
        ? $row['item_name_gu'] : ($row['item_name_en'] ?? '');
}
function distributionBeneficiaryLabel($value) {
    $keys = ['CHILD'=>'child','PREGNANT_WOMAN'=>'pregnant_woman','LACTATING_MOTHER'=>'lactating_mother','OTHER'=>'other'];
    return distributionText($keys[$value] ?? 'other');
}
function distributionStatusLabel($status) {
    $key = 'status_' . strtolower((string)$status);
    return distributionText(in_array($key, ['status_pending','status_approved','status_rejected'], true) ? $key : 'status_other');
}


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errorMessage = $_SESSION['error_message'] ?? '';
$successMessage = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message'], $_SESSION['success_message']);

try {
    // Show active food items and their available stock at this centre.
    $stockStmt = $pdo->prepare("
        SELECT
            f.item_id,
            f.item_name_en,
            f.item_name_gu,
            f.unit,
            COALESCE(SUM(
                CASE
                    WHEN st.transaction_type = 'RECEIPT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity

                    WHEN st.transaction_type IN ('CONSUMPTION', 'DISTRIBUTION')
                         AND st.status IN ('APPROVED', 'PENDING')
                        THEN -st.quantity

                    WHEN st.transaction_type = 'ADJUSTMENT'
                         AND st.status = 'APPROVED'
                        THEN st.quantity

                    ELSE 0
                END
            ), 0) AS available_stock
        FROM food_items f
        LEFT JOIN stock_batches b
            ON b.item_id = f.item_id
           AND b.centre_id = ?
        LEFT JOIN stock_transactions st
            ON st.batch_id = b.batch_id
           AND st.centre_id = b.centre_id
           AND st.item_id = f.item_id
        WHERE f.is_active = 1
        GROUP BY f.item_id, f.item_name_en, f.item_name_gu, f.unit
        ORDER BY f.item_name_en
    ");
    $stockStmt->execute([$centreId]);
    $foodItems = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

    // Distribution history for this centre.
    $historyStmt = $pdo->prepare("
        SELECT
            st.transaction_id,
            f.item_name_en,
            f.item_name_gu,
            f.unit,
            st.quantity,
            st.beneficiary_type,
            st.beneficiary_count,
            st.transaction_date,
            st.status,
            st.notes
        FROM stock_transactions st
        INNER JOIN food_items f ON f.item_id = st.item_id
        WHERE st.centre_id = ?
          AND st.transaction_type = 'DISTRIBUTION'
        ORDER BY st.transaction_date DESC, st.transaction_id DESC
        LIMIT 100
    ");
    $historyStmt->execute([$centreId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('Beneficiary distribution page error: ' . $e->getMessage());
    $foodItems = [];
    $history = [];
    $errorMessage = distributionText('database_error');
}

$beneficiaryLabels = [
    'CHILD' => 'child',
    'PREGNANT_WOMAN' => 'pregnant_woman',
    'LACTATING_MOTHER' => 'lactating_mother',
    'OTHER' => 'other'
];
?>
<!DOCTYPE html>
<html lang="<?= distributionEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= distributionEscape(distributionText('page_title')) ?> | <?= distributionEscape(defined('APP_NAME') ? APP_NAME : 'AnnaSetu') ?></title>

    <link rel="stylesheet" href="<?= distributionEscape(APP_URL) ?>/assets/css/dashboard.css?v=2">

    <style>
        .distribution-content {
            padding: 24px;
        }

        .distribution-title {
            margin-bottom: 6px;
            color: var(--text-primary, #1f2937);
        }

        .distribution-subtitle {
            margin: 0 0 24px;
            color: var(--text-secondary, #6b7280);
        }

        .distribution-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 24px;
        }

        .distribution-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            min-width: 0;
        }

        .distribution-card h2 {
            margin: 0 0 20px;
            font-size: 19px;
        }

        .distribution-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .distribution-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .distribution-field.full-width {
            grid-column: 1 / -1;
        }

        .distribution-field label {
            font-weight: 600;
            font-size: 14px;
        }

        .distribution-field input,
        .distribution-field select,
        .distribution-field textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--border-color, #d1d5db);
            border-radius: 8px;
            padding: 11px 12px;
            font: inherit;
            background: var(--input-bg, #fff);
            color: var(--text-primary, #111827);
        }

        .distribution-field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .stock-hint {
            font-size: 13px;
            color: var(--text-secondary, #6b7280);
        }

        .distribution-submit {
            border: 0;
            border-radius: 8px;
            padding: 12px 20px;
            background: #16804a;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .distribution-submit:hover {
            background: #11683b;
        }

        .distribution-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .distribution-alert {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .distribution-alert.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .distribution-alert.success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .distribution-table-wrap {
            overflow-x: auto;
        }

        .distribution-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .distribution-table th,
        .distribution-table td {
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            font-size: 14px;
        }

        .distribution-table th {
            background: var(--table-header-bg, #f8fafc);
            font-weight: 600;
        }

        .distribution-status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .distribution-status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .distribution-status.approved {
            background: #dcfce7;
            color: #166534;
        }

        .distribution-status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .distribution-empty {
            padding: 28px 12px;
            text-align: center;
            color: var(--text-secondary, #6b7280);
        }

        @media (max-width: 700px) {
            .distribution-content {
                padding: 16px;
            }

            .distribution-card {
                padding: 17px;
            }

            .distribution-form-grid {
                grid-template-columns: 1fr;
            }

            .distribution-field.full-width {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-layout">

    <?php require __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="dashboard-main">
    <?php require __DIR__ . '/../includes/page-header.php'; ?>

    <main class="distribution-content">
        <h1 class="distribution-title"><?= distributionEscape(distributionText('page_title')) ?></h1>
        <p class="distribution-subtitle">
            <?= distributionEscape(distributionText('page_description')) ?>
        </p>

        <?php if ($errorMessage !== ''): ?>
            <div class="distribution-alert error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage !== ''): ?>
            <div class="distribution-alert success" role="status">
                <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="distribution-grid">
            <section class="distribution-card">
                <h2><?= distributionEscape(distributionText('record_distribution')) ?></h2>

                <form
                    action="../actions/save-distribution.php"
                    method="POST"
                    id="distribution-form"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <div class="distribution-form-grid">
                        <div class="distribution-field full-width">
                            <label for="food_item_id"><?= distributionEscape(distributionText('food_item')) ?> *</label>

                            <select id="food_item_id" name="food_item_id" required>
                                <option value=""><?= distributionEscape(distributionText('select_food_item')) ?></option>

                                <?php foreach ($foodItems as $food): ?>
                                    <?php $available = max(0, (float)$food['available_stock']); ?>
                                    <option
                                        value="<?= (int)$food['item_id'] ?>"
                                        data-stock="<?= htmlspecialchars((string)$available, ENT_QUOTES, 'UTF-8') ?>"
                                        data-unit="<?= htmlspecialchars($food['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $available <= 0 ? 'disabled' : '' ?>
                                    >
                                        <?= distributionEscape(distributionFoodName($food, $language)) ?>
                                        — <?= distributionEscape(distributionText('available')) ?>:
                                        <?= number_format($available, 3) ?>
                                        <?= htmlspecialchars($food['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <span id="available-stock" class="stock-hint">
                                <?= distributionEscape(distributionText('select_item_hint')) ?>
                            </span>
                        </div>

                        <div class="distribution-field">
                            <label for="quantity"><?= distributionEscape(distributionText('quantity_to_distribute')) ?> *</label>
                            <input
                                type="number"
                                id="quantity"
                                name="quantity"
                                min="0.001"
                                step="0.001"
                                required
                                placeholder="<?= distributionEscape(distributionText('enter_quantity')) ?>"
                            >
                        </div>

                        <div class="distribution-field">
                            <label for="distribution_date"><?= distributionEscape(distributionText('distribution_date')) ?> *</label>
                            <input
                                type="date"
                                id="distribution_date"
                                name="distribution_date"
                                value="<?= date('Y-m-d') ?>"
                                max="<?= date('Y-m-d') ?>"
                                required
                            >
                        </div>

                        <div class="distribution-field">
                            <label for="beneficiary_type"><?= distributionEscape(distributionText('beneficiary_type')) ?> *</label>
                            <select
                                id="beneficiary_type"
                                name="beneficiary_type"
                                required
                            >
                                <option value=""><?= distributionEscape(distributionText('select_beneficiary')) ?></option>
                                <?php foreach ($beneficiaryLabels as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= distributionEscape(distributionText($label)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="distribution-field">
                            <label for="beneficiary_count"><?= distributionEscape(distributionText('beneficiary_count')) ?> *</label>
                            <input
                                type="number"
                                id="beneficiary_count"
                                name="beneficiary_count"
                                min="1"
                                step="1"
                                required
                                placeholder="<?= distributionEscape(distributionText('example_25')) ?>"
                            >
                        </div>

                        <div class="distribution-field full-width">
                            <label for="notes"><?= distributionEscape(distributionText('notes_optional')) ?></label>
                            <textarea
                                id="notes"
                                name="notes"
                                maxlength="500"
                                placeholder="<?= distributionEscape(distributionText('enter_details')) ?>"
                            ></textarea>
                        </div>

                        <div class="distribution-field full-width">
                            <button
                                type="submit"
                                class="distribution-submit"
                                id="distribution-submit"
                            >
                                <?= distributionEscape(distributionText('submit_distribution')) ?>
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="distribution-card">
                <h2><?= distributionEscape(distributionText('distribution_history')) ?></h2>

                <div class="distribution-table-wrap">
                    <table class="distribution-table">
                        <thead>
                            <tr>
                                <th><?= distributionEscape(distributionText('date')) ?></th>
                                <th><?= distributionEscape(distributionText('food_item')) ?></th>
                                <th><?= distributionEscape(distributionText('quantity')) ?></th>
                                <th><?= distributionEscape(distributionText('beneficiary')) ?></th>
                                <th><?= distributionEscape(distributionText('people')) ?></th>
                                <th><?= distributionEscape(distributionText('status')) ?></th>
                                <th><?= distributionEscape(distributionText('notes')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$history): ?>
                                <tr>
                                    <td colspan="7" class="distribution-empty">
                                        <?= distributionEscape(distributionText('no_records')) ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history as $record): ?>
                                    <?php
                                    $status = strtoupper($record['status'] ?? 'PENDING');
                                    $statusClass = strtolower($status);
                                    ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars(
                                                date('d-m-Y', strtotime($record['transaction_date'])),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </td>
                                        <td>
                                            <?= distributionEscape(distributionFoodName($record, $language)) ?>
                                        </td>
                                        <td>
                                            <?= number_format((float)$record['quantity'], 3) ?>
                                            <?= htmlspecialchars($record['unit'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td>
                                            <?= distributionEscape(distributionBeneficiaryLabel($record['beneficiary_type'] ?? 'OTHER')) ?>
                                        </td>
                                        <td>
                                            <?= $record['beneficiary_count'] === null
                                                ? '—'
                                                : (int)$record['beneficiary_count'] ?>
                                        </td>
                                        <td>
                                            <span class="distribution-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= distributionEscape(distributionStatusLabel($status)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($record['notes'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<script>
const distributionMessages = <?= json_encode([
    'selectItem' => distributionText('select_item_hint'),
    'availableStock' => distributionText('available_stock'),
    'quantityPositive' => distributionText('quantity_positive'),
    'quantityExceeds' => distributionText('quantity_exceeds')
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

(() => {
    const itemSelect = document.getElementById('food_item_id');
    const quantityInput = document.getElementById('quantity');
    const stockHint = document.getElementById('available-stock');
    const form = document.getElementById('distribution-form');

    function updateStock() {
        const option = itemSelect.options[itemSelect.selectedIndex];

        if (!option || !option.value) {
            quantityInput.removeAttribute('max');
            quantityInput.setCustomValidity('');
            stockHint.textContent = distributionMessages.selectItem;
            return;
        }

        const stock = Number(option.dataset.stock || 0);
        const unit = option.dataset.unit || '';

        quantityInput.max = stock;
        stockHint.textContent =
            distributionMessages.availableStock + ': ' + stock.toFixed(3) + ' ' + unit;

        validateQuantity();
    }

    function validateQuantity() {
        const option = itemSelect.options[itemSelect.selectedIndex];

        if (!option || !option.value || quantityInput.value === '') {
            quantityInput.setCustomValidity('');
            return;
        }

        const stock = Number(option.dataset.stock || 0);
        const quantity = Number(quantityInput.value);

        if (quantity <= 0) {
            quantityInput.setCustomValidity(distributionMessages.quantityPositive);
        } else if (quantity > stock) {
            quantityInput.setCustomValidity(distributionMessages.quantityExceeds);
        } else {
            quantityInput.setCustomValidity('');
        }
    }

    itemSelect.addEventListener('change', updateStock);
    quantityInput.addEventListener('input', validateQuantity);

    form.addEventListener('submit', event => {
        validateQuantity();

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        }
    });

    updateStock();
})();
</script>
</body>
</html>