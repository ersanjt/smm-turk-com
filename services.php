<?php
// services.php – Services listing (modern card-based layout)
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/ProviderRegistry.php';
$auth->requireLogin();
$pageTitle = 'Services';
$db = Database::getInstance();

$search   = trim($_GET['q'] ?? '');
$cat      = $_GET['cat'] ?? '';
$platform = trim($_GET['platform'] ?? '');
$tier     = strtolower(trim($_GET['tier'] ?? ''));
if (!in_array($tier, ['', 'one', 'pro'], true)) {
    $tier = '';
}
$providerFilter = ProviderRegistry::providerFromTier($tier);
[$providerSql, $providerParams] = ProviderRegistry::providerFilter($providerFilter);
$proCatalogReady = ProviderRegistry::isEnabled(ProviderRegistry::SMMFA) && ProviderRegistry::api(ProviderRegistry::SMMFA) !== null;
$sort     = $_GET['sort'] ?? 'id';
$dir      = strtolower($_GET['dir'] ?? 'asc');
if (!in_array($dir, ['asc', 'desc'])) $dir = 'asc';

$cat = $cat !== '' ? trim($cat) : '';
$where  = "WHERE status='active'" . $providerSql;
$params = $providerParams;
if ($search)   { $where .= " AND name LIKE ?"; $params[] = "%$search%"; }
if ($platform) { $where .= " AND category LIKE ?"; $params[] = "%$platform%"; }
if ($cat)      { $where .= " AND TRIM(COALESCE(category,'')) = ?"; $params[] = $cat; }

$orderBy = 'service_id ASC';
if ($sort === 'rate') $orderBy = 'rate ' . ($dir === 'desc' ? 'DESC' : 'ASC');
if ($sort === 'min')  $orderBy = 'min ' . ($dir === 'desc' ? 'DESC' : 'ASC');
if ($sort === 'max')  $orderBy = 'max ' . ($dir === 'desc' ? 'DESC' : 'ASC');
if ($sort === 'id')   $orderBy = 'service_id ' . ($dir === 'desc' ? 'DESC' : 'ASC');

