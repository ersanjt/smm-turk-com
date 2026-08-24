<?php
/**
 * Admin: Blog — list articles, categories, tags. Add category/tag.
 */
require_once __DIR__ . '/_init.php';
$pageTitle = 'Blog';
$pageDescription = 'Manage blog articles, categories, and tags';
$db = Database::getInstance();

// POST: add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    if (!csrf_verify()) {
        flash('error', 'Invalid request. Please try again.');
        redirect(url('admin/admin-blog.php'));
    }
    $name = trim((string)($_POST['cat_name'] ?? ''));
    $slug = trim(preg_replace('/[^a-z0-9\-]/', '-', strtolower((string)($_POST['cat_slug'] ?? ''))));
    if ($name !== '' && $slug !== '') {
        $db->insert("INSERT INTO blog_categories (slug, name, meta_description) VALUES (?, ?, ?)", [$slug, $name, trim((string)($_POST['cat_meta'] ?? ''))]);
        flash('success', 'Category added.');
    }
    redirect(url('admin/admin-blog.php'));
}

// POST: add tag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tag'])) {
    if (!csrf_verify()) {
        flash('error', 'Invalid request. Please try again.');
        redirect(url('admin/admin-blog.php'));
    }
    $name = trim((string)($_POST['tag_name'] ?? ''));
    $slug = trim(preg_replace('/[^a-z0-9\-]/', '-', strtolower((string)($_POST['tag_slug'] ?? ''))));
    if ($name !== '' && $slug !== '') {
        $db->insert("INSERT INTO blog_tags (slug, name) VALUES (?, ?)", [$slug, $name]);
        flash('success', 'Tag added.');
    }
    redirect(url('admin/admin-blog.php'));
}

// POST: delete article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_article']) && csrf_verify()) {
    $articleId = (int) $_POST['delete_article'];
    if ($articleId > 0) {
        $db->execute("DELETE FROM blog_article_tags WHERE article_id = ?", [$articleId]);
        $db->execute("DELETE FROM blog_articles WHERE id = ?", [$articleId]);
        flash('success', 'Article deleted.');
    }
    redirect(url('admin/admin-blog.php'));
}

// POST: delete category/tag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cat']) && csrf_verify()) {
    $catId = (int)$_POST['delete_cat'];
    if ($catId > 0) {
        $db->execute("DELETE FROM blog_categories WHERE id = ?", [$catId]);
        flash('success', 'Category deleted.');
    }
    redirect(url('admin/admin-blog.php'));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tag']) && csrf_verify()) {
    $tagId = (int)$_POST['delete_tag'];
    if ($tagId > 0) {
        $db->execute("DELETE FROM blog_tags WHERE id = ?", [$tagId]);
        flash('success', 'Tag deleted.');
    }
    redirect(url('admin/admin-blog.php'));
}

$articles = $db->fetchAll("SELECT a.id, a.slug, a.title, a.status, a.published_at, c.name AS category_name FROM blog_articles a LEFT JOIN blog_categories c ON c.id = a.category_id ORDER BY a.updated_at DESC");
$categories = $db->fetchAll("SELECT id, slug, name FROM blog_categories ORDER BY name");
$tags = $db->fetchAll("SELECT id, slug, name FROM blog_tags ORDER BY name");

require_once __DIR__ . '/../layouts/header.php';
?>
<div class="card">
  <h2 class="card-title">Blog — Articles (<?= count($articles) ?>)</h2>
  <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Create, edit, or delete posts shown on <a href="<?= h(path('blog.php')) ?>" target="_blank" rel="noopener">/blog</a>.</p>
  <p><a href="<?= h(path('admin/admin-blog-edit.php')) ?>" class="btn btn-primary">+ New Article</a> &nbsp; <a href="<?= h(path('blog.php')) ?>" target="_blank" rel="noopener" class="btn" style="background:var(--border);">View Blog</a></p>
  <?php if (empty($articles)): ?>
  <p>No articles yet. Click <strong>+ New Article</strong> to create your first post.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Published</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a): ?>
      <tr>
        <td><strong><?= h($a['title']) ?></strong><br><small><?= h($a['slug']) ?></small></td>
        <td><?= h($a['category_name'] ?? '—') ?></td>
        <td><span class="badge badge-<?= $a['status'] === 'published' ? 'green' : 'gray' ?>"><?= h($a['status']) ?></span></td>
        <td><?= $a['published_at'] ? date('Y-m-d', strtotime($a['published_at'])) : '—' ?></td>
        <td style="white-space:nowrap;">
          <?php if ($a['status'] === 'published'): ?>
          <a href="<?= h(path('blog/' . $a['slug'])) ?>" target="_blank" rel="noopener">View</a>
          &nbsp;·&nbsp;
          <?php endif; ?>
          <a href="<?= h(path('admin/admin-blog-edit.php') . '?id=' . $a['id']) ?>">Edit</a>
          &nbsp;·&nbsp;
          <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Delete this article permanently?');">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="delete_article" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="btn-link" style="color:var(--red);background:none;border:none;padding:0;font:inherit;cursor:pointer;">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="grid2" style="margin-top:24px;">
  <div class="card">
    <h2 class="card-title">Categories</h2>
    <form method="post" style="margin-bottom:16px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="add_category" value="1">
      <div class="form-group">
        <label class="form-label">Name</label>
        <input type="text" name="cat_name" class="form-control" required placeholder="e.g. SMM Tips">
      </div>
      <div class="form-group">
        <label class="form-label">Slug</label>
        <input type="text" name="cat_slug" class="form-control" placeholder="smm-tips">
      </div>
      <div class="form-group">
        <label class="form-label">Meta description (optional)</label>
        <input type="text" name="cat_meta" class="form-control" placeholder="SEO description">
      </div>
      <button type="submit" class="btn btn-primary">Add Category</button>
    </form>
    <ul style="list-style:none;padding:0;">
      <?php foreach ($categories as $c): ?>
      <li style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <span><?= h($c['name']) ?> <code><?= h($c['slug']) ?></code></span>
        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this category?');">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="delete_cat" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn" style="padding:4px 8px;font-size:12px;color:var(--red);background:transparent;border:1px solid var(--red);">Delete</button>
        </form>
      </li>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?><li>No categories.</li><?php endif; ?>
    </ul>
  </div>
  <div class="card">
    <h2 class="card-title">Tags</h2>
    <form method="post" style="margin-bottom:16px;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="add_tag" value="1">
      <div class="form-group">
        <label class="form-label">Name</label>
        <input type="text" name="tag_name" class="form-control" required placeholder="e.g. Instagram">
      </div>
      <div class="form-group">
        <label class="form-label">Slug</label>
        <input type="text" name="tag_slug" class="form-control" placeholder="instagram">
      </div>
      <button type="submit" class="btn btn-primary">Add Tag</button>
    </form>
    <ul style="list-style:none;padding:0;">
      <?php foreach ($tags as $t): ?>
      <li style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <span><?= h($t['name']) ?> <code><?= h($t['slug']) ?></code></span>
        <form method="post" style="margin:0;" onsubmit="return confirm('Delete this tag?');">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="delete_tag" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn" style="padding:4px 8px;font-size:12px;color:var(--red);background:transparent;border:1px solid var(--red);">Delete</button>
        </form>
      </li>
      <?php endforeach; ?>
      <?php if (empty($tags)): ?><li>No tags.</li><?php endif; ?>
    </ul>
  </div>
</div>
<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
