<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var \wpdb $wpdb */
global $wpdb;

$audience = Dispatcher::get_audience((int) $_GET['id']);

if (!$audience) {
    die('Audience not found');
}

$audience_type = Dispatcher::get_audience_type($audience->type);
if (!$audience_type) {
    die('Audience type not registered');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('dispatcher-action');

    if (isset($_POST['save'])) {
        $settings = wp_unslash($_POST['settings']);
        $name = wp_strip_all_tags(wp_unslash($_POST['name']));

        if (method_exists($audience_type, 'validate_settings')) {
            $settings = $audience_type->validate_settings($settings);
        }

        $wpdb->update($wpdb->prefix . 'dispatcher_audiences', ['name' => $name, 'settings' => serialize($settings)], ['id' => $audience->id]);
        $audience = Dispatcher::get_audience($audience->id);
    }
} else {
    $settings = $audience->settings ?? [];
}

$controls = new Controls($settings);
$total = Dispatcher::get_audience_total($audience);
?>

<div class="wrap">
    <h2><?= esc_html($audience->name) ?> (<?= esc_html($total) ?>)</h2>

    <form method="post">
        <?php wp_nonce_field('dispatcher-action'); ?>

        <p>
            <input type="text" name="name" value="<?= esc_attr($audience->name) ?>" style="width: 500px;">
        </p>

        <?php
        if (method_exists($audience_type, 'settings_form')) {
            $audience_type->settings_form($controls);
        }
        ?>

        <?php //do_action('dispatcher_audience_settings_form', $audience);  ?>

        <p>
            <button name="save" class="button button-primary">Save</button>
        </p>
    </form>

    <?php if (WP_DEBUG) { ?>

        <h3>Debug</h3>
        <p>
            That helps me when supporting you...
        </p>
        <pre><?php echo esc_html(print_r($audience, true)); ?></pre>
    <?php } ?>

</div>

