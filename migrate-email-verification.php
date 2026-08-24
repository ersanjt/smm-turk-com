<?php
/**
 * One-time migration: enable email verification setting for existing installs.
 * Run once from CLI: php migrate-email-verification.php
 */
require_once __DIR__ . '/app/init.php';
require_cli();
$db = Database::getInstance();

$existing = $db->getSetting('email_verification_required');
if ($existing === null || $existing === '') {
    $db->setSetting('email_verification_required', '1');
    echo "Set email_verification_required = 1\n";
} else {
    echo "email_verification_required already set to: {$existing}\n";
}

echo "Migration done.\n";
