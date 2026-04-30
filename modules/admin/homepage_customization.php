<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/homepage_customization.php';

$current_page = 'homepage_customization';
$flash = '';
$homepageTabs = [
    'hero' => [
        'label' => 'Hero Section',
        'description' => 'Update the homepage banner image.',
        'icon' => 'fa-image',
    ],
    'collections' => [
        'label' => 'Shop by Collection',
        'description' => 'Manage the four collection cards.',
        'icon' => 'fa-layer-group',
    ],
    'journey' => [
        'label' => 'Follow Our Journey',
        'description' => 'Refresh the three gallery photos.',
        'icon' => 'fa-images',
    ],
    'logo' => [
        'label' => 'Header Logo',
        'description' => 'Change the logo used in the site header.',
        'icon' => 'fa-signature',
    ],
];

if (isset($_SESSION['homepage_customization_flash'])) {
    $flash = (string)$_SESSION['homepage_customization_flash'];
    unset($_SESSION['homepage_customization_flash']);
}

app_homepage_ensure_schema($conn);
$defaults = app_homepage_default_config_values();
$activeTab = (string)($_GET['tab'] ?? 'hero');
if (!isset($homepageTabs[$activeTab])) {
    $activeTab = 'hero';
}

$sectionUploadKeys = [
    'hero' => ['homepage_hero_image'],
    'collections' => [],
    'journey' => [
        'homepage_journey_1_image',
        'homepage_journey_2_image',
        'homepage_journey_3_image',
    ],
    'logo' => ['homepage_header_logo_path'],
];
$sectionMessages = [
    'hero' => 'Hero section updated.',
    'collections' => 'Shop by Collection updated.',
    'journey' => 'Follow Our Journey updated.',
    'logo' => 'Header logo updated.',
];
$emptyScopeMessages = [
    'all' => 'No new homepage changes were submitted.',
    'hero' => 'No new hero image was submitted.',
    'collections' => 'No new Shop by Collection changes were submitted.',
    'journey' => 'No new Follow Our Journey images were submitted.',
    'logo' => 'No new header logo was submitted.',
];

