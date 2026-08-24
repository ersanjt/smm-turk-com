<?php
require_once __DIR__ . '/app/init.php';
if (!$auth->isLoggedIn()) {
    $returnPath = '/dashboard';
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query !== '') {
        $returnPath .= '?' . $query;
    }
    header('Location: ' . url('login.php') . '?next=' . urlencode($returnPath), true, 302);
    exit;
}

$pageTitle = 'New Order';
$db = Database::getInstance();
$om = new OrderManager();
$revenue = new RevenueEngine();
$userId = (int) $auth->getUserId();
$userBalance = (float)($db->fetch("SELECT balance FROM users WHERE id = ?", [$userId])['balance'] ?? 0);
$vip = $revenue->vipTier($userId);
$promo = $revenue->promoBanner();
$depositBonusPct = (float) ($db->getSetting('deposit_bonus_percent') ?: 0);
$hasChildPanel = (int) ($db->fetch("SELECT COUNT(*) c FROM child_panels WHERE user_id = ? AND status != 'cancelled'", [$userId])['c'] ?? 0) > 0;

$message = '';
$error   = '';

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link      = trim($_POST['link'] ?? '');
    $quantity  = (int)($_POST['quantity'] ?? 0);
    $coupon    = trim($_POST['coupon_code'] ?? '');

    if (!$serviceId || !$link || !$quantity) {
        $error = 'Please fill in all required fields.';
    } else {
        $link = normalize_order_link($link);
        if ($link === '') {
            $error = 'Please enter a valid link (e.g. https://instagram.com/username).';
        } else {
        $extra = $coupon !== '' ? ['coupon' => $coupon] : [];
        $result = $om->placeOrder($userId, $serviceId, $link, $quantity, $extra);
        if ($result['success']) {
            flash('success', "✅ Order #{$result['order_id']} placed! Charged: \${$result['charge']}");
            header('Location: ' . url('orders.php'));
            exit;
        } else {
            $error = $result['error'];
        }
        }
    }
}

// Load categories and services (dedupe by trimmed category to avoid duplicate filter tags)
require_once __DIR__ . '/app/ProviderRegistry.php';

$tier = strtolower(trim($_GET['tier'] ?? ''));
if (!in_array($tier, ['', 'one', 'pro'], true)) {
    $tier = '';
}
$providerFilter = ProviderRegistry::providerFromTier($tier);
[$providerSql, $providerParams] = ProviderRegistry::providerFilter($providerFilter);
$proCatalogReady = ProviderRegistry::isEnabled(ProviderRegistry::SMMFA) && ProviderRegistry::api(ProviderRegistry::SMMFA) !== null;
$catParam = isset($_GET['cat']) ? trim((string)$_GET['cat']) : null;
$platform = trim($_GET['platform'] ?? '');
$preselectServiceId = isset($_GET['service']) ? (int)$_GET['service'] : 0;
$searchQ = trim($_GET['q'] ?? '');

$platformSql = '';
$platformParams = [];
if ($platform !== '') {
    $platformSql = ' AND category LIKE ?';
    $platformParams = ['%' . $platform . '%'];
}

$catSql = "SELECT DISTINCT category FROM services WHERE status='active'" . $providerSql . $platformSql . ' ORDER BY category';
$catParams = array_merge($providerParams, $platformParams);
$categoriesRaw = $db->fetchAll($catSql, $catParams);
$seen = [];
$categories = [];
foreach ($categoriesRaw as $row) {
    $cat = trim($row['category'] ?? '');
    if ($cat !== '' && !isset($seen[$cat])) {
        $seen[$cat] = true;
        $categories[] = ['category' => $cat];
    }
}

$countSql = "SELECT TRIM(COALESCE(category,'')) AS category, COUNT(*) AS cnt FROM services WHERE status='active'" . $providerSql . $platformSql . " GROUP BY TRIM(COALESCE(category,''))";
$countParams = array_merge($providerParams, $platformParams);
$categoryCounts = $db->fetchAll($countSql, $countParams);
$countByCategory = [];
foreach ($categoryCounts as $r) {
    $countByCategory[trim($r['category'] ?? '')] = (int) $r['cnt'];
}

