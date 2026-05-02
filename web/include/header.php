<?php
// Determine path depth for assets
$depth = (strpos($_SERVER['PHP_SELF'], '/Pages/') !== false) ? '../' : '';
$_lang = $GLOBALS['selectedLang'] ?? 'en';
$_dir  = $GLOBALS['languages'][$_lang]['dir'] ?? 'ltr';

// Sync the googtrans cookie server-side BEFORE HTML output.
// Google Translate reads this cookie on init — setting it via JS is too late.
$gtCookieVal = ($_lang !== 'en') ? '/en/' . $_lang : '';
$existingGt  = $_COOKIE['googtrans'] ?? '';
$wantedGt    = $gtCookieVal;

if ($wantedGt === '' && $existingGt !== '') {
    // Clear translation (back to English)
    setcookie('googtrans', '', time() - 3600, '/');
    setcookie('googtrans', '', time() - 3600, '/', '.' . ($_SERVER['HTTP_HOST'] ?? ''));
} elseif ($wantedGt !== '' && $existingGt !== $wantedGt) {
    // Set/update translation cookie
    setcookie('googtrans', $wantedGt, 0, '/');
    setcookie('googtrans', $wantedGt, 0, '/', '.' . ($_SERVER['HTTP_HOST'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_lang) ?>" dir="<?= $_dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' — ForsaDrive' : 'ForsaDrive' ?></title>

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome 6 -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<!-- ForsaDrive app styles -->
<link href="<?= $depth ?>Css/app.css" rel="stylesheet">
<?php if (isset($extraCss)) echo $extraCss; ?>

<style>
/* Google Translate bar suppression */
.goog-te-banner-frame,.skiptranslate { display:none!important; }
body { top:0!important; }
</style>

<!-- Google Translate widget (free, no API key) -->
<!-- The googtrans cookie is set server-side above so the widget reads the right value on init -->
<div id="google_translate_element" style="display:none"></div>
<script>
function googleTranslateElementInit() {
    new google.translate.TranslateElement({
        pageLanguage: 'en',
        includedLanguages: 'en,fr,ar',
        autoDisplay: false
    }, 'google_translate_element');
}
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async defer></script>
</head>
<body>
