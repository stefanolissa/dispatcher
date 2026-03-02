<?php

// Vanilla PHP/WP here, do not rely on the plugin code.

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('dispatcher_settings');
delete_option('dispatcher_update_data');
wp_unschedule_hook('dispatcher_run');
