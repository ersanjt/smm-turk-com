<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../app/Lang.php';
Lang::initUser();

$pageTitle = __('dep_title');
$pageSubtitle = __('dep_sub');
$db = Database::getInstance();

$depositViews = ['action', 'pending', 'approved', 'closed'];

$depositView = static function () use ($depositViews): string {
    $view = (string) ($_POST['return_view'] ?? $_GET['view'] ?? 'action');
    return in_array($view, $depositViews, true) ? $view : 'action';
};

$depositBack = static function (string $view): string {
    return url('admin/admin-deposits.php?view=' . rawurlencode($view));
};

$depositFill = static function (string $key, array $repl = []): string {
    $text = __($key);
    foreach ($repl as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
};

$depositMailNote = static function (array $result) use ($depositFill): string {
    return ' ' . (!empty($result['email_sent']) ? $depositFill('dep_flash_email_ok') : $depositFill('dep_flash_email_fail'));
};

$view = $depositView();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $dm = new DepositManager();

    if (isset($_POST['approve_id'])) {
        $result = $dm->approvePendingDeposit((int) $_POST['approve_id']);
        if ($result['success']) {
            flash('success', $depositFill('dep_flash_approved', [
                'amount' => '$' . number_format((float) $result['amount'], 2),
            ]) . $depositMailNote($result));
        } else {
            flash('error', $result['error'] ?? $depositFill('dep_flash_approve_fail'));
        }
        redirect($depositBack($view));
    }

    if (isset($_POST['cancel_id'])) {
        $tid = (int) $_POST['cancel_id'];
        $result = $dm->rejectPendingDeposit($tid);
        if ($result['success']) {
            flash('success', $depositFill('dep_flash_rejected', ['id' => (string) $tid]));
        } else {
            flash('error', $result['error'] ?? $depositFill('dep_flash_reject_fail'));
        }
        redirect($depositBack($view));
    }

    if (isset($_POST['check_id'])) {
        $tid = (int) $_POST['check_id'];
        $row = $db->fetch(
            "SELECT id, user_id, amount, description, reference, status FROM transactions WHERE id = ? AND type = 'deposit' AND status = 'pending'",
            [$tid]
        );
        if (!$row || trim((string) ($row['reference'] ?? '')) === '') {
            flash('error', $depositFill('dep_flash_no_tx'));
        } else {
            $catalog = DepositAutoConfirm::buildWalletCatalog($db);
            $coinKey = DepositAutoConfirm::parseCoinKey((string) ($row['description'] ?? ''), $catalog);
            $wallet = $coinKey ? trim((string) ($db->getSetting($coinKey) ?? '')) : '';
            if ($coinKey === null || $wallet === '') {
                flash('error', $depositFill('dep_flash_no_wallet'));
            } else {
                $verify = (new CryptoVerifier())->verify($coinKey, (string) $row['reference'], $wallet, (float) $row['amount']);
                $prefix = !empty($verify['ok']) ? $depositFill('dep_flash_chain_ok') : $depositFill('dep_flash_chain_fail');
                $detail = trim((string) ($verify['message'] ?? ''));
                flash(!empty($verify['ok']) ? 'success' : 'error', $prefix . ($detail !== '' ? ' ' . $detail : ''));
            }
        }
        redirect($depositBack($view));
    }

    if (isset($_POST['recover_id'])) {
        $result = $dm->approveFailedDeposit((int) $_POST['recover_id']);
        if ($result['success']) {
            flash('success', $depositFill('dep_flash_recovered', [
                'amount' => '$' . number_format((float) $result['amount'], 2),
            ]) . $depositMailNote($result));
        } else {
            flash('error', $result['error'] ?? $depositFill('dep_flash_recover_fail'));
        }
        redirect($depositBack($view));
    }
}

$depositMaintenance = new DepositManager();
if (method_exists($depositMaintenance, 'remindUnpaidCheckouts')) {
    $depositMaintenance->remindUnpaidCheckouts(2);
}
if (method_exists($depositMaintenance, 'expireAbandonedCheckouts')) {
    $depositMaintenance->expireAbandonedCheckouts(24);
}

