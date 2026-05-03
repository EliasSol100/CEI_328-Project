<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/shipping_helpers.php';
require_once __DIR__ . '/../../include/platform_integrations.php';

$current_page = 'shipping_settings';
$flash = '';

app_system_config_seed_defaults($conn, [
    'shipping_free_threshold' => (string)app_shipping_default_free_threshold(),
]);

function shippingSettingsMoney($value): string
{
    return number_format((float)$value, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $threshold = round(max(0.0, (float)($_POST['free_threshold'] ?? app_shipping_default_free_threshold())), 2);

    $ok = app_system_config_set_many($conn, [
        'shipping_free_threshold' => shippingSettingsMoney($threshold),
    ]);

    $flash = $ok ? 'ok:Shipping settings updated.' : 'err:Could not update shipping settings.';
    header('Location: shipping_settings.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}

$freeThreshold = app_shipping_free_threshold();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shipping Settings - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Shipping Settings</h1>
        <p>Update free-shipping threshold and courier prices used in checkout.</p>
      </div>
    </div>

    <div class="content-body">
      <?php if ($flash): ?>
        <?php [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= app_h($msg) ?></div>
      <?php endif; ?>

      <form method="POST">
        <?= app_csrf_input() ?>
        <div class="card mb-6">
          <div class="card-title">General</div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Free Shipping Threshold (EUR)</label>
              <input type="number" step="0.01" min="0" name="free_threshold" class="form-input" value="<?= app_h(shippingSettingsMoney($freeThreshold)) ?>">
              <span class="form-hint">Checkout gives free shipping when cart subtotal reaches this amount.</span>
            </div>
          </div>
          <div class="modal-footer" style="margin-top:20px;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save</button>
          </div>
        </div>
      </form>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
