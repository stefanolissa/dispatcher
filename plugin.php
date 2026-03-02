<?php

defined('ABSPATH') || exit;

/*
  Plugin Name: Dispatcher
  Plugin URI: https://www.satollo.net/plugins/dispatcher
  Description: Mass mailing your users, not for real
  Version: 0.0.2
  Author: Stefano Lissa
  Author URI: https://www.satollo.net
  Disclaimer: Use at your own risk. No warranty expressed or implied is provided.
  Text Domain: dispatcher
  License: GPLv2 or later
  Requires at least: 6.9
  Requires PHP: 8.1
  Update URI: satollo-dispatcher
 */

define('DISPATCHER_VERSION', '0.0.2');

add_action('plugins_loaded', function () {
    if (defined('SATOLLO_DISPATCHER_VERSION')) {
        // Admin notices
        return;
    }

    //require_once __DIR__ . '/includes/main.php';
});

class Dispatcher {

    const POST_TYPE = 'dsp_email';
    const SLUG = 'dispatcher';

    static $settings;
    static $audiences = [];

    static function init() {
        self::$settings = get_option(self::SLUG . '_settings', []);

        add_action('init', function () {
            $show_in_rest = isset(self::$settings['gutenberg']);
//            if (is_admin() && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit') {
//                $p = get_post($_GET['post']);
//                if (!has_blocks($p)) {
//                    $show_in_rest = false;
//                }
//            }

            $public = true;
            register_post_type(self::POST_TYPE, [
                'labels' => [
                    'name' => 'Emails',
                    'singular_name' => 'Email',
                    'add_new' => 'New Email',
                    'add_new_item' => 'New Email',
                    'edit_item' => 'Compose Email',
                    'all_items' => 'All Emails',
                ],
                'supports' => ['title', 'editor', 'revisions', 'excerpt'],
                'public' => $public,
                'has_archive' => true,
                'show_in_rest' => $show_in_rest,
                'rewrite' => [
                    'slug' => 'emails',
                    'with_front' => true,
                ]
            ]);
        });

        add_filter('cron_schedules', function ($schedules) {
            $schedules['dispatcher'] = [
                'interval' => 900,
                'display' => 'Dispatcher (15 minutes)'
            ];
            return $schedules;
        });

        if (!wp_doing_cron()) {
            $next_scheduled = wp_next_scheduled('dispatcher_run');
            if (!$next_scheduled) {
                // First time ot thank you to who deleted the job...
                wp_schedule_event(time() + 60, 'dispatcher', 'dispatcher_run');
            } elseif ($next_scheduled > time() + 900) {
                // Thank you to change the next execution with a fancy future timestamp
                wp_unschedule_hook('dispatcher_run');
                wp_schedule_event(time() + 60, 'dispatcher', 'dispatcher_run');
            }
        }

        add_action('dispatcher_run', [self::class, 'run']);

        if (isset(self::$settings['gutenberg_debug'])) {
            add_action('template_redirect', function () {

                $object = get_queried_object();

                if ($object instanceof WP_Post && $object->post_type === self::POST_TYPE) {

                    if (isset($_GET['dispatcher'])) {
                        require_once __DIR__ . '/includes/gutenberg.php';
                        echo DispatcherGutenberg::render($object, 'web');
                        die();
                    }
                }
            }, 1);
        }
    }

    static function init_audiences() {
        if (!self::$audiences) {
            do_action('dispatcher_audiences_init');
        }
    }

    static function get_audience($id) {
        self::init_audiences();
        return self::$audiences[$id] ?? null;
    }

    /**
     * Developers will use dispatcher_audience_register(...).
     *
     * @param type $audience
     * @return \WP_Error|bool
     */
    static function register_audience($audience) {
        if (!method_exists($audience, 'get_id')) {
            return new WP_Error('1', 'Missing get_id method');
        }

        $id = sanitize_key($audience->get_id());
        if (isset(self::$audiences[$id])) {
            return new WP_Error('2', 'ID already registered');
        }

        if (!method_exists($audience, 'get_name')) {
            return new WP_Error('1', 'Missing get_name method');
        }

        if (!method_exists($audience, 'get_contacts')) {
            return new WP_Error('4', 'Missing get_contacts method');
        }
        if (!method_exists($audience, 'get_total')) {
            return new WP_Error('5', 'Missing get_total method');
        }

        self::$audiences[$id] = $audience;

        return true;
    }

    static function update_post_meta($post_id, $key, $value) {
        if (is_object($post_id)) {
            $post_id = $post_id->ID;
        }
        return update_post_meta($post_id, 'dsp_' . $key, $value);
    }

    static function get_post_meta($post_id, $key) {
        if (is_object($post_id)) {
            $post_id = $post_id->ID;
        }
        return get_post_meta($post_id, 'dsp_' . $key, true);
    }

    static function get_post_meta_array($post_id, $key) {
        $value = self::get_post_meta($post_id, $key);
        if (!is_array($value)) {
            $value = [];
        }
        return $value;
    }

