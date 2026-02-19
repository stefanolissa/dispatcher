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

define('DISPATCHER_VERSION', '0.0.6');

class Dispatcher {

    const POST_TYPE = 'dsp_email';
    const SLUG = 'dispatcher';

    static $settings;

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
                'display' => 'Dispacther (15 minutes)'
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

//        add_action('dispatcher_run', [self::class, 'run']);
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

        $audience = get_post_meta($email->ID, 'dispatcher_audience', true);
        $roles = $audience['roles'] ?? [];
        self::log(__METHOD__, 'Targeted roles: ', $roles);

        if (!$roles) {
            update_post_meta($email->ID, 'dispatcher_status', 'sent');
            self::log(__METHOD__, 'No roles');
            self::log(__METHOD__, 'Completed');
            self::log(__METHOD__, 'End');
            return;
        }

        $count = intval(get_post_meta($email->ID, 'dispatcher_count', true));

        // Get the content to build the message
        // ...

        $user_id = (int) get_post_meta($email->ID, 'dispatcher_user_id', true);
        /** @var WP_User[] $users */
        $users = $wpdb->get_results($wpdb->prepare("select * from {$wpdb->users} where id > %d limit $max", $user_id));

        $headers = ['Content-Type: text/html'];
        if (self::$settings['from_email'] ?? '') {
            $headers[] = 'From: ' . self::$settings['from_email'];
        }

        $message = self::render_message($email);

        foreach ($users as $db_user) {
            update_post_meta($email->ID, 'dispatcher_user_id', $db_user->ID);

            $user = new WP_User($db_user->ID);

            self::log(__METHOD__, 'Checking audience against ' . $user->user_email);
            if (empty(array_intersect($user->roles, $roles))) {
                self::log(__METHOD__, 'Not targeted');
                continue;
            }

            self::log(__METHOD__, 'Sending to ' . $user->user_email);

            if (self::$settings['redirect_email'] ?? '') {
                self::log(__METHOD__, 'Redirecting to ' . self::$settings['redirect_email']);
                $to = self::$settings['redirect_email'];
            } else {
                $to = $user->user_email;
            }


            $result = wp_mail($to, $email->post_title, $message, $headers);

            $count++;
            update_post_meta($email->ID, 'dispatcher_count', $count);

            if ($result) {
                self::log(__METHOD__, 'Sent to: ' . $user->user_email);
            } else {
                self::log(__METHOD__, 'Sending error');
            }

            if ($delay) {
                usleep($delay * 1000);
            }
        }