$totalSql = "SELECT COUNT(*) c FROM services WHERE status='active'" . $providerSql . $platformSql;
$totalParams = array_merge($providerParams, $platformParams);
$totalServicesCount = (int) $db->fetch($totalSql, $totalParams)['c'];

if ($preselectServiceId) {
    $preselect = $db->fetch("SELECT category FROM services WHERE service_id=? AND status='active'", [$preselectServiceId]);
    if ($preselect && ($catParam === null || trim($preselect['category'] ?? '') !== $catParam)) {
        $catParam = trim($preselect['category'] ?? '');
    }
}
$selectedCat = ($catParam !== null && $catParam !== '') ? $catParam : '';
if ($selectedCat !== '' && !isset($countByCategory[$selectedCat])) {
    $selectedCat = '';
}

$showAllLimitHint = false;
$tierLoadLimit = 1500;
$allLoadLimit = 1500;

$svcProviderClause = $providerSql;
$svcProviderParam = $providerParams;

if ($searchQ !== '') {
    $like = '%' . $searchQ . '%';
    if ($selectedCat !== '') {
        $services = $db->fetchAll(
            "SELECT * FROM services WHERE status='active' AND TRIM(COALESCE(category,''))=? AND (name LIKE ? OR CAST(service_id AS CHAR) LIKE ?)" . $svcProviderClause . " ORDER BY service_id LIMIT 500",
            array_merge([$selectedCat, $like, $like], $svcProviderParam)
        );
    } else {
        $sql = "SELECT * FROM services WHERE status='active' AND (name LIKE ? OR CAST(service_id AS CHAR) LIKE ?)";
        $params = [$like, $like];
        $sql .= $platformSql;
        $params = array_merge($params, $platformParams);
        $sql .= $providerSql;
        $params = array_merge($params, $providerParams);
        $services = $db->fetchAll($sql . " ORDER BY service_id LIMIT 500", $params);
    }
} elseif ($selectedCat !== '') {
    $services = $db->fetchAll(
        "SELECT * FROM services WHERE status='active' AND TRIM(COALESCE(category,''))=?" . $svcProviderClause . " ORDER BY service_id",
        array_merge([$selectedCat], $svcProviderParam)
    );
} elseif ($platform !== '') {
    $services = $db->fetchAll(
        "SELECT * FROM services WHERE status='active'" . $platformSql . $svcProviderClause . " ORDER BY service_id LIMIT 500",
        array_merge($platformParams, $svcProviderParam)
    );
    $showAllLimitHint = count($services) >= 500;
} else {
    $limit = $tier !== '' ? $tierLoadLimit : $allLoadLimit;
    $services = $db->fetchAll(
        "SELECT * FROM services WHERE status='active'" . $platformSql . $svcProviderClause . " ORDER BY service_id LIMIT " . (int) $limit,
        array_merge($platformParams, $svcProviderParam)
    );
    if ($totalServicesCount > count($services)) {
        $showAllLimitHint = true;
    }
}
$hasServices = count($services) > 0;
$showEmptyFilterWarning = !$hasServices && !$searchQ;
$featuredServices = $revenue->featuredServices(8, $providerFilter ?: null);

require_once __DIR__ . '/app/PlatformIcons.php';
require_once __DIR__ . '/layouts/header.php';
?>
<link rel="stylesheet" href="<?= h(asset_url('assets/css/order.css')) ?>">

<link rel="stylesheet" href="<?= h(asset_url('assets/css/order.css')) ?>">

<?php require_once __DIR__ . '/partials/onboarding-strip.php'; ?>

<div class="panel-promo-banner" data-reveal>
  <div>
    <strong><?= h($promo['title']) ?></strong>
    <p><?= h($promo['text']) ?></p>
    <?php if ($depositBonusPct > 0): ?>
    <p style="font-size:12px;margin-top:6px;opacity:.9;">🎁 First deposit bonus: <strong><?= number_format($depositBonusPct, 0) ?>%</strong> extra balance</p>
    <?php endif; ?>
  </div>
  <a href="<?= h($promo['cta_url']) ?>" class="btn-promo"><?= h($promo['cta_label']) ?></a>