$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 48;
$totalServices = (int)$db->fetch("SELECT COUNT(*) c FROM services $where", $params)['c'];
$totalPages = max(1, (int)ceil($totalServices / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$services = $db->fetchAll("SELECT * FROM services $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset", $params);
$listFrom = $totalServices > 0 ? $offset + 1 : 0;
$listTo = min($offset + count($services), $totalServices);

$categoriesRaw = $db->fetchAll(
    "SELECT DISTINCT category FROM services WHERE status='active'" . $providerSql . " ORDER BY category",
    $providerParams
);
$seen = [];
$categories = [];
foreach ($categoriesRaw as $row) {
    $c = trim($row['category'] ?? '');
    if ($c !== '' && !isset($seen[$c])) {
        if ($platform === '' || stripos($c, $platform) !== false) {
            $seen[$c] = true;
            $categories[] = ['category' => $c];
        }
    }
}

$platformIcons = [
    'YouTube' => '▶', 'Instagram' => '📷', 'TikTok' => '🎵', 'Twitter' => '𝕏', 'Facebook' => 'f', 'LinkedIn' => 'in',
    'Telegram' => '✈', 'Spotify' => '♫', 'SoundCloud' => '🔊', 'Twitch' => '🎮', 'Discord' => '💬', 'Tumblr' => 't',
    'Reddit' => '🔴', 'Pinterest' => 'P', 'Vimeo' => 'V', 'VK' => 'VK', 'Dailymotion' => 'D', 'Apple Music' => '🎵',
    'Website Traffic' => '🌐', 'Mobile' => '📱', 'Kwai' => 'K', 'Deezer' => 'D', 'Clubhouse' => 'C', 'Shazam' => 'S',
    'Rumble' => 'R', 'Kick' => 'K', 'Medium' => 'M', 'BlueSky' => '🦋', 'Binance' => 'B', 'Default' => '+',
];
function platformIcon($category, $map) {
    foreach ($map as $key => $icon) {
        if (stripos($category, $key) !== false) return $icon;
    }
    return mb_substr($category, 0, 1);
}

$newCutoff = date('Y-m-d H:i:s', time() - 7*24*3600);
$sortLinks = [];
$q = array_filter(['q' => $search ?: null, 'cat' => $cat ?: null, 'platform' => $platform ?: null], function ($v) { return $v !== null && $v !== ''; });
foreach (['id' => 'ID', 'rate' => 'Price', 'min' => 'Min', 'max' => 'Max'] as $col => $label) {
    $q2 = $q;
    $q2['sort'] = $col;
    $q2['dir'] = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $sortLinks[$col] = ['url' => path('services.php') . '?' . http_build_query($q2), 'label' => $label];
}

function servicesPageUrl(array $extra = []): string {
    global $search, $cat, $platform, $sort, $dir, $tier;
    $q = [];
    if ($search !== '') {
        $q['q'] = $search;
    }
    if ($cat !== '') {
        $q['cat'] = $cat;
    }
    if ($platform !== '') {
        $q['platform'] = $platform;
    }
    if ($tier !== '') {
        $q['tier'] = $tier;
    }
    if ($sort !== 'id') {
        $q['sort'] = $sort;
    }
    if ($dir !== 'asc') {
        $q['dir'] = $dir;
    }
    $q = array_merge($q, $extra);
    return path('services.php') . ($q ? '?' . http_build_query($q) : '');
}

require_once __DIR__ . '/app/PlatformIcons.php';

// Platform logos filter — shared catalog with brand colors
$platformList = platformFilterList();
require_once __DIR__ . '/layouts/header.php';
?>

<style>
/* ---- Services page: motion + layout ---- */
:root {
  --svc-ease: cubic-bezier(0.34, 1.56, 0.64, 1);
  --svc-duration: 0.35s;
}
@keyframes svcHeroPulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.85; transform: scale(1.08); }
}
@keyframes svcCardReveal {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
.svc-page-hero {
  background: linear-gradient(135deg, rgba(227,10,23,.08) 0%, rgba(227,10,23,.02) 50%, rgba(255,255,255,.6) 100%);
  border: 1px solid rgba(227,10,23,.12);
  border-radius: 20px;
  padding: 28px 32px;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  transition: box-shadow var(--svc-duration) ease, transform var(--svc-duration) var(--svc-ease);
}
.svc-page-hero:hover {
  box-shadow: 0 12px 40px rgba(227,10,23,.08);
  transform: translateY(-2px);
}
.svc-page-hero::before {
  content: '';
  position: absolute;
  top: -60px;
  right: -60px;
  width: 180px;
  height: 180px;
  background: radial-gradient(circle, rgba(227,10,23,.15) 0%, transparent 70%);
  border-radius: 50%;
  pointer-events: none;
  animation: svcHeroPulse 8s ease-in-out infinite;
}
.svc-hero-title {
  font-family: 'Syne', sans-serif;
  font-size: clamp(1.5rem, 4vw, 1.85rem);
  font-weight: 800;
  color: var(--text);
  letter-spacing: -0.03em;
  margin-bottom: 6px;
}
.svc-hero-desc {
  font-size: 14px;
  color: var(--text-muted);
  max-width: 520px;
  line-height: 1.55;
}

/* Toolbar: search + sort + filters */
.svc-toolbar-wrap {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  margin-bottom: 20px;
}
.svc-search-form {
  display: flex;
  gap: 10px;
  align-items: center;
  flex: 1;
  min-width: 0;
  max-width: 420px;
}
.svc-search-form .form-control {
  flex: 1;
  min-width: 140px;
  background: var(--white);
}
.svc-sort-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.svc-sort-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--text-muted);
}
.svc-sort-links {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}
.svc-sort-links a {
  display: inline-flex;
  align-items: center;
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  text-decoration: none;
  background: var(--white);
  border: 1px solid var(--border);
  transition: all 0.2s ease;
}
.svc-sort-links a:hover {
  border-color: var(--primary);
  color: var(--primary);
  background: rgba(227,10,23,.06);
}
.svc-sort-links a.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
}