        $user_id = (int) get_post_meta($email->ID, 'dispatcher_user_id', true);
        $count = $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->users} where id > %d", $user_id));
        if (!$count) {
            update_post_meta($email->ID, 'dispatcher_status', 'sent');
            self::log(__METHOD__, 'Completed');
        }
        //}

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

    static function pause_email(array $input) {
        self::log(__METHOD__, $input);

        $email = get_post($input['email_id']);

        if (!$email || $email->post_type !== self::POST_TYPE) {
            return 'Problem: the email was not found the ID is not correct.';
        }

        $status = get_post_meta($email->ID, 'dispatcher_status', true);

        if ($status !== 'sending') {
            return 'Problem: this email is not sending, there nothing to do';
        }

        update_post_meta($email->ID, 'dispatcher_status', 'paused');

        return 'The email is now paused.';
    }

    static function set_email_status(array $input) {
        self::log(__METHOD__, $input);

        $email = get_post($input['email_id']);

        if (!$email) {
            return 'Error: the provided identifier is not an email.';
        }

        if ($email->post_type !== self::POST_TYPE) {
// TODO: Add a list of the available email with their IDs
            return 'Error: the provided identifier is not an email but another kind of post type.';
        }

        $status = $input['status'];
        update_post_meta($email->ID, 'dispatcher_status', $status);

        return 'The status has been changed.';
    }

    /**
     * Changes an email field value.
     *
     * Supported fields are: subject and status
     *
     * @param array $input
     * @return string
     */
    static function set_email_field(array $input) {
        self::log(__METHOD__, $input);

        $email = get_post($input['email_id']);

        if (!$email) {
            return 'Error: the provided identifier is not an email.';
        }

        if ($email->post_type !== self::POST_TYPE) {
// TODO: Add a list of the available email with their IDs
            return 'Error: the provided identifier is not an email but another kind of post type.';
        }

        $field = $input['field'];

        if ($field === 'subject') {
            wp_update_post(['ID' => $email->ID, 'post_title' => $input['value']]);
            return 'Updated.';
        }

        if ($field === 'status') {
            update_post_meta($email->ID, 'dispatcher_status', $input['value']);
            return 'Updated.';
        }

        return 'Error: a wrong field was provided.';
    }

    static function set_email_field_2(array $input) {
        self::log(__METHOD__, $input);

        $email = get_post($input['email_id']);

        if (!$email) {
            return 'Error: the provided identifier is not an email.';
        }

        if ($email->post_type !== self::POST_TYPE) {
// TODO: Add a list of the available email with their IDs
            return 'Error: the provided identifier is not an email but another kind of post type.';
        }

        $field = $input['field'];

        if ($field === 'subject') {
            wp_update_post(['ID' => $email->ID, 'post_title' => $input['subject_value']]);
            return 'Updated.';
        }

        if ($field === 'status') {
            update_post_meta($email->ID, 'dispatcher_status', $input['status_value']);
            return 'Updated.';
        }

        if ($field === 'from_email') {
            update_post_meta($email->ID, 'dispatcher_from_email', $input['from_email_value']);
            return 'Updated.';
        }

        return 'Error: a wrong field was provided.';
    }

    /**
     * Return statistics of a specific email.
     *
     * @param array $input
     * @return array
     */
    static function get_email_statistics($input) {
        self::log(__METHOD__, $input);
        return [
            'subscribers' => 10,
            'opens' => 0,
            'clicks' => 0];
    }

    /**
     * Returns the last 5 emails.
     *
     * Returned fields are id, subject, status.
     *
     * @param array $input
     * @return string
     */
    static function get_emails($input) {
        global $wpdb;

        self::log(__METHOD__, $input);
        $max = (int) ($input['max'] ?? 5);
        $emails = get_posts(['post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => $max]);
        $list = [];

        $total = (int) $wpdb->get_var("select count(*) from {$wpdb->users}");

        foreach ($emails as $email) {
            $status = get_post_meta($email->ID, 'dispatcher_status', true);
            if (empty($status)) {
                $status = 'draft';
            }
            $progress = 0;
            if ($status === 'sending') {
                $last_id = (int) get_post_meta($post->ID, 'dispatcher_user_id', true);
                $sent = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->users} where id <= %d", $last_id));
                $percent = floor($sent * 100.0 / $total);
            } elseif ($status === 'sent') {
                $progress = 100;
            }
            $list[] = ['id' => $email->ID, 'subject' => $email->post_title, 'status' => $status, 'progress_percent' => $progress];
        }
        self::log(__METHOD__, $list);
        return $list;
    }

    static function update_setting($input) {
        self::log(__METHOD__, $input);

        $settings = get_option(self::SLUG, []);
        // Specifing the default value is not enough: object caching plugins mess up with the missing options...
        if (!is_array($settings)) {
            $settings = [];
        }

        if (!is_null($input['speed'])) {
            $settings['speed'] = (int) $input['speed'];
        }
        if (!is_null($input['delay'])) {
            $settings['delay'] = (int) $input['delay'];
        }
        if (!is_null($input['abilities'])) {
            if ('on' === $input['abilities']) {
                $settings['abilities'] = 'on';
            } else {
                unset($settings['abilities']);
            }
        }
        update_option(self::SLUG, $settings);
        return 'ok';

        $name = $input['setting_name'];
        $value = $input['setting_value'];

        // TODO: validation


        $settings[$name] = $value;
        update_option(self::SLUG, $settings);
        return 'ok';
    }

    static function get_setting($input) {
        self::log(__METHOD__, $input);
        $settings = get_option(self::SLUG, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        return $settings[$input['setting_name']] ?? 'Invalid setting or not set yet';
    }

    static function get_settings($input) {
        self::log(__METHOD__, $input);
        $settings = get_option(self::SLUG, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        //self::log(__METHOD__, $settings);
        $settings['abilities'] = $settings['abilities'] ?? 'off';
        return wp_json_encode($settings);
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

    static function register_ability($name, $attrs) {
        $r = wp_register_ability($name, $attrs);
        self::log(__METHOD__, $name, $r);
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

if (is_admin()) {
    require_once __DIR__ . '/admin/admin.php';
}


if (is_admin() || defined('DOING_CRON') && DOING_CRON) {
    require_once __DIR__ . '/includes/repo.php';
}
