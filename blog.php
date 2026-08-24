<?php
/**
 * Blog index: list published articles with category/tag filter. SEO-optimized.
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
$lang = Lang::initPublic();
$db = Database::getInstance();
$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$baseBlogUrl = $siteUrl ? $siteUrl . path('blog') : path('blog');

$categorySlug = isset($_GET['category']) ? trim(preg_replace('/[^a-z0-9\-]/', '', (string)$_GET['category'])) : '';
$tagSlug      = isset($_GET['tag']) ? trim(preg_replace('/[^a-z0-9\-]/', '', (string)$_GET['tag'])) : '';
$searchQuery  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if ($searchQuery !== '') {
    $searchQuery = mb_substr($searchQuery, 0, 80);
}
$pageNum      = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 12;
$offset       = ($pageNum - 1) * $perPage;

$where = ["a.status = 'published'", "a.published_at IS NOT NULL AND a.published_at <= NOW()"];
$params = [];
$pageTitle = function_exists('__') ? __('blog_title') : 'Blog';
$pageDescription = function_exists('__') ? __('blog_meta_desc') : 'SMM Turk Blog — Social media marketing tips, SMM panel guides, Instagram, YouTube, TikTok growth.';
$canonicalUrl = Seo::absoluteUrl(path('blog.php'));
$filterActive = false;
$seoIndexable = true;

try {
    $welcomeCredit = (float) (new GrowthEngine())->welcomeCreditAmount();
} catch (Throwable $e) {
    $welcomeCredit = 0.0;
}

if ($categorySlug !== '') {
    $cat = $db->fetch("SELECT id, name, meta_description FROM blog_categories WHERE slug = ?", [$categorySlug]);
    if (!$cat) {
        header('Location: ' . url('blog.php'));
        exit;
    }
    $where[] = 'a.category_id = ?';
    $params[] = $cat['id'];
    $pageTitle = $cat['name'];
    $pageDescription = $cat['meta_description'] ?: ($pageTitle . ' — ' . $siteName . ' Blog.');
    $canonicalUrl = Seo::absoluteUrl(path('blog.php') . '?category=' . rawurlencode($categorySlug));
    $filterActive = true;
}

if ($tagSlug !== '') {
    $tag = $db->fetch("SELECT id, name FROM blog_tags WHERE slug = ?", [$tagSlug]);
    if (!$tag) {
        header('Location: ' . url('blog.php'));
        exit;
    }
    $where[] = 'EXISTS (SELECT 1 FROM blog_article_tags at WHERE at.article_id = a.id AND at.tag_id = ?)';
    $params[] = $tag['id'];
    if ($categorySlug === '') {
        $pageTitle = $tag['name'];
        $pageDescription = 'Articles tagged with ' . $tag['name'] . ' — ' . $siteName . ' Blog.';
        $canonicalUrl = Seo::absoluteUrl(path('blog.php') . '?tag=' . rawurlencode($tagSlug));
    }
    $filterActive = true;
}

if ($searchQuery !== '') {
    $where[] = '(a.title LIKE ? OR a.excerpt LIKE ?)';
    $like = '%' . $searchQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $filterActive = true;
    $seoIndexable = false;
    $pageTitle = __('blog_search_title');
    $pageDescription = sprintf('%s — %s', __('blog_search_title'), $siteName);
    $canonicalUrl = Seo::absoluteUrl(path('blog.php'));
}

$whereSql = implode(' AND ', $where);
$total = (int) $db->fetch("SELECT COUNT(*) FROM blog_articles a WHERE $whereSql", $params)['COUNT(*)'];
$totalPages = max(1, (int) ceil($total / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = 1;
}
$offset = ($pageNum - 1) * $perPage;

$articles = $db->fetchAll(
    "SELECT a.id, a.slug, a.title, a.excerpt, a.published_at, a.reading_time_min, a.featured_image, c.name AS category_name, c.slug AS category_slug
     FROM blog_articles a
     LEFT JOIN blog_categories c ON c.id = a.category_id
     WHERE $whereSql
     ORDER BY a.published_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

foreach ($articles as &$row) {
    $row['tags'] = $db->fetchAll(
        "SELECT t.name, t.slug FROM blog_tags t INNER JOIN blog_article_tags at ON at.tag_id = t.id WHERE at.article_id = ?",
        [$row['id']]
    );
}
unset($row);

$categories = $db->fetchAll("SELECT slug, name FROM blog_categories ORDER BY name");
$tags = $db->fetchAll("SELECT slug, name FROM blog_tags ORDER BY name LIMIT 30");
$totalPublished = (int) $db->fetch(
    "SELECT COUNT(*) FROM blog_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()"
)['COUNT(*)'];

$showFeatured = !$filterActive && $pageNum === 1 && count($articles) > 0;
$featured = $showFeatured ? array_shift($articles) : null;
$gridArticles = $articles;

$jsonLd = [
    '@type' => 'Blog',
    'name' => $pageTitle . ' — ' . $siteName,
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'publisher' => ['@type' => 'Organization', 'name' => $siteName, 'url' => $siteUrl ?: Seo::absoluteUrl(home_path())],
    'blogPost' => array_map(function ($a) {
        return [
            '@type' => 'BlogPosting',
            'headline' => $a['title'],
            'description' => $a['excerpt'] ?? '',
            'url' => Seo::absoluteUrl(path('blog.php') . '/' . rawurlencode($a['slug'])),
            'datePublished' => $a['published_at'],
        ];
    }, $featured ? array_merge([$featured], $gridArticles) : $gridArticles),
];

$pageImage = og_image_url();
$paginationPrev = null;
$paginationNext = null;
if ($totalPages > 1) {
    if ($pageNum > 1) {
        $prevUrl = path('blog.php') . '?p=' . ($pageNum - 1);
        if ($categorySlug) $prevUrl .= '&category=' . rawurlencode($categorySlug);
        if ($tagSlug) $prevUrl .= '&tag=' . rawurlencode($tagSlug);
        $paginationPrev = Seo::absoluteUrl($prevUrl);
    }
    if ($pageNum < $totalPages) {
        $nextUrl = path('blog.php') . '?p=' . ($pageNum + 1);
        if ($categorySlug) $nextUrl .= '&category=' . rawurlencode($categorySlug);
        if ($tagSlug) $nextUrl .= '&tag=' . rawurlencode($tagSlug);
        $paginationNext = Seo::absoluteUrl($nextUrl);
    }
}

$blogNavActive = 'blog';
$seoHreflang = true;
$seoHreflangBase = $canonicalUrl;
$jsonLdExtra = [
    Seo::breadcrumbSchema([
        ['name' => __('blog_nav_home'), 'url' => Seo::absoluteUrl(home_path())],
        ['name' => $pageTitle, 'url' => $canonicalUrl],
    ], $lang),
];
require __DIR__ . '/layouts/blog-header.php';

function blog_post_url(array $a): string {
    return path('blog') . '/' . rawurlencode($a['slug']);
}

function blog_category_initial(?string $name): string {
    if ($name === null || $name === '') return 'S';
    return mb_strtoupper(mb_substr(trim($name), 0, 1));
}
?>

<header class="blog-hero">
  <div class="blog-hero-inner">
    <div class="blog-hero-badge">📚 <?= h($siteName) ?> Blog</div>
    <h1><?= h($pageTitle) ?></h1>
    <p><?= h($pageDescription) ?></p>
    <?php if (!$filterActive): ?>
    <div class="blog-hero-stats">
      <div class="blog-hero-stat"><strong><?= (int) $totalPublished ?></strong><span><?= h(__('blog_stat_articles')) ?></span></div>
      <div class="blog-hero-stat"><strong><?= count($categories) ?></strong><span><?= h(__('blog_stat_categories')) ?></span></div>
      <div class="blog-hero-stat"><strong><?= h(__('blog_stat_free')) ?></strong><span><?= h(__('blog_stat_free_sub')) ?></span></div>
    </div>
    <?php else: ?>
    <p class="blog-hero-filter">
      <a href="<?= h(path('blog.php')) ?>">← <?= h(__('blog_all')) ?></a>
      · <?= (int) $total ?> <?= h($total === 1 ? __('blog_result') : __('blog_results')) ?><?php if ($searchQuery !== ''): ?> · “<?= h($searchQuery) ?>”<?php endif; ?>
    </p>
    <?php endif; ?>
    <form class="blog-search" method="get" action="<?= h(path('blog.php')) ?>" role="search">
      <input type="search" name="q" value="<?= h($searchQuery) ?>" placeholder="<?= h(__('blog_search_ph')) ?>" aria-label="<?= h(__('blog_search_ph')) ?>" maxlength="80">
      <button type="submit"><?= h(__('blog_search_btn')) ?></button>
    </form>
  </div>
</header>

<main class="blog-wrap" role="main">

<?php if (!empty($categories) || !empty($tags)): ?>
<div class="blog-filters">
  <?php if (!empty($categories)): ?>
  <div class="blog-filters-label"><?= function_exists('__') ? h(__('blog_categories')) : 'Categories' ?></div>
  <div class="blog-cats">
    <a href="<?= h(path('blog.php')) ?>" class="<?= $categorySlug === '' && $tagSlug === '' ? 'active' : '' ?>"><?= function_exists('__') ? h(__('blog_all')) : 'All' ?></a>
    <?php foreach ($categories as $c): ?>
    <a href="<?= h(path('blog.php') . '?category=' . rawurlencode($c['slug'])) ?>" class="<?= $categorySlug === $c['slug'] ? 'active' : '' ?>"><?= h($c['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($tags)): ?>
  <div class="blog-filters-label" style="margin-top:12px;"><?= function_exists('__') ? h(__('blog_tags')) : 'Popular tags' ?></div>
  <div class="blog-tags-row">
    <?php foreach ($tags as $t): ?>
    <a href="<?= h(path('blog.php') . '?tag=' . rawurlencode($t['slug'])) ?>" class="blog-tag<?= $tagSlug === $t['slug'] ? ' active' : '' ?>"><?= h($t['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$filterActive && $pageNum === 1): ?>
<p class="blog-intro"><?= h(sprintf(__('blog_intro'), $siteName)) ?></p>
<?php endif; ?>

<?php if ($featured): ?>
<?php
  $fUrl = blog_post_url($featured);
  $fDate = $featured['published_at'] ? date('M j, Y', strtotime($featured['published_at'])) : '';
?>
<article class="blog-featured">
  <div class="blog-featured-visual">
    <?php if (!empty($featured['featured_image'])): ?>
    <img src="<?= h($featured['featured_image'][0] === '/' ? $featured['featured_image'] : path($featured['featured_image'])) ?>" alt="<?= h($featured['title']) ?>" loading="eager" width="480" height="270">
    <?php else: ?>
    <div class="blog-featured-icon" aria-hidden="true"><?= h(blog_category_initial($featured['category_name'] ?? null)) ?></div>
    <?php endif; ?>
  </div>
  <div class="blog-featured-body">
    <div class="blog-featured-label"><?= function_exists('__') ? h(__('blog_featured')) : 'Featured' ?></div>
    <?php if (!empty($featured['category_name'])): ?>
    <div class="meta"><a href="<?= h(path('blog.php') . '?category=' . rawurlencode($featured['category_slug'])) ?>"><?= h($featured['category_name']) ?></a></div>
    <?php endif; ?>
    <h2><a href="<?= h($fUrl) ?>"><?= h($featured['title']) ?></a></h2>
    <div class="meta"><?= h($fDate) ?><?php if (!empty($featured['reading_time_min'])): ?> · <?= (int)$featured['reading_time_min'] ?> <?= h(__('blog_min_read')) ?><?php endif; ?></div>
    <?php if (!empty($featured['excerpt'])): ?><p class="excerpt"><?= h($featured['excerpt']) ?></p><?php endif; ?>
    <a href="<?= h($fUrl) ?>" class="read-more"><?= function_exists('__') ? h(__('blog_read_more')) : 'Read article' ?> →</a>
  </div>
</article>
<?php endif; ?>

<?php if (empty($gridArticles) && !$featured): ?>
<div class="blog-empty">
  <p><?= $searchQuery !== '' ? h(__('blog_search_none')) : (function_exists('__') ? h(__('blog_no_posts')) : 'No posts yet.') ?></p>
  <?php if ($filterActive): ?><p style="margin-top:10px;"><a href="<?= h(path('blog.php')) ?>" class="read-more">← <?= h(__('blog_all')) ?></a></p><?php endif; ?>
</div>
<?php else: ?>
<div class="blog-grid">
  <?php foreach ($gridArticles as $a):
      $postUrl = blog_post_url($a);
      $dateStr = $a['published_at'] ? date('M j, Y', strtotime($a['published_at'])) : '';
  ?>
  <article class="blog-card">
    <?php if (!empty($a['category_name'])): ?>
    <div class="blog-card-cat"><a href="<?= h(path('blog.php') . '?category=' . rawurlencode($a['category_slug'])) ?>"><?= h($a['category_name']) ?></a></div>
    <?php endif; ?>
    <h2><a href="<?= h($postUrl) ?>"><?= h($a['title']) ?></a></h2>
    <div class="meta"><?= h($dateStr) ?><?php if (!empty($a['reading_time_min'])): ?> · <?= (int)$a['reading_time_min'] ?> <?= h(__('blog_min')) ?><?php endif; ?></div>
    <?php if (!empty($a['excerpt'])): ?><p class="excerpt"><?= h($a['excerpt']) ?></p><?php endif; ?>
    <div class="blog-card-footer">
      <?php if (!empty($a['tags'])): ?>
      <div class="blog-card-tags">
        <?php foreach (array_slice($a['tags'], 0, 3) as $t): ?>
        <a href="<?= h(path('blog.php') . '?tag=' . rawurlencode($t['slug'])) ?>" class="blog-tag"><?= h($t['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a href="<?= h($postUrl) ?>" class="read-more"><?= function_exists('__') ? h(__('blog_read_more')) : 'Read' ?> →</a>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
<nav class="blog-pagination" aria-label="Pagination">
    <?php if ($pageNum > 1):
        $prevUrl = path('blog.php') . '?p=' . ($pageNum - 1);
        if ($categorySlug) $prevUrl .= '&category=' . rawurlencode($categorySlug);
        if ($tagSlug) $prevUrl .= '&tag=' . rawurlencode($tagSlug);
        if ($searchQuery !== '') $prevUrl .= '&q=' . rawurlencode($searchQuery);
    ?>
    <a href="<?= h($prevUrl) ?>">← <?= function_exists('__') ? h(__('blog_prev')) : 'Previous' ?></a>
    <?php endif; ?>
    <?php for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++):
        $pUrl = path('blog.php') . '?p=' . $i;
        if ($categorySlug) $pUrl .= '&category=' . rawurlencode($categorySlug);
        if ($tagSlug) $pUrl .= '&tag=' . rawurlencode($tagSlug);
        if ($searchQuery !== '') $pUrl .= '&q=' . rawurlencode($searchQuery);
    ?><a href="<?= h($pUrl) ?>" class="<?= $i === $pageNum ? 'current' : '' ?>"><?= $i ?></a><?php endfor; ?>
    <?php if ($pageNum < $totalPages):
        $nextUrl = path('blog.php') . '?p=' . ($pageNum + 1);
        if ($categorySlug) $nextUrl .= '&category=' . rawurlencode($categorySlug);
        if ($tagSlug) $nextUrl .= '&tag=' . rawurlencode($tagSlug);
        if ($searchQuery !== '') $nextUrl .= '&q=' . rawurlencode($searchQuery);
    ?>
    <a href="<?= h($nextUrl) ?>"><?= function_exists('__') ? h(__('blog_next')) : 'Next' ?> →</a>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/partials/blog-newsletter.php'; ?>

<div class="blog-cta">
  <div>
    <h3><?= h(__('blog_cta_title')) ?></h3>
    <p><?= h(sprintf(__('blog_cta_desc'), $siteName)) ?></p>
    <?php if ($welcomeCredit > 0): ?>
    <p class="blog-cta-bonus">🎁 <?= h(sprintf(__('blog_cta_bonus'), number_format($welcomeCredit, 2))) ?></p>
    <?php endif; ?>
  </div>
  <a href="<?= h(register_path()) ?>" class="blog-cta-btn"><?= h(__('blog_cta_btn')) ?></a>
</div>

</main>

<?php require __DIR__ . '/layouts/blog-footer.php'; ?>