</div>

<?php if (!$hasChildPanel): ?>
<div class="panel-promo-banner" style="margin-top:10px;background:linear-gradient(135deg,#1a1a2e,#16213e);" data-reveal>
  <div>
    <strong>🚀 Start your own SMM panel</strong>
    <p>Resell services under your brand — earn markup on every customer order.</p>
  </div>
  <a href="<?= h(path('earn.php')) ?>" class="btn-promo">Earn Money →</a>
</div>
<?php endif; ?>

<?php if (!empty($featuredServices)): ?>
<div class="card" style="margin-bottom:16px;" data-reveal>
  <div class="card-title">⭐ Popular services</div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;">
    <?php foreach ($featuredServices as $fs):
        $retail = $revenue->retailRatePerThousand($fs, $userId);
        $qs = http_build_query(array_filter(['service' => (int)$fs['service_id'], 'tier' => $tier ?: null]));
    ?>
    <a href="<?= h(path('dashboard.php') . ($qs ? '?' . $qs : '')) ?>" class="order-cat-pill" style="text-decoration:none;">
      <span class="order-cat-name"><?= h(mb_substr($fs['name'], 0, 40)) ?></span>
      <span class="order-cat-count">$<?= number_format($retail, 3) ?>/1k</span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <div class="page-title-row">
      <?= iconBox('plus', 'primary', 22) ?>
      <div>
        <h1 class="page-title">New Order</h1>
        <p class="page-subtitle"><?= number_format($totalServicesCount) ?> services<?= $tier === 'one' ? ' · ' . ProviderRegistry::BRAND_ONE : ($tier === 'pro' ? ' · ' . ProviderRegistry::BRAND_PRO : '') ?>
          <?php if ($vip['discount_percent'] > 0): ?> · <span class="badge badge-blue"><?= h($vip['name']) ?> −<?= number_format($vip['discount_percent'], 0) ?>%</span><?php endif; ?>
        </p>
      </div>
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary btn-sm"><?= icon('wallet', 16) ?> Add Funds</a>
  </div>
</div>
<nav class="order-tabs" aria-label="Order type">
  <a class="order-tab active" href="<?= h(path('dashboard.php')) ?>">New Order</a>
  <a class="order-tab" href="<?= h(path('mass-order.php')) ?>">Mass Order</a>
  <a class="order-tab" href="<?= h(path('services.php')) ?>">Services</a>
</nav>

<!-- Search & filter toolbar -->
<div class="order-toolbar">
  <form method="GET" class="order-toolbar-search" role="search" aria-label="Search services">
    <?php if ($selectedCat): ?><input type="hidden" name="cat" value="<?= h($selectedCat) ?>"><?php endif; ?>
    <?php if ($platform): ?><input type="hidden" name="platform" value="<?= h($platform) ?>"><?php endif; ?>
    <?php if ($tier): ?><input type="hidden" name="tier" value="<?= h($tier) ?>"><?php endif; ?>
    <label for="search-service" class="sr-only">Search services</label>
    <input type="search" id="search-service" name="q" value="<?= h($searchQ) ?>" class="form-control" placeholder="Search services…" autocomplete="off">
    <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> Search</button>
  </form>
  <label class="checkbox-label" title="Show only services added in the last 7 days">
    <input type="checkbox" id="newOnlyCheck" onchange="filterNew()"> New added <span class="checkbox-hint">(7 days)</span>
  </label>
</div>

<?php
$tierStripParams = array_filter(['platform' => $platform ?: null, 'q' => $searchQ ?: null]);
$tierExtra = array_filter(['cat' => $selectedCat ?: null, 'tier' => $tier ?: null]);
echo ProviderRegistry::serviceTierStrip('dashboard.php', $tier, $searchQ, $tierStripParams);
echo platformFilterStrip('dashboard.php', $platform, $searchQ, $tierExtra);
?>

