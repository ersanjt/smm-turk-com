<?php
require_once __DIR__ . '/app/init.php';
$auth->requireLogin();
$db = Database::getInstance();
$user = $auth->getCurrentUser();
$revenue = new RevenueEngine();
$depositBonusPct = (float) ($db->getSetting('deposit_bonus_percent') ?: 0);
$completedDeposits = (int) $db->fetch("SELECT COUNT(*) c FROM transactions WHERE user_id = ? AND type = 'deposit' AND status = 'completed'", [$user['id']])['c'];
$minDeposit = (float) ($db->getSetting('min_deposit') ?: 10);
$minDeposit = $minDeposit >= 1 ? $minDeposit : 10;

$paymentMethods = PaymentRegistry::enabledMethods();
$methodsAvailable = !empty($paymentMethods);

$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'add';
$selectedMethod = $_POST['method'] ?? $_GET['method'] ?? '';

$pendingDeposit = $db->fetch(
    "SELECT id, amount, description, reference, created_at FROM transactions
     WHERE user_id = ? AND type = 'deposit' AND status = 'pending'
     ORDER BY id DESC LIMIT 1",
    [$user['id']]
);

if (isset($_GET['new']) && $_GET['new'] === '1' && $_SERVER['REQUEST_METHOD'] !== 'POST' && $activeTab === 'add') {
    if ($pendingDeposit) {
        $hasSubmittedTx = trim((string) ($pendingDeposit['reference'] ?? '')) !== '';
        if ($hasSubmittedTx) {
            flash('error', 'You already submitted a payment for this deposit. It cannot be cancelled — wait for confirmation or contact support with your TxHash.');
            redirect(url('add-funds.php'));
        }
        $db->execute(
            "UPDATE transactions SET status = 'failed' WHERE id = ? AND user_id = ? AND type = 'deposit' AND status = 'pending'",
            [$pendingDeposit['id'], $user['id']]
        );
        $pendingDeposit = null;
    }
}

// Submit TxHash (USDT TRC20 manual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_tx']) && csrf_verify()) {
    $txId = trim($_POST['tx_hash'] ?? '');
    $depositId = (int) ($_POST['deposit_id'] ?? 0);
    if ($txId === '') {
        flash('error', 'Please paste your transaction ID (TxHash).');
        redirect(url('add-funds.php'));
    }
    if ($depositId > 0) {
        $tx = $db->fetch(
            "SELECT id, user_id FROM transactions WHERE id = ? AND user_id = ? AND type = 'deposit' AND status = 'pending'",
            [$depositId, $user['id']]
        );
        if ($tx) {
            $db->execute("UPDATE transactions SET reference = ? WHERE id = ?", [substr($txId, 0, 100), $tx['id']]);
            $fullTx = $db->fetch(
                "SELECT id, user_id, amount, description, reference, status, created_at FROM transactions WHERE id = ?",
                [$tx['id']]
            );
            $walletCatalog = DepositAutoConfirm::buildWalletCatalog($db);
            $auto = new DepositAutoConfirm();
            $check = $auto->processTransaction($fullTx, $walletCatalog);
            if ($check['approved']) {
                flash('success', 'Payment confirmed! Your balance has been credited.');
            } else {
                $methodLabel = PaymentRegistry::label(PaymentRegistry::parseMethodFromDescription($fullTx['description'] ?? '') ?? '') ?: 'Crypto';
                Notify::depositPending(
                    (int) $fullTx['id'],
                    $user['username'],
                    (string) ($user['email'] ?? ''),
                    (float) $fullTx['amount'],
                    $methodLabel,
                    substr($txId, 0, 100)
                );
                flash('success', 'Payment submitted. ' . ($check['message'] ?? 'Verifying on-chain…'));
            }
        }
    }
    redirect(url('add-funds.php'));
}

