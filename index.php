<?php
/**
 * DirectoryIndex entry — Apache serves this for /. Do not redirect guests to / (loop).
 * Logged-in users go to panel; guests load the public landing page.
 */
require_once __DIR__ . '/app/init.php';

if ($auth->isLoggedIn()) {
    redirect(url('dashboard.php'));
}

require __DIR__ . '/home.php';
