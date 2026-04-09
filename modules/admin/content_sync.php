<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/content_sync.php';

$current_page = 'content_sync';
$flash = '';

if (isset($_SESSION['content_sync_flash'])) {
    $flash = (string)$_SESSION['content_sync_flash'];
    unset($_SESSION['content_sync_flash']);
}

function contentSyncValue(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function contentSyncFlashMessage(string $type, array $messages): string
{
    return $type . ':' . implode(' ', array_filter($messages, static function ($message): bool {
        return trim((string)$message) !== '';
    }));
}

function contentSyncBadgeClass(string $status): string
{
    if ($status === 'ok') {
        return 'badge-green';
    }
    if ($status === 'warning') {
        return 'badge-warning';
    }
    return 'badge-red';
}

function contentSyncStatusLabel(string $status): string
{
    if ($status === 'ok') {
        return 'Ready';
    }
    if ($status === 'warning') {
        return 'Auto Fix';
    }
    return 'Missing';
}

$selectedScopes = ['homepage', 'shop'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $action = trim((string)($_POST['action'] ?? ''));
    $selectedScopes = app_content_sync_normalize_scopes((array)($_POST['scopes'] ?? []));

    try {
        if ($action === 'download_snapshot' || $action === 'save_repo_snapshot') {
            $result = app_content_sync_build_snapshot($conn, $selectedScopes);
            $snapshot = $result['snapshot'];
            $warnings = $result['warnings'];

            if ($action === 'download_snapshot') {
                $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json) || $json === '') {
                    throw new RuntimeException('Could not encode the content sync snapshot.');
                }

                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . app_content_sync_snapshot_filename($selectedScopes) . '"');
                header('X-Content-Type-Options: nosniff');
                echo $json;
                exit;
            }

            $relativePath = app_content_sync_write_snapshot_file($snapshot);
            $messages = ['Repo sync snapshot updated at ' . $relativePath . '.'];
            if (!empty($warnings)) {
                $messages[] = 'Warnings: ' . count($warnings) . '.';
            }
            $_SESSION['content_sync_flash'] = contentSyncFlashMessage(!empty($warnings) ? 'warn' : 'ok', $messages);
            header('Location: content_sync.php');
            exit;
        }

        if ($action === 'import_repo_snapshot' || $action === 'import_uploaded_snapshot') {
            $readiness = app_content_sync_database_readiness($conn);
            if (empty($readiness['ready'])) {
                throw new RuntimeException('This local database is not ready for content sync imports yet. Import the base project SQL on this machine first.');
            }

            if ($action === 'import_repo_snapshot') {
                $snapshot = app_content_sync_load_snapshot_file(app_content_sync_snapshot_absolute_path());
            } else {
                $file = $_FILES['snapshot_file'] ?? null;
                $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($file === null || $error !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Please choose a valid content sync snapshot file.');
                }
                if ((int)($file['size'] ?? 0) > 50 * 1024 * 1024) {
                    throw new InvalidArgumentException('The content sync snapshot file must be 50MB or smaller.');
                }

                $json = file_get_contents((string)($file['tmp_name'] ?? ''));
                if (!is_string($json) || $json === '') {
                    throw new InvalidArgumentException('The uploaded content sync snapshot could not be read.');
                }
                $snapshot = app_content_sync_parse_snapshot_json($json);
            }

            $result = app_content_sync_import_snapshot($conn, $snapshot, $selectedScopes);
            $messages = $result['messages'];
            $warnings = $result['warnings'];
            if (empty($messages)) {
                $messages[] = 'No content sync changes were imported.';
            }

            $_SESSION['content_sync_flash'] = contentSyncFlashMessage(!empty($warnings) ? 'warn' : 'ok', array_merge($messages, !empty($warnings) ? ['Warnings: ' . count($warnings) . '.'] : []));
            header('Location: content_sync.php');
            exit;
        }

        $_SESSION['content_sync_flash'] = 'err:Unknown content sync action.';
        header('Location: content_sync.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['content_sync_flash'] = 'err:' . $e->getMessage();
        header('Location: content_sync.php');
        exit;
    }
}

