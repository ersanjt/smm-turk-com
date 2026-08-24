<?php
require_once __DIR__ . '/_init.php';
if (is_child_panel()) {
    redirect(admin_path('index.php'));
}
$pageTitle = 'Coupons & Promos';
$revenue = new RevenueEngine();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $result = $revenue->saveCoupon([
            'code' => $_POST['code'] ?? '',
            'type' => $_POST['type'] ?? 'order_percent',
            'value' => $_POST['value'] ?? 0,
            'min_amount' => $_POST['min_amount'] ?? 0,
            'max_uses' => $_POST['max_uses'] ?? 0,
            'per_user_limit' => $_POST['per_user_limit'] ?? 1,
            'expires_at' => $_POST['expires_at'] ?? '',
            'active' => isset($_POST['active']) ? 1 : 0,
            'note' => $_POST['note'] ?? '',
        ], $id);
        flash($result['success'] ? 'success' : 'error', $result['success'] ? 'Coupon saved.' : ($result['error'] ?? 'Save failed.'));
    } elseif ($action === 'toggle' && ($id = (int) ($_POST['id'] ?? 0))) {
        $row = $db->fetch('SELECT active FROM coupons WHERE id = ?', [$id]);
        if ($row) {
            $db->execute('UPDATE coupons SET active = ? WHERE id = ?', [(int) !($row['active'] ?? 0), $id]);
            flash('success', 'Coupon updated.');
        }
    }
    redirect(url('admin/admin-coupons.php'));
}

$coupons = $revenue->allCoupons();
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId ? $db->fetch('SELECT * FROM coupons WHERE id = ?', [$editId]) : null;

require_once __DIR__ . '/../layouts/header.php';
?>

<div class="admin-page-shell" style="max-width:900px;">
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">🎟️ <?= $edit ? 'Edit coupon' : 'New coupon' ?></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Code</label>
          <input type="text" name="code" class="form-control" required value="<?= h($edit['code'] ?? '') ?>" placeholder="WELCOME10" style="text-transform:uppercase;">
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <select name="type" class="form-control">
            <?php
            $types = [
                'order_percent' => 'Order — % off',
                'order_fixed' => 'Order — $ off',
                'deposit_percent' => 'Deposit — % bonus',
                'deposit_fixed' => 'Deposit — $ bonus',
            ];
            $cur = $edit['type'] ?? 'order_percent';
            foreach ($types as $val => $label):
            ?>
            <option value="<?= h($val) ?>" <?= $cur === $val ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid3">
        <div class="form-group">
          <label class="form-label">Value (% or $)</label>
          <input type="number" name="value" class="form-control" min="0.01" step="0.01" required value="<?= h((string) ($edit['value'] ?? '10')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Min order/deposit ($)</label>
          <input type="number" name="min_amount" class="form-control" min="0" step="0.01" value="<?= h((string) ($edit['min_amount'] ?? '0')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Max total uses (0 = unlimited)</label>
          <input type="number" name="max_uses" class="form-control" min="0" value="<?= h((string) ($edit['max_uses'] ?? '0')) ?>">
        </div>
      </div>
      <div class="grid2">
        <div class="form-group">
          <label class="form-label">Per-user limit</label>
          <input type="number" name="per_user_limit" class="form-control" min="0" value="<?= h((string) ($edit['per_user_limit'] ?? '1')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Expires (optional)</label>
          <input type="datetime-local" name="expires_at" class="form-control" value="<?= !empty($edit['expires_at']) ? h(date('Y-m-d\TH:i', strtotime($edit['expires_at']))) : '' ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Note (internal)</label>
        <input type="text" name="note" class="form-control" value="<?= h($edit['note'] ?? '') ?>">
      </div>
      <label class="checkbox-label"><input type="checkbox" name="active" value="1" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> Active</label>
      <div style="margin-top:14px;display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary">Save coupon</button>
        <?php if ($edit): ?><a href="<?= h(path('admin/admin-coupons.php')) ?>" class="btn">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden;">
    <div style="padding:18px 20px;" class="card-title">All coupons</div>
    <table class="table">
      <thead>
        <tr><th>Code</th><th>Type</th><th>Value</th><th>Uses</th><th>Expires</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($coupons)): ?>
        <tr><td colspan="7" style="padding:24px;text-align:center;color:var(--text-muted);">No coupons yet. Create WELCOME10 or DEPOSIT10 to boost conversions.</td></tr>
        <?php else: foreach ($coupons as $c): ?>
        <tr>
          <td><strong><?= h($c['code']) ?></strong></td>
          <td style="font-size:12px;"><?= h($c['type']) ?></td>
          <td><?= h($c['value']) ?></td>
          <td><?= (int) $c['uses_count'] ?><?= (int) $c['max_uses'] > 0 ? ' / ' . (int) $c['max_uses'] : '' ?></td>
          <td style="font-size:11px;"><?= !empty($c['expires_at']) ? h(date('Y-m-d', strtotime($c['expires_at']))) : '—' ?></td>
          <td><span class="badge <?= ($c['active'] ?? 0) ? 'badge-green' : 'badge-red' ?>"><?= ($c['active'] ?? 0) ? 'Active' : 'Off' ?></span></td>
          <td style="white-space:nowrap;">
            <a href="?edit=<?= (int) $c['id'] ?>" class="btn btn-sm">Edit</a>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn btn-sm"><?= ($c['active'] ?? 0) ? 'Disable' : 'Enable' ?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
