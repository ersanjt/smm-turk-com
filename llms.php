<?php
/**
 * llms.txt — guidance for AI crawlers / agents (Markdown).
 * Served as /llms.txt via .htaccess rewrite.
 */
require_once __DIR__ . '/app/init.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$siteUrl = Seo::siteUrl() !== '' ? Seo::siteUrl() : 'https://smm-turk.com';
$siteName = Seo::siteName();
$base = $siteUrl . (function_exists('base_path') ? base_path() : '');

echo "# {$siteName}\n\n";
echo "> Cheap SMM panel for Instagram, TikTok, YouTube and more. Crypto deposits, reseller API, 24/7 support.\n\n";
echo "Primary market: Turkey. Service area: worldwide. Languages: Turkish (default), English, German via `?lang=`.\n\n";

echo "## Public pages\n\n";
echo "- [Home]({$base}/): Marketing landing and sign-in\n";
echo "- [Pricing]({$base}/pricing): Live SMM service prices\n";
echo "- [Earn]({$base}/earn): Reseller, affiliate, child panel\n";
echo "- [Blog]({$base}/blog): Guides and growth articles\n";
echo "- [Help]({$base}/help): Getting started and FAQ\n";
echo "- [Terms]({$base}/terms): Terms of service\n\n";

echo "## Optional\n\n";
echo "- [Sitemap]({$base}/sitemap.xml)\n";
echo "- [Robots]({$base}/robots.txt)\n\n";

echo "## Notes for crawlers\n\n";
echo "- Do not index authenticated panel pages (`/dashboard`, `/orders`, `/funds`, `/login`, `/admin`).\n";
echo "- Blog article bodies are primarily Turkish; UI chrome may switch language without translating posts.\n";
echo "- Preferred citation: {$siteName} ({$base}/)\n";
