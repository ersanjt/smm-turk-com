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

$addUrl = static function (string $loc, string $lastmod, string $freq, string $priority, bool $hreflang = true, string $image = '') use (&$urls, &$seen): void {
    if (isset($seen[$loc])) {
        return;
    }
    $seen[$loc] = true;
    $urls[] = [
        'loc' => $loc,
        'lastmod' => $lastmod,
        'changefreq' => $freq,
        'priority' => $priority,
        'hreflang' => $hreflang,
        'image' => $image,
    ];
};

// Public marketing pages
$staticLastmod = @filemtime(__DIR__ . '/home.php');
$staticLastmod = $staticLastmod ? date('Y-m-d', $staticLastmod) : date('Y-m-d');
$static = [
    '/' => ['freq' => 'daily', 'priority' => '1.0', 'file' => 'home.php'],
    '/get-app' => ['freq' => 'weekly', 'priority' => '0.9', 'file' => 'get-app.php'],
    '/earn' => ['freq' => 'weekly', 'priority' => '0.92', 'file' => 'earn.php'],
    '/pricing' => ['freq' => 'daily', 'priority' => '0.95', 'file' => 'pricing.php'],
    '/buy' => ['freq' => 'daily', 'priority' => '0.96', 'file' => 'buy.php'],
    '/help' => ['freq' => 'weekly', 'priority' => '0.85', 'file' => 'help.php'],
    '/blog' => ['freq' => 'daily', 'priority' => '0.9', 'file' => 'blog.php'],
    '/terms' => ['freq' => 'yearly', 'priority' => '0.5', 'file' => 'terms.php'],
];
foreach ($static as $path => $meta) {
    $mtime = !empty($meta['file']) && is_file(__DIR__ . '/' . $meta['file'])
        ? date('Y-m-d', (int) filemtime(__DIR__ . '/' . $meta['file']))
        : $staticLastmod;
    $addUrl($base . $path, $mtime, $meta['freq'], $meta['priority']);
}

if (class_exists('GoogleAcquisition', false)) {
    foreach (GoogleAcquisition::catalog() as $buyPage) {
        $addUrl($base . '/buy/' . rawurlencode((string) $buyPage['slug']), is_file(__DIR__ . '/buy.php') ? date('Y-m-d', (int) filemtime(__DIR__ . '/buy.php')) : date('Y-m-d'), 'daily', '0.9');
    }
}

try {
    $posts = $db->fetchAll(
        "SELECT slug, updated_at, published_at, featured_image FROM blog_articles WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW() ORDER BY published_at DESC"
    );
    foreach ($posts as $row) {
        $lastmod = !empty($row['updated_at']) ? $row['updated_at'] : $row['published_at'];
        $image = trim((string) ($row['featured_image'] ?? ''));
        if ($image !== '' && !preg_match('#^https?://#i', $image)) {
            $image = Seo::absoluteUrl($image);
        }
        $addUrl(
            $base . '/blog/' . rawurlencode((string) $row['slug']),
            date('Y-m-d', strtotime((string) $lastmod)),
            'weekly',
            '0.8',
            false,
            $image
        );
    }
} catch (Throwable $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc><?php if (!isset($u['hreflang']) || $u['hreflang']): ?><?= Seo::sitemapHreflangLinks($u['loc']) ?><?php endif; ?>

    <lastmod><?= htmlspecialchars($u['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></lastmod>
    <changefreq><?= htmlspecialchars($u['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></changefreq>
    <priority><?= htmlspecialchars($u['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></priority>
    <?php if (!empty($u['image'])): ?>
    <image:image>
      <image:loc><?= htmlspecialchars($u['image'], ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></image:loc>
    </image:image>
    <?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>
