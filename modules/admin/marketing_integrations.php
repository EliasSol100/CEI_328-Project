<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'marketing_integrations';
$flash = '';

/* ── Handle POST: save integration settings ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $platform     = $_POST['platform']     ?? '';
    $apiKey       = trim($_POST['apiKey']  ?? '');
    $listID       = trim($_POST['listID']  ?? '');
    $serverPrefix = trim($_POST['serverPrefix'] ?? '');
    $isConnected  = empty($apiKey) ? 0 : 1;

    $stmt = mysqli_prepare($conn,
        "INSERT INTO marketing_integrations (platform, apiKey, listID, serverPrefix, isConnected)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE apiKey=?, listID=?, serverPrefix=?, isConnected=?");
    mysqli_stmt_bind_param($stmt, 'ssssisssi',
        $platform, $apiKey, $listID, $serverPrefix, $isConnected,
        $apiKey, $listID, $serverPrefix, $isConnected);
    mysqli_stmt_execute($stmt);

    $flash = $isConnected ? 'ok:Settings saved. Connection will be verified on next sync.' : 'ok:Settings cleared.';
    header('Location: marketing_integrations.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load integrations ── */
$integrations = [];
$r = mysqli_query($conn, "SELECT * FROM marketing_integrations ORDER BY platform");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $integrations[$row['platform']] = $row; }

// Ensure both platforms always show
foreach (['Mailchimp','Klaviyo'] as $p) {
    if (!isset($integrations[$p])) {
        $integrations[$p] = ['platform'=>$p,'apiKey'=>'','listID'=>'','serverPrefix'=>'','isConnected'=>0,'lastSyncAt'=>null,'subscriberCount'=>0];
    }
}

$editPlatform = $_GET['setup'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Marketing Integrations – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Marketing Integrations</h1>
        <p>Connect and manage your email marketing platforms.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Mailchimp ── -->
      <?php $mc = $integrations['Mailchimp']; ?>
      <div class="integration-card mb-6">
        <div class="integration-logo logo-mailchimp">
          <i class="fas fa-envelope"></i>
        </div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
            <span style="font-size:17px;font-weight:700">Mailchimp</span>
            <?php if ($mc['isConnected']): ?>
              <span><span class="status-dot dot-ok"></span><span class="text-sm" style="color:#059669">Connected</span></span>
            <?php else: ?>
              <span><span class="status-dot dot-off"></span><span class="text-sm text-muted">Not connected</span></span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-muted" style="margin-bottom:12px">
            Connect Mailchimp to sync your customer list and send email campaigns directly from your store.
          </p>

          <?php if ($mc['isConnected']): ?>
          <div class="grid-3 mb-4" style="gap:12px">
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">Subscribers</div>
              <div class="stat-val" style="font-size:20px"><?= number_format((int)$mc['subscriberCount']) ?></div>
            </div>
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">List ID</div>
              <div style="font-size:13px;font-weight:600;margin-top:8px"><?= htmlspecialchars($mc['listID'] ?: '—') ?></div>
            </div>
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">Last Sync</div>
              <div style="font-size:13px;font-weight:600;margin-top:8px"><?= $mc['lastSyncAt'] ? date('n/j/Y', strtotime($mc['lastSyncAt'])) : 'Never' ?></div>
            </div>
          </div>
          <?php endif; ?>

          <button class="btn-secondary" onclick="openModal('modalMailchimp')">
            <i class="fas fa-gear"></i> <?= $mc['isConnected'] ? 'Update Settings' : 'Set Up Mailchimp' ?>
          </button>
          <?php if ($mc['isConnected']): ?>
            <button class="btn-delete" style="margin-left:8px;padding:9px 12px" onclick="if(confirm('Disconnect Mailchimp?')){
              document.getElementById('clearMailchimp').submit();}">
              <i class="fas fa-unlink"></i> Disconnect
            </button>
            <form id="clearMailchimp" method="POST" style="display:none">
              <input type="hidden" name="platform" value="Mailchimp">
              <input type="hidden" name="apiKey" value="">
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Klaviyo ── -->
      <?php $kl = $integrations['Klaviyo']; ?>
      <div class="integration-card mb-6">
        <div class="integration-logo logo-klaviyo">
          <i class="fas fa-chart-line"></i>
        </div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
            <span style="font-size:17px;font-weight:700">Klaviyo</span>
            <?php if ($kl['isConnected']): ?>
              <span><span class="status-dot dot-ok"></span><span class="text-sm" style="color:#059669">Connected</span></span>
            <?php else: ?>
              <span><span class="status-dot dot-off"></span><span class="text-sm text-muted">Not connected</span></span>
            <?php endif; ?>
          </div>
          <p class="text-sm text-muted" style="margin-bottom:12px">
            Connect Klaviyo for advanced email automation, abandoned cart flows, and personalised product recommendations.
          </p>

          <?php if ($kl['isConnected']): ?>
          <div class="grid-3 mb-4" style="gap:12px">
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">Profiles</div>
              <div class="stat-val" style="font-size:20px"><?= number_format((int)$kl['subscriberCount']) ?></div>
            </div>
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">List ID</div>
              <div style="font-size:13px;font-weight:600;margin-top:8px"><?= htmlspecialchars($kl['listID'] ?: '—') ?></div>
            </div>
            <div class="stat-card" style="padding:14px">
              <div class="stat-header" style="font-size:12px">Last Sync</div>
              <div style="font-size:13px;font-weight:600;margin-top:8px"><?= $kl['lastSyncAt'] ? date('n/j/Y', strtotime($kl['lastSyncAt'])) : 'Never' ?></div>
            </div>
          </div>
          <?php endif; ?>

          <button class="btn-secondary" onclick="openModal('modalKlaviyo')">
            <i class="fas fa-gear"></i> <?= $kl['isConnected'] ? 'Update Settings' : 'Set Up Klaviyo' ?>
          </button>
          <?php if ($kl['isConnected']): ?>
            <button class="btn-delete" style="margin-left:8px;padding:9px 12px" onclick="if(confirm('Disconnect Klaviyo?')){
              document.getElementById('clearKlaviyo').submit();}">
              <i class="fas fa-unlink"></i> Disconnect
            </button>
            <form id="clearKlaviyo" method="POST" style="display:none">
              <input type="hidden" name="platform" value="Klaviyo">
              <input type="hidden" name="apiKey" value="">
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Info card ── -->
      <div class="alert-card alert-blue">
        <div class="alert-title"><i class="fas fa-circle-info"></i> Integration Tips</div>
        <p class="alert-text" style="margin-bottom:6px">
          <strong>Mailchimp:</strong> Find your API key under Account → Extras → API Keys. Your List ID is under Audience → Settings.
        </p>
        <p class="alert-text">
          <strong>Klaviyo:</strong> Find your Private API key under Account → Settings → API Keys. Create a new key with full access.
        </p>
      </div>

    </div>
  </main>
