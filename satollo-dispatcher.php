<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

/*
  Plugin Name: Satollo Dispatcher
  Plugin URI: https://www.satollo.net/plugins/dispatcher
  Description: Send emails to your audiences in seconds
  Version: 1.0.0
  Author: Stefano Lissa
  Author URI: https://www.satollo.net
  Disclaimer: Use at your own risk. No warranty expressed or implied is provided.
  Text Domain: satollo-dispatcher
  License: GPLv2 or later
  Requires at least: 6.9
  Requires PHP: 8.1
 */

const VERSION = '1.0.0';
const POST_TYPE = 'dispatcher_email';

define('DISPATCHER_VERSION', '1.0.0');

spl_autoload_register(function ($class) {
    static $dir = __DIR__ . '/classes/';
    if (strncmp('Satollo\\Dispatcher', $class, 18) === 0) {
        $class = substr($class, 18);
        $file = $dir . str_replace('\\', '/', $class) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
});

class Dispatcher {

    const POST_TYPE = 'dispatcher_email';
    const SLUG = 'dispatcher';

    static $settings;
    static $audience_types = [];

    static function init() {
        self::$settings = get_option('dispatcher_settings', []);

        add_action('init', function () {
            $show_in_rest = true; //isset(self::$settings['gutenberg']);
//            if (is_admin() && isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit') {
//                $p = get_post($_GET['post']);
//                if (has_blocks($p)) {
//                    $show_in_rest = true;
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
                'interval' => WP_DEBUG ? 60 : 300,
                'display' => 'Dispatcher'
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
        if (!self::$audience_types) {
            do_action('dispatcher_audiences_init');
        }
    }

    static function get_audience_type($id) {
        self::init_audiences();
        return self::$audience_types[$id] ?? null;
    }

    static function get_audience_types() {
        self::init_audiences();
        return self::$audience_types;
    }

    /**
     * Developers will use dispatcher_audience_register(...).
     *
     * @param type $audience
     * @return \WP_Error|bool
     */
    static function register_audience_type($audience) {

        if (!method_exists($audience, 'get_id')) {
            return new WP_Error('1', 'Missing get_id method');
        }

        $id = sanitize_key($audience->get_id());

        if (!$id) {
            return new WP_Error('2', 'Invalid ID');
        }

        if (isset(self::$audience_types[$id])) {
            return new WP_Error('3', 'ID already registered');
        }

        if (!method_exists($audience, 'get_name')) {
            return new WP_Error('4', 'Missing get_name method');
        }

        if (!method_exists($audience, 'get_contacts')) {
            return new WP_Error('5', 'Missing get_contacts method');
        }
//        if (!method_exists($audience, 'get_total')) {
//            return new WP_Error('5', 'Missing get_total method');
//        }

        self::$audience_types[$id] = $audience;

        return true;
    }

    static function get_audience_total($audience) {
        self::log(__METHOD__, $audience);
        if (is_scalar($audience)) {
            $audience = self::get_audience($audience);
            if (!$audience) {
                return -1;
            }
        }

        $audience_type = self::get_audience_type($audience->type);

        // If there is a (probably) optimized method
        if (method_exists($audience_type, 'get_total')) {
            return $audience_type->get_total($audience);
        }

        $start_time = time();
        $total = 0;
        $last_contact_id = 0;
        $max = 1000;

        while (true) {

            $contacts = $audience_type->get_contacts($audience, $last_contact_id, $max);

            $total += count($contacts);
            if (empty($contacts)) {
                break;
            }
            if (time() - $start_time > 5) {
                return -1;
            }
            $last_contact_id = $contacts[count($contacts) - 1]->id;
        }

        gc_collect_cycles();

        return $total;
    }

    static function get_audience($id) {
        global $wpdb;
        $audience = $wpdb->get_row($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_audiences where id=%d limit 1", (int) $id));
        if ($audience) {
            $audience->settings = unserialize($audience->settings);
        }
        return $audience;
    }

    /**
     *
     * @global \wpdb $wpdb
     * @return array
     */
    static function get_audiences() {
        global $wpdb;
        $audiences = $wpdb->get_results("select * from {$wpdb->prefix}dispatcher_audiences order by id desc");
        array_walk($audiences, function ($audience) {
            $audience->settings = unserialize($audience->settings);
        });
        return $audiences;
    }

    static function get_email_audience($email_id) {
        global $wpdb;
        if (is_object($email_id)) {
            $email_id = $email_id->ID;
        }
        $audience_id = self::get_post_meta($email_id, 'audience_id');

        $audience = self::get_audience($audience_id);
        return $audience;
    }

    /**
     * Builds an object wirh the audience parameters to used when invoking the audience methods.
     *
     * @param object|int $email_id
     * @return \stdClass
     */
    static function get_audience_params($email_id) {
        global $wpdb;
        if (is_object($email_id)) {
            $email_id = $email_id->ID;
        }

        $last_contact_id = sanitize_key(self::get_post_meta($email_id, 'last_contact_id'));

        $audience_id = self::get_post_meta($email_id, 'audience_id');

        $audience = $wpdb->get_row($wpdb->prepare("select * from {$wpdb->prefix}dispatcher_audiences where id=%d limit 1", (int) $audience_id));

        if (!$audience) {
            return null;
        }

        $params = new \stdClass();
        $params->settings = unserialize($audience->settings);
        $params->last_contact_id = $last_contact_id;
        return $params;
    }

    static function update_post_meta($post_id, $key, $value) {
        if (is_object($post_id)) {
            $post_id = $post_id->ID;
        }
        return update_post_meta($post_id, 'dispatcher_' . $key, $value);
    }

    static function get_post_meta($post_id, $key) {
        if (is_object($post_id)) {
            $post_id = $post_id->ID;
        }
        return get_post_meta($post_id, 'dispatcher_' . $key, true);
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
        Engine::run();
    }

    /**
     *
     * @param WP_Post $email
     * @return string
     */
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

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('dispatcher', [
        'label' => 'Dispatcher',
        'description' => 'Abilities to administrate the Dispatcher plugin. Requires administration role.',
    ]);
});

add_action('wp_abilities_api_init', function () {
    require_once __DIR__ . '/includes/abilities.php';
});

if (is_admin()) {
    require_once __DIR__ . '/admin/admin.php';
}

if (is_admin() || defined('DOING_CRON') && DOING_CRON) {
    if (file_exists(__DIR__ . '/includes/repo.php')) {
        include_once __DIR__ . '/includes/repo.php';
    }
}
