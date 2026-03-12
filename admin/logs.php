<?php
defined('ABSPATH') || exit;

global $wpdb;

$secret = sanitize_key(get_option('dispatcher_secret'));
$file = __DIR__ . '/../' . $secret . '.txt';

check_admin_referer('logs');
if (!file_exists($file)) {
    die('There is no log file');
}

echo '<pre>';
echo esc_html(file_get_contents($file));
echo '</pre>';
