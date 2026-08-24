<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/Lang.php';
header('Content-Type: application/manifest+json; charset=UTF-8');
$lang = Lang::initPublic();
$siteName = Seo::siteName();
$start = home_path();
$scope = base_path() !== '' ? base_path() . '/' : '/';
$customLogo = class_exists('Database') ? trim((string) (Database::getInstance()->getSetting('site_logo') ?? '')) : '';
if ($customLogo !== '') {
    $manifestIcons = [
        ['src' => logo_url(), 'sizes' => 'any', 'type' => 'image/png', 'purpose' => 'any'],
    ];
} else {
    $manifestIcons = [
        ['src' => asset_url('assets/img/logo-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => asset_url('assets/img/logo-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => asset_url('assets/img/logo-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
}
echo json_encode([
    'name' => $siteName,
    'short_name' => $siteName,
    'description' => __('manifest_desc'),
    'lang' => Seo::htmlLang($lang),
    'dir' => 'ltr',
    'start_url' => $start,
    'scope' => $scope,
    'display' => 'standalone',
    'background_color' => '#1a0a0e',
    'theme_color' => '#E30A17',
    'orientation' => 'portrait-primary',
    'icons' => $manifestIcons,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
