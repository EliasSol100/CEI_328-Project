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
    'shipping_rate_table_json' => json_encode(app_shipping_default_rate_table(), JSON_UNESCAPED_UNICODE),
    'shipping_size_surcharges_json' => json_encode(app_shipping_default_size_surcharges(), JSON_UNESCAPED_UNICODE),
]);

function shippingSettingsMoney($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function shippingSettingsNormalizeRateTable(array $postedRates, array $currentRates): array
{
    $normalized = $currentRates;

    foreach ($currentRates as $country => $couriers) {
        foreach ($couriers as $courierCode => $speeds) {
            foreach ($speeds as $speed => $tiers) {
                foreach ($tiers as $idx => $tier) {
                    $postedPrice = $postedRates[$country][$courierCode][$speed][$idx]['price'] ?? null;
                    if ($postedPrice === null || $postedPrice === '') {
                        continue;
                    }
                    $price = round(max(0.0, (float)$postedPrice), 2);
                    $normalized[$country][$courierCode][$speed][$idx] = [
                        'max' => $tier['max'],
                        'price' => $price,
                    ];
                }
            }
        }
    }

    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $threshold = round(max(0.0, (float)($_POST['free_threshold'] ?? app_shipping_default_free_threshold())), 2);
    $currentRateTable = app_shipping_rate_table();
    $rateTable = shippingSettingsNormalizeRateTable(
        is_array($_POST['rates'] ?? null) ? $_POST['rates'] : [],
        $currentRateTable
    );

    $defaultSurcharges = app_shipping_default_size_surcharges();
    $postedSurcharges = is_array($_POST['surcharges'] ?? null) ? $_POST['surcharges'] : [];
    $surcharges = [];
    foreach ($defaultSurcharges as $sizeCode => $defaultValue) {
        $surcharges[$sizeCode] = round(max(0.0, (float)($postedSurcharges[$sizeCode] ?? $defaultValue)), 2);
    }

    $ok = app_system_config_set_many($conn, [
        'shipping_free_threshold' => shippingSettingsMoney($threshold),
        'shipping_rate_table_json' => json_encode($rateTable, JSON_UNESCAPED_UNICODE),
        'shipping_size_surcharges_json' => json_encode($surcharges, JSON_UNESCAPED_UNICODE),
    ]);

    $flash = $ok ? 'ok:Shipping prices updated.' : 'err:Could not update shipping prices.';
    header('Location: shipping_settings.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}

$freeThreshold = app_shipping_free_threshold();
$rateTable = app_shipping_rate_table();
$courierLabels = app_shipping_country_couriers();
$sizeSurcharges = app_shipping_config_json('shipping_size_surcharges_json', app_shipping_default_size_surcharges());
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
    .shipping-rate-group { margin-top: 18px; }
    .shipping-rate-heading { display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px; }
    .shipping-rate-heading h3 { margin:0; font-size:16px; color:#111827; }
    .shipping-rate-table th, .shipping-rate-table td { vertical-align:middle; }
    .shipping-tier-input { max-width: 120px; }
    .shipping-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 9px; border-radius:999px; background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; }
  </style>
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Shipping Settings</h1>
        <p>Update free-shipping threshold, courier prices, and size surcharges used in checkout.</p>
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
          <div class="card-title">General Shipping Prices</div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Free Shipping Threshold (EUR)</label>
              <input type="number" step="0.01" min="0" name="free_threshold" class="form-input" value="<?= app_h(shippingSettingsMoney($freeThreshold)) ?>">
              <span class="form-hint">Checkout gives free shipping when cart subtotal reaches this amount.</span>
            </div>
          </div>
          <div class="form-grid-4">
            <?php foreach (app_shipping_default_size_surcharges() as $sizeCode => $defaultValue): ?>
              <div class="form-group">
                <label class="form-label"><?= app_h(ucwords(str_replace('_', ' ', $sizeCode))) ?> Surcharge (EUR)</label>
                <input type="number" step="0.01" min="0" name="surcharges[<?= app_h($sizeCode) ?>]" class="form-input" value="<?= app_h(shippingSettingsMoney($sizeSurcharges[$sizeCode] ?? $defaultValue)) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card mb-6">
          <div class="card-title">Courier Rate Tiers</div>
          <?php foreach ($rateTable as $country => $couriers): ?>
            <div class="shipping-rate-group">
              <div class="shipping-rate-heading">
                <h3><?= app_h($country) ?></h3>
                <span class="shipping-pill"><i class="fas fa-truck"></i> <?= count($couriers) ?> couriers</span>
              </div>
              <table class="data-table shipping-rate-table">
                <thead>
                  <tr>
                    <th>Courier</th>
                    <th>Speed</th>
                    <th>Weight Tier</th>
                    <th>Price (EUR)</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($couriers as $courierCode => $speeds): ?>
                  <?php foreach ($speeds as $speed => $tiers): ?>
                    <?php foreach ($tiers as $idx => $tier): ?>
                      <?php
                        $max = $tier['max'];
                        $tierLabel = $max === null ? 'Over previous tier' : 'Up to ' . number_format((float)$max, 1) . ' kg';
                        $courierLabel = $courierLabels[$country][$courierCode] ?? $courierCode;
                      ?>
                      <tr>
                        <td class="font-600"><?= app_h($courierLabel) ?></td>
                        <td><?= app_h(ucfirst((string)$speed)) ?></td>
                        <td class="text-muted"><?= app_h($tierLabel) ?></td>
                        <td>
                          <input
                            type="number"
                            step="0.01"
                            min="0"
                            class="form-input shipping-tier-input"
                            name="rates[<?= app_h($country) ?>][<?= app_h($courierCode) ?>][<?= app_h($speed) ?>][<?= (int)$idx ?>][price]"
                            value="<?= app_h(shippingSettingsMoney($tier['price'] ?? 0)) ?>">
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
          <div class="modal-footer" style="margin-top:20px;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Shipping Prices</button>
          </div>
        </div>
      </form>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
