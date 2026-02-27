<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'product_page_setup';

/* ── Toggle colour for category (POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryID = (int)($_POST['categoryID'] ?? 0);
    $colorID    = (int)($_POST['colorID']    ?? 0);
    $isEnabled  = (int)($_POST['isEnabled']  ?? 0);

    // Toggle globally unavailable colour
    if (isset($_POST['toggleGlobal'])) {
        $newActive = (int)($_POST['newActive'] ?? 0);
        $stmt = mysqli_prepare($conn, "UPDATE colors SET isActive=? WHERE colorID=?");
        mysqli_stmt_bind_param($stmt, 'ii', $newActive, $colorID);
        mysqli_stmt_execute($stmt);
    } else {
        // Upsert category_colors
        $stmt = mysqli_prepare($conn,
            "INSERT INTO category_colors (categoryID,colorID,isEnabled) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE isEnabled=?");
        mysqli_stmt_bind_param($stmt, 'iiii', $categoryID, $colorID, $isEnabled, $isEnabled);
        mysqli_stmt_execute($stmt);
    }
    header('Location: product_page_setup.php');
    exit;
}

/* ── Load categories ── */
$categories = [];
$r = mysqli_query($conn, "SELECT * FROM categories ORDER BY categoryName");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $categories[] = $row; }

/* ── Load all colours ── */
$allColors = [];
$r = mysqli_query($conn, "SELECT * FROM colors ORDER BY colorName");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $allColors[$row['colorID']] = $row; }

/* ── Load category_colors ── */
$catColorMap = []; // [categoryID][colorID] => isEnabled
$r = mysqli_query($conn, "SELECT * FROM category_colors");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $catColorMap[$row['categoryID']][$row['colorID']] = (int)$row['isEnabled']; }

/* ── Stats ── */
$totalAvailable  = count(array_filter($allColors, fn($c)=>$c['isActive']==1));
$unavailableCount= count(array_filter($allColors, fn($c)=>$c['isActive']==0));