$countRow = $db->fetch(
    "SELECT
        SUM(CASE WHEN status = 'pending' AND TRIM(COALESCE(reference, '')) <> '' THEN 1 ELSE 0 END) AS pending_tx,
        SUM(CASE WHEN status = 'pending' AND TRIM(COALESCE(reference, '')) = '' THEN 1 ELSE 0 END) AS pending_open,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'failed' AND TRIM(COALESCE(reference, '')) <> '' THEN 1 ELSE 0 END) AS recover,
        SUM(CASE WHEN status = 'failed' AND TRIM(COALESCE(reference, '')) = '' THEN 1 ELSE 0 END) AS closed
     FROM transactions
     WHERE type = 'deposit'"
) ?: [];

$counts = [
    'action' => (int) ($countRow['pending_tx'] ?? 0) + (int) ($countRow['recover'] ?? 0),
    'pending' => (int) ($countRow['pending_open'] ?? 0),
    'approved' => (int) ($countRow['approved'] ?? 0),
    'closed' => (int) ($countRow['closed'] ?? 0),
];

$listLimit = 100;
$listSql = [
    'action' => "(t.status = 'pending' AND TRIM(COALESCE(t.reference, '')) <> '')
        OR (t.status = 'failed' AND TRIM(COALESCE(t.reference, '')) <> '')",
    'pending' => "t.status = 'pending' AND TRIM(COALESCE(t.reference, '')) = ''",
    'approved' => "t.status = 'completed'",
    'closed' => "t.status = 'failed' AND TRIM(COALESCE(t.reference, '')) = ''",
];
$orderSql = $view === 'action'
    ? "CASE WHEN t.status = 'failed' THEN 0 ELSE 1 END, t.created_at DESC"
    : 't.created_at DESC';

