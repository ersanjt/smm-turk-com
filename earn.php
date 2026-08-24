<?php
/**
 * Public + logged-in hub: 3 ways to earn + SEO for "make money SMM panel".
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

$loggedIn = $auth->isLoggedIn();
$db = Database::getInstance();
$growth = new GrowthEngine();
$stats = $growth->publicStats();
$offers = $growth->offerLines();
$childPrice = (float) ($db->getSetting('child_panel_price') ?: 5);
$refCommission = (float) ($db->getSetting('referral_commission') ?: 2);
$welcomeCredit = $growth->welcomeCreditAmount();
$depositBonus = (float) ($db->getSetting('deposit_bonus_percent') ?: 0);
$refLink = '';
$refPct = number_format($refCommission, 0);

if ($loggedIn) {
    Lang::initUser();
    $pageTitle = __('nav_earn');
    $extraCssHref = asset_url('assets/css/earn.css');
    $user = $auth->getCurrentUser();
    $refCode = $auth->ensureReferralCode((int) $user['id']);
    $refLink = $refCode !== '' ? url('c/' . $refCode) : '';
    require_once __DIR__ . '/layouts/header.php';
} else {
    $lang = Lang::initPublic();
    $siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
    $baseCanonical = Seo::absoluteUrl(path('earn.php'));
    $seoTitle = __('earn_meta_title');
    $seoDescription = __('earn_meta_desc');
    $metaKeywords = __('earn_keywords');
    $extraCssHrefs = [asset_url('assets/css/earn.css')];
}

$incomePaths = [
    [
        'id' => 'child-panel',
        'icon' => 'server',
        'tone' => 'primary',
        'title' => __('earn_child_title'),
        'subtitle' => __('earn_child_sub'),
        'desc' => __('earn_child_desc'),
        'bullets' => [
            sprintf(__('earn_child_b1'), number_format($childPrice, 2)),
            __('earn_child_b2'),
            __('earn_child_b3'),
            __('earn_child_b4'),
        ],
        'cta' => $loggedIn ? path('child-panel.php') : register_path(),
        'cta_label' => $loggedIn ? __('earn_child_cta_user') : __('earn_child_cta_guest'),
        'earn_you' => __('earn_child_you'),
    ],
    [
        'id' => 'affiliate',
        'icon' => 'users',
        'tone' => 'green',
        'title' => __('earn_aff_title'),
        'subtitle' => __('earn_aff_sub'),
        'desc' => sprintf(__('earn_aff_desc'), $refPct),
        'bullets' => [
            sprintf(__('earn_aff_b1'), $refPct),
            __('earn_aff_b2'),
            __('earn_aff_b3'),
            __('earn_aff_b4'),
        ],
        'cta' => $loggedIn ? path('affiliates.php') : register_path(),
        'cta_label' => $loggedIn ? __('earn_aff_cta_user') : __('earn_aff_cta_guest'),
        'earn_you' => __('earn_aff_you'),
    ],
    [
        'id' => 'api',
        'icon' => 'plug',
        'tone' => 'blue',
        'title' => __('earn_api_title'),
        'subtitle' => __('earn_api_sub'),
        'desc' => __('earn_api_desc'),
        'bullets' => [
            __('earn_api_b1'),
            __('earn_api_b2'),
            __('earn_api_b3'),
            __('earn_api_b4'),
        ],
        'cta' => $loggedIn ? path('api-page.php') : login_next_path('api-page.php'),
        'cta_label' => $loggedIn ? __('earn_api_cta_user') : __('earn_api_cta_guest'),
        'earn_you' => __('earn_api_you'),
    ],
];

$comparePaths = [
    ['id' => 'child-panel', 'sub' => __('earn_child_sub'), 'effort' => __('earn_compare_child_effort'), 'income' => __('earn_compare_child_income'), 'best' => __('earn_compare_child_best')],
    ['id' => 'affiliate', 'sub' => __('earn_aff_sub'), 'effort' => __('earn_compare_aff_effort'), 'income' => __('earn_compare_aff_income'), 'best' => __('earn_compare_aff_best')],
    ['id' => 'api', 'sub' => __('earn_api_sub'), 'effort' => __('earn_compare_api_effort'), 'income' => __('earn_compare_api_income'), 'best' => __('earn_compare_api_best')],
];

$faqs = [
    ['q' => __('earn_child_title'), 'a' => __('earn_child_desc')],
    ['q' => __('earn_aff_title'), 'a' => sprintf(__('earn_aff_desc'), $refPct)],
    ['q' => __('earn_api_title'), 'a' => __('earn_api_desc')],
    ['q' => __('earn_faq_q_earn'), 'a' => __('earn_faq_a_earn')],
    ['q' => __('earn_faq_q_skills'), 'a' => __('earn_faq_a_skills')],
    ['q' => __('earn_faq_q_pay'), 'a' => __('earn_faq_a_pay')],
    ['q' => __('earn_faq_q_combine'), 'a' => __('earn_faq_a_combine')],
];

if (!$loggedIn):
    $jsonLdGraph = [
        Seo::organizationSchema($seoDescription, $lang),
        Seo::websiteSchema($seoDescription),
        Seo::webPageSchema(__('earn_hero_h1'), $seoDescription, Seo::pageCanonical($baseCanonical, $lang), $lang),
        Seo::breadcrumbSchema([
            ['name' => __('blog_nav_home'), 'url' => Seo::absoluteUrl(home_path())],
            ['name' => __('nav_earn'), 'url' => $baseCanonical],
        ], $lang),
        Seo::faqSchema(array_map(static fn($f) => ['name' => $f['q'], 'text' => $f['a']], $faqs), $lang),
    ];
    $promo = $growth->promoBar();
?>
<!DOCTYPE html>
<html lang="<?= h(Seo::htmlLang($lang)) ?>">
<head>
<?php require __DIR__ . '/partials/public-seo-head.php'; ?>
</head>
<body data-sw="<?= h(path('pwa-sw.php')) ?>" data-sw-scope="<?= h(base_path() !== '' ? base_path() . '/' : '/') ?>">
<script>(function(){var k='smmturk_theme',d=localStorage.getItem(k)==='dark'||(!localStorage.getItem(k)&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);if(d)document.body.classList.add('theme-dark');})();</script>
<?php if ($promo['enabled']): ?>
<div class="growth-promo-bar"><span><?= h($promo['text']) ?></span><a href="<?= h($promo['cta_url']) ?>"><?= h($promo['cta_label']) ?></a></div>
<?php endif; ?>
<?php $navActive = 'earn'; $registrationEnabled = ($db->getSetting('registration_enabled') ?? '1') === '1'; require __DIR__ . '/partials/landing-nav.php'; ?>
<?php endif; ?>

<div class="earn-page">
  <header class="earn-hero">
    <span class="hero-badge"><?= h(__('earn_hero_badge')) ?></span>
    <h1><?= h(__('earn_hero_h1')) ?></h1>
    <p class="earn-hero-lead"><?= h(__('earn_hero_lead')) ?></p>
    <?php if (!empty($offers)): ?>
    <div class="hero-offers">
      <?php foreach (array_slice($offers, 0, 4) as $o): ?><span class="hero-offer-pill"><?= h($o) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($loggedIn && $refLink !== ''): ?>
    <div class="earn-ref-quick">
      <label for="earnRefLink"><?= h(__('earn_ref_label')) ?></label>
      <div class="earn-ref-row">
        <input type="text" readonly value="<?= h($refLink) ?>" id="earnRefLink" aria-label="<?= h(__('earn_ref_label')) ?>">
        <button type="button" class="btn btn-primary btn-sm earn-copy-btn" data-copy-target="earnRefLink" data-copy-done="<?= h(__('earn_copy')) ?>"><?= h(__('earn_copy')) ?></button>
      </div>
    </div>
    <?php elseif (!$loggedIn): ?>
    <div class="earn-hero-cta">
      <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('earn_create_account')) ?><?= $welcomeCredit > 0 ? ' (+$' . number_format($welcomeCredit, 2) . ')' : '' ?> →</a>
      <a href="<?= h(path('pricing.php')) ?>" class="btn-cta-outline"><?= h(__('earn_view_prices')) ?></a>
    </div>
    <?php endif; ?>
    <nav class="earn-quick-nav" aria-label="<?= h(__('earn_section_label')) ?>">
      <?php foreach ($incomePaths as $path): ?>
      <a href="#<?= h($path['id']) ?>"><?= h($path['subtitle']) ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <section class="earn-section" aria-labelledby="earn-paths-heading">
    <p class="section-label" id="earn-paths-heading"><?= h(__('earn_section_label')) ?></p>
    <h2 class="earn-section-title"><?= h(__('earn_section_title')) ?></h2>
    <div class="earn-paths">
      <?php foreach ($incomePaths as $path): ?>
      <article class="earn-card" id="<?= h($path['id']) ?>">
        <div class="earn-card-icon"><?= iconBox($path['icon'], $path['tone'] ?? 'primary', 26) ?></div>
        <div class="earn-card-body">
          <p class="earn-card-sub"><?= h($path['subtitle']) ?></p>
          <h3><?= h($path['title']) ?></h3>
          <p class="earn-card-desc"><?= h($path['desc']) ?></p>
          <ul class="earn-card-list">
            <?php foreach ($path['bullets'] as $b): ?>
            <li><?= h($b) ?></li>
            <?php endforeach; ?>
          </ul>
          <div class="earn-meta">
            <strong><?= h(__('earn_you_label')) ?></strong> <?= h($path['earn_you']) ?>
          </div>
          <a href="<?= h($path['cta']) ?>" class="earn-card-cta"><?= h($path['cta_label']) ?></a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="earn-section earn-compare-section" aria-labelledby="earn-compare-heading">
    <p class="section-label" id="earn-compare-heading"><?= h(__('earn_compare_label')) ?></p>
    <h2 class="earn-section-title"><?= h(__('earn_compare_title')) ?></h2>
    <div class="earn-compare-wrap">
      <table class="earn-compare">
        <thead>
          <tr>
            <th><?= h(__('earn_compare_c_path')) ?></th>
            <th><?= h(__('earn_compare_c_effort')) ?></th>
            <th><?= h(__('earn_compare_c_income')) ?></th>
            <th><?= h(__('earn_compare_c_best')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($comparePaths as $c): ?>
          <tr>
            <th scope="row"><a href="#<?= h($c['id']) ?>"><?= h($c['sub']) ?></a></th>
            <td data-label="<?= h(__('earn_compare_c_effort')) ?>"><?= h($c['effort']) ?></td>
            <td data-label="<?= h(__('earn_compare_c_income')) ?>"><?= h($c['income']) ?></td>
            <td data-label="<?= h(__('earn_compare_c_best')) ?>"><?= h($c['best']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="earn-flow" aria-labelledby="earn-flow-heading">
    <p class="section-label"><?= h(__('earn_flow_label')) ?></p>
    <h2 id="earn-flow-heading" class="earn-section-title"><?= h(__('earn_flow_title')) ?></h2>
    <div class="earn-flow-steps">
      <div class="earn-flow-step">
        <span class="earn-flow-num">1</span>
        <p><?= __('earn_flow_s1') ?></p>
      </div>
      <div class="earn-flow-step">
        <span class="earn-flow-num">2</span>
        <p><?= __('earn_flow_s2') ?></p>
      </div>
      <div class="earn-flow-step">
        <span class="earn-flow-num">3</span>
        <p><?= __('earn_flow_s3') ?></p>
      </div>
      <div class="earn-flow-step">
        <span class="earn-flow-num">4</span>
        <p><?= __('earn_flow_s4') ?></p>
      </div>
    </div>
  </section>

  <section class="earn-section earn-calc-section" aria-labelledby="earn-calc-heading">
    <p class="section-label" id="earn-calc-heading"><?= h(__('earn_calc_label')) ?></p>
    <h2 class="earn-section-title"><?= h(__('earn_calc_title')) ?></h2>
    <p class="earn-calc-desc"><?= h(__('earn_calc_desc')) ?></p>
    <div class="earn-calc" id="earnCalc">
      <div class="earn-calc-controls">
        <div class="earn-calc-field">
          <label for="calcCustomers"><?= h(__('earn_calc_customers')) ?> <span class="earn-calc-out" data-out="customers">25</span></label>
          <input type="range" id="calcCustomers" min="1" max="500" step="1" value="25">
        </div>
        <div class="earn-calc-field">
          <label for="calcOrders"><?= h(__('earn_calc_orders')) ?> <span class="earn-calc-out" data-out="orders">4</span></label>
          <input type="range" id="calcOrders" min="1" max="50" step="1" value="4">
        </div>
        <div class="earn-calc-field">
          <label for="calcSpend"><?= h(__('earn_calc_spend')) ?> <span class="earn-calc-out" data-out="spend">5</span></label>
          <input type="range" id="calcSpend" min="1" max="100" step="1" value="5">
        </div>
        <div class="earn-calc-field">
          <label for="calcMarkup"><?= h(__('earn_calc_markup')) ?> <span class="earn-calc-out" data-out="markup">30</span></label>
          <input type="range" id="calcMarkup" min="5" max="200" step="5" value="30">
        </div>
      </div>
      <div class="earn-calc-result">
        <div class="earn-calc-rows">
          <div class="earn-calc-row"><span><?= h(__('earn_calc_revenue')) ?></span><strong data-out="revenue">$0</strong></div>
          <div class="earn-calc-row"><span><?= h(__('earn_calc_cost')) ?></span><strong data-out="cost">$0</strong></div>
        </div>
        <div class="earn-calc-profit">
          <span><?= h(__('earn_calc_profit')) ?></span>
          <strong data-out="profit">$0</strong>
        </div>
        <?php if (!$loggedIn): ?>
        <a href="<?= h(register_path()) ?>" class="earn-card-cta earn-calc-cta"><?= h(__('earn_child_cta_guest')) ?></a>
        <?php else: ?>
        <a href="<?= h(path('child-panel.php')) ?>" class="earn-card-cta earn-calc-cta"><?= h(__('earn_child_cta_user')) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <p class="earn-calc-note"><?= h(__('earn_calc_note')) ?></p>
  </section>

  <section class="earn-section" aria-labelledby="earn-stats-heading">
    <p class="section-label" id="earn-stats-heading"><?= h(__('earn_stats_label')) ?></p>
    <h2 class="earn-section-title"><?= h(__('earn_stats_title')) ?></h2>
    <div class="stats-row earn-stats-row">
      <div class="stat-item">
        <div class="icon"><?= iconBox('users', 'blue') ?></div>
        <div class="stat-value"><?= h($stats['users']) ?></div>
        <div class="stat-label"><?= h(__('earn_stat_users')) ?></div>
      </div>
      <div class="stat-item">
        <div class="icon"><?= iconBox('orders', 'green') ?></div>
        <div class="stat-value"><?= h($stats['orders']) ?></div>
        <div class="stat-label"><?= h(__('earn_stat_orders')) ?></div>
      </div>
      <div class="stat-item">
        <div class="icon"><?= iconBox('services', 'primary') ?></div>
        <div class="stat-value"><?= h($stats['services']) ?></div>
        <div class="stat-label"><?= h(__('earn_stat_services')) ?></div>
      </div>
      <?php if ($depositBonus > 0): ?>
      <div class="stat-item">
        <div class="icon"><?= iconBox('deposit', 'orange') ?></div>
        <div class="stat-value">+<?= (int) $depositBonus ?>%</div>
        <div class="stat-label"><?= h(__('earn_deposit_bonus')) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="earn-section earn-faq-section" aria-labelledby="earn-faq-heading">
    <p class="section-label" id="earn-faq-heading"><?= h(__('earn_faq_label')) ?></p>
    <h2 class="earn-section-title"><?= h(__('earn_faq_title')) ?></h2>
    <div class="earn-faq">
      <?php foreach ($faqs as $i => $f): ?>
      <div class="earn-faq-item<?= $i === 0 ? ' open' : '' ?>">
        <button type="button" class="earn-faq-q" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
          <span><?= h($f['q']) ?></span>
          <span class="earn-faq-ico" aria-hidden="true">+</span>
        </button>
        <div class="earn-faq-a"><p><?= h($f['a']) ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if (!$loggedIn): ?>
  <section class="earn-section earn-section-cta">
    <div class="cta-block">
      <h2 class="section-title"><?= h(__('earn_cta_title')) ?></h2>
      <p class="section-desc"><?= h(__('earn_cta_desc')) ?></p>
      <a href="<?= h(register_path()) ?>" class="btn-cta"><?= h(__('earn_cta_btn')) ?></a>
    </div>
  </section>
  <?php endif; ?>
</div>

<script>
(function () {
  var calc = document.getElementById('earnCalc');
  if (calc) {
    var ids = { customers: 'calcCustomers', orders: 'calcOrders', spend: 'calcSpend', markup: 'calcMarkup' };
    var inputs = {};
    Object.keys(ids).forEach(function (k) { inputs[k] = document.getElementById(ids[k]); });
    var money = function (n) {
      return '$' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    };
    var setOut = function (key, val) {
      calc.querySelectorAll('[data-out="' + key + '"]').forEach(function (el) { el.textContent = val; });
    };
    var render = function () {
      var customers = +inputs.customers.value;
      var orders = +inputs.orders.value;
      var spend = +inputs.spend.value;
      var markup = +inputs.markup.value;
      setOut('customers', customers);
      setOut('orders', orders);
      setOut('spend', '$' + spend);
      setOut('markup', markup + '%');
      var revenue = customers * orders * spend;
      var cost = revenue / (1 + markup / 100);
      var profit = revenue - cost;
      setOut('revenue', money(revenue));
      setOut('cost', money(cost));
      setOut('profit', money(profit));
    };
    Object.keys(inputs).forEach(function (k) {
      if (inputs[k]) inputs[k].addEventListener('input', render);
    });
    render();
  }

  document.querySelectorAll('.earn-faq-q').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.earn-faq-item');
      var isOpen = item.classList.contains('open');
      document.querySelectorAll('.earn-faq-item').forEach(function (i) {
        i.classList.remove('open');
        var b = i.querySelector('.earn-faq-q');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
      if (!isOpen) {
        item.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });
})();
</script>

<?php if ($loggedIn): ?>
<script>
document.querySelectorAll('.earn-copy-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-copy-target');
    var el = id ? document.getElementById(id) : null;
    if (!el) return;
    var done = btn.getAttribute('data-copy-done') || 'Copied!';
    var orig = btn.textContent;
    navigator.clipboard.writeText(el.value).then(function () {
      btn.textContent = done + '!';
      setTimeout(function () { btn.textContent = orig; }, 2000);
    });
  });
});
</script>
<?php require_once __DIR__ . '/layouts/footer.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/partials/landing-footer.php'; ?>
<script src="<?= h(asset_url('assets/js/landing.js')) ?>" defer></script>
<script src="<?= h(asset_url('assets/js/pwa.js')) ?>" defer></script>
<?php require __DIR__ . '/partials/a11y.php'; ?>
</body></html>
<?php endif; ?>
