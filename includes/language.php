
<?php

// Load the selected language file

$language = $_SESSION['language'] ?? 'gu';

if (!in_array($language, ['en', 'gu'], true)) {
    $language = 'gu';
}

$languageFile = BASE_PATH . '/languages/' . $language . '.php';

if (is_file($languageFile)) {
    $translations = require $languageFile;
} else {
    $translations = [];
}

// Translation helper
if (!function_exists('translate')) {
    function translate(string $key): string
    {
        global $translations;

        return $translations[$key] ?? $key;
    }
}

?>
