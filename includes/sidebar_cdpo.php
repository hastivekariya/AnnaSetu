
<?php
$role = strtolower($_SESSION['role'] ?? 'helper');
$fullName = $_SESSION['full_name'] ?? 'Test helper';
$currentPage = basename($_SERVER['PHP_SELF']);
$language = $_SESSION['language'] ?? 'en';

$isGujarati = ($language === 'gu');

$translations = [
    'Dashboard' => 'ડેશબોર્ડ',
    'Daily Consumption' => 'દૈનિક વપરાશ',
    'Beneficiary Distribution' => 'લાભાર્થી વિતરણ',
    'Current Stock' => 'વર્તમાન સ્ટોક',
    'My Profile' => 'મારી પ્રોફાઇલ',
    'Project Overview' => 'પ્રોજેક્ટની માહિતી',
    'Anganwadi Centres' => 'આંગણવાડી કેન્દ્રો',
    'Stock Overview' => 'સ્ટોકની માહિતી',
    'Shortage and Expiry Alerts' => 'અછત અને સમાપ્તિ ચેતવણીઓ',
    'Reports and Analytics' => 'અહેવાલો અને વિશ્લેષણ',
    'Export Reports' => 'અહેવાલો એક્સપોર્ટ કરો',
    'Logout' => 'લૉગઆઉટ',
    'Worker' => 'કાર્યકર',
    'Helper' => 'સહાયક',
    'Supervisor' => 'સુપરવાઇઝર',
    'CDPO' => 'સીડીપીઓ'
];

function sidebarText($text, $translations, $isGujarati)
{
    return $isGujarati && isset($translations[$text])
        ? $translations[$text]
        : $text;
}

/*
|--------------------------------------------------------------------------
| Role-based menu
|--------------------------------------------------------------------------
*/
$roleMenus = [
    'worker' => [
        ['Dashboard', 'dashboard.php'],
        ['Daily Consumption', 'daily-consumption.php'],
        ['Beneficiary Distribution', 'beneficiary-distribution.php'],
        ['Current Stock', 'current-stock.php'],
        ['My Profile', 'profile.php']
    ],

    'helper' => [
        ['Dashboard', 'dashboard.php'],
        ['Daily Consumption', 'daily-consumption.php'],
        ['Beneficiary Distribution', 'beneficiary-distribution.php'],
        ['Current Stock', 'current-stock.php'],
        ['My Profile', 'profile.php']
    ],

    'supervisor' => [
        ['Dashboard', 'dashboard.php'],
        ['Daily Consumption', 'daily-consumption.php'],
        ['Beneficiary Distribution', 'beneficiary-distribution.php'],
        ['Current Stock', 'current-stock.php'],
        ['My Profile', 'profile.php']
    ],

    'cdpo' => [
        ['Dashboard', 'dashboard.php'],
        ['Project Overview', 'projects.php'],
        ['Anganwadi Centres', 'centres.php'],
        ['Stock Overview', 'stock-overview.php'],
        ['Shortage and Expiry Alerts', 'alerts.php'],
        ['Reports and Analytics', 'reports.php'],
        ['Export Reports', 'export-reports.php'],
        ['My Profile', 'profile.php']
    ]
];

$menuItems = $roleMenus[$role] ?? $roleMenus['helper'];

$roleLabels = [
    'worker' => 'Worker',
    'helper' => 'Helper',
    'supervisor' => 'Supervisor',
    'cdpo' => 'CDPO'
];

$roleLabel = $roleLabels[$role] ?? ucfirst($role);
?>

<aside class="sidebar">

    <!-- AnnaSetu branding -->
    <div class="sidebar-brand">
        <h3>અન્નસેતુ</h3>
        <p>AnnaSetu</p>
    </div>

    <!-- Logged-in user -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <?= htmlspecialchars(
                strtoupper(substr($fullName, 0, 1)),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

        <div>
            <strong>
                <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
            </strong>

            <small>
                <?= htmlspecialchars(
                    sidebarText($roleLabel, $translations, $isGujarati),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </small>
        </div>
    </div>

    <!-- Role-based navigation -->
    <nav class="sidebar-menu">
        <?php foreach ($menuItems as $item): ?>
            <?php
            $label = $item[0];
            $file = $item[1];
            ?>

            <a
                href="<?= htmlspecialchars(
                    APP_URL . '/' . $role . '/' . $file,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                class="menu-link <?= $currentPage === $file ? 'active' : '' ?>"
            >
                <?= htmlspecialchars(
                    sidebarText($label, $translations, $isGujarati),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Logout -->
    <a
        href="<?= htmlspecialchars(
            APP_URL . '/logout.php',
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
        class="logout-link"
    >
        <?= htmlspecialchars(
            sidebarText('Logout', $translations, $isGujarati),
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </a>

</aside>
