<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/homepage_customization.php';

$current_page = 'homepage_customization';
$flash = '';

if (isset($_SESSION['homepage_customization_flash'])) {
    $flash = (string)$_SESSION['homepage_customization_flash'];
    unset($_SESSION['homepage_customization_flash']);
}

app_homepage_ensure_schema($conn);
$defaults = app_homepage_default_config_values();
$uploadSpecs = app_homepage_upload_specs();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $messages = [];
    $errors = [];

    for ($i = 1; $i <= 3; $i++) {
        $labelKey = 'homepage_collection_' . $i . '_label';
        $linkKey = 'homepage_collection_' . $i . '_link';

        $label = trim((string)($_POST[$labelKey] ?? ''));
        $link = trim((string)($_POST[$linkKey] ?? ''));

        if ($label === '') {
            $label = $defaults[$labelKey];
        }
        if ($link === '') {
            $link = $defaults[$linkKey];
        }

        app_homepage_set_config_value($conn, $labelKey, $label);
        app_homepage_set_config_value($conn, $linkKey, $link);
    }
    $messages[] = 'Collection labels and links updated.';

    foreach ($uploadSpecs as $configKey => $spec) {
        $file = $_FILES[$configKey] ?? null;
        $fileError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($file === null || $fileError === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        try {
            $savedPath = app_homepage_save_uploaded_asset($file, $configKey);
            app_homepage_set_config_value($conn, $configKey, $savedPath);
            $messages[] = $spec['label'] . ' updated.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $combinedMessage = '';
        if (!empty($messages)) {
            $combinedMessage .= implode(' ', $messages) . ' ';
        }
        $combinedMessage .= implode(' ', $errors);
        $_SESSION['homepage_customization_flash'] = 'err:' . trim($combinedMessage);
    } else {
        $_SESSION['homepage_customization_flash'] = 'ok:' . implode(' ', $messages);
    }

    header('Location: homepage_customization.php');
    exit;
}

$settings = app_homepage_load_settings($conn);

function homepageCustomizationPreviewUrl(string $path): string
{
    return htmlspecialchars(app_homepage_asset_url($path, '../../'), ENT_QUOTES, 'UTF-8');
}

function homepageCustomizationValue(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Home Page Customization - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Home Page Customization</h1>
        <p>Upload the homepage images, control the collection titles, and keep every asset locked to the required dimensions.</p>
      </div>
    </div>

    <div class="content-body">
      <?php if ($flash !== ''): ?>
        <?php [$flashType, $flashMsg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $flashType === 'ok' ? 'success' : 'error' ?>"><?= homepageCustomizationValue($flashMsg) ?></div>
      <?php endif; ?>

      <div class="alert-card alert-blue mb-6">
        <div class="alert-title"><i class="fas fa-ruler-combined"></i> Required Image Sizes</div>
        <p class="alert-text">Hero section: 1172x600 px. Shop by Collection: 261x260 px for 3 photos. Follow Our Journey: 361x260 px for 3 photos. Header logo: 50x50 px.</p>
      </div>

      <form method="POST" enctype="multipart/form-data" class="homepage-customization-form">
        <?= app_csrf_input() ?>

        <div class="card mb-6">
          <div class="card-title">Hero Section</div>
          <div class="homepage-upload-grid homepage-upload-grid-single">
            <div class="homepage-preview-card">
              <div class="homepage-preview-label">Current hero background</div>
              <img class="homepage-preview-image homepage-preview-image-hero"
                   src="<?= homepageCustomizationPreviewUrl($settings['hero_image']) ?>"
                   alt="Current hero background">
            </div>

            <div class="form-group">
              <label class="form-label" for="homepage_hero_image">Upload hero image</label>
              <input id="homepage_hero_image" name="homepage_hero_image" type="file" class="form-input" accept="image/*">
              <div class="form-hint">Exact size required: 1172x600 px.</div>
            </div>
          </div>
        </div>

        <div class="card mb-6">
          <div class="card-title">Shop by Collection</div>
          <p class="text-sm text-muted mb-4">The homepage now shows 3 collection cards. You can change each image and the text displayed on top of it.</p>

          <div class="homepage-sections-grid">
            <?php foreach ($settings['collections'] as $index => $collection): ?>
              <?php $itemNo = $index + 1; ?>
              <section class="homepage-config-card">
                <div class="homepage-preview-label">Collection <?= $itemNo ?></div>
                <img class="homepage-preview-image homepage-preview-image-collection"
                     src="<?= homepageCustomizationPreviewUrl($collection['image']) ?>"
                     alt="Collection <?= $itemNo ?> preview">

                <div class="form-group">
                  <label class="form-label" for="homepage_collection_<?= $itemNo ?>_label">Card label</label>
                  <input id="homepage_collection_<?= $itemNo ?>_label"
                         name="homepage_collection_<?= $itemNo ?>_label"
                         class="form-input"
                         value="<?= homepageCustomizationValue($collection['label']) ?>">
                </div>

                <div class="form-group">
                  <label class="form-label" for="homepage_collection_<?= $itemNo ?>_link">Card link</label>
                  <input id="homepage_collection_<?= $itemNo ?>_link"
                         name="homepage_collection_<?= $itemNo ?>_link"
                         class="form-input"
                         value="<?= homepageCustomizationValue($collection['link']) ?>">
                  <div class="form-hint">Example: shop.php?category=dragon</div>
                </div>

                <div class="form-group">
                  <label class="form-label" for="homepage_collection_<?= $itemNo ?>_image">Upload image</label>
                  <input id="homepage_collection_<?= $itemNo ?>_image"
                         name="homepage_collection_<?= $itemNo ?>_image"
                         type="file"
                         class="form-input"
                         accept="image/*">
                  <div class="form-hint">Exact size required: 261x260 px.</div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card mb-6">
          <div class="card-title">Follow Our Journey</div>
          <p class="text-sm text-muted mb-4">The storefront grid is reduced to 3 images. Upload each slot below.</p>

          <div class="homepage-sections-grid">
            <?php foreach ($settings['journey_images'] as $index => $journeyImage): ?>
              <?php $itemNo = $index + 1; ?>
              <section class="homepage-config-card">
                <div class="homepage-preview-label">Journey photo <?= $itemNo ?></div>
                <img class="homepage-preview-image homepage-preview-image-journey"
                     src="<?= homepageCustomizationPreviewUrl($journeyImage) ?>"
                     alt="Journey photo <?= $itemNo ?> preview">

                <div class="form-group" style="margin-bottom:0">
                  <label class="form-label" for="homepage_journey_<?= $itemNo ?>_image">Upload image</label>
                  <input id="homepage_journey_<?= $itemNo ?>_image"
                         name="homepage_journey_<?= $itemNo ?>_image"
                         type="file"
                         class="form-input"
                         accept="image/*">
                  <div class="form-hint">Exact size required: 361x260 px.</div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card mb-6">
          <div class="card-title">Header Logo</div>
          <div class="homepage-upload-grid homepage-upload-grid-single">
            <div class="homepage-preview-card">
              <div class="homepage-preview-label">Current header logo</div>
              <?php if ($settings['header_logo_path'] !== ''): ?>
                <img class="homepage-preview-image homepage-preview-image-logo"
                     src="<?= homepageCustomizationPreviewUrl($settings['header_logo_path']) ?>"
                     alt="Current header logo">
              <?php else: ?>
                <div class="homepage-logo-fallback">CA</div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="form-label" for="homepage_header_logo_path">Upload header logo</label>
              <input id="homepage_header_logo_path" name="homepage_header_logo_path" type="file" class="form-input" accept="image/*">
              <div class="form-hint">Exact size required: 50x50 px.</div>
            </div>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <button type="submit" class="btn-primary">
            <i class="fas fa-save"></i> Save Home Page Settings
          </button>
          <a href="../../index.php" class="btn-secondary" target="_blank" rel="noopener">
            <i class="fas fa-arrow-up-right-from-square"></i> Preview Homepage
          </a>
        </div>
      </form>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