$rows = $db->fetchAll(
    "SELECT t.*, u.username, u.email
     FROM transactions t
     JOIN users u ON t.user_id = u.id
     WHERE t.type = 'deposit' AND ({$listSql[$view]})
     ORDER BY {$orderSql}
     LIMIT " . $listLimit
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

$depositMethod = static function (?string $description): string {
    if (preg_match('/—\s*(.+)$/u', (string) $description, $m)) {
        return trim($m[1]);
    }
    return '—';
};

$dateFormat = Lang::current() === 'en' ? 'Y-m-d H:i' : 'd.m.Y H:i';
$notes = [
    'action' => 'dep_note_action',
    'pending' => 'dep_note_pending',
    'approved' => 'dep_note_approved',
    'closed' => 'dep_note_closed',
];
$emptyKeys = [
    'action' => 'dep_empty_action',
    'pending' => 'dep_empty_pending',
    'approved' => 'dep_empty_approved',
    'closed' => 'dep_empty_closed',
];
$tabs = [
    'action' => 'dep_tab_action',
    'pending' => 'dep_tab_pending',
    'approved' => 'dep_tab_approved',
    'closed' => 'dep_tab_closed',
];
?>

<style>
.dep-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
.dep-tabs a{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid var(--border);background:var(--white);color:var(--text);text-decoration:none;font-size:13px;font-weight:700}
.dep-tabs a.is-on{background:var(--primary);border-color:var(--primary);color:#fff}
.dep-count{min-width:1.4em;padding:1px 7px;border-radius:999px;background:rgba(0,0,0,.08);font-size:11px;text-align:center}
.dep-tabs a.is-on .dep-count{background:rgba(255,255,255,.22)}
.dep-note{font-size:13px;color:var(--text-muted);margin:0 0 16px;line-height:1.5}
.dep-table-wrap{overflow-x:auto}
.dep-actions{display:flex;flex-wrap:wrap;gap:6px}
.dep-actions form{margin:0}
</style>

<div class="admin-page-shell" style="max-width:1100px;">
  <nav class="dep-tabs" aria-label="<?= h(__('dep_title')) ?>">
    <?php foreach ($tabs as $key => $labelKey): ?>
    <a class="<?= $view === $key ? 'is-on' : '' ?>" href="<?= h(admin_path('admin-deposits.php', ['view' => $key])) ?>">
      <?= h(__($labelKey)) ?>
      <span class="dep-count"><?= (int) $counts[$key] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="card" style="margin-bottom:18px;">
    <p class="dep-note"><?= h(__($notes[$view])) ?></p>
    <?php if ($counts[$view] > $listLimit): ?>
    <p class="dep-note"><?= h($depositFill('dep_latest', ['n' => (string) $listLimit])) ?></p>
    <?php endif; ?>
    <?php if ($rows === []): ?>
    <p style="color:var(--text-muted);"><?= h(__($emptyKeys[$view])) ?></p>
    <?php else: ?>
    <div class="dep-table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= h(__('dep_col_id')) ?></th>
          <th><?= h(__('dep_col_user')) ?></th>
          <th><?= h(__('dep_col_amount')) ?></th>
          <th><?= h(__('dep_col_method')) ?></th>
          <th><?= h(__('dep_col_tx')) ?></th>
          <th><?= h(__('dep_col_date')) ?></th>
          <th><?= h(__('dep_col_status')) ?></th>
          <th><?= h(__('dep_col_action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $t):
            $ref = trim((string) ($t['reference'] ?? ''));
            $status = (string) ($t['status'] ?? '');
            $scan = $ref !== '' ? $explorerUrl((string) ($t['description'] ?? ''), $ref) : '';
            if ($status === 'completed') {
                $badge = 'badge-green';
                $statusLabel = __('dep_status_approved');
            } elseif ($status === 'failed' && $ref !== '') {
                $badge = 'badge-red';
                $statusLabel = __('dep_status_recover');
            } elseif ($status === 'failed') {
                $badge = 'badge-gray';
                $statusLabel = __('dep_status_closed');
            } elseif ($ref !== '') {
                $badge = 'badge-orange';
                $statusLabel = __('dep_status_review');
            } else {
                $badge = 'badge-gray';
                $statusLabel = __('dep_status_unpaid');
            }
            $amountLabel = '$' . number_format((float) $t['amount'], 2);
            $rejectConfirm = $ref !== ''
                ? $depositFill('dep_reject_tx', ['amount' => $amountLabel])
                : $depositFill('dep_reject_plain');
            $recoverConfirm = $depositFill('dep_recover_confirm', [
                'amount' => $amountLabel,
                'user' => (string) ($t['username'] ?? ''),
            ]);
        ?>
        <tr>
          <td>#<?= (int) $t['id'] ?></td>
          <td><?= h($t['username']) ?><br><span style="font-size:11px;color:var(--text-muted);"><?= h($t['email']) ?></span></td>
          <td><strong><?= h($amountLabel) ?></strong></td>
          <td style="font-size:12px;"><?= h($depositMethod($t['description'] ?? '')) ?></td>
          <td style="font-size:11px;word-break:break-all;max-width:180px;"><?php
            if ($ref === '') {
                echo '—';
            } elseif ($scan !== '') {
                echo '<a href="' . h($scan) . '" target="_blank" rel="noopener">' . h($ref) . '</a>';
            } else {
                echo h($ref);
            }
          ?></td>
          <td style="color:var(--text-muted);font-size:12px;"><?= h(date($dateFormat, strtotime((string) $t['created_at']))) ?></td>
          <td><span class="badge <?= h($badge) ?>"><?= h($statusLabel) ?></span></td>
          <td>
            <div class="dep-actions">
            <?php if ($status === 'pending' && $ref !== ''): ?>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="return_view" value="<?= h($view) ?>">
              <input type="hidden" name="check_id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:var(--border);"><?= h(__('dep_check')) ?></button>
            </form>
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="return_view" value="<?= h($view) ?>">
              <input type="hidden" name="approve_id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;"><?= h(__('dep_approve')) ?></button>
            </form>
            <?php endif; ?>
            <?php if ($status === 'pending'): ?>
            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode($rejectConfirm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_NOQUOTES) ?>);">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="return_view" value="<?= h($view) ?>">
              <input type="hidden" name="cancel_id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn" style="padding:6px 12px;font-size:12px;background:var(--text-muted);color:#fff;"><?= h(__('dep_reject')) ?></button>
            </form>
            <?php endif; ?>
            <?php if ($status === 'failed' && $ref !== ''): ?>
            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode($recoverConfirm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_NOQUOTES) ?>);">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="return_view" value="<?= h($view) ?>">
              <input type="hidden" name="recover_id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-primary" style="padding:6px 12px;font-size:12px;"><?= h(__('dep_recover')) ?></button>
            </form>
            <?php endif; ?>
            <?php if ($status === 'completed' || ($status === 'failed' && $ref === '')): ?>
            <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
