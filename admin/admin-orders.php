<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Manage Orders';
$db = Database::getInstance();

$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($statusFilter !== '') {
    $where .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= " AND (u.username LIKE ? OR u.email LIKE ? OR o.id = ? OR o.link LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = ctype_digit($search) ? $search : -1;
    $params[] = '%' . $search . '%';
}

$total = (int) $db->fetch("SELECT COUNT(*) c FROM orders o JOIN users u ON o.user_id = u.id WHERE $where", $params)['c'];
$orders = $db->fetchAll(
    "SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE $where ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = $total ? (int)ceil($total / $perPage) : 1;

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="card admin-page-card">
  <div class="admin-page-head">
    <div class="card-title">📦 Manage Orders</div>
    <form method="GET" class="admin-search-form">
      <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="User, ID, link…">
      <select name="status" class="form-control">
        <option value="">All statuses</option>
        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
        <option value="Processing" <?= $statusFilter === 'Processing' ? 'selected' : '' ?>>Processing</option>
        <option value="In progress" <?= $statusFilter === 'In progress' ? 'selected' : '' ?>>In progress</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
        <option value="Partial" <?= $statusFilter === 'Partial' ? 'selected' : '' ?>>Partial</option>
        <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
        <option value="Refunded" <?= $statusFilter === 'Refunded' ? 'selected' : '' ?>>Refunded</option>
      </select>
      <button type="submit" class="btn btn-primary">Search</button>
    </form>
  </div>
  <div class="table-wrap admin-table-wrap">
    <table class="table table-wide table-mobile-cards">
      <thead>
        <tr>
          <th>ID</th>
          <th>User</th>
          <th>Service</th>
          <th>Link</th>
          <th>Qty</th>
          <th>Charge</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="8" data-label="" style="text-align:center;padding:40px;color:var(--text-muted);">No orders found.</td></tr>
        <?php else: ?>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td data-label="ID"><strong>#<?= (int)$o['id'] ?></strong></td>
          <td data-label="User"><?= h($o['username']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= h($o['email']) ?></span></td>
          <td data-label="Service" style="font-size:12px;"><?= h(mb_substr($o['service_name'] ?? '', 0, 50)) ?><?= mb_strlen($o['service_name'] ?? '') > 50 ? '…' : '' ?></td>
          <td data-label="Link"><a href="<?= h($o['link']) ?>" target="_blank" rel="noopener" style="color:var(--primary);font-size:11px;word-break:break-all;"><?= h(mb_substr($o['link'], 0, 60)) ?><?= mb_strlen($o['link']) > 60 ? '…' : '' ?></a></td>
          <td data-label="Qty"><?= number_format($o['quantity']) ?></td>
          <td data-label="Charge"><strong>$<?= number_format($o['charge'], 4) ?></strong></td>
          <td data-label="Status"><span class="badge status-<?= str_replace(' ', '-', h($o['status'])) ?>"><?= h($o['status']) ?></span></td>
          <td data-label="Date" style="font-size:11px;color:var(--text-muted);"><?= date('Y-m-d H:i', strtotime($o['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="admin-pagination">
    <?php
    $qs = http_build_query(array_filter(['q' => $search ?: null, 'status' => $statusFilter ?: null]));
    for ($i = 1; $i <= min($totalPages, 20); $i++):
      $url = '?p=' . $i . ($qs ? '&' . $qs : '');
    ?>
    <a href="<?= $url ?>" class="badge <?= $i === $page ? 'badge-blue' : 'badge-gray' ?>" style="padding:5px 12px;text-decoration:none;"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($totalPages > 20): ?><span style="color:var(--text-muted);font-size:12px;">…</span><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
