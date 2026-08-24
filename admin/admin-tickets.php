<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Tickets';
$db = Database::getInstance();

$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($statusFilter !== '') {
    $where .= " AND t.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= " AND (t.subject LIKE ? OR t.order_id LIKE ? OR u.username LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$total = (int) $db->fetch("SELECT COUNT(*) c FROM tickets t JOIN users u ON t.user_id = u.id WHERE $where", $params)['c'];
$tickets = $db->fetchAll(
    "SELECT t.id, t.user_id, t.subject, t.status, t.created_at, t.updated_at, u.username FROM tickets t JOIN users u ON t.user_id = u.id WHERE $where ORDER BY COALESCE(t.updated_at, t.created_at) DESC LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = $total ? (int)ceil($total / $perPage) : 1;

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="card">
  <div class="card-title">Tickets</div>
  <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search subject, order ID, username" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;min-width:200px;">
    <select name="status" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;">
      <option value="">All statuses</option>
      <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
      <option value="answered" <?= $statusFilter === 'answered' ? 'selected' : '' ?>>Answered</option>
      <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
  <?php if (empty($tickets)): ?>
  <p style="color:var(--text-muted);">No tickets found.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>ID</th><th>User</th><th>Subject</th><th>Status</th><th>Updated</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr>
        <td>#<?= (int)$t['id'] ?></td>
        <td><?= h($t['username']) ?></td>
        <td><?= h(mb_substr($t['subject'], 0, 50)) ?><?= mb_strlen($t['subject']) > 50 ? '…' : '' ?></td>
        <td><span class="badge <?= $t['status'] === 'open' ? 'badge-orange' : ($t['status'] === 'answered' ? 'badge-green' : 'badge-gray') ?>"><?= h($t['status']) ?></span></td>
        <td style="font-size:12px;color:var(--text-muted);"><?= h(date('Y-m-d H:i', strtotime($t['updated_at'] ?? $t['created_at']))) ?></td>
        <td><a href="<?= h(path('admin/admin-ticket.php')) ?>?id=<?= (int)$t['id'] ?>" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">View & Reply</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
    <?php if ($page > 1): ?><a href="?p=1&status=<?= h(urlencode($statusFilter)) ?>&q=<?= h(urlencode($search)) ?>">First</a> <a href="?p=<?= $page - 1 ?>&status=<?= h(urlencode($statusFilter)) ?>&q=<?= h(urlencode($search)) ?>">Prev</a><?php endif; ?>
    <span>Page <?= $page ?> / <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?p=<?= $page + 1 ?>&status=<?= h(urlencode($statusFilter)) ?>&q=<?= h(urlencode($search)) ?>">Next</a> <a href="?p=<?= $totalPages ?>&status=<?= h(urlencode($statusFilter)) ?>&q=<?= h(urlencode($search)) ?>">Last</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
