<?php
/**
 * Simple file logger. Writes to tmp/logs/{channel}.log
 * Ensure tmp/logs/ exists and is writable.
 */
class Logger {
    private static function logDir(): string {
        return defined('ROOT_PATH') ? ROOT_PATH . '/tmp/logs' : __DIR__ . '/../tmp/logs';
    }

    public static function log(string $message, string $channel = 'app'): void {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . preg_replace('/[^a-z0-9_-]/', '_', $channel) . '.log';
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
