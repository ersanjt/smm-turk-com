<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
$pageTitle = 'My Orders';
$om  = new OrderManager();
$uid = $auth->getUserId();

$status  = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$orders = $om->getUserOrders($uid, $status, $perPage, $offset);
$total  = $om->getUserOrderCount($uid, $status);
$pages  = (int) ceil($total / $perPage);

$statuses = ['', 'Pending', 'Processing', 'In progress', 'Completed', 'Partial', 'Cancelled'];

require_once __DIR__ . '/layouts/header.php';
?>

<style>
.orders-page .card { padding: 0; overflow: hidden; }
.orders-page .orders-head { padding: 20px 20px 0; }
.orders-page .orders-title { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; font-size: 1.15rem; font-weight: 700; color: var(--text); }
.orders-page .orders-title svg { width: 22px; height: 22px; color: var(--primary); flex-shrink: 0; }
.orders-page .order-filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.orders-page .order-filters a { padding: 8px 14px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 999px; white-space: nowrap; transition: background .2s, color .2s; }
.orders-page .order-filters a.badge-active { background: var(--primary); color: #fff; }
.orders-page .order-filters a:not(.badge-active) { background: var(--border); color: var(--text-muted); }
.orders-page .order-filters a:not(.badge-active):hover { background: #e8e0e2; color: var(--text); }
.orders-empty { text-align: center; padding: 48px 24px 56px; }
.orders-empty-icon { width: 80px; height: 80px; margin: 0 auto 20px; color: var(--text-muted); opacity: 0.7; }
.orders-empty h3 { font-size: 1.1rem; color: var(--text); margin-bottom: 8px; font-weight: 600; }
.orders-empty p { color: var(--text-muted); font-size: 14px; margin-bottom: 20px; max-width: 320px; margin-left: auto; margin-right: auto; }
.orders-empty .btn { display: inline-flex; align-items: center; gap: 8px; }
.orders-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-bottom: 0; border-radius: 0; border: none; border-top: 1px solid var(--border); }
.orders-table-wrap .table { min-width: 640px; }
.orders-cards { display: none; padding: 12px 16px 20px; }
.orders-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
.orders-card:last-child { margin-bottom: 0; }
.orders-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.orders-card-id { font-weight: 700; color: var(--text); font-size: 14px; }
.orders-card-status { flex-shrink: 0; }
.orders-card-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; }
.orders-card-row:last-child { margin-bottom: 0; }
.orders-card-row .label { color: var(--text-muted); }
.orders-card-link { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.orders-card-link a { color: var(--primary); }
.orders-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; padding: 16px 20px; border-top: 1px solid var(--border); }
.orders-pagination a, .orders-pagination span { padding: 8px 14px; min-height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 13px; font-weight: 600; text-decoration: none; transition: background .2s, color .2s; }
.orders-pagination a.pag-active { background: var(--primary); color: #fff; }
.orders-pagination a:not(.pag-active) { background: var(--border); color: var(--text-muted); }
.orders-pagination a:not(.pag-active):hover { background: #e8e0e2; color: var(--text); }
.orders-pagination .pag-ellipsis { color: var(--text-muted); padding: 0 4px; }
@media (max-width: 768px) {
  .orders-page .orders-head { padding: 16px 16px 0; }
  .orders-page .order-filters { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; margin-bottom: 12px; -webkit-overflow-scrolling: touch; }
  .orders-table-wrap { display: none; }
  .orders-cards { display: block; }
  .orders-empty { padding: 36px 20px 44px; }
  .orders-empty-icon { width: 64px; height: 64px; margin-bottom: 16px; }
}
@media (min-width: 769px) {
  .orders-cards { display: none !important; }
}
</style>

<div class="orders-page">
  <div class="card">
    <div class="orders-head">
      <h1 class="orders-title">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
        My Orders
      </h1>
      <nav class="order-filters" aria-label="Filter by status">
        <?php foreach ($statuses as $s): ?>
        <a href="?status=<?= urlencode($s) ?><?= $page > 1 ? '&page=1' : '' ?>"
           class="<?= $status === $s ? 'badge-active' : '' ?>">
          <?= $s ?: 'All' ?>
        </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <?php if (empty($orders)): ?>
      <div class="orders-empty">
        <svg class="orders-empty-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <h3><?= $status !== '' ? 'No orders in this filter' : 'No orders yet' ?></h3>
        <p><?= $status !== '' ? 'Try another status or place a new order.' : 'Place your first order to get started.' ?></p>
        <a href="<?= h(path('dashboard.php')) ?>" class="btn btn-primary">Place New Order</a>
      </div>
    <?php else: ?>
      <div class="orders-table-wrap table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Date</th>
              <th>Service</th>
              <th>Link</th>
              <th>Qty</th>
              <th>Charge</th>
              <th>Start</th>
              <th>Remains</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
              <td><strong>#<?= $o['id'] ?></strong></td>
              <td style="font-size:12px;color:var(--text-muted);"><?= date('Y-m-d H:i', strtotime($o['created_at'])) ?></td>
              <td style="max-width:220px;font-size:12px;"><?= h(mb_substr($o['service_name'] ?? '', 0, 70)) ?><?= mb_strlen($o['service_name'] ?? '') > 70 ? '…' : '' ?></td>
              <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;">
                <a href="<?= h($o['link']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--primary);"><?= h(mb_substr($o['link'], 0, 40)) ?><?= mb_strlen($o['link']) > 40 ? '…' : '' ?></a>
              </td>
              <td><?= number_format($o['quantity']) ?></td>
              <td><strong>$<?= number_format($o['charge'], 4) ?></strong></td>
              <td><?= number_format($o['start_count']) ?></td>
              <td><?= number_format($o['remains']) ?></td>
              <td>
                <span class="badge status-<?= str_replace(' ', '-', h($o['status'])) ?>"><?= h($o['status']) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="orders-cards">
        <?php foreach ($orders as $o): ?>
        <div class="orders-card">
          <div class="orders-card-top">
            <span class="orders-card-id">#<?= $o['id'] ?></span>
            <span class="badge status-<?= str_replace(' ', '-', h($o['status'])) ?> orders-card-status"><?= h($o['status']) ?></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Date</span>
            <span><?= date('Y-m-d H:i', strtotime($o['created_at'])) ?></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Service</span>
            <span style="max-width:55%;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($o['service_name'] ?? '', 0, 35)) ?><?= mb_strlen($o['service_name'] ?? '') > 35 ? '…' : '' ?></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Link</span>
            <span class="orders-card-link"><a href="<?= h($o['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h(mb_substr($o['link'], 0, 30)) ?><?= mb_strlen($o['link']) > 30 ? '…' : '' ?></a></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Qty</span>
            <span><?= number_format($o['quantity']) ?></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Charge</span>
            <span><strong>$<?= number_format($o['charge'], 4) ?></strong></span>
          </div>
          <div class="orders-card-row">
            <span class="label">Start / Remains</span>
            <span><?= number_format($o['start_count']) ?> / <?= number_format($o['remains']) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($pages > 1): ?>
      <nav class="orders-pagination" aria-label="Orders pagination">
        <?php
        $queryStatus = $status !== '' ? 'status=' . urlencode($status) . '&' : '';
        $prevUrl = $page > 1 ? path('orders.php') . '?' . $queryStatus . 'page=' . ($page - 1) : null;
        $nextUrl = $page < $pages ? path('orders.php') . '?' . $queryStatus . 'page=' . ($page + 1) : null;
        ?>
        <?php if ($prevUrl): ?>
          <a href="<?= h($prevUrl) ?>">← Prev</a>
        <?php endif; ?>
        <?php
        $range = 2;
        $start = max(1, $page - $range);
        $end   = min($pages, $page + $range);
        if ($start > 1): ?>
          <a href="<?= h(path('orders.php') . '?' . $queryStatus . 'page=1') ?>">1</a>
          <?php if ($start > 2): ?><span class="pag-ellipsis">…</span><?php endif;
        endif;
        for ($i = $start; $i <= $end; $i++): ?>
          <a href="<?= h(path('orders.php') . '?' . $queryStatus . 'page=' . $i) ?>" class="<?= $i === $page ? 'pag-active' : '' ?>"><?= $i ?></a>
        <?php endfor;
        if ($end < $pages): ?>
          <?php if ($end < $pages - 1): ?><span class="pag-ellipsis">…</span><?php endif; ?>
          <a href="<?= h(path('orders.php') . '?' . $queryStatus . 'page=' . $pages) ?>"><?= $pages ?></a>
        <?php endif; ?>
        <?php if ($nextUrl): ?>
          <a href="<?= h($nextUrl) ?>">Next →</a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
