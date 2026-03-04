<?php
defined('ABSPATH') || exit;

add_action('admin_enqueue_scripts', function ($hook_suffix) {
    wp_enqueue_style('dispatcher', plugin_dir_url(__FILE__) . '../admin.css', [], DISPATCHER_VERSION);
});

add_filter('manage_dsp_email_posts_columns', function ($columns) {
    $columns['status'] = 'Status';
    $columns['send_time'] = 'Sending date';
    $columns['audience'] = 'Audience';
    $columns['id'] = 'ID';

    return $columns;
});

add_action('manage_dsp_email_posts_custom_column', function ($column, $post_id) {
    global $wpdb;
    switch ($column) {
        case 'id' :
            echo (int) $post_id;
            break;
        case 'audience' :
            $audience = Dispatcher::get_audience(get_post_meta($post_id, 'dsp_audience_id', true));
            $params = dispatcher_build_audience_params($post_id);
            if ($audience) {
                echo esc_html($audience->get_name());
                if (method_exists($audience, 'get_target_description')) {
                    echo '<div style="font-size: .9em">', esc_html($audience->get_target_description($params)), '</div>';
                }
            } else {
                echo 'None';
            }
            break;
        case 'status' :
            $status = Dispatcher::get_post_meta($post_id, 'status') ?: 'draft';
            echo '<span class="dsp-status dsp-status-', esc_attr($status), '">';
            echo esc_html(ucfirst($status));
            if ($status === 'sending') {
                $total = (int) Dispatcher::get_post_meta($post_id, 'audience_total') ?: 0;
                $sent = (int) Dispatcher::get_post_meta($post_id, 'sent') ?: 0;

                if ($total > 0) {
                    $percent = floor($sent * 100.0 / $total);
                } elseif ($total < 0) {
                    $percent = '??';
                } else {
                    $percent = '100';
                }

                echo ' (', esc_html($percent), '%)';
            }
            echo '</span>';
            if (WP_DEBUG) {
                echo $sent, '/', $total;
            }
            break;

        case 'send_time' :
            $send_time = get_post_meta($post_id, 'dispatcher_send_time', true);
            if ($send_time) {
                echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), $send_time);
            }
            break;
    }
}, 10, 2);

add_action('admin_notices', function () {

    $emails = get_posts(['post_type' => Dispatcher::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => 'dsp_status',
                'value' => 'sending',
                'compare' => '=’',
            ]],
        'meta_key' => 'dsp_send_time', 'orderby' => 'meta_value_num', 'order' => 'ASC']);

    if ($emails) {
        $next = wp_next_scheduled('dispatcher_run');
        if ($next < time() - 900) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    Sending... but the WordPress scheduler is late.
                    <a href="https://www.satollo.net/plugin/dispatcher" target="_blank" style="font-weight: bold">Read how to solve</a>.
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    Sending... next batch in <?= dispatcher_format_interval($next - time()) ?>
                </p>
            </div>
            <?php
        }
    }
});

