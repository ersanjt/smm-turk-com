<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Manage Orders';
$db = Database::getInstance();
$om = new OrderManager();

$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$listQs = http_build_query(array_filter(['q' => $search ?: null, 'status' => $statusFilter ?: null, 'p' => $page > 1 ? $page : null]));
$listUrl = url('admin/admin-orders.php') . ($listQs ? '?' . $listQs : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['sync_orders'])) {
        try {
            $updated = $om->syncOrders(null, 200);
            if ($updated > 0) {
                flash('success', "Updated {$updated} order(s) from the provider.");
            } else {
                flash('success', 'Checked provider — no status changes. Pending orders are waiting on the upstream provider (SmmFollows / SMMFA).');
            }
        } catch (Throwable $e) {
            Logger::log('admin syncOrders: ' . $e->getMessage(), 'orders');
            flash('error', 'Sync failed. Check API keys in Admin → Settings and tmp/logs/orders.log');
        }
        redirect($listUrl);
    }
    if (isset($_POST['resubmit_unsent'])) {
        try {
            $result = $om->resubmitUnsentOrders(30);
            $msg = "Re-sent {$result['sent']} stuck order(s) to the provider.";
            if ($result['failed'] > 0) {
                $msg .= " {$result['failed']} failed — see orders log.";
            }
            if ($result['checked'] === 0) {
                $msg = 'No pending orders without a provider ID.';
            }
            flash($result['failed'] > 0 && $result['sent'] === 0 ? 'error' : 'success', $msg);
        } catch (Throwable $e) {
            Logger::log('admin resubmit: ' . $e->getMessage(), 'orders');
            flash('error', 'Resend failed. Check provider API key and balance.');
        }
        redirect($listUrl);
    }
}

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

$unsentCount = (int) ($db->fetch(
    "SELECT COUNT(*) c FROM orders WHERE status = 'Pending' AND (provider_order_id IS NULL OR provider_order_id = 0)"
)['c'] ?? 0);
$openCount = (int) ($db->fetch(
    "SELECT COUNT(*) c FROM orders WHERE status IN ('Pending','Processing','In progress')"
)['c'] ?? 0);

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
  <div style="padding:0 20px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <form method="POST" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <button type="submit" name="sync_orders" value="1" class="btn btn-primary btn-sm">🔄 Sync statuses from provider</button>
    </form>
    <?php if ($unsentCount > 0): ?>
    <form method="POST" style="margin:0;" onsubmit="return confirm('Re-send <?= (int)$unsentCount ?> pending order(s) that have no provider ID? Only use if they were never sent.');">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <button type="submit" name="resubmit_unsent" value="1" class="btn btn-sm">Re-send <?= (int)$unsentCount ?> stuck order(s)</button>
    </form>
    <?php endif; ?>
    <span style="font-size:12px;color:var(--text-muted);"><?= (int)$openCount ?> open · statuses update from SmmFollows / SMMFA (cron every 5 min, or this button)</span>
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
          <th>Provider ID</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="9" data-label="" style="text-align:center;padding:40px;color:var(--text-muted);">No orders found.</td></tr>
        <?php else: ?>
        <?php foreach ($orders as $o): ?>
        <tr>
          <td data-label="ID"><strong>#<?= (int)$o['id'] ?></strong></td>
          <td data-label="User"><?= h($o['username']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= h($o['email']) ?></span></td>
          <td data-label="Service" style="font-size:12px;"><?= h(mb_substr($o['service_name'] ?? '', 0, 50)) ?><?= mb_strlen($o['service_name'] ?? '') > 50 ? '…' : '' ?></td>
          <td data-label="Link"><a href="<?= h($o['link']) ?>" target="_blank" rel="noopener" style="color:var(--primary);font-size:11px;word-break:break-all;"><?= h(mb_substr($o['link'], 0, 60)) ?><?= mb_strlen($o['link']) > 60 ? '…' : '' ?></a></td>
          <td data-label="Qty"><?= number_format($o['quantity']) ?></td>
          <td data-label="Charge"><strong>$<?= number_format($o['charge'], 4) ?></strong></td>
          <td data-label="Provider ID" style="font-size:12px;color:var(--text-muted);"><?= !empty($o['provider_order_id']) ? (int)$o['provider_order_id'] : '—' ?></td>
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
