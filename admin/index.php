<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Admin Panel';
$db  = Database::getInstance();
$api = new SmmApi();

// Stats
$totalUsers   = (int) ($db->fetch("SELECT COUNT(*) c FROM users WHERE role='user'")['c'] ?? 0);
$totalOrders  = (int) ($db->fetch("SELECT COUNT(*) c FROM orders")['c'] ?? 0);
$totalRevenue = (float) ($db->fetch("SELECT SUM(charge) c FROM orders WHERE status='Completed'")['c'] ?? 0);
$pendingOrders= (int) ($db->fetch("SELECT COUNT(*) c FROM orders WHERE status='Pending'")['c'] ?? 0);
$openTickets  = (int) ($db->fetch("SELECT COUNT(*) c FROM tickets WHERE status='open'")['c'] ?? 0);
$totalServices= (int) ($db->fetch("SELECT COUNT(*) c FROM services WHERE status='active'")['c'] ?? 0);
$pendingDeposits = (int) ($db->fetch("SELECT COUNT(*) c FROM transactions WHERE type='deposit' AND status='pending'")['c'] ?? 0);
$pendingChildPanels = 0;
try {
    $pendingChildPanels = (int) $db->fetch("SELECT COUNT(*) c FROM child_panels WHERE status='pending'")['c'];
} catch (Throwable $e) {}
$blogArticles = 0;
$blogPublished = 0;
try {
    $blogArticles = (int) $db->fetch("SELECT COUNT(*) c FROM blog_articles")['c'];
    $blogPublished = (int) $db->fetch("SELECT COUNT(*) c FROM blog_articles WHERE status='published'")['c'];
} catch (Throwable $e) {}

// Provider balance
$providerBalance = null;
try {
    $b = $api->balance();
    if ($b && isset($b->balance)) $providerBalance = $b->balance;
} catch (Exception $e) {}

// Recent orders
$recentOrders = $db->fetchAll(
    "SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 10"
);

// Recent users
$recentUsers = $db->fetchAll("SELECT * FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 5");

// Chart data — last 7 days
$chartLabels = [];
$chartOrders = [];
$chartRevenue = [];
$rawChart = $db->fetchAll(
    "SELECT DATE(created_at) AS d, COUNT(*) AS cnt, COALESCE(SUM(charge), 0) AS rev
     FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)"
);
$byDate = [];
foreach ($rawChart as $row) {
    $byDate[$row['d']] = $row;
}
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M j', strtotime($d));
    $chartOrders[] = (int)($byDate[$d]['cnt'] ?? 0);
    $chartRevenue[] = round((float)($byDate[$d]['rev'] ?? 0), 2);
}

$chartHasData = array_sum($chartOrders) > 0 || array_sum($chartRevenue) > 0;

$statCards = [
    ['label' => 'Total Users', 'value' => number_format($totalUsers), 'icon' => 'users', 'tone' => 'primary', 'alert' => false, 'url' => 'admin/admin-users.php'],
    ['label' => 'Total Orders', 'value' => number_format($totalOrders), 'icon' => 'orders', 'tone' => 'green', 'alert' => false, 'url' => 'admin/admin-orders.php'],
    ['label' => 'Total Revenue', 'value' => '$' . number_format($totalRevenue, 2), 'icon' => 'dollar', 'tone' => 'orange', 'alert' => false, 'url' => 'admin/admin-orders.php'],
    ['label' => 'Pending Orders', 'value' => number_format($pendingOrders), 'icon' => 'pending', 'tone' => 'orange', 'alert' => $pendingOrders > 0, 'hint' => 'Needs attention', 'url' => 'admin/admin-orders.php?status=Pending'],
    ['label' => 'Open Tickets', 'value' => number_format($openTickets), 'icon' => 'tickets', 'tone' => 'green', 'alert' => $openTickets > 0, 'hint' => 'Awaiting reply', 'url' => 'admin/admin-tickets.php'],
    ['label' => 'Active Services', 'value' => number_format($totalServices), 'icon' => 'services', 'tone' => 'primary', 'alert' => false, 'url' => 'admin/admin-services.php'],
    ['label' => 'Provider Balance', 'value' => $providerBalance !== null ? '$' . number_format((float)$providerBalance, 2) : 'N/A', 'icon' => 'server', 'tone' => 'dark', 'alert' => false, 'url' => 'admin/admin-sync.php'],
    ['label' => 'Pending Deposits', 'value' => number_format($pendingDeposits), 'icon' => 'deposit', 'tone' => 'orange', 'alert' => $pendingDeposits > 0, 'hint' => 'Review deposits', 'url' => 'admin/admin-deposits.php'],
];

