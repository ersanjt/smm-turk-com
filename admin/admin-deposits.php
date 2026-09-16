<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Pending Deposits';
$db = Database::getInstance();

// Approve deposit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id']) && csrf_verify()) {
    $tid = (int) $_POST['approve_id'];
    $dm = new DepositManager();
    $result = $dm->approvePendingDeposit($tid);
    if ($result['success']) {
        $msg = 'Deposit approved. User balance updated by $' . number_format($result['amount'], 2) . '.';
        if (!empty($result['email_sent'])) {
            $msg .= ' Confirmation email sent.';
        } else {
            $msg .= ' Email could not be sent — check SMTP settings.';
        }
        flash('success', $msg);
    } else {
        flash('error', $result['error'] ?? 'Failed to approve deposit.');
    }
    redirect(url('admin/admin-deposits.php'));
}

// Reject / cancel pending deposit (allowed even when a TxHash exists)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id']) && csrf_verify()) {
    $tid = (int) $_POST['cancel_id'];
    $dm = new DepositManager();
    $result = $dm->rejectPendingDeposit($tid);
    if ($result['success']) {
        flash('success', 'Deposit #' . $tid . ' rejected. User was not credited. Use Recover below if the payment later appears on-chain.');
    } else {
        flash('error', $result['error'] ?? 'Failed to reject deposit.');
    }
    redirect(url('admin/admin-deposits.php'));
}

// Check TxHash on-chain without crediting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_id']) && csrf_verify()) {
    $tid = (int) $_POST['check_id'];
    $row = $db->fetch(
        "SELECT id, user_id, amount, description, reference, status FROM transactions WHERE id = ? AND type = 'deposit' AND status = 'pending'",
        [$tid]
    );
    if (!$row || trim((string) ($row['reference'] ?? '')) === '') {
        flash('error', 'No TxHash to check for this deposit.');
    } else {
        $catalog = DepositAutoConfirm::buildWalletCatalog($db);
        $coinKey = DepositAutoConfirm::parseCoinKey((string) ($row['description'] ?? ''), $catalog);
        $wallet = $coinKey ? trim((string) ($db->getSetting($coinKey) ?? '')) : '';
        if ($coinKey === null || $wallet === '') {
            flash('error', 'Cannot check on-chain — payment method or wallet is not configured.');
        } else {
            $verify = (new CryptoVerifier())->verify($coinKey, (string) $row['reference'], $wallet, (float) $row['amount']);
            $prefix = !empty($verify['ok']) ? 'On-chain OK — you can Approve. ' : 'On-chain check failed. ';
            flash(!empty($verify['ok']) ? 'success' : 'error', $prefix . ($verify['message'] ?? ''));
        }
    }
    redirect(url('admin/admin-deposits.php'));
}

// Recover failed deposit (paid on-chain but marked failed in panel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_id']) && csrf_verify()) {
    $tid = (int) $_POST['recover_id'];
    $dm = new DepositManager();
    $result = $dm->approveFailedDeposit($tid);
    if ($result['success']) {
        $msg = 'Failed deposit recovered. User balance updated by $' . number_format($result['amount'], 2) . '.';
        if (!empty($result['email_sent'])) {
            $msg .= ' Confirmation email sent.';
        } else {
            $msg .= ' Email could not be sent — check SMTP settings.';
        }
        flash('success', $msg);
    } else {
        flash('error', $result['error'] ?? 'Failed to recover deposit.');
    }
    redirect(url('admin/admin-deposits.php'));
}

$pending = $db->fetchAll(
    "SELECT t.*, u.username, u.email FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.type = 'deposit' AND t.status = 'pending' ORDER BY t.created_at DESC"
);

$failedWithTx = $db->fetchAll(
    "SELECT t.*, u.username, u.email FROM transactions t JOIN users u ON t.user_id = u.id
     WHERE t.type = 'deposit' AND t.status = 'failed' AND TRIM(COALESCE(t.reference, '')) != ''
     ORDER BY t.created_at DESC LIMIT 20"
);

require_once __DIR__ . '/../layouts/header.php';