// Create deposit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_funds']) && csrf_verify()) {
    if (!$methodsAvailable) {
        flash('error', 'No payment methods are enabled. Contact support.');
        redirect(url('add-funds.php'));
    }
    $method = strtolower(trim($_POST['method'] ?? ''));
    $amount = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;

    if (!isset($paymentMethods[$method])) {
        flash('error', 'Please select a valid payment method.');
        redirect(url('add-funds.php'));
    }
    if ($amount < $minDeposit) {
        flash('error', "Minimum deposit is \${$minDeposit}.");
        redirect(url('add-funds.php'));
    }

    $coupon = strtoupper(trim($_POST['coupon_code'] ?? ''));
    if ($coupon !== '') {
        $check = $revenue->validateCoupon($coupon, (int) $user['id'], 'deposit', $amount);
        if (!$check['valid']) {
            flash('error', $check['error'] ?? 'Invalid coupon.');
            redirect(url('add-funds.php'));
        }
        $_SESSION['deposit_coupon'] = $coupon;
    } else {
        unset($_SESSION['deposit_coupon']);
    }

    if ($pendingDeposit) {
        $db->execute(
            "UPDATE transactions SET amount = ?, description = ?, reference = '' WHERE id = ? AND user_id = ?",
            [$amount, PaymentRegistry::depositDescription($amount, $method), $pendingDeposit['id'], $user['id']]
        );
        $depositId = (int) $pendingDeposit['id'];
    } else {
        $balanceBefore = (float) $user['balance'];
        $depositId = (int) $db->insert(
            "INSERT INTO transactions (user_id, type, amount, balance_before, balance_after, description, reference, status)
             VALUES (?, 'deposit', ?, ?, ?, ?, '', 'pending')",
            [$user['id'], $amount, $balanceBefore, $balanceBefore, PaymentRegistry::depositDescription($amount, $method)]
        );
    }

    $processor = new PaymentProcessor();
    $init = $processor->initiate($method, $depositId, (int) $user['id'], $amount, (string) ($user['email'] ?? ''));

    if (!$init['success']) {
        $db->execute(
            "UPDATE transactions SET status = 'failed' WHERE id = ? AND user_id = ?",
            [$depositId, $user['id']]
        );
        flash('error', $init['error'] ?? 'Could not start payment.');
        redirect(url('add-funds.php'));
    }

    if (!empty($init['manual'])) {
        $methodLabel = PaymentRegistry::label($method);
        Notify::depositPending(
            $depositId,
            $user['username'],
            (string) ($user['email'] ?? ''),
            $amount,
            $methodLabel
        );
        if (!empty($init['heleket'])) {
            redirect(url('add-funds.php'));
        }
        redirect(page_url('add-funds.php', ['method' => $method]));
    }

    if (!empty($init['redirect_url'])) {
        $methodLabel = PaymentRegistry::label($method);
        Notify::depositPending(
            $depositId,
            $user['username'],
            (string) ($user['email'] ?? ''),
            $amount,
            $methodLabel
        );
        redirect($init['redirect_url']);
    }

    flash('error', 'Payment gateway did not return a URL.');
    redirect(url('add-funds.php'));
}

// Wizard state
$step = 'form';
$amount = null;
$depositId = 0;
$txSubmitted = false;
$activeMethod = null;
$manualPay = null;
$heleketPay = null;

if ($activeTab === 'add' && $pendingDeposit) {
    $amount = (float) $pendingDeposit['amount'];
    $depositId = (int) $pendingDeposit['id'];
    $txSubmitted = trim((string) ($pendingDeposit['reference'] ?? '')) !== '';
    $methodSlug = PaymentRegistry::parseMethodFromDescription($pendingDeposit['description'] ?? '');

    if ($methodSlug === PaymentRegistry::HELEKET) {
        $activeMethod = PaymentRegistry::HELEKET;
        $hkRef = PaymentRegistry::parseHeleketRef($pendingDeposit['reference'] ?? '');
        if ($hkRef && ($hkRef['address'] !== '' || $hkRef['uuid'] !== '')) {
            $heleketPay = [
                'uuid' => $hkRef['uuid'],
                'address' => $hkRef['address'],
                'payer_amount' => '',
                'currency' => strtoupper(trim((string) $db->getSetting('payment_heleket_currency') ?: 'USDT')),
                'network' => strtolower(trim((string) $db->getSetting('payment_heleket_network') ?: 'bsc')),
            ];
            if ($heleketPay['address'] === '' && $heleketPay['uuid'] !== '') {
                $proc = new PaymentProcessor();
                $info = $proc->heleketPaymentInfo($heleketPay['uuid']);
                if (is_array($info)) {
                    $heleketPay['address'] = trim((string) ($info['address'] ?? ''));
                    $heleketPay['payer_amount'] = (string) ($info['payer_amount'] ?? '');
                    $heleketPay['currency'] = (string) ($info['payer_currency'] ?? $heleketPay['currency']);
                    $heleketPay['network'] = (string) ($info['network'] ?? $heleketPay['network']);
                }
            }
            if ($heleketPay['address'] !== '') {
                $step = 'heleket_pay';
            }
        }
    } elseif ($methodSlug !== null && ($methodSlug === PaymentRegistry::USDT_TRC20 || PaymentRegistry::isManualWalletSlug($methodSlug))) {
        $activeMethod = $methodSlug;
        $manualPay = PaymentRegistry::manualPayMeta($methodSlug);
        if ($txSubmitted) {
            $step = 'awaiting';
        } elseif ($manualPay) {
            $step = 'manual_pay';
        }
    }
}

