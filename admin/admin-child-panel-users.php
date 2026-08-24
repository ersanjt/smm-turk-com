<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Child Panel Customers';
$db = Database::getInstance();
$registry = new ChildPanelEndUsers();

$total = $registry->countAll();
$users = $registry->listAllForAdmin(300);

require_once __DIR__ . '/../layouts/header.php';
?>

<div style="max-width:1200px;">
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">Child panel end-users</div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.6;">
      Every customer who registers on a child panel website is synced here automatically.
      Child panel owners must keep <strong>SMM Turk balance</strong> charged — their customers' orders are fulfilled through the owner's API key (wholesale from your panel).
    </p>
    <p style="font-size:13px;margin-bottom:14px;"><strong><?= number_format($total) ?></strong> registered customers across all child panels.</p>

    <?php if (empty($users)): ?>
    <p style="color:var(--text-muted);">No child panel customers synced yet. New registrations on live child panels will appear here.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Customer</th>
            <th>Child panel</th>
            <th>Owner</th>
            <th>Status</th>
            <th>Registered</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>#<?= (int) $u['id'] ?></td>
            <td>
              <strong><?= h($u['username']) ?></strong><br>
              <span style="font-size:11px;color:var(--text-muted);"><?= h($u['email']) ?></span>
            </td>
            <td>
              <a href="<?= h('https://' . $u['child_domain']) ?>" target="_blank" rel="noopener"><?= h($u['child_domain']) ?></a><br>
              <span style="font-size:10px;color:var(--text-muted);">local #<?= (int) $u['child_local_user_id'] ?></span>
            </td>
            <td>
              <?= h($u['owner_username'] ?? '') ?><br>
              <span style="font-size:11px;color:var(--text-muted);"><?= h($u['owner_email'] ?? '') ?></span>
            </td>
            <td><span class="badge <?= ($u['status'] ?? '') === 'active' ? 'badge-green' : 'badge-orange' ?>"><?= h($u['status'] ?? '') ?></span></td>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap;"><?= h(date('Y-m-d H:i', strtotime($u['registered_at'] ?? 'now'))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <p style="font-size:12px;color:var(--text-muted);">
    <a href="<?= h(path('admin/admin-child-panels.php')) ?>" style="color:var(--primary);font-weight:600;">← Child panel orders</a>
  </p>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
