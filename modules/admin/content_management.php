<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/../../include/security.php';
require_once __DIR__ . '/../../include/website_content_settings.php';
require_once __DIR__ . '/../../include/custom_order_settings.php';
require_once __DIR__ . '/../../include/shop_filter_settings.php';

$current_page = 'content_management';
$flash = '';

function cm_normalize_tab(string $value): string
{
    return in_array($value, ['home', 'shop', 'custom_orders', 'about', 'contact'], true) ? $value : 'home';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    app_require_csrf(false, 'Invalid request token. Please refresh and try again.');
    $action = (string)($_POST['action'] ?? '');
    $activeTab = cm_normalize_tab((string)($_POST['active_tab'] ?? 'home'));

    if ($action === 'save_content_management') {
        $contentInput = is_array($_POST['content'] ?? null) ? $_POST['content'] : [];
        $stepsInput = is_array($_POST['steps'] ?? null) ? $_POST['steps'] : [];
        $shopFiltersInput = is_array($_POST['shop_filters'] ?? null) ? $_POST['shop_filters'] : [];
        $contentSaved = app_website_content_save($conn, $contentInput);
        $stepsSaved = app_custom_order_save_steps($conn, $stepsInput);
        $shopFiltersSaved = app_shop_filter_save($conn, $shopFiltersInput);
        $flash = ($contentSaved && $stepsSaved && $shopFiltersSaved)
            ? 'ok:Website content updated.'
            : 'err:Could not update all website content. Please try again.';
    }

    header('Location: content_management.php?tab=' . urlencode($activeTab) . '&flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) {
    $flash = (string)$_GET['flash'];
}
$activeTab = cm_normalize_tab((string)($_GET['tab'] ?? 'home'));

$websiteContent = app_website_content_settings($conn);
$contentDefaults = app_website_content_defaults();
$homeHero = $websiteContent['home']['hero'] ?? $contentDefaults['home']['hero'];
$aboutStory = $websiteContent['about']['story'] ?? $contentDefaults['about']['story'];
$aboutValues = $websiteContent['about']['values'] ?? $contentDefaults['about']['values'];
$contactContent = $websiteContent['contact'] ?? $contentDefaults['contact'];
$customOrderSteps = app_custom_order_steps($conn);
$shopFilterSettings = app_shop_filter_settings($conn);
$shopFilterProducts = app_shop_filter_admin_products($conn);

function cm_field(string $name, string $value = '', string $type = 'text', bool $required = true): void
{
    $requiredAttr = $required ? ' required' : '';
    echo '<input type="' . app_h($type) . '" name="' . app_h($name) . '" class="form-input" value="' . app_h($value) . '"' . $requiredAttr . '>';
}

function cm_textarea(string $name, string $value = '', int $rows = 3, bool $required = true): void
{
    $requiredAttr = $required ? ' required' : '';
    echo '<textarea name="' . app_h($name) . '" class="form-input" rows="' . (int)$rows . '"' . $requiredAttr . '>' . app_h($value) . '</textarea>';
}

function cm_shop_filter_product_checks(string $type, int $index, array $selectedIds, array $products): void
{
    $selectedLookup = array_fill_keys(array_map('intval', $selectedIds), true);
    echo '<div class="cms-product-checks">';
    foreach ($products as $product) {
        $productId = (int)$product['productID'];
        $checked = isset($selectedLookup[$productId]) ? ' checked' : '';
        echo '<label class="cms-product-check">';
        echo '<input type="checkbox" name="shop_filters[' . app_h($type) . '][' . (int)$index . '][product_ids][]" value="' . $productId . '"' . $checked . '>';
        echo '<span>' . app_h((string)$product['nameEN']) . '</span>';
        echo '</label>';
    }
    echo '</div>';
}

function cm_shop_filter_row(string $type, int $index, array $row, array $products): void
{
    $active = !empty($row['active']);
    ?>
    <div class="cms-shop-filter-row" data-shop-filter-row data-shop-filter-type="<?= app_h($type) ?>">
      <div class="cms-step-header">
        <div class="cms-step-title" data-shop-filter-title><?= app_h((string)($row['label_en'] ?? ('Option ' . ($index + 1)))) ?></div>
        <button type="button" class="cms-step-remove" data-remove-shop-filter data-confirm-delete data-confirm-message="Remove this shop filter?"><i class="fas fa-trash"></i> Remove</button>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Slug</label>
          <input type="text" name="shop_filters[<?= app_h($type) ?>][<?= (int)$index ?>][id]" class="form-input" value="<?= app_h((string)($row['id'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <label class="cms-inline-toggle">
            <input type="hidden" name="shop_filters[<?= app_h($type) ?>][<?= (int)$index ?>][active]" value="0">
            <input type="checkbox" name="shop_filters[<?= app_h($type) ?>][<?= (int)$index ?>][active]" value="1"<?= $active ? ' checked' : '' ?>>
            <span>Visible in Shop</span>
          </label>
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Label (EN)</label>
          <input type="text" name="shop_filters[<?= app_h($type) ?>][<?= (int)$index ?>][label_en]" class="form-input" value="<?= app_h((string)($row['label_en'] ?? '')) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Label (GR)</label>
          <input type="text" name="shop_filters[<?= app_h($type) ?>][<?= (int)$index ?>][label_gr]" class="form-input" value="<?= app_h((string)($row['label_gr'] ?? '')) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Assigned Products</label>
        <?php cm_shop_filter_product_checks($type, $index, (array)($row['product_ids'] ?? []), $products); ?>
      </div>
    </div>
    <?php
}

function cm_shop_filter_section(string $type, string $title, string $icon, array $rows, array $products): void
{
    $addLabel = ['categories' => 'Filter', 'materials' => 'Material', 'tags' => 'Tag'][$type] ?? 'Option';
    ?>
    <div class="cms-panel" data-shop-filter-section="<?= app_h($type) ?>">
      <div class="cms-panel-title cms-panel-title-with-action">
        <span class="cms-panel-title-label"><i class="<?= app_h($icon) ?>"></i><?= app_h($title) ?></span>
        <button type="button" class="btn-secondary cms-panel-title-action" data-add-shop-filter="<?= app_h($type) ?>">
          <i class="fas fa-plus"></i> Add <?= app_h($addLabel) ?>
        </button>
      </div>
      <input type="hidden" name="shop_filters[<?= app_h($type) ?>]" value="">
      <div data-shop-filter-list="<?= app_h($type) ?>">
        <?php foreach ($rows as $index => $row): ?>
          <?php cm_shop_filter_row($type, (int)$index, $row, $products); ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Content Management - Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/admin.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .cms-editor-form { display:flex; flex-direction:column; gap:18px; }
    .cms-category-nav { margin-bottom:6px; }
    .cms-category-nav .tab-btn { flex:1 1 170px; justify-content:center; min-height:40px; }
    .cms-card-copy { color:#6b7280; font-size:13px; margin:-4px 0 18px; }
    .cms-panel { border:1px solid #e5e7eb; border-radius:8px; padding:16px; background:#f9fafb; margin-top:14px; }
    .cms-panel-title { font-size:14px; font-weight:700; color:#111827; margin:0 0 12px; display:flex; align-items:center; gap:8px; }
    .cms-panel-title-with-action { justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .cms-panel-title-label { display:inline-flex; align-items:center; gap:8px; }
    .cms-panel-title-action { min-height:32px; padding:6px 12px; font-size:13px; }
    .cms-home-tools { display:grid; grid-template-columns:minmax(0,1fr); gap:14px; margin-bottom:18px; }
    .cms-tool-card { border:1px solid #e5e7eb; border-radius:8px; padding:16px; background:#fff; display:flex; align-items:center; justify-content:space-between; gap:16px; }
    .cms-tool-card-title { font-size:14px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; margin-bottom:6px; }
    .cms-tool-card-copy { color:#6b7280; font-size:13px; margin:0; line-height:1.45; }
    .cms-tool-card .btn-secondary { flex:0 0 auto; }
    .cms-step { border-top:1px solid #e5e7eb; padding-top:14px; margin-top:14px; }
    .cms-step:first-of-type { border-top:0; padding-top:0; margin-top:0; }
    .cms-step-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 12px; }
    .cms-step-title { font-size:13px; font-weight:700; color:#374151; margin:0; }
    .cms-step-remove { border:0; background:transparent; color:#dc2626; font-size:12px; font-weight:700; cursor:pointer; padding:4px 0; }
    .cms-add-step-row { display:flex; justify-content:flex-end; margin-top:14px; }
    .cms-add-step-row .btn-secondary { min-height:34px; padding:7px 12px; font-size:13px; }
    .cms-shop-filter-row { border-top:1px solid #e5e7eb; padding-top:14px; margin-top:14px; }
    .cms-shop-filter-row:first-child { border-top:0; padding-top:0; margin-top:0; }
    .cms-product-checks { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:8px; max-height:210px; overflow:auto; padding:10px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
    .cms-product-check { display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; }
    .cms-product-check input { width:15px; height:15px; }
    .cms-inline-toggle { display:flex; align-items:center; gap:8px; min-height:38px; font-size:13px; color:#374151; }
    .cms-inline-toggle input[type="checkbox"] { width:16px; height:16px; }
    .cms-save-row { display:flex; justify-content:flex-end; position:sticky; bottom:0; padding:16px 0 0; background:linear-gradient(to bottom, rgba(249,250,251,0), #f9fafb 35%); z-index:2; }
    .cms-save-row .btn-save { min-width:180px; justify-content:center; }
    @media (max-width: 760px) {
      .cms-save-row { position:static; }
      .cms-tool-card { align-items:flex-start; flex-direction:column; }
    }
  </style>
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Content Management</h1>
        <p>Edit the live website content for Home, Shop, Custom Orders, About, and Contact.</p>
      </div>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type, $msg] = array_pad(explode(':', $flash, 2), 2, ''); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= app_h($msg) ?></div>
      <?php endif; ?>

      <form method="POST" class="cms-editor-form">
        <?= app_csrf_input() ?>
        <input type="hidden" name="action" value="save_content_management">
        <input type="hidden" name="active_tab" value="<?= app_h($activeTab) ?>" data-active-tab-input="content-management">

        <div class="tab-nav cms-category-nav" data-tab-group="content-management">
          <button type="button" class="tab-btn<?= $activeTab === 'home' ? ' active' : '' ?>" data-tab="cms-panel-home" data-tab-key="home" onclick="switchTab(this,'content-management')">
            <i class="fas fa-house"></i> Home
          </button>
          <button type="button" class="tab-btn<?= $activeTab === 'shop' ? ' active' : '' ?>" data-tab="cms-panel-shop" data-tab-key="shop" onclick="switchTab(this,'content-management')">
            <i class="fas fa-store"></i> Shop
          </button>
          <button type="button" class="tab-btn<?= $activeTab === 'custom_orders' ? ' active' : '' ?>" data-tab="cms-panel-custom-orders" data-tab-key="custom_orders" onclick="switchTab(this,'content-management')">
            <i class="fas fa-star"></i> Custom Orders
          </button>
          <button type="button" class="tab-btn<?= $activeTab === 'about' ? ' active' : '' ?>" data-tab="cms-panel-about" data-tab-key="about" onclick="switchTab(this,'content-management')">
            <i class="fas fa-book-open"></i> About
          </button>
          <button type="button" class="tab-btn<?= $activeTab === 'contact' ? ' active' : '' ?>" data-tab="cms-panel-contact" data-tab-key="contact" onclick="switchTab(this,'content-management')">
            <i class="fas fa-envelope"></i> Contact
          </button>
        </div>

        <section id="cms-panel-home" class="tab-content<?= $activeTab === 'home' ? ' active' : '' ?>" data-tab-target="content-management">
          <div class="card" id="home-content">
            <div class="card-title">Home Page</div>
          <p class="cms-card-copy">Edit homepage text and images from one organized Home category.</p>
          <div class="cms-home-tools">
            <div class="cms-tool-card">
              <div>
                <div class="cms-tool-card-title"><i class="fas fa-image"></i> Homepage Image Editor</div>
                <p class="cms-tool-card-copy">Update the homepage hero image, collection cards, journey photos, and header logo.</p>
              </div>
              <a href="homepage_customization.php" class="btn-secondary">
                <i class="fas fa-arrow-up-right-from-square"></i> Open Editor
              </a>
            </div>
          </div>
          <div class="cms-panel">
            <div class="cms-panel-title"><i class="fas fa-font"></i>Soft Handmade Crochet Treasures</div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Hero Title (EN)</label>
              <?php cm_field('content[home][hero][title_en]', (string)($homeHero['title_en'] ?? '')); ?>
            </div>
            <div class="form-group">
              <label class="form-label">Hero Title (GR)</label>
              <?php cm_field('content[home][hero][title_gr]', (string)($homeHero['title_gr'] ?? '')); ?>
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Hero Subtitle (EN)</label>
              <?php cm_textarea('content[home][hero][subtitle_en]', (string)($homeHero['subtitle_en'] ?? ''), 3); ?>
            </div>
            <div class="form-group">
              <label class="form-label">Hero Subtitle (GR)</label>
              <?php cm_textarea('content[home][hero][subtitle_gr]', (string)($homeHero['subtitle_gr'] ?? ''), 3); ?>
            </div>
          </div>
          <div class="form-grid-2">
            <div class="form-group">
              <label class="form-label">Button Text (EN)</label>
              <?php cm_field('content[home][hero][button_en]', (string)($homeHero['button_en'] ?? '')); ?>
            </div>
            <div class="form-group">
              <label class="form-label">Button Text (GR)</label>
              <?php cm_field('content[home][hero][button_gr]', (string)($homeHero['button_gr'] ?? '')); ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Button Link</label>
            <?php cm_field('content[home][hero][button_url]', (string)($homeHero['button_url'] ?? 'shop.php')); ?>
          </div>
          </div>
          </div>
        </section>

        <section id="cms-panel-shop" class="tab-content<?= $activeTab === 'shop' ? ' active' : '' ?>" data-tab-target="content-management">
          <div class="card" id="shop-content">
            <div class="card-title">Shop Page</div>
            <p class="cms-card-copy">Edit the filters, material chips, tags, price range, and product assignments used by the public Shop page.</p>

            <div class="cms-panel">
              <div class="cms-panel-title"><i class="fas fa-euro-sign"></i>Price Range</div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Minimum Price (€)</label>
                  <input type="number" name="shop_filters[price][min]" class="form-input" min="0" step="0.01" value="<?= app_h((string)($shopFilterSettings['price']['min'] ?? 0)) ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">Maximum Price (€)</label>
                  <input type="number" name="shop_filters[price][max]" class="form-input" min="0" step="0.01" value="<?= app_h((string)($shopFilterSettings['price']['max'] ?? 100)) ?>">
                </div>
              </div>
              <div class="form-group" style="max-width:240px">
                <label class="form-label">Slider Step (€)</label>
                <input type="number" name="shop_filters[price][step]" class="form-input" min="0.01" step="0.01" value="<?= app_h((string)($shopFilterSettings['price']['step'] ?? 1)) ?>">
              </div>
            </div>

            <?php cm_shop_filter_section('categories', 'Filters', 'fas fa-filter', (array)($shopFilterSettings['categories'] ?? []), $shopFilterProducts); ?>
            <?php cm_shop_filter_section('materials', 'Materials', 'fas fa-layer-group', (array)($shopFilterSettings['materials'] ?? []), $shopFilterProducts); ?>
            <?php cm_shop_filter_section('tags', 'Tags', 'fas fa-tags', (array)($shopFilterSettings['tags'] ?? []), $shopFilterProducts); ?>
          </div>
        </section>

        <section id="cms-panel-custom-orders" class="tab-content<?= $activeTab === 'custom_orders' ? ' active' : '' ?>" data-tab-target="content-management">
          <div class="card" id="custom-orders-content">
            <div class="card-title">Custom Orders Page</div>
          <p class="cms-card-copy">Edit the Instagram and website request steps shown to customers on the Custom Orders page.</p>
          <div class="grid-2">
            <?php foreach (['instagram' => 'Instagram Steps', 'website' => 'Website Request Steps'] as $pathKey => $pathTitle): ?>
              <div class="cms-panel">
                <div class="cms-panel-title"><i class="<?= $pathKey === 'instagram' ? 'fab fa-instagram' : 'fas fa-globe' ?>"></i><?= app_h($pathTitle) ?></div>
                <input type="hidden" name="steps[<?= app_h($pathKey) ?>]" value="">
                <?php foreach (($customOrderSteps[$pathKey] ?? []) as $stepIndex => $step): ?>
                  <div class="cms-step" data-cms-step data-step-path="<?= app_h($pathKey) ?>">
                    <div class="cms-step-header">
                      <div class="cms-step-title" data-step-title>Step <?= (int)$stepIndex + 1 ?></div>
                      <button type="button" class="cms-step-remove" data-remove-step data-confirm-delete data-confirm-message="Remove this customer instruction step?"><i class="fas fa-trash"></i> Remove</button>
                    </div>
                    <div class="form-grid-2">
                      <div class="form-group">
                        <label class="form-label">Title (EN)</label>
                        <?php cm_field('steps[' . $pathKey . '][' . (int)$stepIndex . '][title_en]', (string)($step['title_en'] ?? ''), 'text', true); ?>
                      </div>
                      <div class="form-group">
                        <label class="form-label">Title (GR)</label>
                        <?php cm_field('steps[' . $pathKey . '][' . (int)$stepIndex . '][title_gr]', (string)($step['title_gr'] ?? '')); ?>
                      </div>
                    </div>
                    <div class="form-grid-2">
                      <div class="form-group">
                        <label class="form-label">Text (EN)</label>
                        <?php cm_textarea('steps[' . $pathKey . '][' . (int)$stepIndex . '][text_en]', (string)($step['text_en'] ?? ''), 2); ?>
                      </div>
                      <div class="form-group">
                        <label class="form-label">Text (GR)</label>
                        <?php cm_textarea('steps[' . $pathKey . '][' . (int)$stepIndex . '][text_gr]', (string)($step['text_gr'] ?? ''), 2); ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
                <div data-cms-steps-list="<?= app_h($pathKey) ?>"></div>
                <div class="cms-add-step-row">
                  <button type="button" class="btn-secondary" data-add-step="<?= app_h($pathKey) ?>">
                    <i class="fas fa-plus"></i> Add Step
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          </div>
        </section>

        <section id="cms-panel-about" class="tab-content<?= $activeTab === 'about' ? ' active' : '' ?>" data-tab-target="content-management">
          <div class="card" id="about-content">
            <div class="card-title">About Page</div>
          <p class="cms-card-copy">Edit the Our Story section and the Our Values list shown on the About page.</p>
          <div class="cms-panel">
            <div class="cms-panel-title"><i class="fas fa-book-open"></i>Our Story</div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Section Title (EN)</label>
                <?php cm_field('content[about][story][title_en]', (string)($aboutStory['title_en'] ?? '')); ?>
              </div>
              <div class="form-group">
                <label class="form-label">Section Title (GR)</label>
                <?php cm_field('content[about][story][title_gr]', (string)($aboutStory['title_gr'] ?? '')); ?>
              </div>
            </div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Story Content (EN)</label>
                <?php cm_textarea('content[about][story][content_en]', (string)($aboutStory['content_en'] ?? ''), 7); ?>
              </div>
              <div class="form-group">
                <label class="form-label">Story Content (GR)</label>
                <?php cm_textarea('content[about][story][content_gr]', (string)($aboutStory['content_gr'] ?? ''), 7); ?>
              </div>
            </div>
          </div>

          <div class="cms-panel">
            <div class="cms-panel-title"><i class="fas fa-heart"></i>Our Values</div>
            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label">Section Title (EN)</label>
                <?php cm_field('content[about][values][title_en]', (string)($aboutValues['title_en'] ?? '')); ?>
              </div>
              <div class="form-group">
                <label class="form-label">Section Title (GR)</label>
                <?php cm_field('content[about][values][title_gr]', (string)($aboutValues['title_gr'] ?? '')); ?>
              </div>
            </div>
            <?php foreach (($aboutValues['items'] ?? []) as $idx => $valueItem): ?>
              <div class="cms-step">
                <div class="cms-step-title">Value <?= (int)$idx + 1 ?></div>
                <div class="form-grid-2">
                  <div class="form-group">
                    <label class="form-label">Title (EN)</label>
                    <?php cm_field('content[about][values][items][' . (int)$idx . '][title_en]', (string)($valueItem['title_en'] ?? '')); ?>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Title (GR)</label>
                    <?php cm_field('content[about][values][items][' . (int)$idx . '][title_gr]', (string)($valueItem['title_gr'] ?? '')); ?>
                  </div>
                </div>
                <div class="form-grid-2">
                  <div class="form-group">
                    <label class="form-label">Text (EN)</label>
                    <?php cm_textarea('content[about][values][items][' . (int)$idx . '][text_en]', (string)($valueItem['text_en'] ?? ''), 2); ?>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Text (GR)</label>
                    <?php cm_textarea('content[about][values][items][' . (int)$idx . '][text_gr]', (string)($valueItem['text_gr'] ?? ''), 2); ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          </div>
        </section>

        <section id="cms-panel-contact" class="tab-content<?= $activeTab === 'contact' ? ' active' : '' ?>" data-tab-target="content-management">
          <div class="card" id="contact-content">
            <div class="card-title">Contact Page</div>
          <p class="cms-card-copy">Edit only the contact information boxes shown beside the Send a Message form.</p>
          <div class="grid-2">
            <div class="cms-panel">
              <div class="cms-panel-title"><i class="fas fa-envelope"></i>Email Box</div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Label (EN)</label>
                  <?php cm_field('content[contact][email][label_en]', (string)($contactContent['email']['label_en'] ?? 'Email')); ?>
                </div>
                <div class="form-group">
                  <label class="form-label">Label (GR)</label>
                  <?php cm_field('content[contact][email][label_gr]', (string)($contactContent['email']['label_gr'] ?? 'Email')); ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Email Address</label>
                <?php cm_field('content[contact][email][value]', (string)($contactContent['email']['value'] ?? ''), 'email'); ?>
              </div>
            </div>

            <div class="cms-panel">
              <div class="cms-panel-title"><i class="fab fa-instagram"></i>Instagram Box</div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Label (EN)</label>
                  <?php cm_field('content[contact][instagram][label_en]', (string)($contactContent['instagram']['label_en'] ?? 'Instagram')); ?>
                </div>
                <div class="form-group">
                  <label class="form-label">Label (GR)</label>
                  <?php cm_field('content[contact][instagram][label_gr]', (string)($contactContent['instagram']['label_gr'] ?? 'Instagram')); ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Display Text</label>
                <?php cm_field('content[contact][instagram][value]', (string)($contactContent['instagram']['value'] ?? '')); ?>
              </div>
              <div class="form-group">
                <label class="form-label">Profile URL</label>
                <?php cm_field('content[contact][instagram][url]', (string)($contactContent['instagram']['url'] ?? ''), 'url', false); ?>
              </div>
            </div>

            <div class="cms-panel">
              <div class="cms-panel-title"><i class="fab fa-facebook-f"></i>Facebook Box</div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Label (EN)</label>
                  <?php cm_field('content[contact][facebook][label_en]', (string)($contactContent['facebook']['label_en'] ?? 'Facebook')); ?>
                </div>
                <div class="form-group">
                  <label class="form-label">Label (GR)</label>
                  <?php cm_field('content[contact][facebook][label_gr]', (string)($contactContent['facebook']['label_gr'] ?? 'Facebook')); ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Display Text</label>
                <?php cm_field('content[contact][facebook][value]', (string)($contactContent['facebook']['value'] ?? '')); ?>
              </div>
              <div class="form-group">
                <label class="form-label">Page URL</label>
                <?php cm_field('content[contact][facebook][url]', (string)($contactContent['facebook']['url'] ?? ''), 'url', false); ?>
              </div>
            </div>

            <div class="cms-panel">
              <div class="cms-panel-title"><i class="fas fa-clock"></i>Response Time Box</div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Label (EN)</label>
                  <?php cm_field('content[contact][response_time][label_en]', (string)($contactContent['response_time']['label_en'] ?? 'Response Time')); ?>
                </div>
                <div class="form-group">
                  <label class="form-label">Label (GR)</label>
                  <?php cm_field('content[contact][response_time][label_gr]', (string)($contactContent['response_time']['label_gr'] ?? 'Χρόνος Απόκρισης')); ?>
                </div>
              </div>
              <div class="form-grid-2">
                <div class="form-group">
                  <label class="form-label">Text (EN)</label>
                  <?php cm_textarea('content[contact][response_time][text_en]', (string)($contactContent['response_time']['text_en'] ?? ''), 2); ?>
                </div>
                <div class="form-group">
                  <label class="form-label">Text (GR)</label>
                  <?php cm_textarea('content[contact][response_time][text_gr]', (string)($contactContent['response_time']['text_gr'] ?? ''), 2); ?>
                </div>
              </div>
            </div>
          </div>
          </div>
        </section>

        <div class="cms-save-row">
          <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Content</button>
        </div>
      </form>

    </div>
  </main>
</div>

<script src="assets/admin.js?v=<?= (int)filemtime(__DIR__ . '/assets/admin.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function stepTemplate(path, index) {
    return [
      '<div class="cms-step" data-cms-step data-step-path="' + path + '">',
      '  <div class="cms-step-header">',
      '    <div class="cms-step-title" data-step-title>Step ' + (index + 1) + '</div>',
      '    <button type="button" class="cms-step-remove" data-remove-step data-confirm-delete data-confirm-message="Remove this customer instruction step?"><i class="fas fa-trash"></i> Remove</button>',
      '  </div>',
      '  <div class="form-grid-2">',
      '    <div class="form-group">',
      '      <label class="form-label">Title (EN)</label>',
      '      <input type="text" name="steps[' + path + '][' + index + '][title_en]" class="form-input" required>',
      '    </div>',
      '    <div class="form-group">',
      '      <label class="form-label">Title (GR)</label>',
      '      <input type="text" name="steps[' + path + '][' + index + '][title_gr]" class="form-input" required>',
      '    </div>',
      '  </div>',
      '  <div class="form-grid-2">',
      '    <div class="form-group">',
      '      <label class="form-label">Text (EN)</label>',
      '      <textarea name="steps[' + path + '][' + index + '][text_en]" class="form-input" rows="2" required></textarea>',
      '    </div>',
      '    <div class="form-group">',
      '      <label class="form-label">Text (GR)</label>',
      '      <textarea name="steps[' + path + '][' + index + '][text_gr]" class="form-input" rows="2" required></textarea>',
      '    </div>',
      '  </div>',
      '</div>'
    ].join('');
  }

  function reindexSteps(path) {
    document.querySelectorAll('[data-cms-step][data-step-path="' + path + '"]').forEach(function (step, index) {
      var title = step.querySelector('[data-step-title]');
      if (title) title.textContent = 'Step ' + (index + 1);
      step.querySelectorAll('input, textarea').forEach(function (field) {
        field.name = field.name.replace(/steps\[[^\]]+\]\[\d+\]/, 'steps[' + path + '][' + index + ']');
      });
    });
  }

  document.querySelectorAll('[data-add-step]').forEach(function (button) {
    button.addEventListener('click', function () {
      var path = button.getAttribute('data-add-step') || '';
      var list = document.querySelector('[data-cms-steps-list="' + path + '"]');
      if (!path || !list) return;
      var index = document.querySelectorAll('[data-cms-step][data-step-path="' + path + '"]').length;
      list.insertAdjacentHTML('beforeend', stepTemplate(path, index));
      reindexSteps(path);
      var added = list.lastElementChild;
      var firstInput = added ? added.querySelector('input') : null;
      if (firstInput) firstInput.focus();
    });
  });

  document.addEventListener('click', function (event) {
    var removeButton = event.target.closest('[data-remove-step]');
    if (!removeButton) return;
    var step = removeButton.closest('[data-cms-step]');
    if (!step) return;
    var path = step.getAttribute('data-step-path') || '';
    step.remove();
    reindexSteps(path);
  });

  var cmsShopProducts = <?= json_encode(array_values($shopFilterProducts), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (ch) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[ch];
    });
  }

  function shopProductChecksTemplate(type, index) {
    return cmsShopProducts.map(function (product) {
      var id = parseInt(product.productID, 10) || 0;
      return [
        '<label class="cms-product-check">',
        '<input type="checkbox" name="shop_filters[' + type + '][' + index + '][product_ids][]" value="' + id + '">',
        '<span>' + escapeHtml(product.nameEN || ('Product #' + id)) + '</span>',
        '</label>'
      ].join('');
    }).join('');
  }

  function shopFilterTemplate(type, index) {
    var label = type === 'categories' ? 'New Filter' : (type === 'materials' ? 'New Material' : 'New Tag');
    return [
      '<div class="cms-shop-filter-row" data-shop-filter-row data-shop-filter-type="' + type + '">',
      '  <div class="cms-step-header">',
      '    <div class="cms-step-title" data-shop-filter-title>' + label + '</div>',
      '    <button type="button" class="cms-step-remove" data-remove-shop-filter data-confirm-delete data-confirm-message="Remove this shop filter?"><i class="fas fa-trash"></i> Remove</button>',
      '  </div>',
      '  <div class="form-grid-2">',
      '    <div class="form-group">',
      '      <label class="form-label">Slug</label>',
      '      <input type="text" name="shop_filters[' + type + '][' + index + '][id]" class="form-input" placeholder="auto-from-label">',
      '    </div>',
      '    <div class="form-group">',
      '      <label class="form-label">Status</label>',
      '      <label class="cms-inline-toggle">',
      '        <input type="hidden" name="shop_filters[' + type + '][' + index + '][active]" value="0">',
      '        <input type="checkbox" name="shop_filters[' + type + '][' + index + '][active]" value="1" checked>',
      '        <span>Visible in Shop</span>',
      '      </label>',
      '    </div>',
      '  </div>',
      '  <div class="form-grid-2">',
      '    <div class="form-group">',
      '      <label class="form-label">Label (EN)</label>',
      '      <input type="text" name="shop_filters[' + type + '][' + index + '][label_en]" class="form-input" required>',
      '    </div>',
      '    <div class="form-group">',
      '      <label class="form-label">Label (GR)</label>',
      '      <input type="text" name="shop_filters[' + type + '][' + index + '][label_gr]" class="form-input">',
      '    </div>',
      '  </div>',
      '  <div class="form-group">',
      '    <label class="form-label">Assigned Products</label>',
      '    <div class="cms-product-checks">' + shopProductChecksTemplate(type, index) + '</div>',
      '  </div>',
      '</div>'
    ].join('');
  }

  function reindexShopFilters(type) {
    document.querySelectorAll('[data-shop-filter-row][data-shop-filter-type="' + type + '"]').forEach(function (row, index) {
      row.querySelectorAll('input').forEach(function (field) {
        field.name = field.name.replace(/shop_filters\[[^\]]+\]\[\d+\]/, 'shop_filters[' + type + '][' + index + ']');
      });
      var title = row.querySelector('[data-shop-filter-title]');
      var labelInput = row.querySelector('input[name$="[label_en]"]');
      if (title) title.textContent = labelInput && labelInput.value ? labelInput.value : 'Option ' + (index + 1);
    });
  }

  document.querySelectorAll('[data-add-shop-filter]').forEach(function (button) {
    button.addEventListener('click', function () {
      var type = button.getAttribute('data-add-shop-filter') || '';
      var list = document.querySelector('[data-shop-filter-list="' + type + '"]');
      if (!type || !list) return;
      var index = document.querySelectorAll('[data-shop-filter-row][data-shop-filter-type="' + type + '"]').length;
      list.insertAdjacentHTML('beforeend', shopFilterTemplate(type, index));
      reindexShopFilters(type);
      var added = list.lastElementChild;
      var firstInput = added ? added.querySelector('input[name$="[label_en]"]') : null;
      if (firstInput) firstInput.focus();
    });
  });

  document.addEventListener('click', function (event) {
    var removeButton = event.target.closest('[data-remove-shop-filter]');
    if (!removeButton) return;
    var row = removeButton.closest('[data-shop-filter-row]');
    if (!row) return;
    var type = row.getAttribute('data-shop-filter-type') || '';
    row.remove();
    reindexShopFilters(type);
  });

  document.addEventListener('input', function (event) {
    var field = event.target.closest('[data-shop-filter-row] input[name$="[label_en]"]');
    if (!field) return;
    var row = field.closest('[data-shop-filter-row]');
    var title = row ? row.querySelector('[data-shop-filter-title]') : null;
    if (title) title.textContent = field.value || 'Option';
  });
});
</script>
</body>
</html>