$fundHistory = [];
if ($activeTab === 'history') {
    $fundHistory = $db->fetchAll(
        "SELECT id, amount, description, reference, status, created_at
         FROM transactions WHERE user_id = ? AND type = 'deposit'
         ORDER BY created_at DESC LIMIT 50",
        [$user['id']]
    );
}

$currentBalance = (float) ($user['balance'] ?? 0);
$balanceRow = $db->fetch("SELECT balance, spent FROM users WHERE id = ?", [(int) $user['id']]);
if ($balanceRow !== null) {
    $currentBalance = (float) $balanceRow['balance'];
}
$totalSpent = (float) ($balanceRow['spent'] ?? $user['spent'] ?? 0);

$depositStats = ['total_deposited' => 0.0, 'completed_count' => 0, 'pending_count' => 0];
try {
    $depositStats = $db->fetch(
        "SELECT COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS total_deposited,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
         FROM transactions WHERE user_id = ? AND type = 'deposit'",
        [$user['id']]
    ) ?: $depositStats;
} catch (Throwable $e) {
    /* ignore */
}

$zarinRate = (float) ($db->getSetting('payment_zarinpal_usd_rate') ?: 0);
$methodGroups = PaymentRegistry::groupedMethods($paymentMethods);

$defaultMethod = $selectedMethod && isset($paymentMethods[$selectedMethod])
    ? $selectedMethod
    : (array_key_first($paymentMethods) ?: '');

$defaultAmount = max($minDeposit, 25);

function af_render_steps(string $current): void
{
    $steps = [
        'method'  => ['n' => 1, 'label' => 'Choose method'],
        'amount'  => ['n' => 2, 'label' => 'Enter amount'],
        'pay'     => ['n' => 3, 'label' => 'Send payment'],
        'confirm' => ['n' => 4, 'label' => 'Confirmed'],
    ];
    $order = array_keys($steps);
    $currentIdx = array_search($current, $order, true);
    if ($currentIdx === false) {
        $currentIdx = 0;
    }
    echo '<div class="add-funds-steps">';
    foreach ($steps as $key => $step) {
        $idx = array_search($key, $order, true);
        $cls = 'add-funds-step';
        if ($idx < $currentIdx) {
            $cls .= ' done';
        } elseif ($idx === $currentIdx) {
            $cls .= ' active';
        }
        echo '<div class="' . $cls . '"><span>Step ' . (int) $step['n'] . '</span>' . h($step['label']) . '</div>';
    }
    echo '</div>';
}

function af_render_back_link(string $label = 'Back — change payment method'): void
{
    echo '<div class="af-back-bar"><a href="' . h(path('add-funds.php')) . '?new=1" class="af-back-link" title="Return to method and amount selection">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>'
        . h($label) . '</a></div>';
}

