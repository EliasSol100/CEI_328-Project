<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/shipping_helpers.php';
require_once __DIR__ . '/../../include/platform_integrations.php';

$current_page = 'shipping_settings';
$flash = '';

app_system_config_seed_defaults($conn, [
    'shipping_free_threshold'  => (string)app_shipping_default_free_threshold(),
    'shipping_flat_rates_json' => json_encode(app_shipping_default_flat_rates(), JSON_UNESCAPED_UNICODE),
]);

function shippingSettingsMoney($value): string
{
    return number_format((float)$value, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $threshold = round(max(0.0, (float)($_POST['free_threshold'] ?? app_shipping_default_free_threshold())), 2);

    $flatRatesSaved = [];
    foreach (app_shipping_default_flat_rates() as $country => $couriers) {
        foreach ($couriers as $courierCode => $methods) {
            foreach ($methods as $method => $defaultPrice) {
                $val = round(max(0.0, (float)($_POST['flat'][$country][$courierCode][$method] ?? $defaultPrice)), 2);
                $flatRatesSaved[$country][$courierCode][$method] = $val;
            }
        }
    }

    $ok = app_system_config_set_many($conn, [
        'shipping_free_threshold'  => shippingSettingsMoney($threshold),
        'shipping_flat_rates_json' => json_encode($flatRatesSaved, JSON_UNESCAPED_UNICODE),
    ]);

    $flash = $ok ? 'ok:Shipping settings updated.' : 'err:Could not update shipping settings.';
    header('Location: shipping_settings.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}

$freeThreshold  = app_shipping_free_threshold();
$flatRates      = app_shipping_flat_rates();
$courierLabels  = app_shipping_courier_labels();
$methodLabels   = ['home' => 'Deliver to your address', 'pickup' => 'Pick up from courier point'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shipping Settings - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .shipping-price-input { max-width: 120px; }
    .shipping-country-row td { background: #f3f4f6; font-weight: 700; color: #374151; font-size: 13px; padding: 8px 14px; }
    .shipping-courier-name { padding-left: 28px !important; }
  </style>
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Shipping Settings</h1>
        <p>Set free-shipping threshold and flat prices per courier and delivery method.</p>
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
              <input type="number" step="0.01" min="0" name="free_threshold"
                     class="form-input" value="<?= app_h(shippingSettingsMoney($freeThreshold)) ?>">
              <span class="form-hint">Checkout gives free shipping when cart subtotal reaches this amount.</span>
            </div>
          </div>
        </div>

        <div class="card mb-6">
          <div class="card-title">Courier Prices</div>

          <table class="data-table">
            <thead>
              <tr>
                <th>Courier</th>
                <th>Method</th>
                <th>Price (EUR)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (app_shipping_default_flat_rates() as $country => $couriers): ?>
                <tr class="shipping-country-row">
                  <td colspan="3"><?= app_h($country) ?></td>
                </tr>
                <?php foreach ($couriers as $courierCode => $methods): ?>
                  <?php foreach ($methods as $method => $defaultPrice): ?>
                    <tr>
                      <td class="font-600 shipping-courier-name"><?= app_h($courierLabels[$country][$courierCode] ?? $courierCode) ?></td>
                      <td><?= app_h($methodLabels[$method] ?? $method) ?></td>
                      <td>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          class="form-input shipping-price-input"
                          name="flat[<?= app_h($country) ?>][<?= app_h($courierCode) ?>][<?= app_h($method) ?>]"
                          value="<?= app_h(shippingSettingsMoney($flatRates[$country][$courierCode][$method] ?? $defaultPrice)) ?>">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="modal-footer" style="margin-top: 24px;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Shipping Settings</button>
          </div>
        </div>

      </form>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