</div>

<!-- ── Mailchimp Modal ── -->
<div class="modal-overlay" id="modalMailchimp">
  <div class="modal-box">
    <h3>Mailchimp Settings</h3>
    <p class="modal-sub">Enter your Mailchimp credentials to connect your store.</p>
    <form method="POST">
      <input type="hidden" name="platform" value="Mailchimp">
      <div class="form-group">
        <label class="form-label">API Key *</label>
        <input name="apiKey" class="form-input" type="password"
               placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us1"
               value="<?= htmlspecialchars($mc['apiKey'] ?? '') ?>">
        <span class="form-hint">Found in Mailchimp → Account → Extras → API Keys</span>
      </div>
      <div class="form-group">
        <label class="form-label">Server Prefix</label>
        <input name="serverPrefix" class="form-input" placeholder="us1"
               value="<?= htmlspecialchars($mc['serverPrefix'] ?? '') ?>">
        <span class="form-hint">The last part of your API key, e.g. "us1"</span>
      </div>
      <div class="form-group">
        <label class="form-label">Audience / List ID</label>
        <input name="listID" class="form-input" placeholder="abc12345"
               value="<?= htmlspecialchars($mc['listID'] ?? '') ?>">
        <span class="form-hint">Found in Audience → Settings → Audience name & defaults</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalMailchimp')">Cancel</button>
        <button type="submit" class="btn-save">Save Connection</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Klaviyo Modal ── -->
<div class="modal-overlay" id="modalKlaviyo">
  <div class="modal-box">
    <h3>Klaviyo Settings</h3>
    <p class="modal-sub">Enter your Klaviyo credentials to connect your store.</p>
    <form method="POST">
      <input type="hidden" name="platform" value="Klaviyo">
      <div class="form-group">
        <label class="form-label">Private API Key *</label>
        <input name="apiKey" class="form-input" type="password"
               placeholder="pk_xxxxxxxxxxxxxxxxxxxxxxxx"
               value="<?= htmlspecialchars($kl['apiKey'] ?? '') ?>">
        <span class="form-hint">Found in Klaviyo → Account → Settings → API Keys</span>
      </div>
      <div class="form-group">
        <label class="form-label">List ID</label>
        <input name="listID" class="form-input" placeholder="AbCdEf"
               value="<?= htmlspecialchars($kl['listID'] ?? '') ?>">
        <span class="form-hint">The ID of the Klaviyo list you want to sync subscribers to</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalKlaviyo')">Cancel</button>
        <button type="submit" class="btn-save">Save Connection</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/admin.js"></script>
</body>
</html>