function af_render_method_cards(array $methods, string $defaultMethod): void
{
    foreach ($methods as $slug => $meta) {
        $color = PaymentRegistry::coinColor($slug);
        $selected = $slug === $defaultMethod;
        $badge = PaymentRegistry::methodBadge($slug, $meta);
        $rec = PaymentRegistry::isRecommended($slug);
        ?>
        <label class="coin-card<?= $selected ? ' selected' : '' ?>" data-method="<?= h($slug) ?>"
               data-label="<?= h($meta['label']) ?>" data-badge="<?= h($badge) ?>" data-type="<?= h($meta['type'] ?? '') ?>"
               style="--coin-color:<?= h($color) ?>">
          <input type="radio" name="method" value="<?= h($slug) ?>" <?= $selected ? 'checked' : '' ?> required hidden>
          <?php if ($rec): ?><span class="coin-rec">Recommended</span><?php endif; ?>
          <span class="coin-badge"><?= h($meta['icon']) ?></span>
          <span class="coin-name"><?= h($meta['label']) ?></span>
          <span class="coin-network"><?= h($meta['desc']) ?></span>
          <span class="coin-hint"><?= h($badge) ?></span>
        </label>
        <?php
    }
}

$pageTitle = 'Add Funds';
$pageDescription = 'Add funds via SmmPayGate, Heleket, USDT TRC20, Binance Pay, ZarinPal, or CryptoCloud.';
require_once __DIR__ . '/layouts/header.php';
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/add-funds.css')) ?>">

<div class="add-funds-hero">
  <div class="add-funds-hero-main">
    <div class="af-hero-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/><path d="M6 16h4"/></svg>
    </div>
    <div>
      <h1 class="af-hero-title">Add Funds</h1>
      <p class="af-hero-sub">Top up your balance with crypto or payment gateways. Funds are credited in USD.
        <?php if ($depositBonusPct > 0 && $completedDeposits === 0): ?>
        <strong style="color:var(--primary);"> First deposit: +<?= number_format($depositBonusPct, 0) ?>% bonus!</strong>
        <?php endif; ?>
      </p>
    </div>
  </div>
  <div class="add-funds-stats">
    <div class="af-stat">
      <span class="af-stat-label">Balance</span>
      <span class="af-stat-value af-stat-balance">$<?= number_format($currentBalance, 3) ?></span>
    </div>
    <div class="af-stat">
      <span class="af-stat-label">Total deposited</span>
      <span class="af-stat-value">$<?= number_format((float) ($depositStats['total_deposited'] ?? 0), 2) ?></span>
    </div>
    <div class="af-stat">
      <span class="af-stat-label">Spent</span>
      <span class="af-stat-value">$<?= number_format($totalSpent, 3) ?></span>
    </div>
  </div>
</div>

<div class="add-funds-tabs">
  <a href="<?= h(path('add-funds.php')) ?>" class="<?= $activeTab === 'add' ? 'active' : '' ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 5v14M5 12h14"/></svg>
    Add Funds
  </a>
  <a href="<?= h(path('add-funds.php')) ?>?tab=history" class="<?= $activeTab === 'history' ? 'active' : '' ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    Fund History
    <?php if ((int) ($depositStats['pending_count'] ?? 0) > 0): ?>
    <span class="af-tab-badge"><?= (int) $depositStats['pending_count'] ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($activeTab === 'history'): ?>
  <div class="card add-funds-card">
    <div class="card-title">Fund History</div>
    <?php if (empty($fundHistory)): ?>
    <div class="af-empty-state">
      <div class="af-empty-icon">💳</div>
      <h3>No deposits yet</h3>
      <p>Add funds to start placing orders on SMM Turk.</p>
      <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary">+ Add Funds</a>
    </div>
    <?php else: ?>
    <div class="af-history-summary">
      <div><strong><?= (int) ($depositStats['completed_count'] ?? 0) ?></strong> completed</div>
      <div><strong>$<?= number_format((float) ($depositStats['total_deposited'] ?? 0), 2) ?></strong> total added</div>
      <?php if ((int) ($depositStats['pending_count'] ?? 0) > 0): ?>
      <div class="af-pending-pill"><strong><?= (int) $depositStats['pending_count'] ?></strong> pending</div>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table add-funds-table">
        <thead><tr><th>ID</th><th>Date</th><th>Method</th><th>Status</th><th>Amount</th><th>Reference</th></tr></thead>
        <tbody>
        <?php foreach ($fundHistory as $t):
            $st = strtolower($t['status'] ?? 'pending');
            $stClass = $st === 'completed' ? 'status-completed' : ($st === 'failed' ? 'status-failed' : 'status-pending');
            $method = PaymentRegistry::parseMethodFromDescription($t['description'] ?? '');
            $methodLabel = $method ? PaymentRegistry::label($method) : 'Deposit';
            if (preg_match('/—\s*(.+)$/', $t['description'] ?? '', $m)) {
                $methodLabel = trim($m[1]);
            }
            $ref = trim((string) ($t['reference'] ?? ''));
            $refShort = $ref !== '' ? (strlen($ref) > 18 ? substr($ref, 0, 10) . '…' . substr($ref, -6) : $ref) : '—';
        ?>
        <tr>
          <td><span class="af-tx-id">#<?= (int) $t['id'] ?></span></td>
          <td><?= h(date('M j, Y · H:i', strtotime($t['created_at']))) ?></td>
          <td><span class="af-method-pill" style="--coin-color:<?= h(PaymentRegistry::coinColor($method ?? '')) ?>"><?= h($methodLabel) ?></span></td>
          <td><span class="af-status-dot <?= h($stClass) ?>"></span><span class="<?= h($stClass) ?>"><?= h(ucfirst($st)) ?></span></td>
          <td><strong class="af-amount-cell">$<?= number_format((float) $t['amount'], 2) ?></strong></td>
          <td><code class="af-ref" title="<?= h($ref) ?>"><?= h($refShort) ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="add-funds-history-cta"><a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary">+ Add Funds</a></p>
  </div>

