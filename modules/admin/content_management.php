<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/db.php';

$current_page = 'content_management';
$flash = '';

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title    = trim($_POST['pageTitle'] ?? '');
        $slug     = trim($_POST['slug']      ?? '');
        $lang     = $_POST['language']       ?? 'en';
        $type     = $_POST['pageType']       ?? 'static';
        $content  = $_POST['content']        ?? '';
        $published= isset($_POST['isPublished']) ? 1 : 0;
        if (empty($slug)) $slug = strtolower(str_replace(' ','-',$title));
        $stmt = mysqli_prepare($conn,
            "INSERT INTO content_pages (pageTitle, slug, language, pageType, content, isPublished)
             VALUES (?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, 'sssssi', $title, $slug, $lang, $type, $content, $published);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Page created.';
    }

    if ($action === 'edit') {
        $id      = (int)($_POST['pageID'] ?? 0);
        $title   = trim($_POST['pageTitle'] ?? '');
        $lang    = $_POST['language']       ?? 'en';
        $type    = $_POST['pageType']       ?? 'static';
        $content = $_POST['content']        ?? '';
        $published= isset($_POST['isPublished']) ? 1 : 0;
        $stmt = mysqli_prepare($conn,
            "UPDATE content_pages SET pageTitle=?, language=?, pageType=?, content=?, isPublished=?
             WHERE pageID=?");
        mysqli_stmt_bind_param($stmt, 'sssiii', $title, $lang, $type, $content, $published, $id);
        // fix param types
        $stmt = mysqli_prepare($conn,
            "UPDATE content_pages SET pageTitle=?, language=?, pageType=?, content=?, isPublished=?
             WHERE pageID=?");
        mysqli_stmt_bind_param($stmt, 'ssssii', $title, $lang, $type, $content, $published, $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Page updated.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['pageID'] ?? 0);
        $stmt = mysqli_prepare($conn, "DELETE FROM content_pages WHERE pageID=?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $flash = 'ok:Page deleted.';
    }

    header('Location: content_management.php?flash=' . urlencode($flash));
    exit;
}

if (isset($_GET['flash'])) $flash = $_GET['flash'];

/* ── Load pages ── */
$pages = [];
$r = mysqli_query($conn, "SELECT * FROM content_pages ORDER BY pageType ASC, pageTitle ASC, language ASC");
if ($r) { while ($row = mysqli_fetch_assoc($r)) $pages[] = $row; }

/* ── Edit: load one page ── */
$editPage = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($pages as $p) { if ($p['pageID'] == $eid) { $editPage = $p; break; } }
}

$langLabel = ['en'=>'EN','gr'=>'GR','el'=>'GR'];
$typeLabel  = ['static'=>'Static Page','blog'=>'Blog Post'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Content Management – Athena Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="admin-wrapper">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <div class="content-header">
      <div class="content-header-left">
        <h1>Content Management</h1>
        <p>Edit static pages and blog posts for your e-shop.</p>
      </div>
      <button class="btn-primary" onclick="openModal('modalAdd')">
        <i class="fas fa-plus"></i> New Page
      </button>
    </div>

    <div class="content-body">

      <?php if ($flash): ?>
        <?php [$type,$msg] = explode(':', $flash, 2); ?>
        <div class="flash flash-<?= $type === 'ok' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <!-- ── Content pages table ── -->
      <div class="card mb-6">
        <div class="card-title">Website Content</div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Page Title</th>
              <th>Type</th>
              <th>Language</th>
              <th>Status</th>
              <th>Last Modified</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pages as $page): ?>
            <tr>
              <td class="font-600"><?= htmlspecialchars($page['pageTitle']) ?></td>
              <td class="text-muted"><?= $typeLabel[$page['pageType']] ?? $page['pageType'] ?></td>
              <td>
                <span class="badge badge-muted" style="gap:5px">
                  <i class="fas fa-globe" style="font-size:10px"></i>
                  <?= strtoupper($langLabel[$page['language']] ?? $page['language']) ?>
                </span>
              </td>
              <td>
                <?php if ($page['isPublished']): ?>
                  <span class="badge badge-dark">Published</span>
                <?php else: ?>
                  <span class="badge badge-muted">Draft</span>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= date('n/j/Y', strtotime($page['updatedAt'])) ?></td>
              <td style="text-align:right">
                <a href="?edit=<?= $page['pageID'] ?>" class="btn-edit">
                  <i class="fas fa-pen"></i> Edit
                </a>
                <form method="POST" style="display:inline"
                      onsubmit="return confirmDelete('Delete this page?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="pageID" value="<?= $page['pageID'] ?>">
                  <button type="submit" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($pages)): ?>
              <tr><td colspan="6" class="text-muted" style="text-align:center;padding:32px 0">No pages yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ── Info cards ── -->
      <div class="grid-2">
        <div class="alert-card alert-blue">
          <div class="alert-title"><i class="fas fa-file-alt"></i> Static Pages</div>
          <p class="alert-text" style="margin-bottom:8px">
            Static pages include important information like About Us, Contact, Terms of Service, and Privacy Policy.
          </p>
          <p class="alert-text">
            <strong>Best Practices:</strong> Keep content updated, ensure accuracy, and maintain consistency across language versions.
          </p>
        </div>
        <div class="alert-card alert-purple">
          <div class="alert-title"><i class="fas fa-blog"></i> Blog Posts</div>
          <p class="alert-text" style="margin-bottom:8px">
            Use blog posts to share news, product launches, crafting tips, and engage with your community.
          </p>
          <p class="alert-text">
            <strong>Tips:</strong> Post regularly, use engaging images, write compelling titles, and encourage comments.
          </p>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- ── Add Page Modal ── -->
