<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
session_start();

require_once __DIR__ . '/../include/translation_helpers.php';

$project = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($project === '' || $project === '.') {
    $project = '';
}

$failure = $_SESSION['checkout_failed'] ?? [];
unset($_SESSION['checkout_failed']);

$provider = trim((string)($failure['provider'] ?? ($_GET['provider'] ?? '')));
$message = trim((string)($failure['message'] ?? ''));
if ($message === '') {
    $message = 'We could not complete your payment. Please try again or return to checkout.';
}

$configPath = __DIR__ . '/../authentication/get_config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $system_title = function_exists('getSystemConfig') ? getSystemConfig('site_title') : 'Creations by Athina';
} else {
    $system_title = 'Creations by Athina';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout Failed - <?= htmlspecialchars($system_title) ?></title>
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/styles.css">
    <link rel="stylesheet" href="<?= $project ?>/assets/styling/header.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= $project ?>/assets/js/translations.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/translations.js') ?>" defer></script>
    <style>
        .failure-container { max-width: 760px; margin: 60px auto; padding: 0 20px; }
        .failure-card { background: #fff; border-radius: 16px; padding: 42px 34px; box-shadow: 0 12px 40px rgba(52, 16, 79, 0.08); text-align: center; }
        .failure-icon { width: 92px; height: 92px; margin: 0 auto 24px; border-radius: 50%; background: #fff1f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 42px; }
        .failure-title { margin: 0 0 12px; font-size: 36px; font-weight: 800; color: #2f1f45; }
        .failure-message { margin: 0 auto 18px; max-width: 560px; color: #5c4a72; font-size: 17px; line-height: 1.7; }
        .failure-provider { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 22px; padding: 8px 14px; border-radius: 999px; background: #f6f0ff; color: #6e37b8; font-weight: 700; }
        .failure-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; margin-top: 24px; }
        .failure-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 200px; padding: 14px 22px; border-radius: 999px; text-decoration: none; font-weight: 700; }
        .failure-btn-primary { background: linear-gradient(135deg, #ff5db1, #8c38d5); color: #fff; }
        .failure-btn-secondary { border: 1px solid #d6c6ea; color: #573a7d; background: #fff; }
    </style>
</head>
<body class="site-page"<?= app_translate_page_title_attrs('Checkout Failed - ' . $system_title, 'Αποτυχία Ολοκλήρωσης Παραγγελίας - ' . $system_title) ?>>
<?php
$headerPath = __DIR__ . '/../include/header.php';
if (file_exists($headerPath)) {
    $activePage = 'checkout-failed';
    include $headerPath;
}
?>
<div class="failure-container">
    <div class="failure-card">
        <div class="failure-icon"><i class="fas fa-circle-exclamation"></i></div>
        <h1 class="failure-title"<?= app_translate_text_attrs('Payment Not Completed', 'Η Πληρωμή Δεν Ολοκληρώθηκε') ?>>Payment Not Completed</h1>
        <?php if ($provider !== ''): ?>
            <div class="failure-provider">
                <i class="fas fa-wallet"></i>
                <span><?= htmlspecialchars(strtoupper($provider)) ?></span>
            </div>
        <?php endif; ?>
        <p class="failure-message"<?= app_translate_text_attrs($message, 'Δεν μπορέσαμε να ολοκληρώσουμε την πληρωμή σας. Προσπαθήστε ξανά ή επιστρέψτε στο checkout.') ?>><?= htmlspecialchars($message) ?></p>
        <div class="failure-actions">
            <a href="<?= $project ?>/modules/checkout.php" class="failure-btn failure-btn-primary"<?= app_translate_text_attrs('Try Checkout Again', 'Δοκιμάστε Ξανά το Checkout') ?>>Try Checkout Again</a>
            <a href="<?= $project ?>/index.php" class="failure-btn failure-btn-secondary"<?= app_translate_text_attrs('Return to Home Page', 'Επιστροφή στην Αρχική Σελίδα') ?>>Return to Home Page</a>
        </div>
    </div>
</div>
</body>
</html>