    /**
     *
     * @global wpdb $wpdb
     */
    static function run() {
        global $wpdb;

        self::log(__METHOD__, 'Start');

        // Extract the emails to be sent
        // any – retrieves any status except for ‘inherit’, ‘trash’ and ‘auto-draft’.
        // Custom post statuses with ‘exclude_from_search’ set to true are also excluded.
        $emails = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'dsp_status',
                    'value' => 'sending',
                    'compare' => '=’',
                ]],
            'meta_key' => 'dsp_send_time', 'orderby' => 'meta_value_num', 'order' => 'ASC']);

        if (!$emails) {
            self::log(__METHOD__, 'No emails to send');
            self::log(__METHOD__, 'End');
            return;
        }

        $speed = self::$settings['speed'] ?? 100;
        self::log(__METHOD__, 'Speed ' . $speed);
        $max = intdiv($speed, 4);
        $delay = self::$settings['delay'] ?? 0;

        // TODO: select the older or order the previous query by a meta value "dispatcher_send_time"
        $email = $emails[0];

        //foreach ($emails as $email) {
        self::log(__METHOD__, 'Processing ' . $email->post_title);

        $count = intval(self::get_post_meta($email->ID, 'sent_count'));

        $audience_id = sanitize_key(self::get_post_meta($email->ID, 'audience_id'));

        self::init_audiences();

        $audience = self::$audiences[$audience_id] ?? false;

        if (!$audience) {
            self::log(__METHOD__, 'No audience for email', $email->ID);
            self::update_post_meta($email->ID, 'status', 'sent');
            self::log(__METHOD__, 'Completed');
            self::log(__METHOD__, 'End');
            return;
        }

        $last_contact_id = sanitize_key(self::get_post_meta($email->ID, 'last_contact_id'));

        $audience_settings = self::get_post_meta($email->ID, 'audience_settings');
        if (!is_array($audience_settings)) {
            $audience_settings = [];
        }



        $params = new stdClass();
        $params->settings = $audience_settings;
        $params->last_contact_id = $last_contact_id;
        $params->max = $max;

        self::log(__METHOD__, 'Audience params', $params);

        $contacts = $audience->get_contacts($params);

        if (!$contacts) {
            self::update_post_meta($email->ID, 'status', 'sent');
            self::log(__METHOD__, 'Completed');
            self::log(__METHOD__, 'End');
            return;
        }

        self::log(__METHOD__, 'Contacts', $contacts);

        $headers = ['Content-Type: text/html'];
        if (self::$settings['from_email'] ?? '') {
            $headers[] = 'From: ' . self::$settings['from_email'];
        }

        $message = self::render_message($email);

        foreach ($contacts as $contact) {
            self::update_post_meta($email->ID, 'last_contact_id', $contact->id);

            self::log(__METHOD__, 'Sending to ', $contact->email);

            if (self::$settings['redirect_email'] ?? '') {
                self::log(__METHOD__, 'Redirecting to ', self::$settings['redirect_email']);
                $to = self::$settings['redirect_email'];
            } else {
                $to = $contact->email;
            }


            $result = wp_mail($to, $email->post_title, $message, $headers);

            $count++;
            self::update_post_meta($email->ID, 'sent_count', $count);

            if ($result) {
                self::log(__METHOD__, 'Sent to: ', $contact->email);
            } else {
                self::log(__METHOD__, 'Sending error');
            }

            if ($delay) {
                usleep($delay * 1000);
            }
        }

        self::log(__METHOD__, 'End');
    }

    static function render_message($email) {
        if (has_blocks($email)) {
            require_once __DIR__ . '/includes/gutenberg.php';
            $message = DispatcherGutenberg::render($email);
        } else {
            require_once __DIR__ . '/includes/classic.php';
            $message = DispatcherClassic::render($email);
        }
        return $message;
    }

    /**
     * Writes a log line concatenating the provided elements (they could be objects, arrays, ...)
     *
     * @param mixed $texts
     */
    static function log(...$texts) {
        if (!WP_DEBUG) {
            return;
        }
        $row = '';
        foreach ($texts as $text) {
            if (is_scalar($text)) {
                $row .= $text;
            } elseif (is_wp_error($text)) {
                $row .= $text->get_error_code() . ' - ' . $text->get_error_message();
            } else {
                $row .= wp_json_encode($text);
            }
            $row .= ' ';
        }
        error_log('Dispatcher > ' . $row);
    }
}

register_activation_hook(__FILE__, function () {
    $settings = get_option('dispatcher_settings', []);
    if (!$settings || !is_array($settings)) {
        update_option('dispatcher_settings', [], false);
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_unschedule_hook('dispatcher_run');
});

Dispatcher::init();

require_once __DIR__ . '/includes/php-api.php';
require_once __DIR__ . '/includes/audiences.php';

if (is_admin()) {
    require_once __DIR__ . '/admin/admin.php';
}


if (is_admin() || defined('DOING_CRON') && DOING_CRON) {
    require_once __DIR__ . '/includes/repo.php';
}