<?php elseif ($step === 'heleket_pay' && $heleketPay): ?>
  <?php af_render_back_link(); ?>
  <?php af_render_steps('pay'); ?>
  <div class="card add-funds-card">
    <div class="pay-summary">
      <div class="pay-amount">
        $<?= number_format($amount, 2) ?>
        <small>Deposit amount (USD)</small>
      </div>
      <div class="pay-coin-pill" style="--coin-color:#e30a17">
        <span class="pay-coin-dot"></span>
        Heleket · <?= h($heleketPay['currency']) ?> <?= h(PaymentRegistry::heleketNetworkLabel($heleketPay['network'])) ?>
      </div>
    </div>
    <?php if ($heleketPay['payer_amount'] !== ''): ?>
    <div class="heleket-pay-amount">
      Send exactly <strong><?= h($heleketPay['payer_amount']) ?> <?= h($heleketPay['currency']) ?></strong>
    </div>
    <?php endif; ?>
    <div class="pay-panel">
      <div class="pay-qr-wrap">
        <div id="walletQr" class="pay-qr-canvas" aria-label="Heleket QR"></div>
        <span class="pay-qr-label">Scan with your wallet app</span>
      </div>
      <div class="pay-address-block">
        <div class="network-warn">
          <strong>Heleket:</strong> Send only <strong><?= h($heleketPay['currency']) ?></strong> on
          <strong><?= h(PaymentRegistry::heleketNetworkLabel($heleketPay['network'])) ?></strong>.
          Wrong network = lost funds.
        </div>
        <div class="addr-label">Wallet address</div>
        <code id="walletAddress"><?= h($heleketPay['address']) ?></code>
        <div class="pay-copy-row">
          <button type="button" class="btn btn-primary" id="copyAddressBtn">Copy address</button>
          <?php if (!empty($heleketPay['pay_url'])): ?>
          <a href="<?= h($heleketPay['pay_url']) ?>" class="btn" target="_blank" rel="noopener">Open Heleket page</a>
          <?php endif; ?>
          <a href="<?= h(path('add-funds.php')) ?>?new=1" class="btn af-btn-change-method">Change method</a>
        </div>
      </div>
    </div>
    <div class="add-funds-next-box">
      <strong>What happens next?</strong><br>
      1. Send crypto to the address above.<br>
      2. Heleket confirms payment and sends a webhook to our panel.<br>
      3. Balance updates automatically — this page refreshes status below.
    </div>
    <div class="deposit-status-card" id="depositStatusCard" style="margin-top:16px;">
      <h3 id="depositStatusTitle">Waiting for Heleket payment…</h3>
      <p id="depositStatusMsg">Status checks every few seconds.</p>
      <div class="deposit-status-meta">
        <div><strong>Deposit ID:</strong> #<?= $depositId ?></div>
        <div><strong>Status:</strong> <span id="depositLiveStatus">Pending</span></div>
      </div>
    </div>
    <div id="depositConfirmedActions" class="af-confirmed-actions" style="display:none;">
      <a href="<?= h(path('dashboard.php')) ?>" class="btn btn-primary">Place an order →</a>
      <a href="<?= h(path('child-panel.php')) ?>" class="btn">Child Panel</a>
    </div>
  </div>

