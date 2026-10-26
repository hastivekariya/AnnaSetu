
<?php
/*
 * supervisor/profile.php
 * supervisor profile page for AnnaSetu.
 */

require_once __DIR__ . '/../includes/auth.php';

requireRole(['supervisor']);

$role = strtolower($_SESSION['role'] ?? 'supervisor');
$userId = (int)($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'supervisor';
$language = $_SESSION['language'] ?? 'gu';

$profile = [];
$centreName = '';
$errors = [];
$success = '';

function pfEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pfText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

/* Fetch profile information */
try {
    $stmt = $pdo->prepare(
        "SELECT *
         FROM users
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute(['user_id' => $userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        $errors[] = pfText(
            'User profile not found.',
            'વપરાશકર્તાની પ્રોફાઇલ મળી નથી.'
        );
    } else {
        $fullName = $profile['full_name'] ?? $fullName;

        /*
         * Get the supervisor's centre name if users.centre_id exists
         * and contains a valid centre ID.
         */
        if (!empty($profile['centre_id'])) {
            $stmt = $pdo->prepare(
                "SELECT centre_name
                 FROM anganwadi_centres
                 WHERE centre_id = :centre_id
                 LIMIT 1"
            );

            $stmt->execute([
                'centre_id' => (int)$profile['centre_id']
            ]);

            $centreName = (string)($stmt->fetchColumn() ?: '');
        }

        /*
         * If users.centre_id is empty, try centre_assignments.
         * This assumes centre_assignments has an is_active column.
         */
        if ($centreName === '') {
            $stmt = $pdo->prepare(
                "SELECT c.centre_name
                 FROM anganwadi_centres c
                 INNER JOIN centre_assignments ca
                     ON ca.centre_id = c.centre_id
                 WHERE ca.user_id = :user_id
                   AND ca.is_active = 1
                   AND c.is_active = 1
                 ORDER BY c.centre_name
                 LIMIT 1"
            );

            $stmt->execute(['user_id' => $userId]);
            $centreName = (string)($stmt->fetchColumn() ?: '');
        }
    }

} catch (PDOException $e) {
    error_log('supervisor profile error: ' . $e->getMessage());

    $errors[] = pfText(
        'Unable to load your profile. Check your database columns.',
        'તમારી પ્રોફાઇલ લોડ થઈ શકી નથી. કૃપા કરીને ડેટાબેઝના કોલમ તપાસો.'
    );
}

/* Sidebar menu */
/* Supervisor Sidebar Menu */
$menuItems = [
    ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
    ['pending-verification.php', 'Pending Verification', 'ચકાસણી બાકી'],
    ['low-stock.php', 'Low Stock Alerts', 'ઓછા સ્ટોકની ચેતવણી'],
    ['expiry-monitoring.php', 'Expiry Monitoring', 'મુદતની દેખરેખ'],
    ['workers.php', 'Workers and Helpers', 'કાર્યકરો અને તેડાગરો'],
    ['centres.php', 'Anganwadi Centres', 'આંગણવાડી કેન્દ્રો'],
    ['reports.php', 'Reports and Analytics', 'અહેવાલો અને વિશ્લેષણ'],
    ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
];

/*
 * Use the actual fields returned by SELECT *.
 * Optional fields are checked before displaying them.
 */
$email = $profile['email'] ?? '';
$phone = $profile['phone'] ?? ($profile['mobile'] ?? '');
$roleName = $profile['role'] ?? $role;
$accountStatus = $profile['is_active'] ?? null;
$initial = strtoupper(substr(trim($fullName), 0, 1));

if ($initial === '') {
    $initial = 'W';
}
?>

<!DOCTYPE html>
<html lang="<?= pfEscape($language) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= pfEscape(pfText('My Profile', 'મારી પ્રોફાઇલ')) ?> | AnnaSetu
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

    <style>
        .profile-content {
            max-width: 1100px;
            margin: 0 auto;
        }

        .profile-banner {
            display: flex;
            align-items: center;
            gap: 22px;
            padding: 28px;
            margin: 22px 0;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        .profile-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #e7efff;
            color: #004aad;
            font-size: 38px;
            font-weight: 700;
        }

        .profile-banner h2 {
            margin: 0 0 8px;
            color: #183153;
            font-size: 25px;
        }

        .profile-banner p {
            margin: 5px 0;
            color: #68758a;
        }

        .profile-role {
            display: inline-block;
            padding: 6px 13px;
            border-radius: 20px;
            background: #e7f7ed;
            color: #197344;
            font-size: 13px;
            font-weight: 600;
        }

        .profile-card {
            padding: 24px;
            margin-bottom: 22px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        .profile-card h3 {
            margin: 0 0 20px;
            color: #183153;
            font-size: 19px;
            font-weight: 700;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .profile-detail {
            padding: 15px;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            min-width: 0;
        }

        .profile-detail small {
            display: block;
            margin-bottom: 8px;
            color: #788497;
            font-size: 13px;
        }

        .profile-detail strong {
            display: block;
            color: #25354b;
            font-size: 15px;
            overflow-wrap: anywhere;
        }

        .profile-help {
            padding: 16px;
            margin-bottom: 20px;
            background: #f1f6ff;
            border-radius: 10px;
            color: #304d76;
        }

        .profile-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e7f7ed;
            color: #197344;
            font-size: 13px;
        }

        @media (max-width: 600px) {
            .profile-banner {
                align-items: flex-start;
                padding: 20px;
                gap: 15px;
            }

            .profile-avatar {
                width: 68px;
                height: 68px;
                font-size: 27px;
            }

            .profile-banner h2 {
                font-size: 20px;
                overflow-wrap: anywhere;
            }

            .profile-details {
                grid-template-columns: 1fr;
            }

            .profile-card {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<div class="dashboard-layout">

    <aside class="sidebar">

        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <?= pfEscape($initial) ?>
            </div>

            <div>
                <strong><?= pfEscape($fullName) ?></strong>
                <small><?= pfEscape(pfText('supervisor', 'સુપરવાઇઝર')) ?></small>
            </div>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($menuItems as $menu): ?>
                <a
                    href="<?= pfEscape(APP_URL . '/' . $role . '/' . $menu[0]) ?>"
                    class="menu-link <?= $menu[0] === 'profile.php' ? 'active' : '' ?>"
                >
                    <span>
                        <?= pfEscape(pfText($menu[1], $menu[2])) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>

        <a class="logout-link" href="<?= APP_URL ?>/logout.php">
            <?= pfEscape(pfText('Logout', 'લૉગઆઉટ')) ?>
        </a>

    </aside>

    <main class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <div class="receive-content profile-content">

            <a class="back-dashboard"
               href="<?= APP_URL ?>/supervisor/dashboard.php">
                &larr;
                <?= pfEscape(pfText(
                    'Back to Dashboard',
                    'ડેશબોર્ડ પર પાછા જાઓ'
                )) ?>
            </a>

            <div class="receive-heading">
                <h1><?= pfEscape(pfText('My Profile', 'મારી પ્રોફાઇલ')) ?></h1>

                <p>
                    <?= pfEscape(pfText(
                        'View your account and Anganwadi centre details.',
                        'તમારા ખાતા અને આંગણવાડી કેન્દ્રની વિગતો જુઓ.'
                    )) ?>
                </p>
            </div>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger">
                    <?= pfEscape($error) ?>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($profile)): ?>

                <!-- Profile banner -->
                <section class="profile-banner">

                    <div class="profile-avatar">
                        <?= pfEscape($initial) ?>
                    </div>

                    <div>
                        <h2><?= pfEscape($fullName) ?></h2>

                        <p>
                            <?= pfEscape(pfText(
                                'Anganwadi supervisor',
                                'આંગણવાડી સુપરવાઇઝર'
                            )) ?>
                        </p>

                        <span class="profile-role">
                            <?= pfEscape(ucfirst(str_replace('_', ' ', $roleName))) ?>
                        </span>
                    </div>

                </section>

                <!-- Personal details -->
                <section class="profile-card">

                    <h3>
                        <?= pfEscape(pfText(
                            'Personal Information',
                            'વ્યક્તિગત માહિતી'
                        )) ?>
                    </h3>

                    <div class="profile-details">

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Full Name', 'પૂરું નામ')) ?>
                            </small>
                            <strong><?= pfEscape($fullName ?: '—') ?></strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('User ID', 'વપરાશકર્તા આઈડી')) ?>
                            </small>
                            <strong><?= (int)$userId ?></strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Email Address', 'ઈમેલ સરનામું')) ?>
                            </small>
                            <strong>
                                <?= pfEscape($email !== '' ? $email : pfText('Not provided', 'આપેલ નથી')) ?>
                            </strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Mobile Number', 'મોબાઇલ નંબર')) ?>
                            </small>
                            <strong>
                                <?= pfEscape($phone !== '' ? $phone : pfText('Not provided', 'આપેલ નથી')) ?>
                            </strong>
                        </div>

                    </div>

                </section>

                <!-- Work details -->
                <section class="profile-card">

                    <h3>
                        <?= pfEscape(pfText(
                            'Work Information',
                            'કામની માહિતી'
                        )) ?>
                    </h3>

                    <div class="profile-details">

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Role', 'ભૂમિકા')) ?>
                            </small>
                            <strong>
                                <?= pfEscape(ucfirst(str_replace('_', ' ', $roleName))) ?>
                            </strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Assigned Centre', 'સોંપાયેલ કેન્દ્ર')) ?>
                            </small>
                            <strong>
                                <?= pfEscape($centreName !== ''
                                    ? $centreName
                                    : pfText('No centre assigned', 'કોઈ કેન્દ્ર સોંપાયેલ નથી')) ?>
                            </strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Preferred Language', 'પસંદગીની ભાષા')) ?>
                            </small>
                            <strong>
                                <?= pfEscape($language === 'gu' ? 'ગુજરાતી' : 'English') ?>
                            </strong>
                        </div>

                        <div class="profile-detail">
                            <small>
                                <?= pfEscape(pfText('Account Status', 'ખાતાની સ્થિતિ')) ?>
                            </small>

                            <strong>
                                <?php if ($accountStatus === null): ?>
                                    <?= pfEscape(pfText(
                                        'Not available',
                                        'ઉપલબ્ધ નથી'
                                    )) ?>
                                <?php elseif ((int)$accountStatus === 1): ?>
                                    <span class="profile-status">
                                        <?= pfEscape(pfText('Active', 'સક્રિય')) ?>
                                    </span>
                                <?php else: ?>
                                    <?= pfEscape(pfText('Inactive', 'નિષ્ક્રિય')) ?>
                                <?php endif; ?>
                            </strong>
                        </div>

                    </div>

                </section>

                <div class="profile-help">
                    <strong>
                        <?= pfEscape(pfText(
                            'Need to update your details?',
                            'તમારી વિગતો અપડેટ કરવી છે?'
                        )) ?>
                    </strong>

                    <p class="mb-0 mt-2">
                        <?= pfEscape(pfText(
                            'Please contact your supervisor or administrator to update your registered account information.',
                            'તમારા ખાતાની નોંધાયેલ માહિતી અપડેટ કરવા માટે તમારા સુપરવાઇઝર અથવા એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                        )) ?>
                    </p>
                </div>

            <?php endif; ?>

        </div>

    </main>

</div>

</body>
</html>
