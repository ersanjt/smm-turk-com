<?php
/**
 * Health check endpoint for monitoring (uptime, load balancer).
 * Does not start session or load full app. Returns 200 if DB is reachable.
 * Usage: GET /health or /health.php
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

define('ROOT_PATH', __DIR__);

$out = ['status' => 'ok', 'db' => 'unknown'];

try {
    if (!file_exists(ROOT_PATH . '/config.php')) {
        $out['status'] = 'error';
        $out['db'] = 'no_config';
        header('HTTP/1.1 503 Service Unavailable');
        echo json_encode($out);
        exit;
    }
    require_once ROOT_PATH . '/config.php';
    require_once ROOT_PATH . '/app/Logger.php';
    require_once ROOT_PATH . '/app/Database.php';
    $db = Database::getInstance();
    $db->fetch('SELECT 1');
    $out['db'] = 'ok';
    header('HTTP/1.1 200 OK');
} catch (Throwable $e) {
    $out['status'] = 'error';
    $out['db'] = 'fail';
    if (class_exists('Logger')) {
        Logger::log('Health check failed: ' . $e->getMessage(), 'health');
    }
    header('HTTP/1.1 503 Service Unavailable');
}

echo json_encode($out);
