<?php
namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/** @var WP_Post $post - Externally provided */
$status = Dispatcher::get_post_meta($post->ID, 'status', true) ?: 'draft';
$total = (int) Dispatcher::get_post_meta($post->ID, 'audience_total', true);
$sent = (int) Dispatcher::get_post_meta($post->ID, 'sent', true);
$percent = '?';
if ($total) {
    $percent = floor($sent * 100.0 / $total);
}

echo '<p style="font-size: 1.2em">';
if ($status === 'sending') {
    //$next = wp_next_scheduled('dispatcher_run');
    echo '<p style="float: left; text-align: left; font-size: 1.2em">', esc_html(ucfirst($status)), ' (', $percent, '%)</p>';
    //echo '<small>Next batch will be sent in ', dispatcher_format_interval($next - time()), '</small>';
} else {
    echo esc_html(ucfirst($status));
}
echo '</p>';

echo '<p>';

echo '<input type="submit" name="dispatcher_test" value="Test" class="button button-secondary button-large" onclick="fn_dispatcher_action(\'test\'); return false;"> ';

if ($status === 'sending') {
    echo '<input type="submit" name="dispatcher_stop" value="Stop" class="button button-secondary button-large" onclick="return fn_dispatcher_action(\'stop\')">';
    echo ' <input type="submit" name="dispatcher_pause" value="Pause" class="button button-primary button-large" onclick="return fn_dispatcher_action(\'pause\')">';
} elseif ($status === 'draft') {
    echo '<input type="submit" name="dispatcher_send" value="Send" class="button button-primary button-large" onclick="return fn_dispatcher_action(\'send\')">';
} elseif ($status === 'paused') {
    echo '<input type="submit" name="dispatcher_continue" value="Continue" class="button button-secondary button-large" onclick="return fn_dispatcher_action(\'continue\')">';
    echo ' <input type="submit" name="dispatcher_stop" value="Stop" class="button button-primary button-large" onclick="return fn_dispatcher_action(\'stop\')">';
} elseif ($status === 'sent') {
    echo '<input type="submit" name="dispatcher_resend" value="Resend" class="button button-primary button-large" onclick="return fn_dispatcher_action(\'resend\')">';
}
echo '</p>';

echo '<p>';
if ($status === 'sending') {
    $next = wp_next_scheduled('dispatcher_run');
    echo '<small>Next batch will be sent in ', dispatcher_format_interval($next - time()), '</small>';
} elseif ($status === 'sent') {
    $send_time = DispatcherAdmin::get_post_meta($post->ID, 'send_time');
    if ($send_time) {
        echo 'Sent on ', wp_date(get_option('date_format') . ' ' . get_option('time_format'), $send_time),
        ' to ', intval(DispatcherAdmin::get_post_meta($post->ID, 'audience_total')), ' users';
    }
}
echo '</p>';

echo '<input type="hidden" name="dispatcher_action" id="dispatcher-action" value="">';
