<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../app/PlatformIcons.php';
$pageTitle = 'Services';
$extraCssHref = asset_url('assets/css/admin-services.css');
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_service' && ($sid = (int) ($_POST['service_id'] ?? 0))) {
        $markup = (float) ($_POST['markup'] ?? 0);
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $priority = (int) ($_POST['sort_priority'] ?? 0);
        $db->execute('UPDATE services SET markup = ?, is_featured = ?, sort_priority = ? WHERE service_id = ?', [$markup, $featured, $priority, $sid]);
        flash('success', 'Service #' . $sid . ' updated.');
        redirect(url('admin/admin-services.php') . '?' . http_build_query(array_filter(['q' => trim($_GET['q'] ?? '') ?: null, 'cat' => trim($_GET['cat'] ?? '') ?: null, 'p' => max(1, (int) ($_GET['p'] ?? 1)) > 1 ? (int) $_GET['p'] : null])));
    }
}

$search = trim($_GET['q'] ?? '');
$category = trim($_GET['cat'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = "status = 'active'";
$params = [];
if ($search !== '') {
    $where .= " AND (name LIKE ? OR CAST(service_id AS CHAR) = ?)";
    $params[] = '%' . $search . '%';
    $params[] = ctype_digit($search) ? $search : '-1';
}
if ($category !== '') {
    $where .= " AND category = ?";
    $params[] = $category;
}

$total = (int) $db->fetch("SELECT COUNT(*) c FROM services WHERE $where", $params)['c'];
$services = $db->fetchAll(
    "SELECT * FROM services WHERE $where ORDER BY category ASC, service_id ASC LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = $total ? (int) ceil($total / $perPage) : 1;

$globalStats = $db->fetch(
    "SELECT COUNT(*) AS total,
            COUNT(DISTINCT category) AS categories,
            COALESCE(AVG(markup), 0) AS avg_markup,
            COALESCE(SUM(refill), 0) AS refill_count
     FROM services WHERE status = 'active'"
) ?: ['total' => 0, 'categories' => 0, 'avg_markup' => 0, 'refill_count' => 0];

$categoryRows = $db->fetchAll(
    "SELECT category, COUNT(*) AS cnt FROM services WHERE status = 'active' GROUP BY category ORDER BY category ASC"
);

$hasProviderCol = false;
try {
    $db->fetch("SELECT provider FROM services LIMIT 1");
    $hasProviderCol = true;
} catch (Throwable $e) {
    /* column may not exist on older DB */
}

$svcUrl = static function (array $overrides = []) use ($search, $category, $page): string {
    $q = array_filter([
        'q' => $search !== '' ? $search : null,
        'cat' => $category !== '' ? $category : null,
        'p' => $page > 1 ? $page : null,
    ], static fn ($v) => $v !== null && $v !== '');
    $q = array_merge($q, array_filter($overrides, static fn ($v) => $v !== null && $v !== ''));
    if (isset($overrides['p']) && (int) $overrides['p'] <= 1) {
        unset($q['p']);
    }
    if (isset($overrides['cat']) && $overrides['cat'] === '') {
        unset($q['cat']);
    }
    if (isset($overrides['q']) && $overrides['q'] === '') {
        unset($q['q']);
    }
    $qs = http_build_query($q);
    return '?' . $qs;
};

$hideAdminPageHeader = true;
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="admin-svc">
  <a href="<?= h(admin_path('index.php')) ?>" class="admin-back-link" style="margin-bottom:14px;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    <span>Admin Panel</span>
  </a>
  <header class="admin-svc-header">
    <div>
      <h1>Services catalog</h1>
      <p>Active services synced from your provider. Search by ID or name, filter by category, or sync to refresh the list.</p>
    </div>
    <div class="admin-svc-actions">
      <a href="<?= h(path('admin/admin-sync.php')) ?>" class="btn btn-primary">Sync from provider</a>
      <a href="<?= h(path('services.php')) ?>" class="btn" style="background:var(--bg);border:1px solid var(--border);color:var(--text);">View as user</a>
    </div>
  </header>

  <div class="admin-svc-stats">
    <div class="admin-svc-stat">
      <span class="admin-svc-stat-label">Active services</span>
      <span class="admin-svc-stat-value accent"><?= number_format((int) $globalStats['total']) ?></span>
    </div>
    <div class="admin-svc-stat">
      <span class="admin-svc-stat-label">Categories</span>
      <span class="admin-svc-stat-value"><?= number_format((int) $globalStats['categories']) ?></span>
    </div>
    <div class="admin-svc-stat">
      <span class="admin-svc-stat-label">Avg markup</span>
      <span class="admin-svc-stat-value"><?= number_format((float) $globalStats['avg_markup'], 1) ?>%</span>
    </div>
    <div class="admin-svc-stat">
      <span class="admin-svc-stat-label">With refill</span>
      <span class="admin-svc-stat-value"><?= number_format((int) $globalStats['refill_count']) ?></span>
    </div>
  </div>

  <div class="admin-svc-panel">
    <div class="admin-svc-toolbar">
      <form method="GET" class="admin-svc-search">
        <?php if ($category !== ''): ?><input type="hidden" name="cat" value="<?= h($category) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Search by name or service ID…" autocomplete="off">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search !== '' || $category !== ''): ?>
        <a href="?" class="btn" style="background:var(--bg);border:1px solid var(--border);color:var(--text-muted);">Clear</a>
        <?php endif; ?>
      </form>
      <div class="admin-svc-meta">
        <?php if ($search !== '' || $category !== ''): ?>
          <?= number_format($total) ?> result<?= $total === 1 ? '' : 's' ?>
        <?php else: ?>
          Page <?= $page ?> of <?= $totalPages ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($categoryRows)): ?>
    <div class="admin-svc-cats">
      <div class="admin-svc-cats-scroll">
        <a href="<?= h($svcUrl(['cat' => '', 'p' => 1])) ?>" class="admin-svc-cat <?= $category === '' ? 'active' : '' ?>">
          All <span class="cat-count"><?= number_format((int) $globalStats['total']) ?></span>
        </a>
        <?php foreach ($categoryRows as $c):
            $pKey = platformKeyFromCategory($c['category']);
            $brandColor = platformBrandColor($pKey);
        ?>
        <a href="<?= h($svcUrl(['cat' => $c['category'], 'p' => 1])) ?>" class="admin-svc-cat <?= $category === $c['category'] ? 'active' : '' ?>">
          <span class="admin-svc-cat-icon" style="color:<?= h($brandColor) ?>"><?= platformSvgBrand($pKey, 14) ?></span>
          <?= h(ProviderRegistry::displayCategoryName($c['category'])) ?>
          <span class="cat-count"><?= number_format((int) $c['cnt']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="admin-svc-table-wrap">
      <table class="admin-svc-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Service</th>
            <th>Category</th>
            <?php if ($hasProviderCol): ?><th>Provider</th><?php endif; ?>
            <th>Rate / 1k</th>
            <th>Retail / 1k</th>
            <th>Min – Max</th>
            <th>Markup</th>
            <th>Featured</th>
            <th>Refill</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($services)): ?>
          <tr>
            <td colspan="<?= $hasProviderCol ? 10 : 9 ?>">
              <div class="admin-svc-empty">
                <div class="admin-svc-empty-icon">📭</div>
                <h3>No services found</h3>
                <p><?= $search !== '' || $category !== '' ? 'Try a different search or category.' : 'Sync from your provider to import services.' ?></p>
                <a href="<?= h(path('admin/admin-sync.php')) ?>" class="btn btn-primary" style="margin-top:12px;">Sync now</a>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($services as $s):
              $markup = (float) ($s['markup'] ?? 0);
              $rate = (float) $s['rate'];
              $retail = round($rate * (1 + $markup / 100), 5);
              $pKey = platformKeyFromCategory($s['category'] ?? '');
              $brandColor = platformBrandColor($pKey);
              $name = (string) ($s['name'] ?? '');
          ?>
          <tr>
            <td><span class="admin-svc-id"><?= (int) $s['service_id'] ?></span></td>
            <td>
              <div class="admin-svc-name">
                <span class="admin-svc-name-icon" style="--svc-color:<?= h($brandColor) ?>"><?= platformSvgBrand($pKey, 16) ?></span>
                <span class="admin-svc-name-text" title="<?= h($name) ?>"><?= h(mb_strlen($name) > 72 ? mb_substr($name, 0, 72) . '…' : $name) ?></span>
              </div>
            </td>
            <td class="admin-svc-cat-cell"><?= h(ProviderRegistry::displayCategoryName($s['category'] ?? '')) ?></td>
            <?php if ($hasProviderCol): ?>
            <td><span class="admin-svc-provider"><?= h($s['provider'] ?? 'smmfollows') ?></span></td>
            <?php endif; ?>
            <td><span class="admin-svc-rate">$<?= number_format($rate, 4) ?></span></td>
            <td>
              <span class="admin-svc-rate">$<?= number_format($retail, 4) ?>
                <small>incl. markup</small>
              </span>
            </td>
            <td class="admin-svc-range"><?= number_format((int) $s['min']) ?> – <?= number_format((int) $s['max']) ?></td>
            <td>
              <form method="POST" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_service">
                <input type="hidden" name="service_id" value="<?= (int) $s['service_id'] ?>">
                <input type="number" name="markup" step="0.1" min="0" max="200" value="<?= number_format($markup, 1, '.', '') ?>" class="form-control" style="width:64px;padding:4px 6px;font-size:12px;">
                <input type="number" name="sort_priority" min="0" max="999" value="<?= (int) ($s['sort_priority'] ?? 0) ?>" title="Sort priority" class="form-control" style="width:48px;padding:4px;font-size:11px;">
                <label title="Featured on dashboard" style="font-size:11px;white-space:nowrap;"><input type="checkbox" name="is_featured" value="1" <?= !empty($s['is_featured']) ? 'checked' : '' ?>> ⭐</label>
                <button type="submit" class="btn btn-sm" style="padding:4px 8px;font-size:11px;">Save</button>
              </form>
            </td>
            <td><?= !empty($s['is_featured']) ? '⭐' : '—' ?></td>
            <td>
              <span class="admin-svc-pill <?= !empty($s['refill']) ? 'yes' : 'no' ?>">
                <?= !empty($s['refill']) ? 'Yes' : 'No' ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="admin-svc-pagination">
      <div class="admin-svc-page-info">
        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?> of <?= number_format($total) ?>
      </div>
      <div class="admin-svc-page-links">
        <?php if ($page > 1): ?>
        <a href="<?= h($svcUrl(['p' => $page - 1])) ?>" class="admin-svc-page-btn">← Prev</a>
        <?php else: ?>
        <span class="admin-svc-page-btn disabled">← Prev</span>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);
        if ($start > 1): ?>
        <a href="<?= h($svcUrl(['p' => 1])) ?>" class="admin-svc-page-btn">1</a>
        <?php if ($start > 2): ?><span class="admin-svc-page-btn disabled">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="<?= h($svcUrl(['p' => $i])) ?>" class="admin-svc-page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?><span class="admin-svc-page-btn disabled">…</span><?php endif; ?>
        <a href="<?= h($svcUrl(['p' => $totalPages])) ?>" class="admin-svc-page-btn"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
        <a href="<?= h($svcUrl(['p' => $page + 1])) ?>" class="admin-svc-page-btn">Next →</a>
        <?php else: ?>
        <span class="admin-svc-page-btn disabled">Next →</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
