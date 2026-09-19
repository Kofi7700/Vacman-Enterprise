<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    log_audit('logout', 'user', (int) current_user()['id']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
redirect('login.php');
