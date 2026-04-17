<?php
$pwaScriptName = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$pwaRootPrefix = '';

if (strpos($pwaScriptName, '/modules/admin/') !== false) {
    $pwaRootPrefix = '../../';
} elseif (
    strpos($pwaScriptName, '/authentication/') !== false ||
    strpos($pwaScriptName, '/profile/') !== false ||
    strpos($pwaScriptName, '/modules/') !== false
) {
    $pwaRootPrefix = '../';
}

$pwaManifestUrl = $pwaRootPrefix . 'manifest.webmanifest';
$pwaIcon192Url = $pwaRootPrefix . 'assets/pwa/icon-192.png';
$pwaIcon512Url = $pwaRootPrefix . 'assets/pwa/icon-512.png';
?>
<link rel="manifest" href="<?= htmlspecialchars($pwaManifestUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#6A0DAD">
<meta name="application-name" content="Athina E-shop">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Athina">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($pwaIcon192Url, ENT_QUOTES, 'UTF-8') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($pwaIcon192Url, ENT_QUOTES, 'UTF-8') ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= htmlspecialchars($pwaIcon512Url, ENT_QUOTES, 'UTF-8') ?>">
