<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

global $wpdb, $charset_collate;

require_once ABSPATH . 'wp-admin/includes/upgrade.php'; // Isn't there a constant for the admin inclusion path?

$sql = "CREATE TABLE `" . $wpdb->prefix . "dispatcher_audiences` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted` int(11) NOT NULL DEFAULT 0,
            `type` VARCHAR(50) NOT NULL DEFAULT '',
            `name` VARCHAR(100) NOT NULL DEFAULT '',
            `settings` LONGTEXT,
            PRIMARY KEY (`id`)
            ) $charset_collate;";

dbDelta($sql);
if ($wpdb->last_error) {
    error_log($wpdb->last_error);
}

$sql = "CREATE TABLE `" . $wpdb->prefix . "dispatcher_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `type` VARCHAR(50) NOT NULL DEFAULT '',
            `level` int(11) NOT NULL DEFAULT 0,
            `message` TEXT,
            `data` LONGTEXT,
            PRIMARY KEY (`id`)
            ) $charset_collate;";

dbDelta($sql);
if ($wpdb->last_error) {
    error_log($wpdb->last_error);
}