<?php elseif ($step === 'manual_pay' && $manualPay): ?>
  <?php af_render_back_link(); ?>
  <?php af_render_steps('pay'); ?>
  <div class="card add-funds-card">
    <div class="pay-summary">
      <div class="pay-amount">$<?= number_format($amount, 2) ?><small>Send <?= h($manualPay['label']) ?> (<?= h($manualPay['network']) ?>)</small></div>
      <div class="pay-coin-pill" style="--coin-color:#26a17b"><span class="pay-coin-dot"></span><?= h($manualPay['label'] . ' ' . $manualPay['network']) ?></div>
    </div>
    <div class="pay-panel">
      <div class="pay-qr-wrap">
        <div id="walletQr" class="pay-qr-canvas" aria-label="QR code"></div>
        <span class="pay-qr-label">Scan with wallet app</span>
      </div>
      <div class="pay-address-block">
        <div class="network-warn"><strong>Important:</strong> Send only <strong><?= h($manualPay['label']) ?> on <?= h($manualPay['network']) ?></strong> to this address.</div>
        <div class="addr-label">Wallet address</div>
        <code id="walletAddress"><?= h($manualPay['address']) ?></code>
        <div class="pay-copy-row">
          <button type="button" class="btn btn-primary" id="copyAddressBtn">Copy address</button>
          <a href="<?= h(path('add-funds.php')) ?>?new=1" class="btn af-btn-change-method">Change method</a>
        </div>
      </div>
    </div>
    <div class="add-funds-next-box">
      <strong>After sending:</strong> Paste your blockchain TxHash below. We verify on-chain and credit your balance automatically.
    </div>
    <form method="POST" class="af-tx-form">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="deposit_id" value="<?= $depositId ?>">
      <input type="hidden" name="submit_tx" value="1">
      <div class="form-group">
        <label class="form-label">Transaction ID (TxHash)</label>
        <input type="text" name="tx_hash" class="form-control" placeholder="Paste blockchain transaction hash" required autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary btn-block">I've sent the payment</button>
    </form>
  </div>

<?php elseif ($step === 'awaiting'): ?>
  <?php af_render_back_link(); ?>
  <?php af_render_steps('confirm'); ?>
  <div class="card add-funds-card">
    <div class="deposit-status-card" id="depositStatusCard">
      <div class="status-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="28" height="28"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
      </div>
      <h3 id="depositStatusTitle">Verifying payment…</h3>
      <p id="depositStatusMsg">Checking your <?= h($manualPay['label'] ?? 'crypto') ?> <?= h($manualPay['network'] ?? '') ?> transaction.</p>
      <div class="deposit-status-meta">
        <div><strong>Deposit ID:</strong> #<?= $depositId ?></div>
        <div><strong>Amount:</strong> $<?= number_format($amount, 2) ?></div>
        <div><strong>TxHash:</strong> <code><?= h($pendingDeposit['reference'] ?? '') ?></code></div>
        <div><strong>Status:</strong> <span id="depositLiveStatus">Checking…</span></div>
      </div>
    </div>
    <div id="depositConfirmedActions" class="af-confirmed-actions" style="display:none;">
      <a href="<?= h(path('dashboard.php')) ?>" class="btn btn-primary">Place an order →</a>
    </div>
    <p class="text-muted-sm"><a href="<?= h(path('add-funds.php')) ?>?new=1" class="af-back-link af-back-link-inline">← Change payment method</a></p>
  </div>

