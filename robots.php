<?php
/**
 * Dynamic robots.txt — Sitemap URL from SITE_URL.
 * Served as /robots.txt via .htaccess rewrite.
 */
require_once __DIR__ . '/app/init.php';
$siteUrl = Seo::siteUrl() !== '' ? Seo::siteUrl() : 'https://smm-turk.com';
header('Content-Type: text/plain; charset=UTF-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Allow: /pricing\n";
echo "Allow: /earn\n";
echo "Allow: /blog\n";
echo "Allow: /help\n";
echo "Allow: /terms\n";
echo "Disallow: /admin/\n";
echo "Disallow: /dashboard\n";
echo "Disallow: /settings\n";
echo "Disallow: /funds\n";
echo "Disallow: /api-docs\n";
echo "Disallow: /api/\n";
echo "Disallow: /orders\n";
echo "Disallow: /services\n";
echo "Disallow: /tickets\n";
echo "Disallow: /mass-order\n";
echo "Disallow: /affiliates\n";
echo "Disallow: /child-panel\n";
echo "Disallow: /login\n";
echo "Disallow: /login-2fa\n";
echo "Disallow: /verify-email\n";
echo "Disallow: /reset-password\n";
echo "Disallow: /forgot-password\n";
echo "Disallow: /c/\n";
echo "Disallow: /health\n";
echo "Disallow: /payment-\n";
echo "Disallow: /cron-\n";
echo "Disallow: /logout\n";
echo "Disallow: /404\n\n";
echo "Sitemap: " . $siteUrl . "/sitemap.xml\n";
