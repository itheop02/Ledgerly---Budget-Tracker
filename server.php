<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pages = ['/' => 'index.php', '/index.php' => 'index.php', '/gdpr.php' => 'gdpr.php', '/auth/login.php' => 'auth/login.php', '/auth/register.php' => 'auth/register.php', '/auth/logout.php' => 'auth/logout.php', '/dashboard.php' => 'dashboard.php', '/transactions.php' => 'transactions.php', '/add-transaction.php' => 'add-transaction.php', '/delete-transaction.php' => 'delete-transaction.php', '/profile.php' => 'profile.php', '/contact.php' => 'contact.php', '/api/transactions.php' => 'api/transactions.php'];
if (isset($pages[$path])) { require __DIR__.'/'.$pages[$path]; return true; }
$types = ['css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
foreach (['/css/', '/js/', '/images/'] as $folder) {
    if (str_starts_with($path, $folder)) {
        $file = realpath(__DIR__.$path); $base = realpath(__DIR__.$folder); $ext = pathinfo($file ?: '', PATHINFO_EXTENSION);
        if ($file && $base && str_starts_with($file, $base.DIRECTORY_SEPARATOR) && is_file($file) && isset($types[$ext])) { header('Content-Type: '.$types[$ext]); readfile($file); return true; }
    }
}
http_response_code(404); echo 'Not found';
