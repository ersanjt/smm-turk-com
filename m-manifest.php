<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
header('Content-Type: application/manifest+json; charset=UTF-8');
$lang = Lang::initPublic();
$siteName = Seo::siteName();
$start = preg_replace('#m\.php$#', 'm', path('m.php')) ?: path('m.php');
$scope = rtrim($start, '/') . '/';
if (!str_ends_with($scope, '/')) {
    $scope .= '/';
}
$base = base_path() !== '' ? base_path() . '/' : '/';
echo json_encode([
    'name' => $siteName . ' App',
    'short_name' => $siteName,
    'description' => $siteName . ' — SMM panel on your phone',
    'lang' => Seo::htmlLang($lang),
    'start_url' => $start,
    'scope' => $base,
    'display' => 'standalone',
    'background_color' => '#1a0a0e',
    'theme_color' => '#E30A17',
    'orientation' => 'portrait-primary',
    'icons' => [
        ['src' => asset_url('assets/img/logo-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => asset_url('assets/img/logo-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
