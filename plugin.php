<?php

defined('ABSPATH') || exit;

/*
  Plugin Name: Dispatcher
  Plugin URI: https://www.satollo.net/plugins/dispatcher
  Description: Mass mailing your users, not for real
  Version: 0.0.1
  Author: Stefano Lissa
  Author URI: https://www.satollo.net
  Disclaimer: Use at your own risk. No warranty expressed or implied is provided.
  Text Domain: dispatcher
  License: GPLv2 or later
  Requires at least: 6.9
  Requires PHP: 8.2
  Update URI: satollo-dispatcher
 */

define('DISPATCHER_VERSION', '0.0.1');

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
//
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

        add_action('dispatcher_audience_update_total', function ($audience) {
            global $wpdb;
            $audience->total = $wpdb->get_var("select count(*) from {$wpdb->users}");
        });
        add_filter('dispatcher_contacts_dsp-all', function ($contacts, $audience) {

        }, 10, 2);

        add_filter('dispatcher_contacts', function ($contacts, $audience) {
            global $wpdb;
            if ($audience->id === 'dsp-all') {
                //$users = $wpdb->get_results($wpdb->prepare("select ID, user_email from {$wpdb->users} where id > %d limit %d", $audience->last_contact_id, $audience->max));
                $users = $wpdb->get_results($wpdb->prepare("select ID, user_email from {$wpdb->users} where id > %d limit %d", 0, $audience->max));
                foreach ($users as $user) {
                    $contact = new stdClass();
                    $contact->id = $user->ID;
                    $contact->email = $user->user_email;
                    $contacts[] = $contact;
                }
                return $contacts;
            }

            if ($audience->id === 'dsp-roles') {

                $roles = $audience->data['roles'] ?? [];
                while (count($contacts) < $audience->max) {
                    $db_users = $wpdb->get_results($wpdb->prepare("select ID from {$wpdb->users} where id > %d limit %d", $audience->last_contact_id, $audience->max));
                    if (empty($db_users)) {
                        return $contacts;
                    }
                    foreach ($db_users as $db_user) {

                        $user = new WP_User($db_user->ID);
                        if (empty(array_intersect($user->roles, $roles))) {
                            continue;
                        }
                        $contact = new stdClass();
                        $contact->id = $user->ID;
                        $contact->email = $user->user_email;
                        $contacts[] = $contact;
                    }
                }
                return $contacts;
            }

            return $contacts;
        }, 10, 2);
    }

    static function init_audiences() {
        if (!self::$audiences) {
            do_action('dispatcher_audiences_init');
        }
    }

    static function register_audience($audience) {
        if (empty($audience->id)) {
            return new WP_Error('1', 'Missing or empty ID');
        }
        if (empty($audience->name)) {
            return new WP_Error('2', 'Missing or empty name');
        }
        $audience->id = sanitize_key($audience->id);
        if (isset(self::$audiences[$audience->id])) {
            return new WP_Error('3', 'ID already registered');
        }
        if (!method_exists($audience, 'get_contacts')) {
            return new WP_Error('4', 'Missing get_contacts method');
        }
        if (!method_exists($audience, 'get_total')) {
            return new WP_Error('5', 'Missing get_total method');
        }

        self::$audiences[$audience->id] = $audience;

        return true;
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
        $emails = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 100,
            'meta_query' => [
                [
                    'key' => 'dispatcher_status',
                    'value' => 'sending',
                    'compare' => '=’',
                ]],
            'meta_key' => 'dispatcher_send_time', 'orderby' => 'meta_value_num', 'order' => 'ASC']);

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

        $count = intval(get_post_meta($email->ID, 'dispatcher_count', true));

        $audience_id = sanitize_key(get_post_meta($email->ID, 'audience_id', true));

        self::init_audiences();

        $audience = self::$audiences[$audience_id] ?? false;

        if (!$audience) {
            self::log(__METHOD__, 'No audience for email', $email->ID);
            update_post_meta($email->ID, 'dispatcher_status', 'sent');
            self::log(__METHOD__, 'Completed');
            self::log(__METHOD__, 'End');
            return;
        }

        $last_contact_id = sanitize_key(get_post_meta($email->ID, 'last_contact_id', true));

        $audience_settings = get_post_meta($email->ID, 'audience_settings', true);
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
            update_post_meta($email->ID, 'dispatcher_status', 'sent');
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
            update_post_meta($email->ID, 'dispatcher_last_contact_id', $contact->id);

            self::log(__METHOD__, 'Sending to ' . $contact->email);

            if (self::$settings['redirect_email'] ?? '') {
                self::log(__METHOD__, 'Redirecting to ' . self::$settings['redirect_email']);
                $to = self::$settings['redirect_email'];
            } else {
                $to = $contact->email;
            }


            $result = wp_mail($to, $email->post_title, $message, $headers);

            $count++;
            update_post_meta($email->ID, 'dispatcher_count', $count);

            if ($result) {
                self::log(__METHOD__, 'Sent to: ' . $contact->email);
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

    static function get_subscribers_statistics() {
        global $wpdb;
        self::log(__METHOD__, $input);
        return [
            'total_subscribers' => $wpdb->get_var("select count(*) from {$wpdb->users}")
        ];
    }

    static function create_email($input) {
        self::log(__METHOD__, $input);
        $subject = wp_strip_all_tags($input['subject']);
        $content = '';
        $content_type = $input['content_type'];

        switch ($content_type) {
            case 'last posts summary':
                $posts = get_posts();
                foreach ($posts as $post) {
                    $content .= '<h2>' . $post->post_title . '</h2>';
                    $content .= '<p>' . get_the_excerpt($post) . '</p>';
                }
                break;
            case 'last post content':
                $posts = get_posts(['numberposts' => 1]);
                $post = $posts[0];
                $content .= '<h1>' . $post->post_title . '</h1>';

                if (function_exists('has_blocks') && has_blocks($post)) {
                    $content .= do_blocks($post->post_content);
                } else {
                    $content .= wpautop($post->post_content);
                }
                break;
            default:
                $content = '<p>Write something interesting here...</p>';
        }

        $r = wp_insert_post([
            'post_title' => $subject,
            'post_content' => $content,
            'post_type' => self::POST_TYPE,
                ], true, false);

        if (is_wp_error($r)) {
            self::log('Error creating the email', $r->get_error_message());
            return 0;
        }

        return (int) $r;
    }

    static function send_email(array $input) {
        self::log(__METHOD__, $input);

        $email = get_post($input['email_id']);

        if (!$email || $email->post_type !== self::POST_TYPE) {
            self::log(__METHOD__, 'The ID does not identify and email');
            return 'Problem: the email was not found the ID is not correct.';
        }

        $status = get_post_meta($email->ID, 'dispatcher_status', true);

        if ($status === 'sending') {
            return 'Problem: I\'m already sending this email, nothing else to do.';
        }

        if ($status === 'sent') {
            return 'Problem: this email has already been sent, to resend it you should duplicate it and send the new copy.';
        }

        update_post_meta($email->ID, 'dispatcher_status', 'sending');
        update_post_meta($email->ID, 'dispatcher_user_id', 0);

        return 'The email is now sending, the process will start shortly, you can check the progress opening the message details page.';
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
