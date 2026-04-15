<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/platform_integrations.php';

$current_page = 'store_integrations';

app_system_config_seed_defaults($conn, app_platform_integrations_default_values());
app_social_auth_ensure_user_schema($conn);

$flash = '';
if (isset($_SESSION['admin_store_integrations_flash'])) {
    $flash = (string)$_SESSION['admin_store_integrations_flash'];
    unset($_SESSION['admin_store_integrations_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $formSection = trim((string)($_POST['form_section'] ?? ''));

    if ($formSection === 'payments') {
        $paypalMode = strtolower(trim((string)($_POST['paypal_mode'] ?? 'live')));
        if (!in_array($paypalMode, ['live', 'sandbox'], true)) {
            $paypalMode = 'live';
        }

        $saved = app_system_config_set_many($conn, [
            'stripe_publishable_key' => trim((string)($_POST['stripe_publishable_key'] ?? '')),
            'stripe_secret_key' => trim((string)($_POST['stripe_secret_key'] ?? '')),
            'stripe_webhook_secret' => trim((string)($_POST['stripe_webhook_secret'] ?? '')),
            'paypal_client_id' => trim((string)($_POST['paypal_client_id'] ?? '')),
            'paypal_client_secret' => trim((string)($_POST['paypal_client_secret'] ?? '')),
            'paypal_webhook_id' => trim((string)($_POST['paypal_webhook_id'] ?? '')),
            'paypal_mode' => $paypalMode,
        ]);

        $_SESSION['admin_store_integrations_flash'] = $saved
            ? 'ok:Payment gateway settings saved.'
            : 'err:Payment gateway settings could not be saved.';

        header('Location: store_integrations.php');
        exit;
    }

    if ($formSection === 'social_login') {
        $saved = app_system_config_set_many($conn, [
            'google_client_id' => trim((string)($_POST['google_client_id'] ?? '')),
            'google_client_secret' => trim((string)($_POST['google_client_secret'] ?? '')),
            'facebook_app_id' => trim((string)($_POST['facebook_app_id'] ?? '')),
            'facebook_app_secret' => trim((string)($_POST['facebook_app_secret'] ?? '')),
        ]);

        $_SESSION['admin_store_integrations_flash'] = $saved
            ? 'ok:Social login settings saved.'
            : 'err:Social login settings could not be saved.';

        header('Location: store_integrations.php');
        exit;
    }

    $_SESSION['admin_store_integrations_flash'] = 'err:Unknown settings section.';
    header('Location: store_integrations.php');
    exit;
}

$stripePublishableKey = app_integration_setting($conn, 'STRIPE_PUBLISHABLE_KEY', 'stripe_publishable_key', '');
$stripeSecretKey = app_integration_setting($conn, 'STRIPE_SECRET_KEY', 'stripe_secret_key', '');
$stripeWebhookSecret = app_integration_setting($conn, 'STRIPE_WEBHOOK_SECRET', 'stripe_webhook_secret', '');
$paypalClientId = app_integration_setting($conn, 'PAYPAL_CLIENT_ID', 'paypal_client_id', '');
$paypalClientSecret = app_integration_setting($conn, 'PAYPAL_CLIENT_SECRET', 'paypal_client_secret', '');
$paypalWebhookId = app_integration_setting($conn, 'PAYPAL_WEBHOOK_ID', 'paypal_webhook_id', '');
$paypalMode = strtolower(app_integration_setting($conn, 'PAYPAL_MODE', 'paypal_mode', 'live'));
if (!in_array($paypalMode, ['live', 'sandbox'], true)) {
    $paypalMode = 'live';
}

$googleConfig = app_social_auth_config($conn, 'google');
$facebookConfig = app_social_auth_config($conn, 'facebook');

$googleRedirectUri = app_url('/authentication/google_callback.php');
$facebookRedirectUri = app_url('/authentication/facebook_callback.php');
$stripeWebhookUrl = app_url('/modules/stripe_webhook.php');
$paypalWebhookUrl = app_url('/modules/paypal_webhook.php');

$stripeConfigured = $stripeSecretKey !== '';
$paypalConfigured = $paypalClientId !== '' && $paypalClientSecret !== '';
$googleConfigured = !empty($googleConfig['enabled']);
$facebookConfigured = !empty($facebookConfig['enabled']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Store Integrations - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Store Integrations</h1>
        <p>Manage the owner’s live checkout credentials and social login apps from one place.</p>
      </div>
    </div>

    <div class="content-body">
      <?php if ($flash !== ''): ?>
        <?php [$type, $message] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <div class="alert-card alert-blue mb-6">
        <div class="alert-title"><i class="fas fa-circle-info"></i> Before the owner goes live</div>
        <p class="alert-text" style="margin-bottom:6px;">
          Replace the testing keys with the buyer’s real Stripe, PayPal, Google, and Facebook credentials.
        </p>
        <p class="alert-text">
          Google and Facebook login will stay unavailable until both the client ID/app ID and secret are saved here and the redirect URLs below are also added in the provider dashboards.
        </p>
        <p class="alert-text">
          For the most reliable checkout flow, also add the Stripe webhook signing secret and the PayPal webhook ID after creating webhook endpoints in the payment provider dashboards.
        </p>
      </div>

      <div class="grid-2">
        <section class="card">
          <div class="card-header-flex mb-4">
            <div>
              <div class="section-title">Payment Gateways</div>
              <div class="section-sub">These settings control Stripe and PayPal during checkout.</div>
            </div>
            <div class="flex gap-2">
              <span class="badge <?= $stripeConfigured ? 'badge-green' : 'badge-muted' ?>">Stripe <?= $stripeConfigured ? 'Configured' : 'Needs Setup' ?></span>
              <span class="badge <?= $paypalConfigured ? 'badge-green' : 'badge-muted' ?>">PayPal <?= $paypalConfigured ? 'Configured' : 'Needs Setup' ?></span>
            </div>
          </div>

          <form method="POST">
            <?= app_csrf_input() ?>
            <input type="hidden" name="form_section" value="payments">

            <div class="integration-card" style="margin-bottom:18px;">
              <div class="integration-logo" style="background:#eef2ff;color:#4338ca;">
                <i class="fab fa-stripe-s"></i>
              </div>
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                  <span style="font-size:17px;font-weight:700;">Stripe</span>
                  <span><span class="status-dot <?= $stripeConfigured ? 'dot-ok' : 'dot-off' ?>"></span><span class="text-sm <?= $stripeConfigured ? '' : 'text-muted' ?>"><?= $stripeConfigured ? 'Configured' : 'Not configured' ?></span></span>
                </div>
                <p class="text-sm text-muted" style="margin-bottom:16px;">
                  Stripe Checkout on this website needs the secret key. The publishable key is optional here, but it can be stored for future client-side Stripe features.
                </p>

                <div class="form-group">
                  <label class="form-label" for="stripe_publishable_key">Stripe Publishable Key</label>
                  <input id="stripe_publishable_key" name="stripe_publishable_key" class="form-input" type="text" autocomplete="off" placeholder="pk_live_... or pk_test_..." value="<?= htmlspecialchars($stripePublishableKey) ?>">
                  <div class="form-hint">Optional for this checkout flow, but useful to keep in the store settings.</div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="stripe_secret_key">Stripe Secret Key</label>
                  <input id="stripe_secret_key" name="stripe_secret_key" class="form-input" type="password" autocomplete="off" placeholder="sk_live_... or sk_test_..." value="<?= htmlspecialchars($stripeSecretKey) ?>">
                  <div class="form-hint">Required for Stripe Checkout session creation and payment verification.</div>
                </div>

                <div class="form-group" style="margin-top:16px;">
                  <label class="form-label" for="stripe_webhook_secret">Stripe Webhook Signing Secret</label>
                  <input id="stripe_webhook_secret" name="stripe_webhook_secret" class="form-input" type="password" autocomplete="off" placeholder="whsec_..." value="<?= htmlspecialchars($stripeWebhookSecret) ?>">
                  <div class="form-hint">Recommended for production so paid Stripe sessions can still be finalized if the customer never returns to the success page.</div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="stripe_webhook_url">Stripe Webhook URL</label>
                  <div class="flex gap-2">
                    <input id="stripe_webhook_url" class="form-input" type="text" readonly value="<?= htmlspecialchars($stripeWebhookUrl) ?>">
                    <button class="btn-secondary" type="button" onclick="copyCode(document.getElementById('stripe_webhook_url').value)">Copy</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="integration-card" style="margin-bottom:0;">
              <div class="integration-logo" style="background:#eff6ff;color:#1d4ed8;">
                <i class="fab fa-paypal"></i>
              </div>
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                  <span style="font-size:17px;font-weight:700;">PayPal</span>
                  <span><span class="status-dot <?= $paypalConfigured ? 'dot-ok' : 'dot-off' ?>"></span><span class="text-sm <?= $paypalConfigured ? '' : 'text-muted' ?>"><?= $paypalConfigured ? 'Configured' : 'Not configured' ?></span></span>
                </div>
                <p class="text-sm text-muted" style="margin-bottom:16px;">
                  Add the buyer’s PayPal client ID and secret, then switch the mode to <strong>live</strong> when the real account is ready.
                </p>

                <div class="form-grid-2">
                  <div class="form-group">
                    <label class="form-label" for="paypal_client_id">PayPal Client ID</label>
                    <input id="paypal_client_id" name="paypal_client_id" class="form-input" type="text" autocomplete="off" placeholder="PayPal client ID" value="<?= htmlspecialchars($paypalClientId) ?>">
                  </div>

                  <div class="form-group">
                    <label class="form-label" for="paypal_mode">PayPal Mode</label>
                    <select id="paypal_mode" name="paypal_mode" class="form-input">
                      <option value="live" <?= $paypalMode === 'live' ? 'selected' : '' ?>>Live</option>
                      <option value="sandbox" <?= $paypalMode === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    </select>
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="paypal_client_secret">PayPal Client Secret</label>
                  <input id="paypal_client_secret" name="paypal_client_secret" class="form-input" type="password" autocomplete="off" placeholder="PayPal client secret" value="<?= htmlspecialchars($paypalClientSecret) ?>">
                </div>

                <div class="form-group" style="margin-top:16px;">
                  <label class="form-label" for="paypal_webhook_id">PayPal Webhook ID</label>
                  <input id="paypal_webhook_id" name="paypal_webhook_id" class="form-input" type="text" autocomplete="off" placeholder="PayPal webhook ID" value="<?= htmlspecialchars($paypalWebhookId) ?>">
                  <div class="form-hint">Recommended for production so approved PayPal orders can be captured and finalized safely through webhook confirmation.</div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="paypal_webhook_url">PayPal Webhook URL</label>
                  <div class="flex gap-2">
                    <input id="paypal_webhook_url" class="form-input" type="text" readonly value="<?= htmlspecialchars($paypalWebhookUrl) ?>">
                    <button class="btn-secondary" type="button" onclick="copyCode(document.getElementById('paypal_webhook_url').value)">Copy</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-4">
              <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Payment Settings</button>
            </div>
          </form>
        </section>

        <section class="card">
          <div class="card-header-flex mb-4">
            <div>
              <div class="section-title">Social Login Apps</div>
              <div class="section-sub">These settings power “Continue with Google” and “Continue with Facebook”.</div>
            </div>
            <div class="flex gap-2">
              <span class="badge <?= $googleConfigured ? 'badge-green' : 'badge-muted' ?>">Google <?= $googleConfigured ? 'Configured' : 'Needs Setup' ?></span>
              <span class="badge <?= $facebookConfigured ? 'badge-green' : 'badge-muted' ?>">Facebook <?= $facebookConfigured ? 'Configured' : 'Needs Setup' ?></span>
            </div>
          </div>

          <form method="POST">
            <?= app_csrf_input() ?>
            <input type="hidden" name="form_section" value="social_login">

            <div class="integration-card" style="margin-bottom:18px;">
              <div class="integration-logo" style="background:#fff7ed;color:#ea580c;">
                <i class="fab fa-google"></i>
              </div>
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                  <span style="font-size:17px;font-weight:700;">Google Login</span>
                  <span><span class="status-dot <?= $googleConfigured ? 'dot-ok' : 'dot-off' ?>"></span><span class="text-sm <?= $googleConfigured ? '' : 'text-muted' ?>"><?= $googleConfigured ? 'Configured' : 'Not configured' ?></span></span>
                </div>
                <p class="text-sm text-muted" style="margin-bottom:16px;">
                  Create a Web application in Google Cloud and add the redirect URI shown below exactly as it appears.
                </p>

                <div class="form-group">
                  <label class="form-label" for="google_client_id">Google Client ID</label>
                  <input id="google_client_id" name="google_client_id" class="form-input" type="text" autocomplete="off" placeholder="xxxx.apps.googleusercontent.com" value="<?= htmlspecialchars($googleConfig['client_id']) ?>">
                </div>

                <div class="form-group">
                  <label class="form-label" for="google_client_secret">Google Client Secret</label>
                  <input id="google_client_secret" name="google_client_secret" class="form-input" type="password" autocomplete="off" placeholder="Google client secret" value="<?= htmlspecialchars($googleConfig['client_secret']) ?>">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="google_redirect_uri">Google Redirect URI</label>
                  <div class="flex gap-2">
                    <input id="google_redirect_uri" class="form-input" type="text" readonly value="<?= htmlspecialchars($googleRedirectUri) ?>">
                    <button class="btn-secondary" type="button" onclick="copyCode(document.getElementById('google_redirect_uri').value)">Copy</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="integration-card" style="margin-bottom:0;">
              <div class="integration-logo" style="background:#eff6ff;color:#1d4ed8;">
                <i class="fab fa-facebook-f"></i>
              </div>
              <div style="flex:1">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                  <span style="font-size:17px;font-weight:700;">Facebook Login</span>
                  <span><span class="status-dot <?= $facebookConfigured ? 'dot-ok' : 'dot-off' ?>"></span><span class="text-sm <?= $facebookConfigured ? '' : 'text-muted' ?>"><?= $facebookConfigured ? 'Configured' : 'Not configured' ?></span></span>
                </div>
                <p class="text-sm text-muted" style="margin-bottom:16px;">
                  Add the app ID and app secret from Facebook Developers, then make sure the app has the <strong>email</strong> permission enabled.
                </p>

                <div class="form-group">
                  <label class="form-label" for="facebook_app_id">Facebook App ID</label>
                  <input id="facebook_app_id" name="facebook_app_id" class="form-input" type="text" autocomplete="off" placeholder="Facebook app ID" value="<?= htmlspecialchars($facebookConfig['client_id']) ?>">
                </div>

                <div class="form-group">
                  <label class="form-label" for="facebook_app_secret">Facebook App Secret</label>
                  <input id="facebook_app_secret" name="facebook_app_secret" class="form-input" type="password" autocomplete="off" placeholder="Facebook app secret" value="<?= htmlspecialchars($facebookConfig['client_secret']) ?>">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                  <label class="form-label" for="facebook_redirect_uri">Facebook Redirect URI</label>
                  <div class="flex gap-2">
                    <input id="facebook_redirect_uri" class="form-input" type="text" readonly value="<?= htmlspecialchars($facebookRedirectUri) ?>">
                    <button class="btn-secondary" type="button" onclick="copyCode(document.getElementById('facebook_redirect_uri').value)">Copy</button>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-4">
              <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Social Login Settings</button>
            </div>
          </form>
        </section>
      </div>

      <div class="alert-card alert-purple mt-6">
        <div class="alert-title"><i class="fas fa-shield-halved"></i> How this works</div>
        <p class="alert-text" style="margin-bottom:6px;">
          Payment and social-login settings are stored in the website database so the new owner can replace testing credentials without editing PHP files.
        </p>
        <p class="alert-text">
          Google and Facebook accounts are also linked to dedicated user columns in the database, so returning social-login users can be matched safely by provider ID instead of relying only on email lookups.
        </p>
      </div>
    </div>
  </main>
</div>

<script src="assets/admin.js"></script>
</body>
</html>
