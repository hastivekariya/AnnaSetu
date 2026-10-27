
<?php
/*
 * cdpo/profile.php
 * CDPO profile page for AnnaSetu.
 */

require_once __DIR__ . '/../includes/auth.php';

requireRole(['cdpo']);

$role = strtolower($_SESSION['role'] ?? 'cdpo');
$userId = (int)($_SESSION['user_id'] ?? 0);
$fullName = $_SESSION['full_name'] ?? 'CDPO';
$language = $_SESSION['language'] ?? 'gu';

$profile = [];
$errors = [];

function cdpoProfileEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cdpoProfileText(string $english, string $gujarati): string
{
    return ($_SESSION['language'] ?? 'gu') === 'gu'
        ? $gujarati
        : $english;
}

/* Fetch CDPO profile information */
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
        $errors[] = cdpoProfileText(
            'User profile not found.',
            'વપરાશકર્તાની પ્રોફાઇલ મળી નથી.'
        );
    } else {
        $fullName = $profile['full_name'] ?? $fullName;
    }
} catch (PDOException $e) {
    error_log('CDPO profile error: ' . $e->getMessage());

    $errors[] = cdpoProfileText(
        'Unable to load your profile. Please check the database.',
        'તમારી પ્રોફાઇલ લોડ થઈ શકી નથી. કૃપા કરીને ડેટાબેઝ તપાસો.'
    );
}

/* CDPO sidebar menu */
$menuItems = [
    ['dashboard.php', 'Dashboard', 'ડેશબોર્ડ'],
    ['projects.php', 'Project Overview', 'પ્રોજેક્ટની માહિતી'],
    ['centres.php', 'Anganwadi Centres', 'આંગણવાડી કેન્દ્રો'],
    ['stock-overview.php', 'Stock Overview', 'સ્ટોકની માહિતી'],
    ['alerts.php', 'Shortage and Expiry Alerts', 'અછત અને સમાપ્તિ ચેતવણીઓ'],
    ['reports.php', 'Reports and Analytics', 'અહેવાલો અને વિશ્લેષણ'],
    ['export-reports.php', 'Export Reports', 'અહેવાલો એક્સપોર્ટ કરો'],
    ['profile.php', 'My Profile', 'મારી પ્રોફાઇલ']
];

/* Profile values */
$email = $profile['email'] ?? '';
$phone = $profile['phone'] ?? ($profile['mobile'] ?? '');
$roleName = $profile['role'] ?? $role;
$accountStatus = $profile['is_active'] ?? null;

$initial = strtoupper(substr(trim($fullName), 0, 1));

if ($initial === '') {
    $initial = 'C';
}

$displayRole = ucfirst(str_replace('_', ' ', $roleName));
?>

