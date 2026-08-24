<?php
/**
 * Single blog post by slug. SEO + JSON-LD Article schema for search and AI.
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
$lang = Lang::initPublic();
$db = Database::getInstance();
$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');
$siteUrl  = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

$slug = isset($_GET['slug']) ? trim(preg_replace('/[^a-z0-9\-]/', '', (string)$_GET['slug'])) : '';
if ($slug === '') {
    header('Location: ' . url('blog'));
    exit;
}

$post = $db->fetch(
    "SELECT a.*, c.name AS category_name, c.slug AS category_slug
     FROM blog_articles a
     LEFT JOIN blog_categories c ON c.id = a.category_id
     WHERE a.slug = ? AND a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW()",
    [$slug]
);

if (!$post) {
    http_response_code(404);
    $pageTitle = 'Article Not Found';
    $pageDescription = 'This blog article was not found.';
    $blogNavActive = 'blog';
    $seoIndexable = false;
    $canonicalUrl = Seo::absoluteUrl(path('blog.php'));
    require __DIR__ . '/layouts/blog-header.php';
    echo '<main class="blog-article-wrap" role="main" style="max-width:640px;margin:40px auto;padding:20px;text-align:center;">';
    echo '<h1>Article not found</h1><p><a href="' . h(path('blog.php')) . '">← Back to Blog</a></p></main>';
    require __DIR__ . '/layouts/blog-footer.php';
    exit;
}

$tags = $db->fetchAll(
    "SELECT t.name, t.slug FROM blog_tags t INNER JOIN blog_article_tags at ON at.tag_id = t.id WHERE at.article_id = ?",
    [$post['id']]
);

$related = [];
try {
    $related = $db->fetchAll(
        "SELECT a.slug, a.title, a.excerpt, a.published_at, a.reading_time_min, c.name AS category_name, c.slug AS category_slug,
                (a.category_id = ?) AS same_cat,
                (SELECT COUNT(*) FROM blog_article_tags at
                    WHERE at.article_id = a.id
                      AND at.tag_id IN (SELECT tag_id FROM blog_article_tags WHERE article_id = ?)) AS shared_tags
         FROM blog_articles a
         LEFT JOIN blog_categories c ON c.id = a.category_id
         WHERE a.id <> ? AND a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW()
         ORDER BY shared_tags DESC, same_cat DESC, a.published_at DESC
         LIMIT 3",
        [(int) $post['category_id'], (int) $post['id'], (int) $post['id']]
    );
} catch (Throwable $e) {
    $related = [];
}

$pageTitle = $post['title'];
$pageDescription = $post['meta_description'] ?: ($post['excerpt'] ?: strip_tags(mb_substr($post['body'], 0, 160)));
$metaKeywords = $post['meta_keywords'];
$canonicalUrl = Seo::absoluteUrl(path('blog.php') . '/' . rawurlencode($post['slug']));
$pageImage = $post['featured_image']
    ? Seo::absoluteUrl($post['featured_image'])
    : Seo::absoluteUrl(path('og-image.php') . '?slug=' . rawurlencode($post['slug']));
$ogType = 'article';
$articlePublished = $post['published_at'];
$articleModified = $post['updated_at'] ?? $post['published_at'];
$articleSection = $post['category_name'] ?? '';
$articleTags = array_column($tags, 'name');

$jsonLd = [
    '@type' => 'Article',
    'headline' => $post['title'],
    'description' => $pageDescription,
    'image' => $pageImage,
    'url' => Seo::pageCanonical($canonicalUrl, $lang),
    'inLanguage' => Seo::pageLanguage($lang),
    'datePublished' => date('c', strtotime($post['published_at'])),
    'dateModified' => date('c', strtotime($post['updated_at'] ?? $post['published_at'])),
    'author' => ['@type' => 'Organization', 'name' => $siteName],
    'publisher' => [
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => $siteUrl,
        'logo' => ['@type' => 'ImageObject', 'url' => og_image_url()],
    ],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => Seo::pageCanonical($canonicalUrl, $lang)],
];
if (!empty($tags)) {
    $jsonLd['keywords'] = implode(', ', array_column($tags, 'name'));
}

$blogNavActive = 'blog';
$seoHreflang = false;
$seoHreflangBase = $canonicalUrl;
$jsonLdExtra = [
    Seo::breadcrumbSchema([
        ['name' => __('blog_nav_home'), 'url' => $siteUrl !== '' ? Seo::absoluteUrl(home_path()) : home_path()],
        ['name' => function_exists('__') ? __('blog_title') : 'Blog', 'url' => $siteUrl !== '' ? Seo::absoluteUrl(path('blog.php')) : path('blog.php')],
        ['name' => $post['title'], 'url' => Seo::pageCanonical($canonicalUrl, $lang)],
    ], $lang),
];
require __DIR__ . '/layouts/blog-header.php';
?>
<main class="blog-article-wrap" role="main">
<article>
    <nav class="blog-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= h(path('blog.php')) ?>"><?= function_exists('__') ? h(__('blog_title')) : 'Blog' ?></a>
        <?php if (!empty($post['category_name'])): ?>
        · <a href="<?= h(path('blog.php') . '?category=' . rawurlencode($post['category_slug'])) ?>"><?= h($post['category_name']) ?></a>
        <?php endif; ?>
    </nav>

    <?php if (!empty($post['category_name'])): ?>
    <div class="blog-card-cat"><a href="<?= h(path('blog.php') . '?category=' . rawurlencode($post['category_slug'])) ?>"><?= h($post['category_name']) ?></a></div>
    <?php endif; ?>

    <h1><?= h($post['title']) ?></h1>
    <div class="article-meta">
        <span><?= $post['published_at'] ? date('F j, Y', strtotime($post['published_at'])) : '' ?></span>
        <?php if (!empty($post['reading_time_min'])): ?><span>· <?= (int)$post['reading_time_min'] ?> <?= h(__('blog_min_read')) ?></span><?php endif; ?>
        <span>· <?= h($siteName) ?></span>
    </div>

    <?php if (!empty($tags)): ?>
    <div class="blog-tags-row" style="margin-bottom:24px;">
        <?php foreach ($tags as $t): ?>
        <a href="<?= h(path('blog.php') . '?tag=' . rawurlencode($t['slug'])) ?>" class="blog-tag"><?= h($t['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($post['featured_image'])): ?>
    <p><img src="<?= h($post['featured_image'][0] === '/' ? $post['featured_image'] : path($post['featured_image'])) ?>" alt="<?= h($post['title']) ?>" loading="eager" width="800" height="450" style="width:100%;border-radius:16px;margin-bottom:24px;"></p>
    <?php endif; ?>

    <div class="article-body">
        <?= $post['body'] ?>
    </div>
</article>

<?php if (!empty($related)): ?>
<section class="blog-related" aria-labelledby="blog-related-title">
  <h2 id="blog-related-title" class="blog-related-title"><?= h(__('blog_related')) ?></h2>
  <div class="blog-grid">
    <?php foreach ($related as $r):
        $rUrl = path('blog') . '/' . rawurlencode($r['slug']);
        $rDate = $r['published_at'] ? date('M j, Y', strtotime($r['published_at'])) : '';
    ?>
    <article class="blog-card">
      <?php if (!empty($r['category_name'])): ?>
      <div class="blog-card-cat"><a href="<?= h(path('blog.php') . '?category=' . rawurlencode($r['category_slug'])) ?>"><?= h($r['category_name']) ?></a></div>
      <?php endif; ?>
      <h2><a href="<?= h($rUrl) ?>"><?= h($r['title']) ?></a></h2>
      <div class="meta"><?= h($rDate) ?><?php if (!empty($r['reading_time_min'])): ?> · <?= (int) $r['reading_time_min'] ?> <?= h(__('blog_min')) ?><?php endif; ?></div>
      <?php if (!empty($r['excerpt'])): ?><p class="excerpt"><?= h($r['excerpt']) ?></p><?php endif; ?>
      <div class="blog-card-footer">
        <a href="<?= h($rUrl) ?>" class="read-more"><?= h(__('blog_read_more')) ?> →</a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/blog-newsletter.php'; ?>

<div class="blog-cta" style="margin-top:40px;">
  <?php
  $growthCta = new GrowthEngine();
  $ctaOffers = $growthCta->offerLines();
  ?>
  <div>
    <h3>Start using <?= h($siteName) ?> today</h3>
    <p><?= h(implode(' · ', array_slice($ctaOffers, 0, 3))) ?></p>
  </div>
  <a href="<?= h(register_path()) ?>" class="blog-cta-btn"><?= function_exists('__') ? h(__('nav_sign_up')) : 'Get Started' ?> →</a>
</div>

<a href="<?= h(path('blog')) ?>" class="blog-back-link">← <?= function_exists('__') ? h(__('blog_back')) : 'Back to Blog' ?></a>
</main>

<?php require __DIR__ . '/layouts/blog-footer.php'; ?>
