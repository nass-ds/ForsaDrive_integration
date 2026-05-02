<?php
/**
 * ForsaDrive — Language System
 * Supports: English (en), French (fr), Arabic (ar / RTL)
 * Translation: built-in PHP arrays + optional MyMemory free API for missing keys
 * Country: Tunisia only (TN) — country selector removed
 */

$langDir = __DIR__ . '/../lang/';

$GLOBALS['languages'] = [
    'en' => ['name' => 'English',  'dir' => 'ltr', 'flag' => '🇬🇧'],
    'fr' => ['name' => 'Français', 'dir' => 'ltr', 'flag' => '🇫🇷'],
    'ar' => ['name' => 'العربية', 'dir' => 'rtl', 'flag' => '🇹🇳'],
];

// 1. Resolve language: GET param → session → user DB pref → default 'en'
if (isset($_GET['lang']) && isset($GLOBALS['languages'][$_GET['lang']])) {
    $newLang = $_GET['lang'];
    $_SESSION['lang'] = $newLang;
    // Persist to DB for logged-in users so preference survives logout/login
    $uid_for_lang = $_SESSION['user_data']['id'] ?? ($_SESSION['user_id'] ?? null);
    if ($uid_for_lang) {
        try {
            $db = getDB();
            $db->prepare("UPDATE users SET lang_pref=? WHERE id=?")->execute([$newLang, $uid_for_lang]);
        } catch (Exception $e) { /* silent */ }
    }
}

// Read user's saved language preference from DB when session has no lang yet
if (!isset($_SESSION['lang'])) {
    $uid_for_lang = $_SESSION['user_data']['id'] ?? ($_SESSION['user_id'] ?? null);
    if ($uid_for_lang) {
        try {
            $db = getDB();
            $ls = $db->prepare("SELECT lang_pref FROM users WHERE id=?");
            $ls->execute([$uid_for_lang]);
            $lrow = $ls->fetch(PDO::FETCH_ASSOC);
            if (!empty($lrow['lang_pref'])) {
                $_SESSION['lang'] = $lrow['lang_pref'];
            }
        } catch (Exception $e) { /* silent */ }
    }
}

$GLOBALS['selectedLang'] = $_SESSION['lang'] ?? 'en';
$selectedLang = $GLOBALS['selectedLang']; // used by header.php

// 2. Load translations from PHP array file
$langFile = $langDir . $GLOBALS['selectedLang'] . '.php';
if (!file_exists($langFile)) {
    $langFile = $langDir . 'en.php';
    $GLOBALS['selectedLang'] = 'en';
}
$GLOBALS['translations'] = file_exists($langFile) ? include $langFile : [];

// 3. Translation helper — falls back to $default if key missing
function t(string $key, string $default = ''): string {
    return $GLOBALS['translations'][$key] ?? $default;
}

// 4. URL helper for language switching (keeps current URL, changes lang param)
function langUrl(string $lang): string {
    $params = $_GET;
    $params['lang'] = $lang;
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    return $base . '?' . http_build_query($params);
}

// 5. Country is always Tunisia
$GLOBALS['selectedCountry'] = 'TN';