<!-- Category quick filter: labeled pills (name + count), scrollable -->
<p class="order-section-label"><?= $tier === 'one' ? ProviderRegistry::BRAND_ONE : ($tier === 'pro' ? ProviderRegistry::BRAND_PRO : 'Category') ?></p>
<div class="order-cat-pills">
  <a class="order-cat-pill <?= $selectedCat === '' ? 'active' : '' ?>" href="<?= h(path('dashboard.php')) ?><?= ($q = http_build_query(array_filter(['q' => $searchQ ?: null, 'platform' => $platform ?: null, 'tier' => $tier ?: null]))) ? '?' . $q : '' ?>" title="All — <?= (int)$totalServicesCount ?> services">
    <span class="order-cat-name">All</span>
    <span class="order-cat-count" aria-hidden="true"><?= (int)$totalServicesCount ?></span>
  </a>
  <?php foreach ($categories as $cat):
    $pKey = platformKeyFromCategory($cat['category']);
    $isActive = $selectedCat !== '' && $cat['category'] === $selectedCat;
    $cnt = $countByCategory[$cat['category']] ?? 0;
  ?>
  <a class="order-cat-pill <?= $isActive ? 'active' : '' ?>" href="<?= h(path('dashboard.php')) ?>?<?= h(http_build_query(array_filter(['cat' => $cat['category'], 'q' => $searchQ ?: null, 'platform' => $platform ?: null, 'tier' => $tier ?: null]))) ?>" title="<?= h($cat['category']) ?> — <?= $cnt ?> services">
    <?php $catIcon = platformSvgBrand($pKey, 18); if ($catIcon !== '') echo $catIcon; ?>
    <span class="order-cat-name"><?= h(ProviderRegistry::displayCategoryName($cat['category'], $tier)) ?></span>
    <span class="order-cat-count" aria-hidden="true"><?= $cnt ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($error): ?>
<div class="alert alert-error">
  <?= h($error) ?>
  <?php if (stripos($error, 'Insufficient balance') !== false): ?>
  <div class="alert-funds-link"><a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary btn-sm">Add Funds →</a></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($userBalance <= 0): ?>
<div class="order-onboard">
  <h2>Get started in 3 steps</h2>
  <ol>
    <li><strong>Add Funds (crypto)</strong> — send BTC, ETH, USDT, or other supported coins; we email you when credited</li>
    <li><strong>Pick a service</strong> — choose category and service below</li>
    <li><strong>Submit order</strong> — paste your link and quantity</li>
  </ol>
  <a href="<?= h(path('add-funds.php')) ?>" class="btn btn-primary">Step 1: Add Funds</a>
</div>
<?php endif; ?>

<?php if ($tier === 'pro' && !$proCatalogReady && $totalServicesCount === 0): ?>
<div class="alert alert-info">
  <?php if ($auth->isAdmin()): ?>
  <strong>SMM Turk Pro</strong> is not live yet. Enable <em>SMMFA provider</em>, add the API key in Settings, then run <a href="<?= h(path('admin/admin-sync.php')) ?>">Sync</a>.
  <?php else: ?>
  <strong>SMM Turk Pro</strong> catalog is coming soon. Use <strong>SMM Turk One</strong> for now or <a href="<?= h(path('tickets.php')) ?>">contact support</a>.
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($showAllLimitHint && !$searchQ): ?>
<div class="alert alert-info" style="margin-bottom:12px;">
  Showing <?= number_format(count($services)) ?> of <?= number_format($totalServicesCount) ?> services<?= $tier === 'one' ? ' (' . ProviderRegistry::BRAND_ONE . ')' : ($tier === 'pro' ? ' (' . ProviderRegistry::BRAND_PRO . ')' : '') ?>.
  Pick a <strong>category</strong> above to narrow the list, or use search.
