<?php
/**
 * Dynamic Open Graph image generator (1200x630) for blog posts.
 * Renders the article title over a branded background so shared links
 * look great on social media. Falls back to the default OG image when
 * GD or a usable TTF font is unavailable.
 */
require_once __DIR__ . '/app/init.php';

$slug = isset($_GET['slug']) ? trim(preg_replace('/[^a-z0-9\-]/', '', (string) $_GET['slug'])) : '';

$fallback = function () {
    header('Location: ' . og_image_url());
    exit;
};

if ($slug === '' || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
    $fallback();
}

$db = Database::getInstance();
$post = $db->fetch(
    "SELECT a.title, a.updated_at, c.name AS category_name
     FROM blog_articles a
     LEFT JOIN blog_categories c ON c.id = a.category_id
     WHERE a.slug = ? AND a.status = 'published' AND a.published_at IS NOT NULL AND a.published_at <= NOW()",
    [$slug]
);
if (!$post) {
    $fallback();
}

$fontCandidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    '/usr/share/fonts/gnu-free/FreeSansBold.ttf',
];
$fontRegularCandidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
    '/usr/share/fonts/gnu-free/FreeSans.ttf',
];
$fontBold = '';
foreach ($fontCandidates as $f) {
    if (is_readable($f)) { $fontBold = $f; break; }
}
$fontRegular = '';
foreach ($fontRegularCandidates as $f) {
    if (is_readable($f)) { $fontRegular = $f; break; }
}
if ($fontBold === '') {
    $fontBold = $fontRegular;
}
if ($fontBold === '') {
    $fallback();
}
if ($fontRegular === '') {
    $fontRegular = $fontBold;
}

$title = trim((string) $post['title']);
$category = strtoupper(trim((string) ($post['category_name'] ?? '')));
$siteName = function_exists('site_name') ? site_name() : (defined('SITE_NAME') ? SITE_NAME : 'SMM Turk');

$cacheDir = __DIR__ . '/assets/img/og-cache';
$cacheKey = 'blog-' . $slug . '-' . substr(md5($title . '|' . $category . '|' . (string) ($post['updated_at'] ?? '') . '|' . $siteName), 0, 10) . '.png';
$cacheFile = $cacheDir . '/' . $cacheKey;

$sendPng = function (string $file) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
};

if (is_readable($cacheFile)) {
    $sendPng($cacheFile);
}

$W = 1200;
$H = 630;
$img = imagecreatetruecolor($W, $H);
imageantialias($img, true);

// Vertical gradient background: #1a0a0e -> #2d1519
$top = [0x1a, 0x0a, 0x0e];
$bot = [0x2d, 0x15, 0x19];
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H;
    $r = (int) round($top[0] + ($bot[0] - $top[0]) * $t);
    $g = (int) round($top[1] + ($bot[1] - $top[1]) * $t);
    $b = (int) round($top[2] + ($bot[2] - $top[2]) * $t);
    $col = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $W, $y, $col);
}

// Brand accent bar on the left
$primary = imagecolorallocate($img, 0xE3, 0x0A, 0x17);
imagefilledrectangle($img, 0, 0, 14, $H, $primary);

// Soft red glow (top-right)
$glow = imagecolorallocatealpha($img, 0xE3, 0x0A, 0x17, 95);
for ($i = 0; $i < 260; $i += 6) {
    imagefilledellipse($img, $W - 160, 150, 520 - $i, 420 - $i, $glow);
}

$white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
$muted = imagecolorallocate($img, 0xC9, 0xB8, 0xBC);
$accent = imagecolorallocate($img, 0xFF, 0x5A, 0x66);

$marginX = 80;
$maxTextWidth = $W - $marginX - 120;

// Word-wrap helper using the chosen font at a given size.
$wrap = function (string $text, float $size, string $font, int $maxWidth): array {
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        $box = imagettfbbox($size, 0, $font, $try);
        $w = abs($box[2] - $box[0]);
        if ($w > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
};

// Category / eyebrow
$y = 150;
if ($category !== '') {
    imagettftext($img, 22, 0, $marginX, $y, $accent, $fontBold, $category);
    $y += 40;
}

// Fit the title: shrink size until it fits within ~4 lines
$titleSize = 64.0;
$lines = [];
for ($attempt = 0; $attempt < 6; $attempt++) {
    $lines = $wrap($title, $titleSize, $fontBold, $maxTextWidth);
    if (count($lines) <= 4) {
        break;
    }
    $titleSize -= 8;
}
if (count($lines) > 4) {
    $lines = array_slice($lines, 0, 4);
    $lines[3] = rtrim(mb_substr($lines[3], 0, 40)) . '…';
}

$lineHeight = (int) round($titleSize * 1.32);
$y += (int) round($titleSize);
foreach ($lines as $ln) {
    imagettftext($img, $titleSize, 0, $marginX, $y, $white, $fontBold, $ln);
    $y += $lineHeight;
}

// Footer: site name + domain
$footerY = $H - 70;
imagefilledrectangle($img, $marginX, $footerY - 30, $marginX + 44, $footerY + 4, $primary);
imagettftext($img, 30, 0, $marginX + 60, $footerY, $white, $fontBold, $siteName);
$host = parse_url(defined('SITE_URL') ? SITE_URL : '', PHP_URL_HOST) ?: '';
if ($host !== '') {
    imagettftext($img, 20, 0, $marginX + 60, $footerY + 34, $muted, $fontRegular, $host);
}

// Persist to cache when possible, otherwise stream directly.
$stored = false;
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    if (imagepng($img, $cacheFile, 6)) {
        $stored = true;
    }
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=2592000, immutable');
if ($stored && is_readable($cacheFile)) {
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
} else {
    imagepng($img, null, 6);
}
imagedestroy($img);
exit;
