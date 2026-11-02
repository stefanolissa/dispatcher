<?php
defined('ABSPATH') || exit;

global $wpdb;

$subpage = $_GET['subpage'] ?? '';

switch ($subpage) {
    case 'logs':
        include __DIR__ . '/logs.php';
        return;
//    case 'view':
//        include __DIR__ . '/view.php';
//        return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('dispatcher-action');

    if (isset($_POST['save'])) {
        // TODO: Add kses, unslash, ...
        $settings = wp_unslash($_POST['data']);

        $settings['from_email'] = sanitize_email($settings['from_email']);
        //$settings['from_name'] = sanitize_text_field($settings['from_name']);
        $settings['speed'] = intval($settings['speed']);
        $settings['speed'] = $settings['speed'] ?: 100;
        $settings['speed'] = max($settings['speed'], 4);

        $settings['delay'] = max(intval($settings['delay'] ?? 0), 0);

        $settings['from_email'] = strtolower(sanitize_email($settings['from_email']));
        $settings['redirect_email'] = strtolower(sanitize_email($settings['redirect_email']));
        //$settings['test_email'] = strtolower(sanitize_email($settings['test_email'] ?? ''));

        update_option('dispatcher_settings', $settings, false);
    }

    if (isset($_POST['run'])) {
        Dispatcher::run();
    }
}

$action = $_GET['action'] ?? '';

if ($action) {
    check_admin_referer('dispatcher-action');

    if ($action === 'delete-logs') {
        $secret = sanitize_key(get_option('dispatcher_secret'));
        $file = __DIR__ . '/../' . $secret . '.txt';
        unlink($file);
        echo '<script>location.href="?post_type=dispatcher_email&page=dispatcher";</script>';
        return;
    }

    if ($action === 'reset-lock') {
        update_option('dispatcher_lock', 0, false);
    }
}

$settings = get_option('dispatcher_settings', []);
// Thank you to (some) object cache plugin ignoring the default value on missing options
if (!is_array($settings)) {
    $settings = [];
}
$user_count = $wpdb->get_var("select count(*) from {$wpdb->users}");
?>

<style>
    #dsp-gutenberg-description, #dsp-gutenberg-all-blocks, #dsp-gutenberg-debug {
        display: none;
    }
    #dsp-gutenberg:checked ~ #dsp-gutenberg-description {
        display: block;
    }
    #dsp-gutenberg:checked ~ #dsp-gutenberg-all-blocks {
        display: block;
    }

    #dsp-gutenberg:checked ~ #dsp-gutenberg-debug {
        display: block;
    }
</style>

<div class="wrap">
    <h2>Settings</h2>
    <p>
        <a href="https://www.satollo.net/plugins/dispatcher" target="_blank">Read something interesting</a>, the AI doesn't solve
        every problems.
    <p>
        If emails are not delivered, install WP Mail Logging to see if they're processed by WP. If they are, contact
        your hosting provider for a check of dropped emails by its mailing service.
    </p>
    <p>
        It's recommended to install an SMTP plugin and connect the site to an SMTP service, maybe the one
        available with your hosting provider.
    </p>

    <form method="post">

        <?php wp_nonce_field('dispatcher-action'); ?>

        <table class="form-table">

            <tbody>

                <tr>
                    <th>
                        Sender email
                    </th>
                    <td>
                        <input type="email" name="data[from_email]" size="40" value="<?= esc_attr($settings['from_email'] ?? ''); ?>" placeholder="Valid email address">
                        <p class="description"></p>
                    </td>

                </tr>
                <tr>
                    <th>
                        Speed
                    </th>
                    <td>
                        <input type="text" id="data-speed" name="data[speed]" size="5" value="<?= esc_attr($settings['speed'] ?? '100'); ?>" placeholder="100"> emails per hour

                        <p class="description">
                            Be sure to respect the hosting provider limits.
                        </p>
                    </td>
                </tr>


                <!--
                <tr>
                    <th>
                        Test email
                    </th>
                    <td>
                        <input type="email" name="data[test_email]" value="<?= esc_attr($settings['test_email'] ?? ''); ?>" placeholder="">
                        <p class="description">Not working. Where test emails are sent.</p>
                    </td>

                </tr>
                -->

                <tr>
                    <th>
                        Gutenberg
                    </th>
                    <td>
                        <!--
                                                <p class="description" id="dsp-gutenberg-description">
                                                    To use both Gutenberg and the old editor, install the
                                                    <a href="https://wordpress.org/plugins/classic-editor/" target="_blank">Classic Editor</a> plugin.
                                                    <br>
                                                    Warning: Gutenberg produce modern HTML not compatibile with many email readers and required a lot of styles
                                                    that cannot be included into emails. But if you stick on paragraph and titles it may work.
                                                </p>
                        -->
                        <div style="margin-bottom: 1rem">
                            <input type="checkbox" name="data[gutenberg_all_blocks]" value="1" <?= isset($settings['gutenberg_all_blocks']) ? 'checked' : '' ?>>
                            Show unsupported Gutenberg blocks
                            <p class="description">
                                They typically do not render correctly on email clients.
                            </p>
                        </div>
                        <?php if (WP_DEBUG) { ?>
                            <div style="margin-bottom: 1rem">
                                <input type="checkbox" name="data[gutenberg_debug]" value="1" <?= isset($settings['gutenberg_debug']) ? 'checked' : '' ?>>
                                Add debug information on rendered emails
                                <p class="description">
                                    Use only for development, testing, debugging!
                                </p>
                            </div>
                        <?php } ?>
                    </td>
                </tr>