</div>
<?php endif; ?>
<?php if ($showEmptyFilterWarning): ?>
<div class="alert alert-warning">No services match your filter. Try another category or <a href="<?= h(path('dashboard.php')) ?>">clear filters</a>.</div>
<?php endif; ?>

<div class="order-grid">
  <div class="card">
    <div class="card-title"><?= icon('plus', 18) ?> New Order</div>
    <form method="POST" action="<?= h(path('dashboard.php') . (($orderQs = http_build_query(array_filter(['cat' => $selectedCat ?: null, 'platform' => $platform ?: null, 'tier' => $tier ?: null, 'q' => $searchQ ?: null]))) ? '?' . $orderQs : '')) ?>" id="order-form" data-preselect-service="<?= (int)$preselectServiceId ?>" data-vip-discount="<?= number_format((float)$vip['discount_percent'], 2, '.', '') ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

      <div class="form-group">
        <label class="form-label" for="service-filter">Find service</label>
        <input type="search" id="service-filter" class="form-control service-picker-filter" placeholder="Type ID or service name…" autocomplete="off" aria-controls="service-select" <?= !$hasServices ? 'disabled' : '' ?>>
        <div class="service-picker-meta" id="service-filter-meta"><?= count($services) ?> service<?= count($services) === 1 ? '' : 's' ?> in list</div>
        <label class="form-label" for="service-select">Service</label>
        <select name="service_id" id="service-select" class="form-control" onchange="updateDesc()" required aria-required="true" <?= !$hasServices ? 'disabled' : '' ?> size="8">
          <option value="">— Select a service —</option>
          <?php foreach ($services as $s):
            $updatedAt = isset($s['updated_at']) ? strtotime($s['updated_at']) : 0;
          ?>
          <option value="<?= $s['service_id'] ?>"
            data-rate="<?= $s['rate'] ?>"
            data-min="<?= $s['min'] ?>"
            data-max="<?= $s['max'] ?>"
            data-refill="<?= $s['refill'] ? 'Yes' : 'No' ?>"
            data-markup="<?= $s['markup'] ?>"
            data-updated="<?= $updatedAt ?>"
            data-category="<?= h($s['category']) ?>"
            data-tier="<?= h(ProviderRegistry::tierFromProvider(ProviderRegistry::providerForService($s))) ?>">
            <?php if ($tier === ''): ?>
            [<?= ProviderRegistry::tierFromProvider(ProviderRegistry::providerForService($s)) === 'pro' ? 'Pro' : 'One' ?>]
            <?php endif; ?>
            ID-<?= $s['service_id'] ?> | <?= h(mb_substr($s['name'], 0, 90)) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="order-link">Link</label>
        <input type="text" name="link" id="order-link" class="form-control" placeholder="instagram.com/username or full URL" required aria-required="true">
      </div>

      <div class="form-group">
        <label class="form-label" for="order-qty">Quantity</label>
        <input type="number" name="quantity" id="order-qty" class="form-control"
               placeholder="Enter quantity" oninput="calcPrice()" required min="1" aria-describedby="qty-hint">
        <div style="text-align:right;font-size:11px;color:var(--text-muted);margin-top:4px;" id="qty-hint" aria-live="polite">—</div>
      </div>

      <div class="price-box">
        <div>
          <div class="lbl">Charge</div>
          <div class="amt" id="price-display">$0.0000</div>
        </div>
        <div class="price-box-right">
          <div class="lbl">Rate / 1000</div>
          <div id="rate-display">—</div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="coupon-code">Coupon code <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
        <input type="text" name="coupon_code" id="coupon-code" class="form-control" placeholder="SAVE10" autocomplete="off" style="text-transform:uppercase;">
      </div>

      <button type="submit" class="btn btn-primary btn-block" <?= !$hasServices ? 'disabled' : '' ?>>Submit</button>
    </form>
  </div>

  <div class="card" id="desc-panel">
    <div class="card-title"><?= icon('chart', 18) ?> Description</div>
    <div id="desc-content">Select a service to see details.</div>
  </div>
</div>

<script src="<?= h(asset_url('assets/js/order.js')) ?>" defer></script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
