<?php
// logout.php
require_once __DIR__ . '/app/init.php';
$_SESSION = [];
session_destroy();
header('Location: ' . url('login.php'));
exit;
