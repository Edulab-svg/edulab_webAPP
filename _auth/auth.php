<?php

function portal_safe_redirect_path($url) {
    if (!is_string($url) || $url === '') {
        return '/';
    }
    if (strpos($url, '/') !== 0) {
        return '/';
    }
    if (isset($url[1]) && $url[0] === '/' && $url[1] === '/') {
        return '/';
    }
    return $url;
}

function portal_is_logged_in() {
    return !empty($_SESSION['portal_user_id']);
}

function portal_require_login() {
    if (portal_is_logged_in()) {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /login.php?redirect=' . rawurlencode($uri));
    exit;
}

function portal_set_csrf_token() {
    if (empty($_SESSION['portal_csrf'])) {
        $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['portal_csrf'];
}

function portal_verify_csrf($token) {
    return is_string($token) && !empty($_SESSION['portal_csrf']) && hash_equals($_SESSION['portal_csrf'], $token);
}
