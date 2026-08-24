<?php
/**
 * Admin: Edit or create blog article.
 */
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post = $id ? $db->fetch("SELECT * FROM blog_articles WHERE id = ?", [$id]) : null;
$isNew = !$post;

if (!$isNew && !$post) {
    header('Location: ' . url('admin/admin-blog.php'));
    exit;
}

$categories = $db->fetchAll("SELECT id, name, slug FROM blog_categories ORDER BY name");
$allTags = $db->fetchAll("SELECT id, name, slug FROM blog_tags ORDER BY name");
$articleTags = [];
if ($post) {
    $articleTags = $db->fetchAll("SELECT tag_id FROM blog_article_tags WHERE article_id = ?", [$post['id']]);
    $articleTags = array_column($articleTags, 'tag_id');
}

// POST save or delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid request. Please try again.');
        redirect(url('admin/admin-blog.php'));
    }

    if (isset($_POST['delete_article']) && !$isNew) {
        $db->execute("DELETE FROM blog_article_tags WHERE article_id = ?", [$id]);
        $db->execute("DELETE FROM blog_articles WHERE id = ?", [$id]);
        flash('success', 'Article deleted.');
        header('Location: ' . url('admin/admin-blog.php'));
        exit;
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $slug = trim(preg_replace('/[^a-z0-9\-]/', '-', strtolower((string)($_POST['slug'] ?? ''))));
    $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $metaDesc = trim((string)($_POST['meta_description'] ?? ''));
    $metaKeywords = trim((string)($_POST['meta_keywords'] ?? ''));
    $excerpt = trim((string)($_POST['excerpt'] ?? ''));
    $body = (string)($_POST['body'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['draft', 'published']) ? $_POST['status'] : 'draft';
    $publishedAt = trim((string)($_POST['published_at'] ?? ''));
    $readingTime = isset($_POST['reading_time_min']) && $_POST['reading_time_min'] !== '' ? (int)$_POST['reading_time_min'] : null;
    $featuredImage = trim((string)($_POST['featured_image'] ?? ''));
    $tagIds = isset($_POST['tag_ids']) && is_array($_POST['tag_ids']) ? array_map('intval', array_filter($_POST['tag_ids'])) : [];

    if ($title === '' || $slug === '') {
        flash('error', 'Title and slug required.');
    } else {
        $existing = $db->fetch("SELECT id FROM blog_articles WHERE slug = ? AND id != ?", [$slug, $id ?: 0]);
        if ($existing) {
            flash('error', 'Slug already used.');
        } else {
            if ($isNew) {
                $newId = $db->insert(
                    "INSERT INTO blog_articles (category_id, author_id, slug, title, meta_description, meta_keywords, excerpt, body, featured_image, status, published_at, reading_time_min) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$categoryId, $auth->getUserId(), $slug, $title, $metaDesc, $metaKeywords, $excerpt, $body, $featuredImage ?: null, $status, $publishedAt ?: null, $readingTime]
                );
                foreach ($tagIds as $tid) {
                    $db->execute("INSERT IGNORE INTO blog_article_tags (article_id, tag_id) VALUES (?, ?)", [$newId, $tid]);
                }
                flash('success', 'Article created.');
            } else {
                $db->execute(
                    "UPDATE blog_articles SET category_id = ?, slug = ?, title = ?, meta_description = ?, meta_keywords = ?, excerpt = ?, body = ?, featured_image = ?, status = ?, published_at = ?, reading_time_min = ?, updated_at = NOW() WHERE id = ?",
                    [$categoryId, $slug, $title, $metaDesc, $metaKeywords, $excerpt, $body, $featuredImage ?: null, $status, $publishedAt ?: null, $readingTime, $id]
                );
                $db->execute("DELETE FROM blog_article_tags WHERE article_id = ?", [$id]);
                foreach ($tagIds as $tid) {
                    $db->execute("INSERT IGNORE INTO blog_article_tags (article_id, tag_id) VALUES (?, ?)", [$id, $tid]);
                }
                flash('success', 'Article updated.');
            }
            header('Location: ' . url('admin/admin-blog.php'));
            exit;
        }
    }
    // Re-fill for validation error
    $post = [
        'title' => $title ?? '',
        'slug' => $slug ?? '',
        'category_id' => $categoryId,
        'meta_description' => $metaDesc ?? '',
        'meta_keywords' => $metaKeywords ?? '',
        'excerpt' => $excerpt ?? '',
        'body' => $body ?? '',
        'status' => $status ?? 'draft',
        'published_at' => $publishedAt ?? '',
        'reading_time_min' => $readingTime,
        'featured_image' => $featuredImage ?? '',
    ];
    $articleTags = $tagIds;
}

$pageTitle = $isNew ? 'New Article' : 'Edit Article';
$backUrl = admin_path('admin-blog.php');
$backLabel = 'Manage Blog';
require_once __DIR__ . '/../layouts/header.php';
?>
<div class="card">
  <h2 class="card-title"><?= $isNew ? 'New Article' : 'Edit Article' ?></h2>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">Title *</label>
      <input type="text" name="title" class="form-control" value="<?= h($post['title'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Slug * (URL-friendly, e.g. how-to-get-instagram-followers)</label>
      <input type="text" name="slug" class="form-control" value="<?= h($post['slug'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Category</label>
      <select name="category_id" class="form-control">
        <option value="">— None —</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)($post['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Meta description (SEO)</label>
      <input type="text" name="meta_description" class="form-control" value="<?= h($post['meta_description'] ?? '') ?>" maxlength="512" placeholder="155–160 chars recommended">
    </div>
    <div class="form-group">
      <label class="form-label">Meta keywords (SEO, comma-separated)</label>
      <input type="text" name="meta_keywords" class="form-control" value="<?= h($post['meta_keywords'] ?? '') ?>" maxlength="512">
    </div>
    <div class="form-group">
      <label class="form-label">Featured image (SEO / social sharing)</label>
      <input type="text" name="featured_image" class="form-control" value="<?= h($post['featured_image'] ?? '') ?>" maxlength="512" placeholder="assets/img/blog/my-post.jpg or /uploads/blog/image.jpg">
      <small style="color:var(--text-muted);">1200×630 recommended. Used in blog listing, article page, and Open Graph.</small>
    </div>
    <div class="form-group">
      <label class="form-label">Excerpt (short summary)</label>
      <textarea name="excerpt" class="form-control" rows="2"><?= h($post['excerpt'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Body (HTML allowed)</label>
      <textarea name="body" class="form-control" rows="16"><?= h($post['body'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Tags</label>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($allTags as $t): ?>
        <label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="tag_ids[]" value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $articleTags) ? 'checked' : '' ?>> <?= h($t['name']) ?></label>
        <?php endforeach; ?>
        <?php if (empty($allTags)): ?>No tags. <a href="<?= h(path('admin/admin-blog.php')) ?>">Add tags</a> first.<?php endif; ?>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select name="status" class="form-control">
        <option value="draft" <?= ($post['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="published" <?= ($post['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Published at (YYYY-MM-DD HH:MM or leave empty)</label>
      <input type="datetime-local" name="published_at" class="form-control" value="<?= !empty($post['published_at']) ? date('Y-m-d\TH:i', strtotime($post['published_at'])) : '' ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Reading time (minutes, optional)</label>
      <input type="number" name="reading_time_min" class="form-control" value="<?= h($post['reading_time_min'] ?? '') ?>" min="1" max="120" placeholder="5">
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="<?= h(path('admin/admin-blog.php')) ?>" class="btn" style="margin-left:8px;">Cancel</a>
    <?php if (!$isNew): ?>
    <button type="submit" name="delete_article" value="1" class="btn" style="margin-left:8px;color:var(--red);border:1px solid var(--red);background:transparent;" onclick="return confirm('Delete this article permanently?');">Delete</button>
    <?php endif; ?>
  </form>
</div>
<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
