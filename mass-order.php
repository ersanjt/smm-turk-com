<?php
require_once __DIR__ . '/app/init.php';

$auth->requireLogin();
$pageTitle = 'Mass Order';
$om = new OrderManager();
$db = Database::getInstance();
$uid = $auth->getUserId();

$userBalance = (float) ($db->fetch('SELECT balance FROM users WHERE id = ?', [$uid])['balance'] ?? 0);
$rawOrders = trim($_POST['orders'] ?? '');
$action = $_POST['action'] ?? '';
$preview = null;
$results = [];
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $parsed = MassOrderHelper::parseBulk($rawOrders);

    if ($parsed === []) {
        flash('error', 'Enter at least one order line.');
        redirect(url('mass-order.php'));
    }

    if ($action === 'validate') {
        $preview = MassOrderHelper::validate($uid, $parsed);
    } elseif ($action === 'place') {
        $validated = MassOrderHelper::validate($uid, $parsed);
        $runningBalance = $validated['summary']['balance'];
        $placed = 0;
        $failed = 0;
        $totalCharged = 0.0;

        foreach ($validated['rows'] as $row) {
            if (!$row['ok']) {
                $results[] = [
                    'line' => $row['line'],
                    'raw' => $row['raw'],
                    'success' => false,
                    'error' => $row['error'] ?? 'Skipped',
                ];
                $failed++;
                continue;
            }

            $result = $om->placeOrder($uid, (int) $row['service_id'], $row['link'], (int) $row['quantity']);
            if ($result['success']) {
                $placed++;
                $totalCharged += (float) $result['charge'];
                $runningBalance -= (float) $result['charge'];
                $results[] = [
                    'line' => $row['line'],
                    'raw' => $row['raw'],
                    'success' => true,
                    'order_id' => $result['order_id'],
                    'charge' => $result['charge'],
                    'service_name' => $row['service_name'],
                ];
            } else {
                $failed++;
                $results[] = [
                    'line' => $row['line'],
                    'raw' => $row['raw'],
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed',
                ];
            }
        }

        $summary = [
            'placed' => $placed,
            'failed' => $failed,
            'charged' => round($totalCharged, 4),
        ];
        $userBalance = (float) ($db->fetch('SELECT balance FROM users WHERE id = ?', [$uid])['balance'] ?? 0);
    }
}

require_once __DIR__ . '/layouts/header.php';
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/order.css')) ?>">

<div class="page-header">
  <div>
    <div class="page-title-row">
      <?= iconBox('clipboard', 'orange', 22) ?>
      <div>
        <h1 class="page-title">Mass Order</h1>
        <p class="page-subtitle">Place many orders at once — up to <?= MassOrderHelper::MAX_LINES ?> lines per batch</p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= h(path('services.php')) ?>" class="btn btn-sm"><?= icon('search', 16) ?> Find service IDs</a>
    <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary btn-sm"><?= icon('wallet', 16) ?> Add Funds</a>
  </div>
</div>

<nav class="order-tabs" aria-label="Order type">
  <a class="order-tab" href="<?= h(path('dashboard.php')) ?>">New Order</a>
  <a class="order-tab active" href="<?= h(path('mass-order.php')) ?>">Mass Order</a>
  <a class="order-tab" href="<?= h(path('services.php')) ?>">Services</a>
</nav>

<?php if ($userBalance <= 0): ?>
<div class="alert alert-info">
  Your balance is <strong>$0</strong>. <a href="<?= h(path('add-funds.php')) ?>">Add funds</a> before placing mass orders.
</div>
<?php endif; ?>