$mostUsed = '—';
$r = mysqli_query($conn, "SELECT c.colorName, COUNT(cc.categoryID) AS cnt
      FROM colors c
      LEFT JOIN category_colors cc ON cc.colorID=c.colorID AND cc.isEnabled=1
      WHERE c.isActive=1
      GROUP BY c.colorID ORDER BY cnt DESC LIMIT 1");
if ($r && mysqli_num_rows($r)) { $row = mysqli_fetch_assoc($r); $mostUsed = $row['colorName']; }

/* ── Active category tab from GET ── */
$activeCategory = (int)($_GET['cat'] ?? ($categories[0]['categoryID'] ?? 0));
$activeCatObj   = null;
foreach ($categories as $cat) {
    if ($cat['categoryID'] == $activeCategory) { $activeCatObj = $cat; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Product Page Setup – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Product Page Management</h1>
        <p>Manage which colours are available for each product category. Quick enable/disable when yarn availability changes.</p>
      </div>
    </div>

    <div class="content-body">

      <!-- ── Stat cards ── -->
      <div class="grid-3 mb-6">
        <div class="stat-card">
          <div class="stat-header">Total Available Colours <i class="fas fa-palette stat-icon"></i></div>
          <div class="stat-val"><?= $totalAvailable ?></div>
          <div class="stat-desc">Globally enabled</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Most Used Colour <i class="fas fa-check stat-icon" style="color:#10b981"></i></div>
          <div class="stat-val" style="font-size:18px;margin-top:12px;"><?= htmlspecialchars($mostUsed) ?></div>
          <div class="stat-desc">Available in all categories</div>
        </div>
        <div class="stat-card">
          <div class="stat-header">Unavailable Colours <i class="fas fa-times stat-icon" style="color:#dc2626"></i></div>
          <div class="stat-val"><?= $unavailableCount ?></div>
          <div class="stat-desc">Globally disabled</div>
        </div>
      </div>

      <!-- ── Global colours management ── -->
      <div class="card mb-6">
        <div class="card-title">Global Colour Settings</div>
        <p class="text-sm text-muted mb-4">Toggle a colour globally. Disabled colours cannot be selected for any product.</p>
        <table class="data-table">
          <thead>
            <tr>
              <th>Colour</th>
              <th>Yarn Stock</th>
              <th>Global Status</th>
              <th>Toggle</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allColors as $col): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($col['colorName']) ?></td>
              <td><?= (int)$col['globalInventoryAvailable'] ?> units</td>
              <td>
                <?php if ($col['isActive']): ?>
                  <span class="badge badge-dark">Available</span>
                <?php else: ?>
                  <span class="badge badge-red">Unavailable</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="toggleGlobal" value="1">
                  <input type="hidden" name="colorID" value="<?= $col['colorID'] ?>">
                  <input type="hidden" name="newActive" value="<?= $col['isActive'] ? 0 : 1 ?>">
                  <label class="toggle-wrap">
                    <input type="checkbox" <?= $col['isActive'] ? 'checked' : '' ?>
                           onchange="this.closest('form').submit()">
                    <span class="toggle-slider"></span>
                  </label>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Colour availability by category ── -->
      <div class="card">
        <div class="card-title">Colour Availability by Category</div>
        <p class="text-sm text-muted mb-4">Enable or disable colours for specific product categories. Only globally available colours can be selected.</p>

        <!-- Category tabs -->
        <div class="tab-nav mb-4" style="margin-bottom:0">
          <?php foreach ($categories as $cat):
            $cnt = 0;
            foreach ($allColors as $col) {
                if ($col['isActive'] && ($catColorMap[$cat['categoryID']][$col['colorID']] ?? 1)) $cnt++;
            }
          ?>
          <a href="?cat=<?= $cat['categoryID'] ?>"
             class="tab-btn <?= $cat['categoryID'] == $activeCategory ? 'active' : '' ?>"
             style="text-decoration:none">
            <?= htmlspecialchars($cat['categoryName']) ?> <?= $cnt ?>
          </a>
          <?php endforeach; ?>
        </div>

        <div class="mt-6">
          <?php if ($activeCatObj):
            $cnt = 0;
            foreach ($allColors as $col) {
                if ($col['isActive'] && ($catColorMap[$activeCategory][$col['colorID']] ?? 1)) $cnt++;
            }
          ?>
          <div class="alert-card alert-blue mb-4">
            <p class="alert-text">
              <strong><?= $cnt ?> colours</strong> are currently available for
              <strong><?= htmlspecialchars($activeCatObj['categoryName']) ?></strong> products.
              Toggle colours on/off to match your yarn inventory.
            </p>
          </div>

          <?php foreach ($allColors as $col): ?>
          <div class="colour-row">
            <div>
              <div class="colour-name"><?= htmlspecialchars($col['colorName']) ?></div>
              <div class="colour-stock">Stock: <?= (int)$col['globalInventoryAvailable'] ?> units</div>
            </div>
            <div style="display:flex;align-items:center;gap:14px">
              <?php
                $enabled = $catColorMap[$activeCategory][$col['colorID']] ?? 1;
                $globalOk = $col['isActive'];
              ?>
              <?php if ($globalOk && $enabled): ?>
                <span class="badge badge-dark"><i class="fas fa-check" style="font-size:10px;margin-right:4px"></i> Available</span>
              <?php elseif (!$globalOk): ?>
                <span class="badge badge-red">Globally Disabled</span>
              <?php else: ?>
                <span class="badge badge-muted">Disabled</span>
              <?php endif; ?>

              <?php if ($globalOk): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="categoryID" value="<?= $activeCategory ?>">
                <input type="hidden" name="colorID"    value="<?= $col['colorID'] ?>">
                <input type="hidden" name="isEnabled"  value="<?= $enabled ? 0 : 1 ?>">
                <label class="toggle-wrap" title="<?= $enabled ? 'Disable' : 'Enable' ?> for this category">
                  <input type="checkbox" <?= $enabled ? 'checked' : '' ?>
                         onchange="this.closest('form').submit()">
                  <span class="toggle-slider"></span>
                </label>
              </form>
              <?php else: ?>
                <label class="toggle-wrap">
                  <input type="checkbox" disabled>
                  <span class="toggle-slider" style="opacity:.4;cursor:not-allowed"></span>
                </label>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
