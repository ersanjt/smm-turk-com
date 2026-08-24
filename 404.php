<?php
/**
 * Custom 404 — SEO-friendly not found page.
 */
http_response_code(404);
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';

$lang = Lang::initPublic();
$pageTitle = __('404_title');
$pageDescription = __('404_desc');
$blogNavActive = '';
$seoIndexable = false;
$canonicalUrl = Seo::absoluteUrl('/404');

require_once __DIR__ . '/layouts/blog-header.php';
?>

<main class="blog-article-wrap" role="main" style="max-width:640px;margin:0 auto;padding:48px 20px;text-align:center;">
  <h1 style="font-size:2rem;margin:0 0 12px;"><?= h(__('404_title')) ?></h1>
  <p style="color:var(--text-muted, #64748b);line-height:1.7;margin-bottom:28px;">
    <?= h(__('404_desc')) ?>
  </p>
  <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;">
    <a href="<?= h(home_path()) ?>" class="blog-nav-cta" style="display:inline-block;padding:12px 24px;">← Home</a>
    <a href="<?= h(path('blog.php')) ?>" style="padding:12px 24px;border:1px solid var(--border,#e2e8f0);border-radius:8px;">Blog</a>
    <a href="<?= h(path('help.php')) ?>" style="padding:12px 24px;border:1px solid var(--border,#e2e8f0);border-radius:8px;">Help</a>
    <a href="<?= h(route_path('login.php')) ?>" style="padding:12px 24px;border:1px solid var(--border,#e2e8f0);border-radius:8px;">Login</a>
  </div>
</main>

<?php require_once __DIR__ . '/layouts/blog-footer.php'; ?>
