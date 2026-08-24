<?php
/**
 * Admin bootstrap — require instead of duplicating init + requireAdmin in each file.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$auth->requireAdmin();