<!DOCTYPE html>
<html lang="<?= cdpoProfileEscape($language === 'gu' ? 'gu' : 'en') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= cdpoProfileEscape(
            cdpoProfileText('My Profile', 'મારી પ્રોફાઇલ')
        ) ?> | AnnaSetu
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?= cdpoProfileEscape(APP_URL) ?>/assets/css/dashboard.css?v=3"
    >

    <style>
        .cdpo-profile-content {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
            min-width: 0;
        }

        .cdpo-profile-heading {
            margin-bottom: 22px;
        }

        .cdpo-profile-heading h1 {
            margin: 0 0 8px;
            color: #183153;
            font-size: 28px;
            font-weight: 700;
        }

        .cdpo-profile-heading p {
            margin: 0;
            color: #68758a;
        }

        .cdpo-profile-banner {
            display: flex;
            align-items: center;
            gap: 22px;
            padding: 28px;
            margin-bottom: 22px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e8edf4;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        .cdpo-profile-avatar {
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

        .cdpo-profile-banner h2 {
            margin: 0 0 8px;
            color: #183153;
            font-size: 25px;
            overflow-wrap: anywhere;
        }

        .cdpo-profile-banner p {
            margin: 5px 0 12px;
            color: #68758a;
        }

        .cdpo-profile-role {
            display: inline-block;
            padding: 6px 13px;
            border-radius: 20px;
            background: #e7f7ed;
            color: #197344;
            font-size: 13px;
            font-weight: 600;
        }

        .cdpo-profile-card {
            padding: 24px;
            margin-bottom: 22px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e8edf4;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.05);
        }

        .cdpo-profile-card h3 {
            margin: 0 0 20px;
            color: #183153;
            font-size: 19px;
            font-weight: 700;
        }

        .cdpo-profile-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .cdpo-profile-detail {
            padding: 15px;
            border: 1px solid #e8edf4;
            border-radius: 10px;
            min-width: 0;
        }

        .cdpo-profile-detail small {
            display: block;
            margin-bottom: 8px;
            color: #788497;
            font-size: 13px;
        }

        .cdpo-profile-detail strong {
            display: block;
            color: #25354b;
            font-size: 15px;
            overflow-wrap: anywhere;
        }

        .cdpo-profile-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #e7f7ed;
            color: #197344;
            font-size: 13px;
            font-weight: 600;
        }

        .cdpo-profile-inactive {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 13px;
            font-weight: 600;
        }

        .cdpo-profile-help {
            padding: 18px;
            margin-bottom: 20px;
            background: #f1f6ff;
            border: 1px solid #dce8ff;
            border-radius: 10px;
            color: #304d76;
        }

        .cdpo-profile-help p {
            margin-bottom: 0;
            margin-top: 8px;
        }

        .cdpo-back-dashboard {
            display: inline-block;
            margin-bottom: 20px;
            color: #004aad;
            font-weight: 600;
            text-decoration: none;
        }

        .cdpo-back-dashboard:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .cdpo-profile-content {
                padding: 16px;
            }

            .cdpo-profile-banner {
                align-items: flex-start;
                padding: 20px;
                gap: 15px;
            }

            .cdpo-profile-avatar {
                width: 68px;
                height: 68px;
                font-size: 27px;
            }

            .cdpo-profile-banner h2 {
                font-size: 20px;
            }

            .cdpo-profile-details {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .cdpo-profile-card {
                padding: 18px;
            }

            .cdpo-profile-heading h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<div class="dashboard-layout">

    <!-- CDPO sidebar -->
    <aside class="sidebar">

        <div class="sidebar-brand">
            <h3>અન્નસેતુ</h3>
            <p>AnnaSetu</p>
        </div>

        <div class="sidebar-user">
            <div class="user-avatar">
                <?= cdpoProfileEscape($initial) ?>
            </div>

            <div>
                <strong>
                    <?= cdpoProfileEscape($fullName) ?>
                </strong>

                <small>
                    <?= cdpoProfileEscape(
                        cdpoProfileText('CDPO', 'સીડીપીઓ')
                    ) ?>
                </small>
            </div>
        </div>

        <nav class="sidebar-menu">
            <?php foreach ($menuItems as $menu): ?>
                <a
                    href="<?= cdpoProfileEscape(
                        APP_URL . '/cdpo/' . $menu[0]
                    ) ?>"
                    class="menu-link <?= $menu[0] === 'profile.php' ? 'active' : '' ?>"
                >
                    <?= cdpoProfileEscape(
                        cdpoProfileText($menu[1], $menu[2])
                    ) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <a
            class="logout-link"
            href="<?= cdpoProfileEscape(APP_URL . '/logout.php') ?>"
        >
            <?= cdpoProfileEscape(
                cdpoProfileText('Logout', 'લૉગઆઉટ')
            ) ?>
        </a>

    </aside>

    <!-- Main content -->
    <div class="dashboard-main">

        <?php require_once __DIR__ . '/../includes/page-header.php'; ?>

        <main class="cdpo-profile-content">

            <a
                class="cdpo-back-dashboard"
                href="<?= cdpoProfileEscape(
                    APP_URL . '/cdpo/dashboard.php'
                ) ?>"
            >
                &larr;
                <?= cdpoProfileEscape(
                    cdpoProfileText(
                        'Back to Dashboard',
                        'ડેશબોર્ડ પર પાછા જાઓ'
                    )
                ) ?>
            </a>

            <div class="cdpo-profile-heading">
                <h1>
                    <?= cdpoProfileEscape(
                        cdpoProfileText('My Profile', 'મારી પ્રોફાઇલ')
                    ) ?>
                </h1>

                <p>
                    <?= cdpoProfileEscape(
                        cdpoProfileText(
                            'View your account information and administrative role.',
                            'તમારા ખાતાની માહિતી અને વહીવટી ભૂમિકા જુઓ.'
                        )
                    ) ?>
                </p>
            </div>

            <!-- Error messages -->
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger" role="alert">
                    <?= cdpoProfileEscape($error) ?>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($profile)): ?>

                <!-- Profile banner -->
                <section class="cdpo-profile-banner">

                    <div class="cdpo-profile-avatar">
                        <?= cdpoProfileEscape($initial) ?>
                    </div>

                    <div>
                        <h2><?= cdpoProfileEscape($fullName) ?></h2>

                        <p>
                            <?= cdpoProfileEscape(
                                cdpoProfileText(
                                    'Child Development Project Officer',
                                    'બાળ વિકાસ પ્રોજેક્ટ અધિકારી'
                                )
                            ) ?>
                        </p>

                        <span class="cdpo-profile-role">
                            <?= cdpoProfileEscape(
                                cdpoProfileText('CDPO', 'સીડીપીઓ')
                            ) ?>
                        </span>
                    </div>

                </section>

                <!-- Personal information -->
                <section class="cdpo-profile-card">

                    <h3>
                        <?= cdpoProfileEscape(
                            cdpoProfileText(
                                'Personal Information',
                                'વ્યક્તિગત માહિતી'
                            )
                        ) ?>
                    </h3>

                    <div class="cdpo-profile-details">

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText('Full Name', 'પૂરું નામ')
                                ) ?>
                            </small>

                            <strong>
                                <?= cdpoProfileEscape(
                                    $fullName !== '' ? $fullName : '—'
                                ) ?>
                            </strong>
                        </div>

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText(
                                        'User ID',
                                        'વપરાશકર્તા આઈડી'
                                    )
                                ) ?>
                            </small>

                            <strong><?= (int)$userId ?></strong>
                        </div>

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText(
                                        'Email Address',
                                        'ઈમેલ સરનામું'
                                    )
                                ) ?>
                            </small>

                            <strong>
                                <?= cdpoProfileEscape(
                                    $email !== ''
                                        ? $email
                                        : cdpoProfileText(
                                            'Not provided',
                                            'આપેલ નથી'
                                        )
                                ) ?>
                            </strong>
                        </div>

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText(
                                        'Mobile Number',
                                        'મોબાઇલ નંબર'
                                    )
                                ) ?>
                            </small>

                            <strong>
                                <?= cdpoProfileEscape(
                                    $phone !== ''
                                        ? $phone
                                        : cdpoProfileText(
                                            'Not provided',
                                            'આપેલ નથી'
                                        )
                                ) ?>
                            </strong>
                        </div>

                    </div>

                </section>

                <!-- Administrative information -->
                <section class="cdpo-profile-card">

                    <h3>
                        <?= cdpoProfileEscape(
                            cdpoProfileText(
                                'Administrative Information',
                                'વહીવટી માહિતી'
                            )
                        ) ?>
                    </h3>

                    <div class="cdpo-profile-details">

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText('Role', 'ભૂમિકા')
                                ) ?>
                            </small>

                            <strong>
                                <?= cdpoProfileEscape(
                                    $displayRole
                                ) ?>
                            </strong>
                        </div>

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText(
                                        'Preferred Language',
                                        'પસંદગીની ભાષા'
                                    )
                                ) ?>
                            </small>

                            <strong>
                                <?= cdpoProfileEscape(
                                    $language === 'gu' ? 'ગુજરાતી' : 'English'
                                ) ?>
                            </strong>
                        </div>

                        <div class="cdpo-profile-detail">
                            <small>
                                <?= cdpoProfileEscape(
                                    cdpoProfileText(
                                        'Account Status',
                                        'ખાતાની સ્થિતિ'
                                    )
                                ) ?>
                            </small>

                            <strong>
                                <?php if ($accountStatus === null): ?>

                                    <?= cdpoProfileEscape(
                                        cdpoProfileText(
                                            'Not available',
                                            'ઉપલબ્ધ નથી'
                                        )
                                    ) ?>

                                <?php elseif ((int)$accountStatus === 1): ?>

                                    <span class="cdpo-profile-status">
                                        <?= cdpoProfileEscape(
                                            cdpoProfileText(
                                                'Active',
                                                'સક્રિય'
                                            )
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="cdpo-profile-inactive">
                                        <?= cdpoProfileEscape(
                                            cdpoProfileText(
                                                'Inactive',
                                                'નિષ્ક્રિય'
                                            )
                                        ) ?>
                                    </span>

                                <?php endif; ?>
                            </strong>
                        </div>

                    </div>

                </section>

                <!-- Help message -->
                <div class="cdpo-profile-help">

                    <strong>
                        <?= cdpoProfileEscape(
                            cdpoProfileText(
                                'Need to update your details?',
                                'તમારી વિગતો અપડેટ કરવી છે?'
                            )
                        ) ?>
                    </strong>

                    <p>
                        <?= cdpoProfileEscape(
                            cdpoProfileText(
                                'Please contact the system administrator to update your registered account information.',
                                'તમારા ખાતાની નોંધાયેલ માહિતી અપડેટ કરવા માટે સિસ્ટમ એડમિનિસ્ટ્રેટરનો સંપર્ક કરો.'
                            )
                        ) ?>
                    </p>

                </div>

            <?php endif; ?>

        </main>

    </div>

</div>

</body>
</html>
