<?php
// Local preview only: php -S localhost:8000 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/(content|data|lib|tools|vendor)(/|$)#', $path) || preg_match('#^/composer\.(json|lock)$#', $path) || preg_match('#^/uploads/.*\.(php|phtml|html?|svg)$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    if (is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
        return false;
    }
    if ($path === '/admin/oauth.php' || $path === '/admin/calendar-oauth.php') {
        require __DIR__ . $path;
        return true;
    }
    require __DIR__ . '/admin/index.php';
    return true;
}
if ($path !== '/' && is_file(__DIR__ . $path) && !str_ends_with($path, '.php')) {
    return false;
}
require __DIR__ . '/index.php';
