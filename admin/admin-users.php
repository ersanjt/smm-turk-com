<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Manage Users';
$db = Database::getInstance();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['uid'] ?? 0);

    if ($action === 'add_balance' && $uid) {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount > 0) {
            $dm = new DepositManager();
            $result = $dm->creditUser($uid, $amount, 'admin', 'Admin deposit');
            if ($result['success']) {
                $msg = 'Balance added successfully.';
                if (!empty($result['email_sent'])) {
                    $msg .= ' User notified by email.';
                }
                flash('success', $msg);
            } else {
                flash('error', $result['error'] ?? 'Failed to add balance.');
            }
        }
    } elseif ($action === 'payout_referral' && $uid) {
        $result = (new RevenueEngine())->payoutReferralEarnings($uid, true);
        flash($result['success'] ? 'success' : 'error', $result['success'] ? 'Referral earnings paid to balance.' : ($result['error'] ?? 'Failed.'));
    } elseif ($action === 'ban' && $uid) {
        $db->execute("UPDATE users SET status='banned' WHERE id=? AND role='user'", [$uid]);
        flash('success', "User banned.");
    } elseif ($action === 'unban' && $uid) {
        $db->execute("UPDATE users SET status='active' WHERE id=?", [$uid]);
        flash('success', "User unbanned.");
    }
    redirect(url('admin/admin-users.php'));
}

$search = trim($_GET['q'] ?? '');
$where  = $search ? "WHERE username LIKE ? OR email LIKE ?" : "";
$params = $search ? ["%$search%", "%$search%"] : [];
$users  = $db->fetchAll("SELECT * FROM users $where ORDER BY created_at DESC LIMIT 50", $params);

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="card admin-page-card">
  <div class="admin-page-head">
    <div class="card-title">👥 Manage Users</div>
    <form method="GET" class="admin-search-form">
      <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Search username or email…">
      <button type="submit" class="btn btn-primary">Search</button>
    </form>
  </div>
  <div class="table-wrap admin-table-wrap">
    <table class="table table-wide table-mobile-cards">
      <thead>
        <tr><th>ID</th><th>Username</th><th>Email</th><th>Balance</th><th>Spent</th><th>Referral $</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="ID">#<?= $u['id'] ?></td>
          <td data-label="Username"><strong><?= h($u['username']) ?></strong><?= $u['role']==='admin' ? ' <span class="badge badge-blue">Admin</span>' : '' ?></td>
          <td data-label="Email" style="font-size:12px;"><?= h($u['email']) ?></td>
          <td data-label="Balance"><strong>$<?= number_format($u['balance'], 4) ?></strong></td>
          <td data-label="Spent">$<?= number_format($u['spent'], 4) ?></td>
          <td data-label="Referral">$<?= number_format((float)($u['referral_earnings'] ?? 0), 2) ?></td>
          <td data-label="Status">
            <span class="badge <?= $u['status']==='active' ? 'badge-green' : 'badge-red' ?>">
              <?= h($u['status']) ?>
            </span>
          </td>
          <td data-label="Joined" style="font-size:11px;color:var(--text-muted);"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
          <td data-label="Actions" class="td-actions admin-actions-cell">
              <!-- Add balance form -->
              <form method="POST" style="display:flex;gap:4px;" onsubmit="return confirm('Add balance?')">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="add_balance">
                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                <input type="number" name="amount" step="0.01" min="0.01" placeholder="$" class="form-control" style="width:70px;padding:5px 8px;font-size:12px;">
                <button type="submit" class="btn btn-success" style="padding:5px 10px;font-size:11px;">+$</button>
              </form>
              <!-- Ban/Unban -->
              <?php if ((float)($u['referral_earnings'] ?? 0) > 0): ?>
              <form method="POST" onsubmit="return confirm('Pay referral earnings to balance?')">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="payout_referral">
                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                <button type="submit" class="btn" style="padding:5px 10px;font-size:11px;">💰 Ref</button>
              </form>
              <?php endif; ?>
              <?php if ($u['role'] !== 'admin'): ?>
              <form method="POST" onsubmit="return confirm('Are you sure?')">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                <input type="hidden" name="action" value="<?= $u['status']==='active' ? 'ban' : 'unban' ?>">
                <button type="submit" class="btn <?= $u['status']==='active' ? 'btn-danger' : 'btn-success' ?>"
                        style="padding:5px 10px;font-size:11px;">
                  <?= $u['status']==='active' ? '🚫 Ban' : '✅ Unban' ?>
                </button>
              </form>
              <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
