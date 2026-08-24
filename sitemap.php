<?php
/**
 * XML Sitemap for search engines. Outputs all public pages + blog posts.
 * Multilingual: xhtml:link hreflang for tr, en, de on each URL.
 * Access as: /sitemap.xml (via .htaccess rewrite)
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

header('Content-Type: application/xml; charset=UTF-8');

$siteUrl = Seo::siteUrl();
if ($siteUrl === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    exit;
}

$db = Database::getInstance();
$base = $siteUrl . (function_exists('base_path') ? base_path() : '');

$urls = [];
$seen = [];

$addUrl = static function (string $loc, string $lastmod, string $freq, string $priority) use (&$urls, &$seen): void {
    if (isset($seen[$loc])) {
        return;
    }
    $seen[$loc] = true;
    $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'changefreq' => $freq, 'priority' => $priority];
};

// Public marketing pages
$static = [
    '/' => ['freq' => 'daily', 'priority' => '1.0'],
    '/earn' => ['freq' => 'weekly', 'priority' => '0.92'],
    '/pricing' => ['freq' => 'daily', 'priority' => '0.95'],
    '/help' => ['freq' => 'weekly', 'priority' => '0.85'],
    '/blog' => ['freq' => 'daily', 'priority' => '0.9'],
    '/terms' => ['freq' => 'yearly', 'priority' => '0.5'],
];
foreach ($static as $path => $meta) {
    $addUrl($base . $path, date('Y-m-d'), $meta['freq'], $meta['priority']);
}

try {
    $posts = $db->fetchAll(
        "SELECT slug, updated_at, published_at FROM blog_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW() ORDER BY published_at DESC"
    );
    foreach ($posts as $row) {
        $lastmod = !empty($row['updated_at']) ? $row['updated_at'] : $row['published_at'];
        $addUrl(
            $base . '/blog/' . rawurlencode((string) $row['slug']),
            date('Y-m-d', strtotime((string) $lastmod)),
            'weekly',
            '0.8'
        );
    }
} catch (Throwable $e) {}

try {
    $categories = $db->fetchAll(
        "SELECT c.slug, MAX(a.updated_at) AS lastmod FROM blog_categories c
         INNER JOIN blog_articles a ON a.category_id = c.id
         WHERE a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW()
         GROUP BY c.slug"
    );
    foreach ($categories as $cat) {
        $addUrl(
            $base . '/blog?category=' . rawurlencode((string) $cat['slug']),
            !empty($cat['lastmod']) ? date('Y-m-d', strtotime((string) $cat['lastmod'])) : date('Y-m-d'),
            'weekly',
            '0.7'
        );
    }
} catch (Throwable $e) {}

try {
    $tags = $db->fetchAll(
        "SELECT t.slug, MAX(a.updated_at) AS lastmod FROM blog_tags t
         INNER JOIN blog_article_tags at ON at.tag_id = t.id
         INNER JOIN blog_articles a ON a.id = at.article_id
         WHERE a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW()
         GROUP BY t.slug"
    );
    foreach ($tags as $tag) {
        $addUrl(
            $base . '/blog?tag=' . rawurlencode((string) $tag['slug']),
            !empty($tag['lastmod']) ? date('Y-m-d', strtotime((string) $tag['lastmod'])) : date('Y-m-d'),
            'weekly',
            '0.65'
        );
    }
} catch (Throwable $e) {}

try {
    $totalPosts = (int) $db->fetch(
        "SELECT COUNT(*) c FROM blog_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()"
    )['c'];
    $perPage = 12;
    $totalPages = max(1, (int) ceil($totalPosts / $perPage));
    for ($p = 2; $p <= $totalPages; $p++) {
        $addUrl($base . '/blog?p=' . $p, date('Y-m-d'), 'weekly', '0.6');
    }
} catch (Throwable $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc><?= Seo::sitemapHreflangLinks($u['loc']) ?>

    <lastmod><?= htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></lastmod>
    <changefreq><?= htmlspecialchars($u['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></changefreq>
    <priority><?= htmlspecialchars($u['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
