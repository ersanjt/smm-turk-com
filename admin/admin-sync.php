<?php
require_once __DIR__ . '/_init.php';
$pageTitle = 'Sync Services';
$om = new OrderManager();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $provider = trim($_POST['provider'] ?? 'all');
    if (isset($_POST['dedupe_only'])) {
        $dedupeProvider = $provider === 'all' ? null : $provider;
        $stats = (new ServiceDeduper())->run($dedupeProvider);
        $removed = $stats['by_upstream_id'] + $stats['by_name'];
        flash('success', "Removed {$removed} duplicate services. {$stats['remaining']} active remain in catalog.");
        redirect(url('admin/admin-sync.php'));
    }
    if ($provider === 'all') {
        $result = $om->syncServices();
    } else {
        $result = $om->syncServices($provider);
    }
    if ($result['success']) {
        $msg = "Synced {$result['synced']} services.";
        if (!empty($result['failed'])) {
            $msg .= " ({$result['failed']} skipped — see sync log)";
        }
        if (!empty($result['deduped'])) {
            $msg .= " Removed {$result['deduped']} duplicates.";
        }
        if (!empty($result['errors'])) {
            $msg .= ' Warnings: ' . implode('; ', $result['errors']);
        }
        flash('success', $msg);
    } else {
        flash('error', $result['error'] ?? 'Sync failed');
    }
    redirect(url('admin/admin-sync.php'));
}

$providerStatus = [];
foreach (ProviderRegistry::definitions() as $slug => $def) {
    $enabled = ProviderRegistry::isEnabled($slug);
    $api = ProviderRegistry::api($slug);
    $balance = null;
    $error = null;
    if ($api) {
        $test = $api->testConnection();
        if ($test['success']) {
            $balance = ($test['balance'] ?? '?') . ' ' . ($test['currency'] ?? 'USD');
        } else {
            $error = $test['error'] ?? 'Connection failed';
        }
    } elseif ($enabled) {
        $error = 'API key not set';
    } else {
        $error = 'Disabled';
    }
    $count = (int) $db->fetch("SELECT COUNT(*) c FROM services WHERE provider = ? AND status='active'", [$slug])['c'];
    $providerStatus[$slug] = [
        'name' => $def['name'],
        'enabled' => $enabled,
        'balance' => $balance,
        'error' => $error,
        'services' => $count,
    ];
}

require_once __DIR__ . '/../layouts/header.php';
?>
<div class="card" style="max-width:640px;margin-bottom:18px;">
  <div class="card-title">🔄 Sync Services from Providers</div>
  <p style="color:var(--text-muted);font-size:13px;margin-bottom:20px;line-height:1.6;">
    Fetch services from connected APIs. <strong>SMM Turk One</strong> = SmmFollows · <strong>SMM Turk Pro</strong> = SMMFA (IDs 8000000+).
  </p>

  <?php foreach ($providerStatus as $slug => $st): ?>
  <div style="padding:14px 16px;border:1px solid var(--border);border-radius:12px;margin-bottom:12px;background:var(--bg);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
      <div>
        <strong><?= h($st['name']) ?></strong>
        <span class="badge <?= $st['enabled'] && $st['balance'] ? 'badge-green' : 'badge-gray' ?>" style="margin-left:8px;">
          <?= $st['enabled'] ? ($st['balance'] ? 'Connected' : 'Not ready') : 'Off' ?>
        </span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">
          <?= (int)$st['services'] ?> services in panel
          <?php if ($st['balance']): ?> · Balance: <strong><?= h($st['balance']) ?></strong><?php endif; ?>
          <?php if ($st['error'] && !$st['balance']): ?> · <?= h($st['error']) ?><?php endif; ?>
        </div>
      </div>
      <?php if ($st['enabled']): ?>
      <form method="POST" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="provider" value="<?= h($slug) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Sync <?= h($st['name']) ?></button>
        <button type="submit" name="dedupe_only" value="1" class="btn btn-sm" title="Deactivate duplicate names / upstream IDs">Dedupe <?= h(ProviderRegistry::brandLabel($slug)) ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <form method="POST" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="provider" value="all">
    <button type="submit" class="btn btn-primary">🔄 Sync All Providers</button>
    <button type="submit" name="dedupe_only" value="1" class="btn">🧹 Dedupe All Catalogs</button>
  </form>
  <p style="margin-top:14px;font-size:12px;color:var(--text-muted);line-height:1.6;">
    <strong>Dedupe</strong> keeps the lowest service ID per duplicate name (within SMM Turk One / Pro) and deactivates the rest. Safe for orders — inactive services are hidden from customers.
    <br>
    <a href="<?= h(path('admin/admin-settings.php')) ?>">Edit API keys →</a>
    · <a href="<?= h(path('admin/admin-services.php')) ?>">View services →</a>
  </p>
</div>
<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