<!--                <tr>
                    <th>
                        Redirect all emails to
                    </th>
                    <td>
                        <input type="email" name="data[redirect_email]" size="40" id="dsp-redirect_email" value="<?= esc_attr($settings['redirect_email'] ?? ''); ?>" placeholder="Valid email">
                        <p class="description">If set all emails are sent to this address instead of the real recipient. Use for tests.</p>
                    </td>
                </tr>-->

                <tr>
                    <th>
                        Dry run
                    </th>
                    <td>
                        <input type="checkbox" name="data[dry_run]" value="1" <?= isset($settings['dry_run']) ? 'checked' : '' ?>>
                        <a href="<?php echo esc_attr(wp_nonce_url('?post_type=dispatcher_email&page=dispatcher-logs', 'dispatcher-action')) ?>" target="_blank">View log file</a>
                        <p class="description">
                            When you send an email, no messages are sent and a log file is created so you can test the audience.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>
                        Debug
                    </th>
                    <td>
                        <input type="checkbox" name="data[debug]" value="1" <?= isset($settings['debug']) ? 'checked' : '' ?>>
                        <p class="description">
                            Detailed information are recorded on the logs and possibly on the server "error_log".
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>
                        Lock
                    </th>
                    <td>
                        <?= (int) get_option('dispatcher_lock', 0); ?>

                        <?= esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), get_option('dispatcher_lock', 0))) ?>
                        <a href="<?php echo esc_attr(wp_nonce_url('?post_type=dispatcher_email&page=dispatcher-settings&action=reset-lock', 'dispatcher-action')) ?>" onclick="return confirm('Proceed?')">Reset lock</a>

                    </td>
                </tr>
                </td>
                </tr>
                <!--
                <tr>
                    <th>
                        Delay between emails
                    </th>
                    <td>
                        <input type="text" name="data[delay]" size="5" value="<?= esc_attr($settings['delay'] ?? ''); ?>" placeholder="0"> milliseconds
                        <p class="description">
                            Set if the hosting provider requires you to send an email not often than X seconds. For example if they ask
                            to send max one email every 2 seconds, set it to 2000.<br>
                            If they ask to not send more than 10 emails per second, set to 1000/10 = 100.
                        </p>
                    </td>
                </tr>
                -->
                <!--
                <tr>
                    <th>
                        wp-cron.php URL
                    </th>
                    <td>
                        <code><?= esc_html(site_url('/wp-cron.php')) ?></code>
                        <p class="description">To be used to trigger the WP scheduler.</p>
                    </td>
                </tr>
                -->
                <!--
                <tr>
                    <th>
                        Enable AI abilities
                    </th>
                    <td>
                        <input type="checkbox" name="data[abilities]" <?= isset($settings['abilities']) ? 'checked' : '' ?>>
                        <p class="description">
                            This is something experimental and you need an AI plugin able to use that feature made available by this plugin.
                        </p>
                    </td>
                </tr>-->

            </tbody>
        </table>

        <p>
            <button name="save" class="button button-primary">Save</button>
            <?php if (WP_DEBUG) { ?>
                <button name="run" class="button button-secondary">Run</button>

            <?php } ?>
        </p>

    </form>
    <?php if (WP_DEBUG) { ?>

        <h3>Debug</h3>
        <p>
            That helps me when supporting you...
        </p>
        <pre><?php echo esc_html(print_r(get_option('dispatcher_settings'), true)); ?></pre>
    <?php } ?>
</div>
