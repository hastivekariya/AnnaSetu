
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Allow only English and Gujarati
$language = $_POST['language'] ?? 'en';

if (!in_array($language, ['en', 'gu'], true)) {
    $language = 'en';
}

// Save language
$_SESSION['language'] = $language;

// Get the requested return URL
$returnTo = $_POST['return_to'] ?? '';

// Redirect to the previous local page
if (
    is_string($returnTo) &&
    $returnTo !== '' &&
    str_starts_with($returnTo, '/') &&
    !str_starts_with($returnTo, '//') &&
    strpos($returnTo, '://') === false &&
    strpos($returnTo, '..') === false
) {
    header('Location: ' . $returnTo);
    exit;
}

// Fallback if return URL is missing or invalid
header('Location: ' . AnnaSetu . '/index.php');
exit;
?>
