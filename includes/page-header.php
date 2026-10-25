
<?php
$language = $_SESSION['language'] ?? 'gu';
$fullName = $_SESSION['full_name'] ?? 'User';
?>

<header class="page-header">
    <div>
        <h2>
            <?= htmlspecialchars(translate('welcome'), ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>!
        </h2>

        <p>
            <?= htmlspecialchars(translate('tagline'), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>

    <form
        action="<?= APP_URL ?>/change-language.php"
        method="POST"
        class="language-form"
    >
        <input
            type="hidden"
            name="return_to"
            value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        >

        <label for="language">
            <?= htmlspecialchars(translate('language'), ENT_QUOTES, 'UTF-8') ?>
        </label>

        <select
            name="language"
            id="language"
            onchange="this.form.submit()"
        >
            <option value="gu" <?= $language === 'gu' ? 'selected' : '' ?>>
                ગુજરાતી
            </option>

            <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>
                English
            </option>
        </select>
    </form>
</header>