$explorerUrl = static function (string $description, string $hash): string {
    $hash = trim($hash);
    if ($hash === '') {
        return '';
    }
    if (stripos($description, 'TRC20') !== false) {
        return 'https://tronscan.org/#/transaction/' . rawurlencode($hash);
    }
    if (stripos($description, 'ERC20') !== false || stripos($description, 'ETH') !== false) {
        return 'https://etherscan.io/tx/' . rawurlencode($hash);
    }
    if (stripos($description, 'BEP20') !== false || stripos($description, 'BNB') !== false) {
        return 'https://bscscan.com/tx/' . rawurlencode($hash);
    }
    if (stripos($description, 'BTC') !== false) {
        return 'https://blockstream.info/tx/' . rawurlencode($hash);
    }
    if (stripos($description, 'SOL') !== false) {
        return 'https://solscan.io/tx/' . rawurlencode($hash);
    }
    return '';
};
?>

<div class="admin-page-shell" style="max-width:900px;">
  <div class="card" style="margin-bottom:18px;">
    <div class="card-title">₿ Pending crypto deposits</div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Approve only after the payment is in your wallet. If a TxHash is present, use <strong>Check chain</strong> first. Reject is allowed even with a TxHash — the user is not credited, and Recover still works later if the payment shows up.</p>
    <?php if (empty($pending)): ?>
    <p style="color:var(--text-muted);">No pending deposits.</p>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr><th>ID</th><th>User</th><th>Amount</th><th>Method</th><th>TxHash / Ref</th><th>Date</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $t): ?>
        <tr>
          <td>#<?= $t['id'] ?></td>
          <td><?= h($t['username']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= h($t['email']) ?></span></td>
          <td><strong>$<?= number_format($t['amount'], 2) ?></strong></td>
          <td style="font-size:12px;"><?php
            $method = '—';
            if (preg_match('/—\s*(.+)$/', $t['description'] ?? '', $m)) {
                $method = trim($m[1]);
            }
            echo h($method);
          ?></td>
          <td style="font-size:11px;word-break:break-all;max-width:180px;"><?php
            $ref = trim((string) ($t['reference'] ?? ''));
            $scan = $ref !== '' ? $explorerUrl((string) ($t['description'] ?? ''), $ref) : '';
            if ($ref === '') {
                echo '—';
            } elseif ($scan !== '') {
                echo '<a href="' . h($scan) . '" target="_blank" rel="noopener">' . h($ref) . '</a>';
            } else {
                echo h($ref);
            }
          ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?= date('Y-m-d H:i', strtotime($t['created_at'])) ?></td>
          <td>
            <?php if ($ref !== ''): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="check_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:var(--border);">Check chain</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="approve_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">Approve</button>
            </form>
            <?php
              $rejectConfirm = $ref !== ''
                  ? 'This deposit has a TxHash. Reject only if $' . number_format((float) $t['amount'], 2) . ' did NOT arrive in your wallet. The user will not be credited.'
                  : 'Cancel this deposit request?';
            ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= h($rejectConfirm) ?>');">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="cancel_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:var(--text-muted);color:#fff;">Reject</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($failedWithTx)): ?>
  <div class="card admin-card-warning" style="margin-bottom:18px;">
    <div class="card-title">⚠ Failed deposits with TxHash (recovery)</div>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">User paid on-chain but the panel marked the deposit as failed (e.g. changed payment method after sending). Verify the TxHash on Tronscan, then click <strong>Recover &amp; credit</strong>.</p>
    <table class="table">
      <thead>
        <tr><th>ID</th><th>User</th><th>Amount</th><th>Method</th><th>TxHash</th><th>Date</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach ($failedWithTx as $t): ?>
        <tr>
          <td>#<?= $t['id'] ?></td>
          <td><?= h($t['username']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= h($t['email']) ?></span></td>
          <td><strong>$<?= number_format($t['amount'], 2) ?></strong></td>
          <td style="font-size:12px;"><?php
            $method = '—';
            if (preg_match('/—\s*(.+)$/', $t['description'] ?? '', $m)) {
                $method = trim($m[1]);
            }
            echo h($method);
          ?></td>
          <td style="font-size:11px;word-break:break-all;max-width:180px;"><?php
            $failRef = trim((string) ($t['reference'] ?? ''));
            $failScan = $failRef !== '' ? $explorerUrl((string) ($t['description'] ?? ''), $failRef) : '';
            if ($failScan !== '') {
                echo '<a href="' . h($failScan) . '" target="_blank" rel="noopener">' . h($failRef) . '</a>';
            } else {
                echo h($failRef);
            }
          ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?= date('Y-m-d H:i', strtotime($t['created_at'])) ?></td>
          <td>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Credit $<?= number_format((float) $t['amount'], 2) ?> to <?= h($t['username']) ?>? Verify TxHash on-chain first.');">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="recover_id" value="<?= $t['id'] ?>">
              <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;">Recover &amp; credit</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
