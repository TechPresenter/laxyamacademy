<?php
// /admin/ entry point.
declare(strict_types=1);

require __DIR__ . '/../api/config.php';
$me = current_user();
header('Location: ' . ($me && $me['user_type'] === 'staff' ? 'dashboard.php' : 'login.php'), true, 303);
exit;