$dashAlerts = [];
if ($pendingOrders > 0) {
    $dashAlerts[] = ['text' => $pendingOrders . ' order(s) pending', 'url' => 'admin/admin-orders.php?status=Pending', 'tone' => 'warn'];
}
if ($pendingDeposits > 0) {
    $dashAlerts[] = ['text' => $pendingDeposits . ' deposit(s) awaiting approval', 'url' => 'admin/admin-deposits.php', 'tone' => 'warn'];
}
if ($openTickets > 0) {
    $dashAlerts[] = ['text' => $openTickets . ' open ticket(s)', 'url' => 'admin/admin-tickets.php', 'tone' => 'info'];
}
if ($pendingChildPanels > 0 && !is_child_panel()) {
    $dashAlerts[] = ['text' => $pendingChildPanels . ' child panel(s) pending', 'url' => 'admin/admin-child-panels.php', 'tone' => 'info'];
}

$quickLinks = [
    ['url' => 'admin/admin-users.php', 'label' => 'Manage Users', 'icon' => 'users', 'style' => 'primary'],
    ['url' => 'admin/admin-orders.php', 'label' => 'Manage Orders', 'icon' => 'orders', 'style' => 'outline'],
    ['url' => 'admin/admin-services.php', 'label' => 'Services', 'icon' => 'services', 'style' => 'outline'],
    ['url' => 'admin/admin-sync.php', 'label' => 'Sync Services', 'icon' => 'sync', 'style' => 'dark'],
    ['url' => 'admin/admin-tickets.php', 'label' => 'Tickets' . ($openTickets > 0 ? " ($openTickets)" : ''), 'icon' => 'tickets', 'style' => $openTickets > 0 ? 'urgent' : 'outline'],
    ['url' => 'admin/admin-deposits.php', 'label' => 'Deposits' . ($pendingDeposits > 0 ? " ($pendingDeposits)" : ''), 'icon' => 'deposit', 'style' => $pendingDeposits > 0 ? 'urgent' : 'outline'],
    ['url' => 'admin/admin-settings.php', 'label' => 'Settings', 'icon' => 'settings', 'style' => 'outline'],
    ['url' => 'admin/admin-mail.php', 'label' => 'Test Email', 'icon' => 'message', 'style' => 'outline'],
    ['url' => 'admin/admin-blog.php', 'label' => 'Manage Blog' . ($blogArticles > 0 ? " ($blogArticles)" : ''), 'icon' => 'clipboard', 'style' => 'outline'],
];
if (!is_child_panel()) {
    array_splice($quickLinks, 6, 0, [
        ['url' => 'admin/admin-coupons.php', 'label' => 'Coupons', 'icon' => 'clipboard', 'style' => 'outline'],
        ['url' => 'admin/admin-child-panels.php', 'label' => 'Child Panels' . ($pendingChildPanels > 0 ? " ($pendingChildPanels)" : ''), 'icon' => 'server', 'style' => $pendingChildPanels > 0 ? 'urgent' : 'outline'],
    ]);
}