/* Platform logos row */
.svc-platform-wrap {
  margin-bottom: 20px;
}
.svc-platform-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--text-muted);
  margin-bottom: 10px;
}
.svc-platform-scroll {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}
.svc-platform-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: 14px;
  background: var(--white);
  border: 1.5px solid var(--border);
  color: var(--text-muted);
  text-decoration: none;
  transition: transform var(--svc-duration) var(--svc-ease), box-shadow var(--svc-duration) ease, border-color var(--svc-duration) ease, background var(--svc-duration) ease, color var(--svc-duration) ease;
  flex-shrink: 0;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.svc-platform-btn svg {
  width: 24px;
  height: 24px;
  display: block;
}
.svc-platform-btn .svc-platform-fallback {
  font-size: 18px;
  font-weight: 700;
  line-height: 1;
}
.svc-platform-btn.svc-platform-all {
  font-size: 12px;
  font-weight: 700;
  width: auto;
  padding-left: 14px;
  padding-right: 14px;
}
.svc-platform-btn:hover {
  border-color: var(--primary);
  color: var(--primary);
  background: rgba(227,10,23,.06);
  transform: translateY(-3px) scale(1.05);
  box-shadow: 0 10px 28px rgba(227,10,23,.15);
}
.svc-platform-btn.active {
  background: linear-gradient(145deg, var(--primary), var(--primary-dark));
  border-color: var(--primary);
  color: #fff;
  box-shadow: 0 4px 16px rgba(227,10,23,.3);
}
.svc-platform-btn.active:hover {
  color: #fff;
  background: linear-gradient(145deg, var(--primary), var(--primary-dark));
}

/* Category pills (horizontal scroll) */
.svc-cats-wrap {
  margin-bottom: 24px;
}
.svc-cats-label {
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--text-muted);
  margin-bottom: 10px;
}
.svc-cats-scroll {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  padding: 6px 0 12px;
  -webkit-overflow-scrolling: touch;
  scrollbar-height: 6px;
}
.svc-cats-scroll::-webkit-scrollbar { height: 6px; }
.svc-cat-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 14px;
  background: var(--white);
  border: 1.5px solid var(--border);
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  text-decoration: none;
  color: var(--text-muted);
  transition: transform var(--svc-duration) var(--svc-ease), box-shadow var(--svc-duration) ease, border-color var(--svc-duration) ease, background var(--svc-duration) ease, color var(--svc-duration) ease;
  flex-shrink: 0;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.svc-cat-pill:hover {
  border-color: var(--primary);
  color: var(--primary);
  background: rgba(227,10,23,.06);
  transform: translateY(-4px) scale(1.02);
  box-shadow: 0 10px 28px rgba(227,10,23,.15);
}
.svc-cat-pill.active {
  background: linear-gradient(145deg, var(--primary), var(--primary-dark));
  border-color: var(--primary);
  color: #fff;
  box-shadow: 0 4px 16px rgba(227,10,23,.3);
}
.svc-cat-pill .pill-icon {
  font-size: 16px;
  line-height: 1;
}

/* Results count */
.svc-results-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 18px;
}
.svc-results-count {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-muted);
}
.svc-pagination { margin: 4px 0 28px; }
.ticket-pagination { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }
.ticket-pagination a, .ticket-pagination span { padding: 8px 14px; border-radius: 10px; font-size: 13px; text-decoration: none; color: var(--text); border: 1.5px solid var(--border); transition: all .2s; }
.ticket-pagination a:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
.ticket-pagination .current { background: var(--primary); color: #fff; border-color: var(--primary); }
.svc-results-count strong { color: var(--text); }

/* Service cards grid */
.svc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 18px;
}
@media (max-width: 380px) {
  .svc-grid { grid-template-columns: 1fr; }
}