<div class="modal-overlay" id="modalAdd">
  <div class="modal-box">
    <h3>New Page</h3>
    <p class="modal-sub">Create a new static page or blog post.</p>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Page Title *</label>
          <input name="pageTitle" class="form-input" required placeholder="e.g. About Us">
        </div>
        <div class="form-group">
          <label class="form-label">Slug (URL)</label>
          <input name="slug" class="form-input" placeholder="about-us (auto-generated)">
        </div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Language</label>
          <select name="language" class="form-input">
            <option value="en">English (EN)</option>
            <option value="gr">Greek (GR)</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="pageType" class="form-input">
            <option value="static">Static Page</option>
            <option value="blog">Blog Post</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Content</label>
        <textarea name="content" class="form-input" style="min-height:140px"
                  placeholder="Enter the page content here..."></textarea>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:10px">
        <label class="toggle-wrap">
          <input type="checkbox" name="isPublished" value="1" checked>
          <span class="toggle-slider"></span>
        </label>
        <span class="text-sm">Publish immediately</span>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('modalAdd')">Cancel</button>
        <button type="submit" class="btn-save">Create Page</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Page Modal ── -->
<?php if ($editPage): ?>
<div class="modal-overlay show" id="modalEdit">
  <div class="modal-box">
    <h3>Edit Page</h3>
    <p class="modal-sub">Update "<?= htmlspecialchars($editPage['pageTitle']) ?>".</p>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="pageID" value="<?= $editPage['pageID'] ?>">
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label">Page Title *</label>
          <input name="pageTitle" class="form-input" required value="<?= htmlspecialchars($editPage['pageTitle']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Language</label>
          <select name="language" class="form-input">
            <option value="en" <?= $editPage['language']==='en'?'selected':'' ?>>English (EN)</option>
            <option value="gr" <?= $editPage['language']==='gr'||$editPage['language']==='el'?'selected':'' ?>>Greek (GR)</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Type</label>
        <select name="pageType" class="form-input">
          <option value="static" <?= $editPage['pageType']==='static'?'selected':'' ?>>Static Page</option>
          <option value="blog"   <?= $editPage['pageType']==='blog'  ?'selected':'' ?>>Blog Post</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Content</label>
        <textarea name="content" class="form-input" style="min-height:180px"><?= htmlspecialchars($editPage['content'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="display:flex;align-items:center;gap:10px">
        <label class="toggle-wrap">
          <input type="checkbox" name="isPublished" value="1" <?= $editPage['isPublished']?'checked':'' ?>>
          <span class="toggle-slider"></span>
        </label>
        <span class="text-sm">Published</span>
      </div>
      <div class="modal-footer">
        <a href="content_management.php" class="btn-cancel">Cancel</a>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="assets/admin.js"></script>
</body>
</html>
