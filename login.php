
<?php
require_once __DIR__ . '/includes/init.php';

// If the user is already logged in, redirect to dashboard.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);

    $allowedRoles = ['worker', 'helper', 'supervisor', 'cdpo', 'admin'];

    if (in_array($role, $allowedRoles, true)) {
        header('Location: ' . APP_URL . '/' . $role . '/dashboard.php');
        exit;
    }
}

$error = '';
$username = '';
$selectedLanguage = $_SESSION['language'] ?? 'gu';

// Handle language selection.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedLanguage = $_POST['language'] ?? 'gu';

    if (!in_array($selectedLanguage, ['en', 'gu'], true)) {
        $selectedLanguage = 'gu';
    }

    $_SESSION['language'] = $selectedLanguage;

    // Reload translations for the selected language.
    $languageFile = __DIR__ . '/languages/' . $selectedLanguage . '.php';

    if (is_file($languageFile)) {
        $translations = require $languageFile;
    }

    // Handle login form submission.
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = translate('fill_all_fields');
    } else {
        try {
            $sql = "SELECT
                        u.user_id,
                        u.full_name,
                        u.username,
                        u.password_hash,
                        u.preferred_language,
                        u.is_active,
                        r.role_name
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.role_id
                    WHERE u.username = :username
                       OR u.mobile_number = :mobile
                    LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'username' => $username,
                'mobile' => $username
            ]);

            $user = $stmt->fetch();

            if (
                $user &&
                (int) $user['is_active'] === 1 &&
                password_verify($password, $user['password_hash'])
            ) {
                // Regenerate the session ID after successful login.
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = strtolower($user['role_name']);

                // Save the user's preferred language.
                if (in_array($user['preferred_language'], ['en', 'gu'], true)) {
                    $_SESSION['language'] = $user['preferred_language'];
                }

                // Update the last login time.
                $update = $pdo->prepare(
                    "UPDATE users SET last_login = NOW()
                     WHERE user_id = :user_id"
                );
                $update->execute(['user_id' => $user['user_id']]);

                // Redirect according to the database role.
                $role = strtolower($user['role_name']);
                $allowedRoles = [
                    'worker', 'helper', 'supervisor', 'cdpo', 'admin'
                ];

                if (in_array($role, $allowedRoles, true)) {
                    header('Location: ' . APP_URL . '/' . $role . '/dashboard.php');
                    exit;
                }

                $error = translate('invalid_role');
            } else {
                $error = translate('invalid_login');
            }
        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = translate('login_error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($selectedLanguage, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars(translate('login'), ENT_QUOTES, 'UTF-8') ?> | Anganwadi</title>

    <!-- Bootstrap CSS -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <div class="logo-circle">🏡</div>

        <h1 class="text-center">આંગણવાડી</h1>
        <p class="subtitle text-center">
            <?= htmlspecialchars(translate('app_name'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <form method="POST" action="login.php">

            <!-- Language selection -->
            <div class="mb-3">
                <label for="language" class="form-label">
                    <?= htmlspecialchars(translate('language'), ENT_QUOTES, 'UTF-8') ?>
                </label>

                <select
                    name="language"
                    id="language"
                    class="form-select"
                    onchange="this.form.submit()"
                >
                    <option value="gu" <?= $selectedLanguage === 'gu' ? 'selected' : '' ?>>
                        ગુજરાતી
                    </option>
                    <option value="en" <?= $selectedLanguage === 'en' ? 'selected' : '' ?>>
                        English
                    </option>
                </select>
            </div>

            <!-- Username or mobile number -->
            <div class="mb-3">
                <label for="username" class="form-label">
                    <?= htmlspecialchars(translate('username_mobile'), ENT_QUOTES, 'UTF-8') ?>
                </label>

                <input
                    type="text"
                    name="username"
                    id="username"
                    class="form-control"
                    placeholder="<?= htmlspecialchars(translate('username_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                    value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    required
                >
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label for="password" class="form-label">
                    <?= htmlspecialchars(translate('password'), ENT_QUOTES, 'UTF-8') ?>
                </label>

                <div class="password-wrapper">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        placeholder="<?= htmlspecialchars(translate('password_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="show-password"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        👁
                    </button>
                </div>
            </div>

            <!-- Error message -->
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <!-- Login button -->
            <button type="submit" class="btn login-btn w-100">
                <?= htmlspecialchars(translate('login'), ENT_QUOTES, 'UTF-8') ?>
            </button>

        </form>

        <p class="footer-text text-center">
            <?= htmlspecialchars(translate('login_footer'), ENT_QUOTES, 'UTF-8') ?>
        </p>

    </div>
</div>

<script>
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword.addEventListener('click', function () {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            togglePassword.textContent = '🙈';
            togglePassword.setAttribute('aria-label', 'Hide password');
        } else {
            passwordInput.type = 'password';
            togglePassword.textContent = '👁';
            togglePassword.setAttribute('aria-label', 'Show password');
        }
    });
</script>

</body>
</html>