<?php else: ?>
  <?php af_render_steps('method'); ?>
  <div class="card add-funds-card">
    <?php if (!$methodsAvailable): ?>
    <p class="text-muted">No payment methods are active yet.</p>
    <ul class="text-muted" style="font-size:13px;line-height:1.7;margin:12px 0 16px;padding-left:20px;">
      <li><strong>Crypto wallets</strong> — set BTC, ETH, USDT, BNB, SOL addresses in Admin → Settings → Crypto Wallets</li>
      <li><strong>Gateways</strong> — enable SmmPayGate, Heleket, Binance Pay, ZarinPal, CryptoCloud and add API keys</li>
    </ul>
    <?php if ($auth->isAdmin()): ?>
    <p><a href="<?= h(path('admin/admin-settings.php')) ?>" class="btn btn-primary">Open Admin Settings</a></p>
    <?php else: ?>
    <p><a href="<?= h(path('tickets.php')) ?>" class="btn">Contact support</a></p>
    <?php endif; ?>
    <?php else: ?>
    <form method="POST" class="add-funds-form" id="addFundsForm"
          data-min-deposit="<?= h((string) $minDeposit) ?>"
          data-current-balance="<?= h((string) $currentBalance) ?>"
          data-zarin-rate="<?= h((string) $zarinRate) ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="add_funds" value="1">

      <?php if (!empty($methodGroups['crypto'])): ?>
      <div class="af-method-section">
        <h3 class="af-section-title">
          <span class="af-section-icon">₿</span> Crypto wallets
          <small>Direct transfer — paste TxHash after sending</small>
        </h3>
        <div class="coin-grid">
          <?php af_render_method_cards($methodGroups['crypto'], $defaultMethod); ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($methodGroups['gateways'])): ?>
      <div class="af-method-section">
        <h3 class="af-section-title">
          <span class="af-section-icon">⚡</span> Payment gateways
          <small>Redirect checkout — auto credit</small>
        </h3>
        <div class="coin-grid">
          <?php af_render_method_cards($methodGroups['gateways'], $defaultMethod); ?>
        </div>
      </div>
      <?php endif; ?>

      <div id="methodDetail" class="af-method-detail" hidden></div>

      <div class="af-amount-section">
        <h3 class="af-section-title">
          <span class="af-section-icon">$</span> Deposit amount
          <small>Minimum $<?= (int) $minDeposit ?></small>
        </h3>
        <div class="af-amount-input-wrap">
          <span class="af-currency">$</span>
          <input type="number" name="amount" id="amountInput" class="af-amount-input" min="<?= $minDeposit ?>" step="0.01" required value="<?= number_format($defaultAmount, 2, '.', '') ?>" placeholder="<?= (int) $minDeposit ?>">
          <span class="af-amount-usd">USD</span>
        </div>
        <div class="add-funds-presets">
          <?php
          $presets = [10, 25, 50, 100, 200, 500, 1000];
          foreach ($presets as $preset):
              if ($preset >= $minDeposit):
          ?>
          <button type="button" class="preset-btn<?= $preset === $defaultAmount ? ' active' : '' ?>" data-amount="<?= $preset ?>">$<?= $preset ?></button>
          <?php endif; endforeach; ?>
        </div>
        <div class="af-balance-preview">
          <div class="af-preview-row">
            <span>Current balance</span>
            <strong>$<?= number_format($currentBalance, 3) ?></strong>
          </div>
          <div class="af-preview-row af-preview-after">
            <span>After deposit</span>
            <strong id="afterBalance">$<?= number_format($currentBalance + $defaultAmount, 3) ?></strong>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin-top:12px;">
        <label class="form-label" for="deposit-coupon">Promo / coupon code <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
        <input type="text" name="coupon_code" id="deposit-coupon" class="form-control" placeholder="DEPOSIT10" autocomplete="off" style="text-transform:uppercase;">
      </div>

      <button type="submit" class="btn btn-primary btn-block add-funds-submit" id="addFundsSubmit">
        Continue to payment →
      </button>
      <div class="af-trust-row">
        <span>🔒 Secure</span>
        <span>⚡ Auto-verify</span>
        <span>📧 Email on credit</span>
      </div>
      <p class="add-funds-hint">Minimum <strong>$<?= (int) $minDeposit ?></strong>. Wrong network or coin may result in lost funds — always match the method shown.</p>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="add-funds-toast" id="copyToast" role="status">Address copied!</div>

<script>window.ADD_FUNDS_STATUS_URL = <?= json_encode(path('api/deposit-status.php')) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js" crossorigin="anonymous"></script>
<script src="<?= h(asset_url('assets/js/add-funds.js')) ?>"></script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