.svc-card {
  background: var(--white);
  border-radius: 16px;
  border: 1px solid var(--border);
  padding: 20px;
  transition: transform var(--svc-duration) var(--svc-ease), box-shadow var(--svc-duration) ease, border-color var(--svc-duration) ease;
  box-shadow: 0 2px 12px rgba(0,0,0,.04);
  display: flex;
  flex-direction: column;
  gap: 14px;
  position: relative;
  overflow: hidden;
  animation: svcCardReveal 0.5s var(--svc-ease) both;
}
.svc-card:nth-child(1){animation-delay:0.02s}.svc-card:nth-child(2){animation-delay:0.05s}.svc-card:nth-child(3){animation-delay:0.08s}.svc-card:nth-child(4){animation-delay:0.11s}.svc-card:nth-child(5){animation-delay:0.14s}.svc-card:nth-child(6){animation-delay:0.17s}.svc-card:nth-child(7){animation-delay:0.2s}.svc-card:nth-child(8){animation-delay:0.23s}.svc-card:nth-child(9){animation-delay:0.26s}.svc-card:nth-child(n+10){animation-delay:0.29s}
.svc-card::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: linear-gradient(90deg, var(--primary), var(--primary-light));
  opacity: 0;
  transition: opacity var(--svc-duration) ease;
}
.svc-card:hover {
  border-color: rgba(227,10,23,.3);
  box-shadow: 0 20px 48px rgba(227,10,23,.14), 0 0 0 1px rgba(227,10,23,.08);
  transform: translateY(-6px);
}
.svc-card:hover::after { opacity: 1; }
.svc-card-cta .btn {
  transition: transform var(--svc-duration) var(--svc-ease), box-shadow var(--svc-duration) ease;
}
.svc-card:hover .svc-card-cta .btn {
  transform: scale(1.02);
  box-shadow: 0 6px 20px rgba(227,10,23,.3);
}

.svc-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.svc-card-header-main {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  flex: 1 1 auto;
}
.svc-card-header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.svc-card-platform-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--bg);
  border: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--text-muted);
}
.svc-card-platform-icon svg {
  width: 24px;
  height: 24px;
}
.svc-card-platform-icon .svc-platform-fallback {
  font-size: 18px;
  font-weight: 700;
  line-height: 1;
}
.svc-card-id-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 10px;
  min-height: 28px;
  border-radius: 9px;
  background: linear-gradient(145deg, rgba(227,10,23,.12), rgba(227,10,23,.06));
  border: 1px solid rgba(227,10,23,.2);
  font-family: 'Syne', sans-serif;
  font-size: 12px;
  font-weight: 800;
  color: var(--primary);
  flex-shrink: 0;
  white-space: nowrap;
}
.svc-card-tier {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  padding: 3px 9px;
  border-radius: 7px;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  line-height: 1.4;
  white-space: nowrap;
}
.svc-card-tier-one {
  background: rgba(59,130,246,.12);
  border: 1px solid rgba(59,130,246,.28);
  color: #2563eb;
}
.svc-card-tier-pro {
  background: rgba(234,179,8,.14);
  border: 1px solid rgba(234,179,8,.32);
  color: #b45309;
}
.svc-card-cat {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  line-height: 1.4;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.svc-card-refill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  border-radius: 10px;
  background: rgba(34,197,94,.12);
  border: 1px solid rgba(34,197,94,.3);
  font-size: 11px;
  font-weight: 700;
  color: #16a34a;
  flex-shrink: 0;
}

.svc-card-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--text);
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.9em;
}