for ($i = 1; $i <= app_homepage_collection_count(); $i++) {
    $sectionUploadKeys['collections'][] = 'homepage_collection_' . $i . '_image';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');

    $saveScope = (string)($_POST['save_scope'] ?? 'all');
    if ($saveScope !== 'all' && !isset($homepageTabs[$saveScope])) {
        $saveScope = 'all';
    }

    $postedActiveTab = (string)($_POST['active_tab'] ?? $activeTab);
    if (!isset($homepageTabs[$postedActiveTab])) {
        $postedActiveTab = 'hero';
    }

    $redirectTab = $saveScope === 'all' ? $postedActiveTab : $saveScope;
    $currentConfig = [];
    foreach ($defaults as $configKey => $defaultValue) {
        $currentConfig[$configKey] = app_homepage_get_config_value($conn, $configKey, $defaultValue);
    }

    $sectionChanged = [
        'hero' => false,
        'collections' => false,
        'journey' => false,
        'logo' => false,
    ];
    $errors = [];

    if ($saveScope === 'all' || $saveScope === 'collections') {
        for ($i = 1; $i <= app_homepage_collection_count(); $i++) {
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

            if ($label !== $currentConfig[$labelKey]) {
                app_homepage_set_config_value($conn, $labelKey, $label);
                $sectionChanged['collections'] = true;
            }
            if ($link !== $currentConfig[$linkKey]) {
                app_homepage_set_config_value($conn, $linkKey, $link);
                $sectionChanged['collections'] = true;
            }
        }
    }

    foreach ($sectionUploadKeys as $sectionKey => $configKeys) {
        if ($saveScope !== 'all' && $saveScope !== $sectionKey) {
            continue;
        }

        foreach ($configKeys as $configKey) {
            $file = $_FILES[$configKey] ?? null;
            $fileError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($file === null || $fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                $savedPath = app_homepage_save_uploaded_asset($file, $configKey);
                app_homepage_set_config_value($conn, $configKey, $savedPath);
                if ($configKey === 'homepage_header_logo_path') {
                    app_homepage_set_config_value($conn, 'logo_path', $savedPath);
                }
                $sectionChanged[$sectionKey] = true;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    $messages = [];
    foreach ($sectionMessages as $sectionKey => $message) {
        if ($sectionChanged[$sectionKey]) {
            $messages[] = $message;
        }
    }

    if (!empty($errors)) {
        $combinedMessage = '';
        if (!empty($messages)) {
            $combinedMessage .= implode(' ', $messages) . ' ';
        }
        $combinedMessage .= implode(' ', $errors);
        $_SESSION['homepage_customization_flash'] = 'err:' . trim($combinedMessage);
    } elseif (!empty($messages)) {
        $_SESSION['homepage_customization_flash'] = 'ok:' . implode(' ', $messages);
    } else {
        $_SESSION['homepage_customization_flash'] = 'warn:' . ($emptyScopeMessages[$saveScope] ?? $emptyScopeMessages['all']);
    }

    header('Location: homepage_customization.php?' . http_build_query(['tab' => $redirectTab]));
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
  <title>Homepage Editor - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Homepage Editor</h1>
        <p>Work section by section, save only what you need, or batch multiple updates together with one save.</p>
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
        <div class="flash flash-<?= $flashClass ?>"><?= homepageCustomizationValue($flashMsg) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" class="homepage-customization-form">
        <?= app_csrf_input() ?>
        <input
          type="hidden"
          name="active_tab"
          id="homepage_active_tab"
          value="<?= homepageCustomizationValue($activeTab) ?>"
          data-active-tab-input="homepage-customization">

        <div class="card homepage-toolbar mb-6">
          <div>
            <div class="card-title homepage-toolbar-title">Save one section or save everything together</div>
            <p class="text-sm text-muted">Use the tabs below to keep the page compact. Section save buttons update only the area you are working on.</p>
          </div>

          <div class="homepage-toolbar-actions">
            <button type="submit" name="save_scope" value="all" class="btn-primary">
              <i class="fas fa-save"></i> Save all homepage changes
            </button>
            <a href="../../index.php" class="btn-secondary" target="_blank" rel="noopener">
              <i class="fas fa-arrow-up-right-from-square"></i> Preview Homepage
            </a>
          </div>
        </div>

        <div class="card mb-6">
          <div class="homepage-size-header">
            <div class="card-title" style="margin-bottom:6px;">Required Image Sizes</div>
            <p class="text-sm text-muted">Uploads still follow the same exact sizes, and every homepage image is auto-converted to WebP in the background.</p>
          </div>
          <div class="homepage-size-grid">
            <div class="homepage-size-card">
              <span class="homepage-size-name">Hero</span>
              <strong class="homepage-size-value">1920 x 600 px</strong>
            </div>
            <div class="homepage-size-card">
              <span class="homepage-size-name">Collections</span>
              <strong class="homepage-size-value">261 x 260 px</strong>
            </div>
            <div class="homepage-size-card">
              <span class="homepage-size-name">Journey</span>
              <strong class="homepage-size-value">361 x 260 px</strong>
            </div>
            <div class="homepage-size-card">
              <span class="homepage-size-name">Logo</span>
              <strong class="homepage-size-value">50 x 50 px</strong>
            </div>
          </div>
        </div>

        <div class="tab-nav homepage-tab-nav mb-6" data-tab-group="homepage-customization">
          <?php foreach ($homepageTabs as $tabKey => $tab): ?>
            <button
              type="button"
              class="tab-btn homepage-tab-btn <?= $activeTab === $tabKey ? 'active' : '' ?>"
              data-tab="homepage-panel-<?= $tabKey ?>"
              data-tab-key="<?= homepageCustomizationValue($tabKey) ?>"
              onclick="switchTab(this, 'homepage-customization')">
              <i class="fas <?= homepageCustomizationValue($tab['icon']) ?>"></i>
              <span><?= homepageCustomizationValue($tab['label']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>

        <section id="homepage-panel-hero" class="tab-content <?= $activeTab === 'hero' ? 'active' : '' ?>" data-tab-target="homepage-customization">
          <div class="card mb-6">
            <div class="homepage-panel-header">
              <div>
                <div class="homepage-panel-title">Hero Section</div>
                <p class="homepage-panel-copy">Change the homepage banner without scrolling past the other image groups. Use a full-width hero canvas and keep important text inside the centered safe area.</p>
              </div>

              <button type="submit" name="save_scope" value="hero" class="btn-secondary homepage-section-save">
                <i class="fas fa-floppy-disk"></i> Save Hero section
              </button>
            </div>

            <div class="homepage-upload-grid homepage-upload-grid-single">
              <div class="homepage-preview-card">
                <div class="homepage-preview-label">Current hero background</div>
                <img class="homepage-preview-image homepage-preview-image-hero"
                     src="<?= homepageCustomizationPreviewUrl($settings['hero_image']) ?>"
                     alt="Current hero background">
              </div>

              <div class="homepage-config-card homepage-config-card-simple">
                <div class="form-group">
                  <label class="form-label" for="homepage_hero_image">Upload hero image</label>
                  <input id="homepage_hero_image" name="homepage_hero_image" type="file" class="form-input" accept="image/*">
                  <div class="form-hint">Best fit: 1920x600 px. Keep important text/logo inside the centered 1172x600 safe area. Legacy 1172x600 banners are expanded automatically, and uploads are saved as WebP.</div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section id="homepage-panel-collections" class="tab-content <?= $activeTab === 'collections' ? 'active' : '' ?>" data-tab-target="homepage-customization">
          <div class="card mb-6">
            <div class="homepage-panel-header">
              <div>
                <div class="homepage-panel-title">Shop by Collection</div>
                <p class="homepage-panel-copy">Edit all 4 collection cards from one compact view.</p>
              </div>

              <button type="submit" name="save_scope" value="collections" class="btn-secondary homepage-section-save">
                <i class="fas fa-floppy-disk"></i> Save Collection section
              </button>
            </div>

            <div class="homepage-sections-grid homepage-collections-grid">
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

                  <div class="form-group homepage-form-group-last">
                    <label class="form-label" for="homepage_collection_<?= $itemNo ?>_image">Upload image</label>
                    <input id="homepage_collection_<?= $itemNo ?>_image"
                           name="homepage_collection_<?= $itemNo ?>_image"
                           type="file"
                           class="form-input"
                           accept="image/*">
                    <div class="form-hint">Exact size required: 261x260 px. Uploaded files are saved as WebP.</div>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section id="homepage-panel-journey" class="tab-content <?= $activeTab === 'journey' ? 'active' : '' ?>" data-tab-target="homepage-customization">
          <div class="card mb-6">
            <div class="homepage-panel-header">
              <div>
                <div class="homepage-panel-title">Follow Our Journey</div>
                <p class="homepage-panel-copy">Swap the three gallery images without opening a long stacked layout.</p>
              </div>

              <button type="submit" name="save_scope" value="journey" class="btn-secondary homepage-section-save">
                <i class="fas fa-floppy-disk"></i> Save Journey section
              </button>
            </div>

            <div class="homepage-sections-grid">
              <?php foreach ($settings['journey_images'] as $index => $journeyImage): ?>
                <?php $itemNo = $index + 1; ?>
                <section class="homepage-config-card">
                  <div class="homepage-preview-label">Journey photo <?= $itemNo ?></div>
                  <img class="homepage-preview-image homepage-preview-image-journey"
                       src="<?= homepageCustomizationPreviewUrl($journeyImage) ?>"
                       alt="Journey photo <?= $itemNo ?> preview">

                  <div class="form-group homepage-form-group-last">
                    <label class="form-label" for="homepage_journey_<?= $itemNo ?>_image">Upload image</label>
                    <input id="homepage_journey_<?= $itemNo ?>_image"
                           name="homepage_journey_<?= $itemNo ?>_image"
                           type="file"
                           class="form-input"
                           accept="image/*">
                    <div class="form-hint">Exact size required: 361x260 px. Uploaded files are saved as WebP.</div>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section id="homepage-panel-logo" class="tab-content <?= $activeTab === 'logo' ? 'active' : '' ?>" data-tab-target="homepage-customization">
          <div class="card mb-6">
            <div class="homepage-panel-header">
              <div>
                <div class="homepage-panel-title">Header Logo</div>
                <p class="homepage-panel-copy">Update the logo used in the site header and keep the rest of the homepage settings untouched.</p>
              </div>

              <button type="submit" name="save_scope" value="logo" class="btn-secondary homepage-section-save">
                <i class="fas fa-floppy-disk"></i> Save Header Logo
              </button>
            </div>

            <div class="homepage-upload-grid homepage-upload-grid-single">
              <div class="homepage-preview-card homepage-preview-card-logo">
                <div class="homepage-preview-label">Current header logo</div>
                <?php if ($settings['header_logo_path'] !== ''): ?>
                  <img class="homepage-preview-image homepage-preview-image-logo"
                       src="<?= homepageCustomizationPreviewUrl($settings['header_logo_path']) ?>"
                       alt="Current header logo">
                <?php else: ?>
                  <div class="homepage-logo-fallback">CA</div>
                <?php endif; ?>
              </div>

              <div class="homepage-config-card homepage-config-card-simple">
                <div class="form-group">
                  <label class="form-label" for="homepage_header_logo_path">Upload header logo</label>
                  <input id="homepage_header_logo_path" name="homepage_header_logo_path" type="file" class="form-input" accept="image/*">
                  <div class="form-hint">Exact size required: 50x50 px. Uploaded files are saved as WebP.</div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </form>
    </div>
  </main>
</div>
<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
</body>
</html>
