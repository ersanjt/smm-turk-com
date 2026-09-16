<?php
require_once __DIR__ . '/_init.php';
if (!function_exists('csrf_require')) {
    function csrf_require(string $redirectTo = ''): void {
        if (function_exists('csrf_verify') && csrf_verify()) {
            return;
        }
        flash('error', 'Invalid or expired form token. Please try again.');
        redirect($redirectTo !== '' ? $redirectTo : url('dashboard.php'));
    }
}
if (!function_exists('safe_http_href')) {
    function safe_http_href(string $raw): string {
        $raw = trim($raw);
        if ($raw === '' || str_contains($raw, "\0")) {
            return '';
        }
        if (preg_match('#^(?:javascript|data|vbscript|file|blob|about):#i', $raw)) {
            return '';
        }
        $href = function_exists('normalize_order_link')
            ? normalize_order_link($raw)
            : (preg_match('#^https?://#i', $raw) ? $raw : '');
        return preg_match('#^https?://#i', $href) ? $href : '';
    }
}
$pageTitle = 'Manage Orders';
$pageSubtitle = 'Search, sync, and manage customer orders. Money moves only on Cancel or Partial.';
$db = Database::getInstance();
$om = new OrderManager();
$adminId = (int) $auth->getUserId();

$statusFilter = trim((string) ($_GET['status'] ?? $_POST['status'] ?? ''));
$search = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? $_POST['p'] ?? 1));
$detailId = max(0, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
$perPage = 30;
$offset = ($page - 1) * $perPage;
$allowedFilters = OrderManager::workflowStatuses();
if ($statusFilter !== '' && !in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = '';
}

$returnQs = static function () use ($search, $statusFilter, $page, $detailId): string {
    return http_build_query(array_filter([
        'q' => $search !== '' ? $search : null,
        'status' => $statusFilter !== '' ? $statusFilter : null,
        'p' => $page > 1 ? $page : null,
        'id' => $detailId > 0 ? $detailId : null,
    ]));
};
$returnTo = static function () use ($returnQs): string {
    $qs = $returnQs();
    return url('admin/admin-orders.php') . ($qs !== '' ? '?' . $qs : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require($returnTo());
    require_once __DIR__ . '/../app/RateLimit.php';
    $rl = new RateLimit(40, 600, 'admin-orders-' . $adminId);
    if ($rl->isLimited()) {
        flash('error', 'Too many order actions. Wait a few minutes and try again.');
        redirect($returnTo());
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === '' && isset($_POST['cancel_order_id'])) {
        $action = 'cancel';
        $_POST['order_id'] = (int) $_POST['cancel_order_id'];
    }
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ids = [];
    foreach ((array) ($_POST['ids'] ?? []) as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);

    $rl->recordAttempt();
    $ok = false;
    $message = 'Nothing changed.';

    switch ($action) {
        case 'sync':
            $result = $om->syncOrderById($orderId);
            $ok = !empty($result['success']);
            if ($ok) {
                $message = !empty($result['changed'])
                    ? 'Order #' . $orderId . ' synced: ' . ($result['old'] ?? '') . ' → ' . ($result['new'] ?? '') . '.'
                    : 'Order #' . $orderId . ' already matches the provider.';
            } else {
                $message = $result['error'] ?? 'Sync failed.';
            }
            break;

        case 'sync_all':
            $updated = $om->syncOrders(null, 200);
            $ok = true;
            $message = $updated > 0
                ? 'Synced ' . $updated . ' unfinished order(s) from the provider.'
                : 'No unfinished orders needed an update.';
            break;

        case 'sync_selected':
            $result = $om->syncOrdersByIds($ids);
            $ok = !empty($result['success']);
            $message = $ok
                ? 'Synced selection: ' . (int) $result['updated'] . ' updated, ' . (int) $result['skipped'] . ' unchanged or skipped.'
                : ($result['error'] ?? 'Could not sync the selection.');
            break;

        case 'resubmit':
            $result = $om->resubmitToProvider($orderId, $adminId);
            $ok = !empty($result['success']);
            $message = $ok
                ? 'Order #' . $orderId . ' sent to the provider (API #' . (int) $result['provider_order_id'] . '). Customer was not charged again.'
                : ($result['error'] ?? 'Resubmit failed.');
            break;

        case 'resubmit_stuck':
            $result = $om->resubmitStuckPending(20, $adminId);
            $ok = ((int) ($result['sent'] ?? 0)) > 0;
            $parts = [];
            if (!empty($result['sent'])) {
                $parts[] = (int) $result['sent'] . ' sent to the provider';
            }
            if (!empty($result['failed'])) {
                $parts[] = (int) $result['failed'] . ' failed';
            }
            $message = $parts !== [] ? implode(', ', $parts) . '.' : 'No stuck orders to resubmit.';
            if (!empty($result['errors'])) {
                $message .= ' ' . implode(' ', array_slice($result['errors'], 0, 3));
            }
            break;

        case 'edit_link':
            $result = $om->updateOrderLink($orderId, (string) ($_POST['link'] ?? ''), $adminId);
            $ok = !empty($result['success']);
            $message = $ok ? 'Link updated for order #' . $orderId . '.' : ($result['error'] ?? 'Could not update the link.');
            break;

        case 'set_status':
            $result = $om->setManualStatus($orderId, (string) ($_POST['new_status'] ?? ''), $adminId);
            $ok = !empty($result['success']);
            $message = $ok
                ? 'Status updated for order #' . $orderId . '. No refund was issued.'
                : ($result['error'] ?? 'Could not update status.');
            break;

        case 'set_partial':
            $result = $om->setPartial($orderId, (int) ($_POST['remains'] ?? -1), $adminId);
            $ok = !empty($result['success']);
            $message = $ok
                ? 'Order #' . $orderId . ' marked Partial. $' . number_format((float) ($result['refunded'] ?? 0), 4) . ' credited to the user.'
                : ($result['error'] ?? 'Could not mark partial.');
            break;

        case 'refill':
            $result = $om->refillOrder($orderId, $adminId);
            $ok = !empty($result['success']);
            $message = $ok ? 'Refill requested for order #' . $orderId . '.' : ($result['error'] ?? 'Refill failed.');
            break;

        case 'cancel':
            $result = $om->cancelOrder($orderId, true);
            $ok = !empty($result['success']);
            $message = $ok
                ? 'Order #' . $orderId . ' cancelled. $' . number_format((float) ($result['refunded'] ?? 0), 4) . ' refunded to the user.'
                : ($result['error'] ?? 'Could not cancel order.');
            if ($ok && !empty($result['warning'])) {
                $message .= ' Provider note: ' . $result['warning'];
            }
            break;

        case 'cancel_selected':
            if ($ids === []) {
                $message = 'Select at least one order to cancel.';
                break;
            }
            if (count($ids) > 30) {
                $message = 'Cancel at most 30 orders at a time.';
                break;
            }
            $cancelled = 0;
            $refunded = 0.0;
            $failed = 0;
            foreach ($ids as $id) {
                $result = $om->cancelOrder($id, true);
                if (!empty($result['success'])) {
                    $cancelled++;
                    $refunded += (float) ($result['refunded'] ?? 0);
                } else {
                    $failed++;
                }
            }
            $ok = $cancelled > 0;
            $message = $cancelled . ' cancelled, $' . number_format($refunded, 4) . ' refunded.';
            if ($failed > 0) {
                $message .= ' ' . $failed . ' could not be cancelled.';
            }
            break;

        default:
            $message = 'Unknown action.';
            break;
    }

    flash($ok ? 'success' : 'error', $message);
    redirect($returnTo());
}

$where = '1=1';
$params = [];
if ($statusFilter !== '') {
    $where .= ' AND o.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= ' AND (u.username LIKE ? OR u.email LIKE ? OR o.id = ? OR o.link LIKE ? OR o.provider_order_id = ? OR o.service_name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = ctype_digit($search) ? $search : -1;
    $params[] = $like;
    $params[] = ctype_digit($search) ? $search : -1;
    $params[] = $like;
}

$total = (int) $db->fetch("SELECT COUNT(*) c FROM orders o JOIN users u ON o.user_id = u.id WHERE $where", $params)['c'];
$orders = $db->fetchAll(
    "SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE $where ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset",
    $params
);
$totalPages = $total ? (int) ceil($total / $perPage) : 1;
$counts = $om->statusCounts();
$stuck = (int) ($counts['_stuck'] ?? 0);

$detail = null;
if ($detailId > 0) {
    $detail = $db->fetch(
        "SELECT o.*, u.username, u.email, u.balance FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?",
        [$detailId]
    );
}

$filterUrl = static function (array $extra = []) use ($search): string {
    $qs = http_build_query(array_filter([
        'q' => $search !== '' ? $search : null,
        'status' => $extra['status'] ?? null,
        'id' => isset($extra['id']) ? (int) $extra['id'] : null,
    ], static fn ($v) => $v !== null && $v !== '' && $v !== 0));
    return url('admin/admin-orders.php') . ($qs !== '' ? '?' . $qs : '');
};

$hiddenFilters = static function () use ($search, $statusFilter, $page, $detailId): void {
    echo '<input type="hidden" name="q" value="' . h($search) . '">';
    echo '<input type="hidden" name="status" value="' . h($statusFilter) . '">';
    echo '<input type="hidden" name="p" value="' . (int) $page . '">';
    if ($detailId > 0) {
        echo '<input type="hidden" name="id" value="' . (int) $detailId . '">';
    }
};

$statusBadge = static function (string $status): string {
    $class = 'status-' . str_replace(' ', '-', $status);
    return '<span class="badge ' . h($class) . '">' . h($status) . '</span>';
};

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="admin-order-stats" role="navigation" aria-label="Filter orders by status">
  <a class="admin-order-chip<?= $statusFilter === '' ? ' is-active' : '' ?>" href="<?= h($filterUrl()) ?>">
    <span>All</span><strong><?= number_format((int) ($counts['_all'] ?? 0)) ?></strong>
  </a>
  <?php foreach (OrderManager::workflowStatuses() as $st): ?>
  <a class="admin-order-chip<?= $statusFilter === $st ? ' is-active' : '' ?>" href="<?= h($filterUrl(['status' => $st])) ?>">
    <span><?= h($st) ?></span><strong><?= number_format((int) ($counts[$st] ?? 0)) ?></strong>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($stuck > 0): ?>
<div class="admin-stuck-banner" role="status">
  <div>
    <strong><?= (int) $stuck ?> unfinished order(s) never reached the provider</strong>
    <p>They have no API order ID, so status sync cannot start them. Resubmit sends them upstream without charging the customer again.</p>
  </div>
  <form method="POST" onsubmit="return confirm('Send up to 20 stuck orders to the provider? Customers will not be charged again.');">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="resubmit_stuck">
    <?php $hiddenFilters(); ?>
    <button type="submit" class="btn btn-primary">Resubmit stuck</button>
  </form>
</div>
<?php endif; ?>

<?php if ($detail): ?>
<?php
    $dCanCancel = in_array((string) $detail['status'], OrderManager::cancellableStatuses(), true);
    $dHasPid = (int) ($detail['provider_order_id'] ?? 0) > 0;
    $dStuck = $dCanCancel && !$dHasPid;
    $dCharge = number_format((float) $detail['charge'], 4);
?>
<section class="card admin-order-detail" aria-labelledby="order-detail-title">
  <div class="admin-order-detail-head">
    <h2 id="order-detail-title">Order #<?= (int) $detail['id'] ?></h2>
    <a class="admin-back-link" href="<?= h($filterUrl($statusFilter !== '' ? ['status' => $statusFilter] : [])) ?>">Close details</a>
  </div>
  <dl class="admin-order-dl">
    <div><dt>User</dt><dd><?= h($detail['username']) ?><br><span class="admin-muted"><?= h($detail['email']) ?></span></dd></div>
    <div><dt>Balance</dt><dd>$<?= number_format((float) $detail['balance'], 4) ?></dd></div>
    <div><dt>Service</dt><dd>#<?= (int) $detail['service_id'] ?> · <?= h((string) $detail['service_name']) ?></dd></div>
    <div><dt>Link</dt><dd><?php $dHref = safe_http_href((string) ($detail['link'] ?? '')); ?>
      <?php if ($dHref !== ''): ?><a href="<?= h($dHref) ?>" target="_blank" rel="noopener"><?= h((string) $detail['link']) ?></a>
      <?php else: ?><?= h((string) $detail['link']) ?><?php endif; ?></dd></div>
    <div><dt>Quantity</dt><dd><?= number_format((int) $detail['quantity']) ?></dd></div>
    <div><dt>Charge</dt><dd>$<?= $dCharge ?></dd></div>
    <div><dt>Start / remains</dt><dd><?= number_format((int) ($detail['start_count'] ?? 0)) ?> / <?= number_format((int) ($detail['remains'] ?? 0)) ?></dd></div>
    <div><dt>Provider</dt><dd><?= h((string) ($detail['provider'] ?? '—')) ?> · API #<?= $dHasPid ? (int) $detail['provider_order_id'] : '—' ?></dd></div>
    <div><dt>Status</dt><dd><?= $statusBadge((string) $detail['status']) ?></dd></div>
    <div><dt>Created</dt><dd><?= h(date('Y-m-d H:i', strtotime((string) $detail['created_at']))) ?></dd></div>
  </dl>
  <div class="admin-order-detail-actions">
    <?php if ($dHasPid && $dCanCancel): ?>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="sync">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <button type="submit" class="btn btn-primary">Sync from provider</button>
    </form>
    <?php endif; ?>
    <?php if ($dStuck): ?>
    <form method="POST" onsubmit="return confirm('Send order #<?= (int) $detail['id'] ?> to the provider without charging the customer again?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="resubmit">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <button type="submit" class="btn btn-primary">Resubmit to provider</button>
    </form>
    <?php endif; ?>
    <?php if ((string) $detail['status'] === 'Completed' && $dHasPid): ?>
    <form method="POST" onsubmit="return confirm('Request a refill for order #<?= (int) $detail['id'] ?> from the provider?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="refill">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <button type="submit" class="btn">Request refill</button>
    </form>
    <?php endif; ?>
    <?php if ($dCanCancel): ?>
    <form method="POST" class="admin-order-inline-form" onsubmit="return confirm('Change status only — no refund will be issued.');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="set_status">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <label class="sr-only" for="new_status">New status</label>
      <select name="new_status" id="new_status" class="form-control">
        <?php foreach (OrderManager::manualStatuses() as $st): ?>
        <option value="<?= h($st) ?>" <?= (string) $detail['status'] === $st ? 'selected' : '' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Set status</button>
    </form>
    <form method="POST" class="admin-order-inline-form" onsubmit="return confirm('Edit the target link for order #<?= (int) $detail['id'] ?>?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="edit_link">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <label class="sr-only" for="edit_link">Order link</label>
      <input type="text" name="link" id="edit_link" class="form-control" value="<?= h((string) $detail['link']) ?>" maxlength="2000" required>
      <button type="submit" class="btn">Save link</button>
    </form>
    <form method="POST" class="admin-order-inline-form" onsubmit="return confirm('Mark partial and refund the unused share for order #<?= (int) $detail['id'] ?>?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="set_partial">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <label class="sr-only" for="set_remains">Remains</label>
      <input type="number" name="remains" id="set_remains" class="form-control" min="0" max="<?= max(0, (int) $detail['quantity'] - 1) ?>" placeholder="Remains" required>
      <button type="submit" class="btn">Mark partial</button>
    </form>
    <form method="POST" onsubmit="return confirm('Cancel order #<?= (int) $detail['id'] ?> and refund $<?= h($dCharge) ?> to <?= h($detail['username']) ?>?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="order_id" value="<?= (int) $detail['id'] ?>">
      <?php $hiddenFilters(); ?>
      <button type="submit" class="btn btn-danger">Cancel &amp; refund $<?= h($dCharge) ?></button>
    </form>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="card admin-page-card">
  <div class="admin-page-head">
    <div class="card-title">📦 Manage Orders</div>
    <div class="admin-orders-toolbar">
      <form method="GET" class="admin-search-form">
        <label class="sr-only" for="order-q">Search orders</label>
        <input type="text" id="order-q" name="q" value="<?= h($search) ?>" class="form-control" placeholder="User, ID, link, API #…">
        <label class="sr-only" for="order-status">Status</label>
        <select name="status" id="order-status" class="form-control">
          <option value="">All statuses</option>
          <?php foreach (OrderManager::workflowStatuses() as $st): ?>
          <option value="<?= h($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Search</button>
      </form>
      <form method="POST" class="admin-sync-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="sync_all">
        <?php $hiddenFilters(); ?>
        <button type="submit" class="btn">Sync statuses</button>
      </form>
    </div>
  </div>

  <form method="POST" id="admin-orders-bulk" class="admin-orders-bulk">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" id="bulk-action" value="">
    <?php $hiddenFilters(); ?>
    <div class="admin-bulk-bar" id="admin-bulk-bar" hidden>
      <span id="admin-bulk-count">0 selected</span>
      <button type="submit" class="btn" data-bulk="sync_selected">Sync selected</button>
      <button type="submit" class="btn btn-danger" data-bulk="cancel_selected" data-confirm="Cancel selected unfinished orders and refund each customer?">Cancel selected</button>
    </div>
    <div class="table-wrap admin-table-wrap">
      <table class="table table-wide table-mobile-cards">
        <thead>
          <tr>
            <th><label class="sr-only" for="select-all-orders">Select all on this page</label><input type="checkbox" id="select-all-orders"></th>
            <th>ID</th>
            <th>User</th>
            <th>Service</th>
            <th>Link</th>
            <th>Qty</th>
            <th>Charge</th>
            <th>Start</th>
            <th>Remains</th>
            <th>API #</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
          <tr><td colspan="13" data-label="" class="admin-empty">No orders found.</td></tr>
          <?php else: ?>
          <?php foreach ($orders as $o): ?>
          <?php
            $canCancel = in_array((string) ($o['status'] ?? ''), OrderManager::cancellableStatuses(), true);
            $hasPid = (int) ($o['provider_order_id'] ?? 0) > 0;
            $isStuck = $canCancel && !$hasPid;
            $orderHref = safe_http_href((string) ($o['link'] ?? ''));
            $chargeFmt = number_format((float) $o['charge'], 4);
          ?>
          <tr<?= $detailId === (int) $o['id'] ? ' class="is-selected"' : '' ?>>
            <td data-label="Select">
              <input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" class="order-row-check">
            </td>
            <td data-label="ID"><a href="<?= h($filterUrl(['status' => $statusFilter, 'id' => (int) $o['id']])) ?>"><strong>#<?= (int) $o['id'] ?></strong></a></td>
            <td data-label="User"><?= h($o['username']) ?><br><span class="admin-muted"><?= h($o['email']) ?></span></td>
            <td data-label="Service"><?= h(mb_substr((string) ($o['service_name'] ?? ''), 0, 50)) ?><?= mb_strlen((string) ($o['service_name'] ?? '')) > 50 ? '…' : '' ?></td>
            <td data-label="Link">
              <?php if ($orderHref !== ''): ?>
              <a href="<?= h($orderHref) ?>" target="_blank" rel="noopener" class="admin-order-link"><?= h(mb_substr((string) $o['link'], 0, 48)) ?><?= mb_strlen((string) $o['link']) > 48 ? '…' : '' ?></a>
              <?php else: ?>
              <span class="admin-order-link"><?= h(mb_substr((string) ($o['link'] ?? ''), 0, 48)) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Qty"><?= number_format((int) $o['quantity']) ?></td>
            <td data-label="Charge"><strong>$<?= $chargeFmt ?></strong></td>
            <td data-label="Start"><?= number_format((int) ($o['start_count'] ?? 0)) ?></td>
            <td data-label="Remains"><?= number_format((int) ($o['remains'] ?? 0)) ?></td>
            <td data-label="API #"><?= $hasPid ? (int) $o['provider_order_id'] : '—' ?><?= $isStuck ? ' <span class="badge badge-orange">Stuck</span>' : '' ?></td>
            <td data-label="Status"><?= $statusBadge((string) $o['status']) ?></td>
            <td data-label="Date" class="admin-muted"><?= h(date('Y-m-d H:i', strtotime((string) $o['created_at']))) ?></td>
            <td data-label="Action" class="td-actions admin-actions-cell">
              <details class="admin-action-menu">
                <summary class="btn">Actions</summary>
                <div class="admin-action-menu-list">
                  <a class="btn" href="<?= h($filterUrl(['status' => $statusFilter, 'id' => (int) $o['id']])) ?>">Details</a>
                  <?php if ($hasPid && $canCancel): ?>
                  <button type="submit" class="btn" form="row-sync-<?= (int) $o['id'] ?>">Sync</button>
                  <?php endif; ?>
                  <?php if ($isStuck): ?>
                  <button type="submit" class="btn btn-primary" form="row-resubmit-<?= (int) $o['id'] ?>">Resubmit</button>
                  <?php endif; ?>
                  <?php if ($canCancel): ?>
                  <button type="submit" class="btn btn-danger" form="row-cancel-<?= (int) $o['id'] ?>">Cancel &amp; refund</button>
                  <?php endif; ?>
                  <?php if (!$canCancel && (string) $o['status'] === 'Completed' && $hasPid): ?>
                  <button type="submit" class="btn" form="row-refill-<?= (int) $o['id'] ?>">Refill</button>
                  <?php endif; ?>
                  <?php if (!$canCancel && (string) $o['status'] !== 'Completed'): ?>
                  <span class="admin-muted">—</span>
                  <?php endif; ?>
                </div>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php if (!empty($orders)): ?>
  <div class="admin-row-forms" hidden>
    <?php foreach ($orders as $o): ?>
    <?php
      $canCancel = in_array((string) ($o['status'] ?? ''), OrderManager::cancellableStatuses(), true);
      $hasPid = (int) ($o['provider_order_id'] ?? 0) > 0;
      $isStuck = $canCancel && !$hasPid;
      $chargeFmt = number_format((float) $o['charge'], 4);
    ?>
    <?php if ($hasPid && $canCancel): ?>
    <form method="POST" id="row-sync-<?= (int) $o['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="sync">
      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <?php $hiddenFilters(); ?>
    </form>
    <?php endif; ?>
    <?php if ($isStuck): ?>
    <form method="POST" id="row-resubmit-<?= (int) $o['id'] ?>" onsubmit="return confirm('Send order #<?= (int) $o['id'] ?> to the provider without charging the customer again?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="resubmit">
      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <?php $hiddenFilters(); ?>
    </form>
    <?php endif; ?>
    <?php if ($canCancel): ?>
    <form method="POST" id="row-cancel-<?= (int) $o['id'] ?>" onsubmit="return confirm('Cancel order #<?= (int) $o['id'] ?> and refund $<?= h($chargeFmt) ?> to <?= h($o['username']) ?>?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <?php $hiddenFilters(); ?>
    </form>
    <?php endif; ?>
    <?php if ((string) $o['status'] === 'Completed' && $hasPid): ?>
    <form method="POST" id="row-refill-<?= (int) $o['id'] ?>" onsubmit="return confirm('Request a refill for order #<?= (int) $o['id'] ?>?');">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="refill">
      <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
      <?php $hiddenFilters(); ?>
    </form>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
  <nav class="admin-pagination" aria-label="Orders pages">
    <?php
    $pageQs = http_build_query(array_filter(['q' => $search ?: null, 'status' => $statusFilter ?: null]));
    $from = max(1, $page - 4);
    $to = min($totalPages, $page + 4);
    ?>
    <?php if ($page > 1): ?>
    <a href="?p=1<?= $pageQs ? '&' . h($pageQs) : '' ?>" class="badge badge-gray">First</a>
    <a href="?p=<?= $page - 1 ?><?= $pageQs ? '&' . h($pageQs) : '' ?>" class="badge badge-gray">Prev</a>
    <?php endif; ?>
    <?php for ($i = $from; $i <= $to; $i++): ?>
    <a href="?p=<?= $i ?><?= $pageQs ? '&' . h($pageQs) : '' ?>" class="badge <?= $i === $page ? 'badge-blue' : 'badge-gray' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="?p=<?= $page + 1 ?><?= $pageQs ? '&' . h($pageQs) : '' ?>" class="badge badge-gray">Next</a>
    <a href="?p=<?= $totalPages ?><?= $pageQs ? '&' . h($pageQs) : '' ?>" class="badge badge-gray">Last</a>
    <?php endif; ?>
    <span class="admin-muted">Page <?= (int) $page ?> / <?= (int) $totalPages ?> · <?= number_format($total) ?> orders</span>
  </nav>
  <?php endif; ?>
</div>

<script>
(function () {
  var form = document.getElementById('admin-orders-bulk');
  var bar = document.getElementById('admin-bulk-bar');
  var countEl = document.getElementById('admin-bulk-count');
  var actionEl = document.getElementById('bulk-action');
  var selectAll = document.getElementById('select-all-orders');
  if (!form || !bar || !countEl || !actionEl) return;

  function boxes() {
    return Array.prototype.slice.call(form.querySelectorAll('.order-row-check'));
  }
  function refresh() {
    var n = boxes().filter(function (b) { return b.checked; }).length;
    countEl.textContent = n + ' selected';
    bar.hidden = n === 0;
    if (selectAll) {
      var all = boxes();
      selectAll.checked = all.length > 0 && n === all.length;
      selectAll.indeterminate = n > 0 && n < all.length;
    }
  }
  form.addEventListener('change', function (e) {
    if (e.target && e.target.classList && e.target.classList.contains('order-row-check')) refresh();
  });
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      boxes().forEach(function (b) { b.checked = selectAll.checked; });
      refresh();
    });
  }
  form.querySelectorAll('[data-bulk]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      actionEl.value = btn.getAttribute('data-bulk') || '';
      var confirmMsg = btn.getAttribute('data-confirm');
      if (confirmMsg && !window.confirm(confirmMsg)) {
        e.preventDefault();
        actionEl.value = '';
      }
    });
  });
  refresh();
})();
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