.svc-card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.svc-card-rate {
  font-family: 'Syne', sans-serif;
  font-size: 18px;
  font-weight: 800;
  color: var(--primary);
}
.svc-card-rate span { font-size: 12px; font-weight: 600; opacity: .85; }
.svc-card-minmax {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.svc-card-minmax span {
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
}
.svc-card-minmax .min {
  background: rgba(227,10,23,.08);
  border: 1px solid rgba(227,10,23,.2);
}
.svc-card-minmax .max {
  background: rgba(99,102,241,.08);
  border: 1px solid rgba(99,102,241,.2);
}

.svc-card-cta {
  margin-top: auto;
  padding-top: 4px;
}
.svc-card-cta .btn {
  width: 100%;
  justify-content: center;
  padding: 12px 16px;
  font-size: 13px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.svc-card-header-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
  flex-shrink: 0;
}
.svc-card-new {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border-radius: 7px;
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  line-height: 1.4;
  white-space: nowrap;
  box-shadow: 0 2px 10px rgba(227,10,23,.35);
}

/* List view (optional compact) */
.svc-list-view .svc-grid {
  grid-template-columns: 1fr;
}
.svc-list-view .svc-card {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
  padding: 16px 20px;
}
.svc-list-view .svc-card-header { flex: 0 0 auto; max-width: 300px; }
.svc-list-view .svc-card-name {
  flex: 1 1 280px;
  min-height: 0;
  -webkit-line-clamp: 1;
}
.svc-list-view .svc-card-meta { flex: 0 0 auto; margin-left: auto; }
.svc-list-view .svc-card-cta { margin-top: 0; padding-top: 0; flex: 0 0 auto; }
.svc-list-view .svc-card-cta .btn { width: auto; }

/* View toggle */
.svc-view-toggle {
  display: flex;
  gap: 4px;
  border-radius: 12px;
  padding: 4px;
  background: var(--bg);
  border: 1px solid var(--border);
  width: fit-content;
}
.svc-view-toggle button {
  padding: 8px 14px;
  border: none;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  background: transparent;
  cursor: pointer;
  transition: all 0.2s;
}
.svc-view-toggle button:hover { color: var(--primary); }
.svc-view-toggle button.active {
  background: var(--white);
  color: var(--primary);
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

/* Empty state */
.svc-empty {
  text-align: center;
  padding: 56px 24px;
  background: var(--white);
  border-radius: 20px;
  border: 1px solid var(--border);
  border-style: dashed;
}
.svc-empty-icon {
  font-size: 48px;
  margin-bottom: 16px;
  opacity: 0.6;
}
.svc-empty-title {
  font-family: 'Syne', sans-serif;
  font-size: 18px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 8px;
}
.svc-empty-desc {
  font-size: 14px;
  color: var(--text-muted);
  margin-bottom: 20px;
  max-width: 320px;
  margin-left: auto;
  margin-right: auto;
}
.svc-empty .btn { min-width: 160px; }

@media (max-width: 768px) {
  .svc-page-hero { padding: 22px 20px; margin-bottom: 22px; }
  .svc-toolbar-wrap { flex-direction: column; align-items: stretch; }
  .svc-search-form { max-width: none; }
  .svc-sort-wrap { justify-content: flex-start; }
  .svc-sort-links { flex: 1; }
  .svc-sort-links a { padding: 10px 12px; min-height: 44px; }
  .svc-platform-scroll { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 8px; -webkit-overflow-scrolling: touch; }
  .svc-platform-btn { flex-shrink: 0; width: 44px; height: 44px; }
  .svc-cat-pill { padding: 10px 14px; font-size: 12px; }
  .svc-card { padding: 16px; }
  .svc-card-name { font-size: 14px; }
  .svc-card-rate { font-size: 16px; }
  .svc-results-bar { flex-direction: column; align-items: flex-start; }
  .svc-view-toggle button { min-height: 44px; padding: 10px 16px; }
}

@media (prefers-reduced-motion: reduce) {
  .svc-card, .svc-cat-pill, .svc-sort-links a, .svc-page-hero { transition: none; animation: none; }
  .svc-card:hover, .svc-page-hero:hover { transform: none; }
  .svc-cat-pill:hover { transform: none; }
  .svc-page-hero::before { animation: none; }
}

/* Dark mode */
body.theme-dark .svc-page-hero {
  background: linear-gradient(135deg, rgba(227,10,23,.16) 0%, rgba(227,10,23,.05) 45%, rgba(20,16,19,.95) 100%);
  border-color: rgba(227,10,23,.22);
}
body.theme-dark .svc-page-hero:hover {
  box-shadow: 0 12px 40px rgba(0,0,0,.35);
}
body.theme-dark .svc-card {
  box-shadow: 0 2px 16px rgba(0,0,0,.28);
}
body.theme-dark .svc-card:hover {
  box-shadow: 0 16px 40px rgba(0,0,0,.4), 0 0 0 1px rgba(227,10,23,.2);
}
body.theme-dark .svc-card-platform-icon {
  background: rgba(255,255,255,.04);
  border-color: rgba(255,255,255,.1);
}
body.theme-dark .svc-card-cat {
  color: var(--text-muted);
}
body.theme-dark .svc-card-tier-one {
  background: rgba(59,130,246,.18);
  border-color: rgba(59,130,246,.4);
  color: #60a5fa;
}
body.theme-dark .svc-card-tier-pro {
  background: rgba(234,179,8,.18);
  border-color: rgba(234,179,8,.4);
  color: #fbbf24;
}
body.theme-dark .svc-card-id-badge {
  background: rgba(227,10,23,.18);
  border-color: rgba(227,10,23,.35);
  color: var(--primary-light);
}
body.theme-dark .svc-card-refill {
  background: rgba(34,197,94,.16);
  border-color: rgba(34,197,94,.35);
  color: #4ade80;
}
body.theme-dark .svc-card-minmax .min {
  background: rgba(227,10,23,.14);
  border-color: rgba(227,10,23,.3);
  color: var(--text);
}
body.theme-dark .svc-card-minmax .max {
  background: rgba(99,102,241,.14);
  border-color: rgba(99,102,241,.3);
  color: var(--text);
}
body.theme-dark .svc-platform-btn,
body.theme-dark .svc-cat-pill,
body.theme-dark .svc-sort-links a {
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
body.theme-dark .svc-platform-btn:hover,
body.theme-dark .svc-cat-pill:hover,
body.theme-dark .svc-sort-links a:hover {
  background: rgba(227,10,23,.12);
}
body.theme-dark .svc-cat-pill.active,
body.theme-dark .svc-platform-btn.active {
  color: #fff;
}
body.theme-dark .ticket-pagination a,
body.theme-dark .ticket-pagination span {
  background: var(--white);
  border-color: rgba(255,255,255,.1);
  color: var(--text);
}
body.theme-dark .svc-empty {
  border-color: rgba(255,255,255,.12);
}
</style>

<!-- Hero -->
<section class="svc-page-hero" data-reveal>
  <h1 class="svc-hero-title">Services</h1>
  <p class="svc-hero-desc">Choose <strong>SMM Turk One</strong> or <strong>SMM Turk Pro</strong>, then filter by network and category. Buy from either catalog in one panel.</p>
</section>

<?php
$tierExtra = array_filter(['cat' => $cat ?: null, 'tier' => $tier ?: null]);
echo ProviderRegistry::serviceTierStrip('services.php', $tier, $search, $tierExtra);
?>

<?php if ($tier === 'pro' && !$proCatalogReady && $totalServices === 0): ?>
<div class="alert alert-info" style="margin-bottom:16px;">
  <?php if ($auth->isAdmin()): ?>
  <strong>SMM Turk Pro</strong> is not live yet. Enable <em>SMMFA provider</em>, add the API key in Settings, then run <a href="<?= h(path('admin/admin-sync.php')) ?>">Sync</a>.
  <?php else: ?>
  <strong>SMM Turk Pro</strong> catalog is coming soon. Use <strong>SMM Turk One</strong> for now or <a href="<?= h(path('tickets.php')) ?>">contact support</a>.
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Search + Sort -->
<div class="svc-toolbar-wrap" data-reveal>
  <form method="GET" class="svc-search-form" role="search">
    <?php if ($cat): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
    <?php if ($platform): ?><input type="hidden" name="platform" value="<?= h($platform) ?>"><?php endif; ?>
    <?php if ($tier): ?><input type="hidden" name="tier" value="<?= h($tier) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Search services…" aria-label="Search services">
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
  <div class="svc-sort-wrap">
    <span class="svc-sort-label">Sort</span>
    <div class="svc-sort-links">
      <?php foreach ($sortLinks as $col => $info): ?>
      <a href="<?= h($info['url']) ?>" class="<?= $sort === $col ? 'active' : '' ?>"><?= h($info['label']) ?> <?= $sort === $col ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Platform logos: real brand colors -->
<div class="svc-platform-wrap" data-reveal>
  <p class="svc-platform-label">Filter by network</p>
  <?= platformFilterStrip('services.php', $platform, $search, $tierExtra) ?>
</div>

<!-- Category pills -->
<div class="svc-cats-wrap" data-reveal>
  <p class="svc-cats-label"><?= $tier === 'one' ? ProviderRegistry::BRAND_ONE : ($tier === 'pro' ? ProviderRegistry::BRAND_PRO : 'Category') ?></p>
  <div class="svc-cats-scroll" role="tablist">
    <?php
      $allCatParams = array_filter(['platform' => $platform ?: null, 'q' => $search ?: null, 'tier' => $tier ?: null]);
      $allCatUrl = path('services.php') . ($allCatParams ? '?' . http_build_query($allCatParams) : '');
    ?>
    <a class="svc-cat-pill <?= !$cat ? 'active' : '' ?>" href="<?= h($allCatUrl) ?>">All</a>
    <?php foreach ($categories as $c):
      $pKey = platformKeyFromCategory($c['category']);
      $catUrl = path('services.php') . '?' . http_build_query(array_filter([
          'cat' => $c['category'],
          'platform' => $platform ?: null,
          'q' => $search ?: null,
          'tier' => $tier ?: null,
      ]));
    ?>
    <a class="svc-cat-pill <?= $cat === $c['category'] ? 'active' : '' ?>" href="<?= h($catUrl) ?>"><span class="pill-icon"><?= platformSvgBrand($pKey, 16) ?></span> <?= h(ProviderRegistry::displayCategoryName($c['category'], $tier)) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Results count + view toggle -->
<div class="svc-results-bar" data-reveal>
  <p class="svc-results-count">
    <?php if ($totalServices > 0): ?>
    Showing <strong><?= $listFrom ?>–<?= $listTo ?></strong> of <strong><?= number_format($totalServices) ?></strong> services
    <?php else: ?>
    <strong>0</strong> services
    <?php endif; ?>
  </p>
  <?php if (!empty($services)): ?>
  <div class="svc-view-toggle" role="group" aria-label="View mode">
    <button type="button" class="svc-view-btn active" data-view="grid" aria-pressed="true">Grid</button>
    <button type="button" class="svc-view-btn" data-view="list" aria-pressed="false">List</button>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($services)): ?>
<div class="svc-list-container" id="svcListContainer">
<div class="svc-grid" id="svcGrid">
  <?php
  $displayedNew = false;
  foreach ($services as $s):
    $displayRate = $s['rate'] * (1 + $s['markup']/100);
    $isNew = isset($s['updated_at']) && $s['updated_at'] >= $newCutoff;
    $orderUrl = path('dashboard.php') . '?' . http_build_query(array_filter([
        'cat' => $s['category'],
        'service' => $s['service_id'],
        'tier' => $tier ?: null,
    ]));
    $icon = platformIcon($s['category'], $platformIcons);
    $cardPlatformKey = platformKeyFromCategory($s['category']);
    $cardTier = ProviderRegistry::tierFromProvider($s['provider'] ?? ProviderRegistry::PRIMARY);
    $cardTierLabel = $cardTier === 'pro' ? ProviderRegistry::BRAND_PRO : ProviderRegistry::BRAND_ONE;
  ?>
  <?php $catFull = ProviderRegistry::displayCategoryName($s['category'], $tier); ?>
  <article class="svc-card" data-reveal>
    <div class="svc-card-header">
      <div class="svc-card-header-main">
        <span class="svc-card-platform-icon" aria-hidden="true"><?= platformSvgBrand($cardPlatformKey, 26) ?></span>
        <div class="svc-card-header-text">
          <span class="svc-card-tier svc-card-tier-<?= h($cardTier) ?>"><?= h($cardTierLabel) ?></span>
          <span class="svc-card-cat" title="<?= h($catFull) ?>"><?= h($catFull) ?></span>
        </div>
      </div>
      <div class="svc-card-header-side">
        <?php if ($isNew): ?><span class="svc-card-new">New</span><?php endif; ?>
        <span class="svc-card-id-badge">#<?= $s['service_id'] ?></span>
      </div>
    </div>
    <h2 class="svc-card-name"><?= h(mb_substr($s['name'], 0, 200)) ?></h2>
    <div class="svc-card-meta">
      <span class="svc-card-rate">$<?= number_format($displayRate, 2) ?> <span>/ 1K</span></span>
      <div class="svc-card-minmax">
        <span class="min">Min <?= number_format($s['min']) ?></span>
        <span class="max">Max <?= number_format($s['max']) ?></span>
        <?php if (!empty($s['refill'])): ?><span class="svc-card-refill">Refill</span><?php endif; ?>
      </div>
    </div>
    <div class="svc-card-cta">
      <a href="<?= h($orderUrl) ?>" class="btn btn-primary">Order now</a>
    </div>
  </article>
  <?php endforeach; ?>
</div>
</div>
<?php if ($totalPages > 1): ?>
<div class="ticket-pagination svc-pagination" data-reveal>
  <?php if ($page > 1): ?>
  <a href="<?= h(servicesPageUrl(['p' => $page - 1])) ?>">Previous</a>
  <?php endif; ?>
  <?php
  $startPage = max(1, $page - 2);
  $endPage = min($totalPages, $page + 2);
  for ($p = $startPage; $p <= $endPage; $p++):
  ?>
  <?php if ($p === $page): ?>
  <span class="current"><?= $p ?></span>
  <?php else: ?>
  <a href="<?= h(servicesPageUrl(['p' => $p])) ?>"><?= $p ?></a>
  <?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $totalPages): ?>
  <a href="<?= h(servicesPageUrl(['p' => $page + 1])) ?>">Next</a>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php else: ?>
<div class="svc-empty" data-reveal>
  <div class="svc-empty-icon">🔍</div>
  <h2 class="svc-empty-title">No services found</h2>
  <p class="svc-empty-desc">Try changing filters or search term to see more services.</p>
  <a href="<?= h(path('services.php')) ?>" class="btn btn-primary">Show all services</a>
</div>
<?php endif; ?>

<script>
(function() {
  var container = document.getElementById('svcListContainer');
  if (!container) return;
  var btns = document.querySelectorAll('.svc-view-btn');
  btns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var view = this.getAttribute('data-view');
      btns.forEach(function(b) { b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
      this.classList.add('active'); this.setAttribute('aria-pressed', 'true');
      if (view === 'list') container.classList.add('svc-list-view');
      else container.classList.remove('svc-list-view');
    });
  });
})();
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