$databaseReadiness = app_content_sync_database_readiness($conn);
$canImportSnapshots = !empty($databaseReadiness['ready']);
$repoSnapshotPath = app_content_sync_snapshot_absolute_path();
$repoSnapshotExists = is_file($repoSnapshotPath);
$repoSnapshotMeta = null;
$repoSnapshotSize = 0;
$repoSnapshotModified = '';
if ($repoSnapshotExists) {
    $repoSnapshotSize = (int)filesize($repoSnapshotPath);
    $repoSnapshotModified = date('Y-m-d H:i:s', (int)filemtime($repoSnapshotPath));
    try {
        $repoSnapshot = app_content_sync_load_snapshot_file($repoSnapshotPath);
        $repoSnapshotMeta = is_array($repoSnapshot['meta'] ?? null) ? $repoSnapshot['meta'] : null;
    } catch (Throwable $e) {
        $repoSnapshotMeta = ['warning' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Content Sync - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Content Sync</h1>
        <p>Share homepage settings, shop products, and synced images safely between different local machines.</p>
      </div>
    </div>

    <div class="content-body">
      <?php if ($flash !== ''): ?>
        <?php [$flashType, $flashMsg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <?php
          $flashClass = 'error';
          if ($flashType === 'ok') {
              $flashClass = 'success';
          } elseif ($flashType === 'warn') {
              $flashClass = 'warning';
          }
        ?>
        <div class="flash flash-<?= $flashClass ?>"><?= contentSyncValue($flashMsg) ?></div>
      <?php endif; ?>

      <div class="alert-card alert-blue mb-6">
        <div class="alert-title"><i class="fas fa-circle-info"></i> What This Sync Includes</div>
        <p class="alert-text">Homepage sync includes hero, collection, journey, and header logo settings plus uploaded homepage image files. Shop sync includes products, main product photos stored in the database, and colour-specific shop photos stored on disk.</p>
      </div>

      <div class="card mb-6 sync-readiness-card">
        <div class="sync-readiness-top">
          <div>
            <div class="card-title" style="margin-bottom:6px;">Database Readiness Check</div>
            <p class="section-sub" style="margin-bottom:0;">This checks whether the local database already has the core tables and columns needed before you import a snapshot.</p>
          </div>
          <span class="badge <?= contentSyncBadgeClass((string)$databaseReadiness['status']) ?>">
            <?= contentSyncValue($databaseReadiness['status'] === 'ok' ? 'Ready for Import' : ($databaseReadiness['status'] === 'warning' ? 'Ready with Auto Fixes' : 'Not Ready Yet')) ?>
          </span>
        </div>

        <div class="sync-readiness-summary sync-readiness-summary-<?= contentSyncValue((string)$databaseReadiness['status']) ?>">
          <?= contentSyncValue((string)$databaseReadiness['summary']) ?>
        </div>

        <div class="sync-readiness-meta">
          <div><span>Required issues</span><strong><?= contentSyncValue((string)count((array)$databaseReadiness['missing_required'])) ?></strong></div>
          <div><span>Auto-fix items</span><strong><?= contentSyncValue((string)count((array)$databaseReadiness['missing_autofix'])) ?></strong></div>
        </div>

        <div class="sync-readiness-list">
          <?php foreach ($databaseReadiness['checks'] as $check): ?>
            <div class="sync-readiness-item">
              <div class="sync-readiness-main">
                <strong><?= contentSyncValue((string)$check['label']) ?></strong>
                <small><?= contentSyncValue((string)$check['detail']) ?></small>
              </div>
              <span class="badge <?= contentSyncBadgeClass((string)$check['status']) ?>">
                <?= contentSyncValue(contentSyncStatusLabel((string)$check['status'])) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid-2">
        <div class="card mb-6">
          <div class="card-title">1. Create a Sync Snapshot</div>
          <p class="section-sub">Choose which content to package, then either save the snapshot inside the repo for GitHub or download it for manual sharing.</p>

          <form method="POST" class="sync-form-block">
            <?= app_csrf_input() ?>

            <div class="sync-scope-grid">
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="homepage" checked>
                <span>
                  <strong>Homepage</strong>
                  <small>Settings + homepage uploaded images</small>
                </span>
              </label>
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="shop" checked>
                <span>
                  <strong>Shop</strong>
                  <small>Products + main photos + colour photos</small>
                </span>
              </label>
            </div>

            <div class="sync-action-row">
              <button type="submit" name="action" value="save_repo_snapshot" class="btn-primary">
                <i class="fas fa-code-branch"></i> Update Repo Snapshot
              </button>
              <button type="submit" name="action" value="download_snapshot" class="btn-secondary">
                <i class="fas fa-download"></i> Download Snapshot
              </button>
            </div>
          </form>
        </div>

        <div class="card mb-6">
          <div class="card-title">2. Import the Repo Snapshot</div>
          <p class="section-sub">After a teammate commits and pushes the snapshot JSON, pull the repo here and import it into your local database and assets.</p>

          <div class="sync-meta-list">
            <div><span>Repo file</span><strong><?= contentSyncValue(app_content_sync_snapshot_relative_path()) ?></strong></div>
            <div><span>Status</span><strong><?= $repoSnapshotExists ? 'Available' : 'Not created yet' ?></strong></div>
            <div><span>Last updated</span><strong><?= $repoSnapshotModified !== '' ? contentSyncValue($repoSnapshotModified) : '—' ?></strong></div>
            <div><span>File size</span><strong><?= $repoSnapshotExists ? contentSyncValue(number_format($repoSnapshotSize / 1024, 1) . ' KB') : '—' ?></strong></div>
            <div><span>Scopes</span><strong><?= !empty($repoSnapshotMeta['scopes']) && is_array($repoSnapshotMeta['scopes']) ? contentSyncValue(implode(', ', $repoSnapshotMeta['scopes'])) : '—' ?></strong></div>
            <div><span>Snapshot generated</span><strong><?= isset($repoSnapshotMeta['generated_at']) ? contentSyncValue((string)$repoSnapshotMeta['generated_at']) : '—' ?></strong></div>
          </div>

          <?php if (isset($repoSnapshotMeta['warning'])): ?>
            <div class="flash flash-warning" style="margin-top:16px"><?= contentSyncValue((string)$repoSnapshotMeta['warning']) ?></div>
          <?php endif; ?>

          <form method="POST" class="sync-form-block">
            <?= app_csrf_input() ?>

            <div class="sync-scope-grid">
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="homepage" checked>
                <span>
                  <strong>Import Homepage</strong>
                  <small>Hero, collections, journey, logo</small>
                </span>
              </label>
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="shop" checked>
                <span>
                  <strong>Import Shop</strong>
                  <small>Products and shop images</small>
                </span>
              </label>
            </div>

            <button type="submit" name="action" value="import_repo_snapshot" class="btn-primary" <?= ($repoSnapshotExists && $canImportSnapshots) ? '' : 'disabled' ?>>
              <i class="fas fa-rotate"></i> Import Repo Snapshot
            </button>
            <?php if (!$canImportSnapshots): ?>
              <div class="form-hint">Resolve the database readiness issues above before importing any snapshot.</div>
            <?php elseif (!$repoSnapshotExists): ?>
              <div class="form-hint">Create or pull the repo snapshot JSON first, then import it here.</div>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <div class="grid-2">
        <div class="card mb-6">
          <div class="card-title">3. Import an Uploaded Snapshot</div>
          <p class="section-sub">Use this when a teammate sends you the JSON file directly instead of pushing it through GitHub.</p>

          <form method="POST" enctype="multipart/form-data" class="sync-form-block">
            <?= app_csrf_input() ?>

            <div class="form-group">
              <label class="form-label" for="snapshot_file">Snapshot file</label>
              <input id="snapshot_file" name="snapshot_file" type="file" class="form-input" accept="application/json,.json" required>
              <div class="form-hint">Maximum upload size for this admin import is 50MB.</div>
            </div>

            <div class="sync-scope-grid">
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="homepage" checked>
                <span>
                  <strong>Homepage</strong>
                  <small>Import homepage settings and uploaded images</small>
                </span>
              </label>
              <label class="sync-scope-card">
                <input type="checkbox" name="scopes[]" value="shop" checked>
                <span>
                  <strong>Shop</strong>
                  <small>Import products and shop image content</small>
                </span>
              </label>
            </div>

            <button type="submit" name="action" value="import_uploaded_snapshot" class="btn-primary" <?= $canImportSnapshots ? '' : 'disabled' ?>>
              <i class="fas fa-file-import"></i> Import Uploaded Snapshot
            </button>
            <?php if (!$canImportSnapshots): ?>
              <div class="form-hint">Resolve the database readiness issues above before importing an uploaded snapshot.</div>
            <?php endif; ?>
          </form>
        </div>

        <div class="card mb-6">
          <div class="card-title">Team Workflow</div>
          <div class="sync-step-list">
            <div><strong>1.</strong><span>On the source machine, update homepage or shop content from the admin dashboard.</span></div>
            <div><strong>2.</strong><span>Open Content Sync and click <em>Update Repo Snapshot</em>.</span></div>
            <div><strong>3.</strong><span>Commit and push <code>sync/content-sync/latest-content-sync.json</code> to GitHub.</span></div>
            <div><strong>4.</strong><span>On another local machine, pull the repo and click <em>Import Repo Snapshot</em>.</span></div>
            <div><strong>5.</strong><span>Everyone on that machine now sees the same homepage and shop content. Admins can still be the only ones making the changes.</span></div>
          </div>

          <div class="alert-card alert-purple" style="margin-top:16px">
            <div class="alert-title"><i class="fas fa-shield-halved"></i> Safety Note</div>
            <p class="alert-text">This sync updates homepage settings and shop catalog content only. It does not touch orders, customers, analytics, or other admin data.</p>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