$extraCssHref = asset_url('assets/css/admin-dashboard.css');
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="admin-dash">
  <header class="admin-dash-header">
    <div>
      <h1>Admin Dashboard</h1>
      <p>Overview of users, orders, revenue, and support at a glance.</p>
    </div>
    <div class="admin-dash-header-actions">
      <div class="admin-dash-date"><?= date('l, M j, Y') ?></div>
      <a href="<?= h(path('admin/admin-sync.php')) ?>" class="qa-btn qa-btn-outline admin-dash-sync"><?= icon('sync', 16) ?> Sync</a>
    </div>
  </header>

  <?php if ($dashAlerts !== []): ?>
  <div class="admin-dash-alerts" role="status">
    <?php foreach ($dashAlerts as $alert): ?>
    <a href="<?= h(path($alert['url'])) ?>" class="admin-dash-alert admin-dash-alert-<?= h($alert['tone']) ?>"><?= h($alert['text']) ?> →</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <p class="admin-section-label">Overview</p>
  <div class="admin-grid">
    <?php foreach ($statCards as $card):
      $cardUrl = $card['url'] ?? '';
      $tag = $cardUrl !== '' ? 'a' : 'div';
      $attrs = $cardUrl !== '' ? ' href="' . h(path($cardUrl)) . '" class="admin-card admin-stat-link' : ' class="admin-card';
      $attrs .= !empty($card['alert']) ? ' admin-card-alert"' : '"';
    ?>
    <<?= $tag ?><?= $attrs ?>>
      <div class="ac-icon"><?= iconBox($card['icon'], $card['tone'], 24) ?></div>
      <div class="ac-info">
        <div class="ac-label"><?= h($card['label']) ?></div>
        <div class="ac-value"><?= h($card['value']) ?></div>
        <?php if (!empty($card['hint']) && !empty($card['alert'])): ?>
        <span class="ac-badge"><?= h($card['hint']) ?></span>
        <?php endif; ?>
      </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>

  <p class="admin-section-label">Quick actions</p>
  <div class="admin-qa-grid">
    <?php foreach ($quickLinks as $link):
      $cls = 'qa-btn';
      if ($link['style'] === 'primary') {
          $cls .= ' qa-btn-primary';
      } elseif ($link['style'] === 'dark') {
          $cls .= ' qa-btn-dark';
      } elseif ($link['style'] === 'urgent') {
          $cls .= ' qa-btn-outline qa-btn-urgent';
      } else {
          $cls .= ' qa-btn-outline';
      }
    ?>
    <a href="<?= h(path($link['url'])) ?>" class="<?= $cls ?>"><?= icon($link['icon'], 16) ?> <?= h($link['label']) ?></a>
    <?php endforeach; ?>
  </div>

  <p class="admin-section-label">Analytics — last 7 days</p>
  <div class="admin-charts">
    <div class="admin-chart-card">
      <h3>Orders</h3>
      <div class="admin-chart-wrap<?= $chartHasData ? '' : ' is-empty' ?>">
        <?php if (!$chartHasData): ?><div class="admin-chart-empty">No orders in the last 7 days yet</div><?php endif; ?>
        <canvas id="ordersChart" aria-label="Orders last 7 days"<?= $chartHasData ? '' : ' hidden' ?>></canvas>
      </div>
    </div>
    <div class="admin-chart-card">
      <h3>Revenue</h3>
      <div class="admin-chart-wrap<?= $chartHasData ? '' : ' is-empty' ?>">
        <?php if (!$chartHasData): ?><div class="admin-chart-empty">No revenue recorded yet</div><?php endif; ?>
        <canvas id="revenueChart" aria-label="Revenue last 7 days"<?= $chartHasData ? '' : ' hidden' ?>></canvas>
      </div>
    </div>
  </div>

  <p class="admin-section-label">Recent activity</p>
  <div class="grid2 admin-tables">
    <div class="card" style="padding:0;">
      <div class="admin-table-head">
        <div class="card-title"><?= icon('orders', 18) ?> Recent Orders</div>
        <a href="<?= h(path('admin/admin-orders.php')) ?>" class="admin-table-link">View all →</a>
      </div>
      <div class="table-wrap">
        <table class="table table-mobile-cards" style="font-size:13px;">
          <thead><tr><th>ID</th><th>User</th><th>Charge</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($recentOrders)): ?>
            <tr><td colspan="5" data-label="" style="color:var(--text-muted);text-align:center;padding:24px;">No orders yet</td></tr>
            <?php else: foreach ($recentOrders as $o): ?>
            <tr>
              <td data-label="ID"><a href="<?= h(path('admin/admin-orders.php')) ?>" style="color:var(--primary);font-weight:600;">#<?= (int)$o['id'] ?></a></td>
              <td data-label="User"><?= h($o['username']) ?></td>
              <td data-label="Charge">$<?= number_format((float)$o['charge'], 4) ?></td>
              <td data-label="Status"><span class="badge status-<?= str_replace(' ', '-', h($o['status'])) ?>"><?= h($o['status']) ?></span></td>
              <td data-label="Date" style="color:var(--text-muted)"><?= date('M j, H:i', strtotime($o['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="padding:0;">
      <div class="admin-table-head">
        <div class="card-title"><?= icon('users', 18) ?> Recent Users</div>
        <a href="<?= h(path('admin/admin-users.php')) ?>" class="admin-table-link">View all →</a>
      </div>
      <div class="table-wrap">
        <table class="table table-mobile-cards" style="font-size:13px;">
          <thead><tr><th>User</th><th>Balance</th><th>Spent</th><th>Joined</th></tr></thead>
          <tbody>
            <?php if (empty($recentUsers)): ?>
            <tr><td colspan="4" data-label="" style="color:var(--text-muted);text-align:center;padding:24px;">No users yet</td></tr>
            <?php else: foreach ($recentUsers as $u): ?>
            <tr>
              <td data-label="User"><strong><?= h($u['username']) ?></strong><br><span style="color:var(--text-muted);font-size:11px;"><?= h($u['email']) ?></span></td>
              <td data-label="Balance">$<?= number_format((float)$u['balance'], 2) ?></td>
              <td data-label="Spent">$<?= number_format((float)$u['spent'], 2) ?></td>
              <td data-label="Joined" style="color:var(--text-muted)"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  var hasData = <?= $chartHasData ? 'true' : 'false' ?>;
  if (!hasData) return;

  var labels = <?= json_encode($chartLabels) ?>;
  var orders = <?= json_encode($chartOrders) ?>;
  var revenue = <?= json_encode($chartRevenue) ?>;
  var gridColor = getComputedStyle(document.body).getPropertyValue('--border').trim() || '#f0e6e8';
  var textColor = getComputedStyle(document.body).getPropertyValue('--text-muted').trim() || '#6b4a50';
  var primary = getComputedStyle(document.body).getPropertyValue('--primary').trim() || '#E30A17';
  var maxOrders = Math.max.apply(null, orders.concat([1]));
  var maxRev = Math.max.apply(null, revenue.concat([0.01]));

  function baseScales(max, decimals) {
    return {
      x: { grid: { display: false }, ticks: { color: textColor, font: { size: 11, weight: '600' } } },
      y: {
        beginAtZero: true,
        suggestedMax: max,
        grid: { color: gridColor },
        ticks: { color: textColor, font: { size: 11 }, precision: decimals }
      }
    };
  }

  var ordersEl = document.getElementById('ordersChart');
  if (ordersEl) {
    new Chart(ordersEl, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: 'Orders', data: orders, backgroundColor: primary, borderRadius: 8, maxBarThickness: 36 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: baseScales(maxOrders, 0) }
    });
  }

  var revenueEl = document.getElementById('revenueChart');
  if (revenueEl) {
    new Chart(revenueEl, {
      type: 'line',
      data: { labels: labels, datasets: [{ label: 'Revenue ($)', data: revenue, borderColor: primary, backgroundColor: 'rgba(227,10,23,.1)', fill: true, tension: .35, pointRadius: 4, pointHoverRadius: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: baseScales(maxRev, 2) }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