<div class="mass-order-grid">
  <div class="card mass-order-main">
    <div class="card-title"><?= icon('clipboard', 18) ?> Order lines</div>

    <div class="mass-order-format">
      <strong>Format:</strong> <code>service_id | link | quantity</code>
      <span class="mass-order-format-alt">Also accepts tab or comma separators</span>
    </div>

    <?php if ($summary): ?>
    <div class="mass-order-summary-bar <?= $summary['placed'] > 0 ? 'is-success' : 'is-error' ?>">
      <span><?= (int) $summary['placed'] ?> placed</span>
      <span><?= (int) $summary['failed'] ?> failed</span>
      <span>Charged $<?= number_format($summary['charged'], 4) ?></span>
      <span>Balance $<?= number_format($userBalance, 3) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($results): ?>
    <div class="mass-order-results" role="region" aria-label="Order results">
      <table class="mass-order-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Status</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results as $r): ?>
          <tr class="<?= !empty($r['success']) ? 'row-ok' : 'row-fail' ?>">
            <td><?= (int) $r['line'] ?></td>
            <td>
              <?php if (!empty($r['success'])): ?>
              <span class="mass-badge mass-badge-ok">OK</span>
              <?php else: ?>
              <span class="mass-badge mass-badge-fail">Fail</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($r['success'])): ?>
              Order #<?= (int) $r['order_id'] ?> — $<?= number_format((float) $r['charge'], 4) ?>
              <?php if (!empty($r['service_name'])): ?>
              <span class="mass-order-muted"><?= h(mb_substr($r['service_name'], 0, 48)) ?></span>
              <?php endif; ?>
              <?php else: ?>
              <?= h($r['error'] ?? 'Error') ?>
              <code class="mass-order-line-code"><?= h($r['raw']) ?></code>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($summary && $summary['placed'] > 0): ?>
      <p class="mass-order-after-actions">
        <a href="<?= h(path('orders.php')) ?>" class="btn btn-sm">View orders</a>
      </p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($preview): $ps = $preview['summary']; ?>
    <div class="mass-order-preview" role="region" aria-label="Validation preview">
      <div class="mass-order-summary-bar <?= $ps['valid'] > 0 ? 'is-preview' : 'is-error' ?>">
        <span><?= (int) $ps['total'] ?> lines</span>
        <span><?= (int) $ps['valid'] ?> ready</span>
        <span><?= (int) $ps['invalid'] ?> invalid</span>
        <?php if ($ps['duplicate'] > 0): ?><span><?= (int) $ps['duplicate'] ?> duplicate</span><?php endif; ?>
        <span>Est. $<?= number_format($ps['charge'], 4) ?></span>
      </div>
      <table class="mass-order-table mass-order-table-preview">
        <thead>
          <tr>
            <th>#</th>
            <th>ID</th>
            <th>Service</th>
            <th>Qty</th>
            <th>Cost</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview['rows'] as $row): ?>
          <tr class="<?= !empty($row['ok']) ? 'row-ok' : 'row-fail' ?>">
            <td><?= (int) $row['line'] ?></td>
            <td><?= (int) $row['service_id'] ?></td>
            <td class="mass-order-service-cell" title="<?= h($row['service_name'] ?? '') ?>">
              <?= h($row['service_name'] !== '' ? mb_substr($row['service_name'], 0, 36) : '—') ?>
            </td>
            <td><?= (int) $row['quantity'] ?></td>
            <td><?= !empty($row['ok']) ? '$' . number_format((float) $row['charge'], 4) : '—' ?></td>
            <td>
              <?php if (!empty($row['ok'])): ?>
              <span class="mass-badge mass-badge-ok">Ready</span>
              <?php else: ?>
              <span class="mass-badge mass-badge-fail"><?= h($row['error'] ?? 'Invalid') ?></span>
              <?php endif; ?>
              <?php if (!empty($row['duplicate'])): ?>
              <span class="mass-badge mass-badge-warn">Dup</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($ps['valid'] > 0 && $ps['charge'] > $ps['balance']): ?>
      <div class="alert alert-error">Total cost exceeds your balance ($<?= number_format($ps['balance'], 3) ?>). <a href="<?= h(path('add-funds.php')) ?>">Add funds</a> or remove lines.</div>
      <?php elseif ($ps['valid'] > 0): ?>
      <p class="mass-order-balance-hint">Balance after placement: <strong>$<?= number_format($ps['balance_after'], 4) ?></strong></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="mass-order-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="form-group">
        <label class="form-label" for="mass-orders-input">Orders (one per line)</label>
        <textarea
          id="mass-orders-input"
          name="orders"
          class="form-control mass-order-textarea"
          rows="12"
          spellcheck="false"
          placeholder="# Example — copy from Services page IDs&#10;17708 | https://youtu.be/xxxxx | 1000&#10;17701 | https://twitter.com/user | 500&#10;17681 | https://twitter.com/status/xxx | 10000"
        ><?= h($rawOrders) ?></textarea>
        <div class="mass-order-meta" id="mass-order-meta" aria-live="polite">
          <span id="mass-line-count">0</span> lines · Balance <strong>$<?= number_format($userBalance, 3) ?></strong>
        </div>
      </div>
      <div class="mass-order-actions">
        <button type="submit" name="action" value="validate" class="btn">Check orders</button>
        <button type="submit" name="action" value="place" class="btn btn-primary" id="mass-place-btn"<?= $userBalance <= 0 ? ' disabled data-no-balance="1"' : '' ?>>
          <?= icon('send', 16) ?> Place valid orders
        </button>
      </div>
    </form>
  </div>

  <aside class="card mass-order-side">
    <div class="card-title"><?= icon('info', 18) ?> How it works</div>
    <ol class="mass-order-steps">
      <li>Copy <strong>service ID</strong> from <a href="<?= h(path('services.php')) ?>">Services</a></li>
      <li>Paste one order per line: <code>ID | link | quantity</code></li>
      <li>Click <strong>Check orders</strong> to preview cost and errors</li>
      <li>Click <strong>Place valid orders</strong> to submit</li>
    </ol>

    <div class="mass-order-tips">
      <div class="desc-item"><strong>Comments</strong> — lines starting with <code>#</code> are ignored</div>
      <div class="desc-item"><strong>Links</strong> — <code>https://</code> is added automatically if missing</div>
      <div class="desc-item"><strong>Balance</strong> — orders run top to bottom; later lines fail if balance runs out</div>
      <div class="desc-item"><strong>Limit</strong> — max <?= MassOrderHelper::MAX_LINES ?> lines per batch</div>
    </div>

    <div class="price-box" style="margin-top:18px;">
      <div>
        <div class="lbl">Your balance</div>
        <div class="amt">$<?= number_format($userBalance, 3) ?></div>
      </div>
      <div class="price-box-right">
        <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;">Add Funds</a>
      </div>
    </div>
  </aside>
</div>

<script src="<?= h(asset_url('assets/js/mass-order.js')) ?>" defer></script>
<?php require_once __DIR__ . '/layouts/footer.php'; ?>
