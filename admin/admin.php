<?php

namespace Satollo\Dispatcher;

defined('ABSPATH') || exit;

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

//add_action('init', function () {
//    Admin::init();
//});

class DispatcherAdmin {

    static function init() {

        $version = get_option('dispatcher_version');
        if (VERSION !== $version) {
            if (WP_DEBUG) {
                //Plugin::log('Version change');
            }
            update_option('dispatcher_version', VERSION, false);
            include_once __DIR__ . '/includes/activate.php';
        }

        add_action('admin_menu', function () {
            add_submenu_page('edit.php?post_type=' . POST_TYPE,
                    'Settings', 'Settings', 'administrator', 'dispatcher-settings',
                    function () {
                        include __DIR__ . '/settings.php';
                    }
            );
            add_submenu_page('edit.php?post_type=' . POST_TYPE,
                    'Audiences', 'Audiences', 'administrator', 'dispatcher-audiences',
                    function () {
                        include __DIR__ . '/audiences/index.php';
                    }
            );
            add_submenu_page('edit.php?post_type=' . POST_TYPE,
                    'Logs', 'Logs', 'administrator', 'dispatcher-logs',
                    function () {
                        include __DIR__ . '/logs/index.php';
                    }
            );
        });

        if (isset($_GET['page']) && str_starts_with($_GET['page'], 'dispatcher')) {
            add_action('admin_enqueue_scripts', function ($hook_suffix) {
                wp_enqueue_style('dispatcher', plugin_dir_url(__FILE__) . 'admin.css', [], VERSION);
            });
        }

        if (empty(get_option('dispatcher_secret'))) {
            update_option('dispatcher_secret', wp_generate_password(12, false, false), false);
        }

        self::init_gutenberg();
    }

    static function init_gutenberg() {

        add_action('save_post_' . POST_TYPE, [self::class, 'save_post'], 10, 3);

        add_action('add_meta_boxes', function () {
            add_meta_box('dsp-status', 'Sending', function ($post) {
                include __DIR__ . '/includes/gutenberg-metabox.php';
            },
                    [POST_TYPE], 'side', 'high');
        });

        if (false && !isset(Dispatcher::$settings['gutenberg_all_blocks'])) {
            add_filter('allowed_block_types_all', function ($allowed_blocks, $editor_context) {
                if ($editor_context->post->post_type === POST_TYPE) {
                    $allowed_blocks = [
                        'core/paragraph',
                        'core/heading',
                        'core/image',
                        'core/list',
                        'core/columns',
                        'core/columns',
                        'core/group',
                        'core/spacer',
                        'core/media-text',
                            //'core/latest-posts'
                    ];
                }
                return $allowed_blocks;
            }, 10, 2);
        }

        add_action('current_screen', function () {

            $screen = get_current_screen();

            if ($screen->base === 'edit' && $screen->post_type === POST_TYPE) {
                require_once __DIR__ . '/includes/post-list.php';
            }

            if ($screen->base === 'post' && $screen->post_type === POST_TYPE) {
                // I don't think this is the right place...
                add_filter('wp_theme_json_data_theme', function ($theme_json) {
                    $new_data = [
                        'version' => 3,
                        'settings' => [
                            'spacing' => [
                                'blockGap' => false,
                                'spacingSizes' => [],
                                'units' => ['px'],
                                //'spacingScale' => [],
                                'defaultSpacingSizes' => false,
                                'padding' => true,
                                'margin' => true
                            ]
                        ],
                    ];

                    return $theme_json->update_with($new_data);
                });
            }
        });
    }

    static function get_audience($id) {
        return Dispatcher::get_audience($id);
    }

    static function get_audience_total($audience) {
        return Dispatcher::get_audience_total($audience);
    }

    static function get_post_meta($post_id, $key) {
        return Dispatcher::get_post_meta($post_id, $key);
    }

    static function get_post_meta_array($post_id, $key) {
        return Dispatcher::get_post_meta_array($post_id, $key);
    }

    static function update_post_meta($post_id, $key, $value) {
        Dispatcher::update_post_meta($post_id, $key, $value);
    }

    static function log(...$texts) {
        Dispatcher::log($texts);
    }

    /**
     * Action triggered by WP when a post is saved.
     *
     * @param type $post_id
     * @param type $post
     * @param type $update
     */
    static function save_post($post_id, $post, $update) {
        self::log('Saving post ', $post_id);
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!wp_verify_nonce($_POST['_dispatchernonce'] ?? '', 'save')) {
            return;
        }

        $status = self::get_post_meta($post->ID, 'status');

        if ($status != 'sending') {
            $audience_id = sanitize_key($_POST['audience_id'] ?? '');
            self::update_post_meta($post_id, 'audience_id', $audience_id);
        }

        $action = sanitize_key($_POST['dispatcher_action'] ?? '');

        self::process_action($post, $action);
    }

    static function process_action($email, $action) {
        self::log('Processing action', $action);

        if ($action === 'send') {
            self::update_post_meta($email->ID, 'send_time', time());
            self::update_post_meta($email->ID, 'last_contact_id', '0');
            $audience = self::get_audience(self::get_post_meta($email->ID, 'audience_id'));
            if ($audience) {
                $total = DispatcherAdmin::get_audience_total($audience);
                self::update_post_meta($email->ID, 'audience_total', $total);
            } else {
                self::update_post_meta($email->ID, 'audience_total', 0);
            }
            self::update_post_meta($email->ID, 'status', 'sending');
            return;
        }

        if ($action === 'pause') {
            self::update_post_meta($email->ID, 'status', 'paused');
        } elseif ($action === 'continue') {
            self::update_post_meta($email->ID, 'status', 'sending');
        } elseif ($action === 'resend') {
            self::update_post_meta($email->ID, 'send_time', time());
            self::update_post_meta($email->ID, 'last_contact_id', '0');
            $audience = self::get_audience(self::get_post_meta($email->ID, 'audience_id'));
            if ($audience) {
                $total = self::get_audience_total($audience);
                self::update_post_meta($email->ID, 'audience_total', $total);
            } else {
                self::update_post_meta($email->ID, 'audience_total', 0);
            }
            self::update_post_meta($email->ID, 'status', 'sending');
            //Dispatcher::run();
        } elseif ($action === 'stop') {
            self::update_post_meta($email->ID, 'last_contact_id', '0');
            self::update_post_meta($email->ID, 'status', 'draft');
        } elseif ($action === 'test') {

            $headers = ['Content-Type: text/html'];
            if (Dispatcher::$settings['from_email'] ?? '') {
                $headers[] = 'From: ' . Dispatcher::$settings['from_email'];
            }
            $message = self::render_message($email);
            $to = wp_get_current_user()->user_email;
            wp_mail($to, $email->post_title . ' [PREVIEW]', $message, $headers);
        }
    }

    static function ajax_audience_total() {
        check_ajax_referer('save', 'audience_nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error('You do not have permission to do this.', 403);
        }

        //$post_id = (int) ($_POST['post_id'] ?? 0);
        $audience_settings = wp_unslash($_POST['audience_data'] ?? []);
        if (!is_array($audience_settings)) {
            $audience_settings = [];
        }

        $audience_id = sanitize_key($_POST['audience_id'] ?? '');

        $audience = self::get_audience($audience_id);

        if (!$audience) {
            echo 'Error: audience not found!';
            return;
        }

        $params = new stdClass();
        $params->settings = $audience_settings;
        $total = self::get_audience_total($audience);
        $post_id = (int) ($_POST['audience_post_id'] ?? 0);
        self::update_post_meta($post_id, 'audience_total', $total);

        if ($total == -1) {
            echo 'Not computable';
            wp_die();
        }

        echo $total;

        wp_die();
    }
}

add_action('init', function () {
    if (current_user_can('administrator')) {
        DispatcherAdmin::init();
    }
});

/**
 * Builds the Audience object for an email, used to provide the audience to actions and
 * filters.
 *
 * @param WP_Post $email
 * @return \stdClass
 */
function dispatcher_build_audience_params($email_id) {
    if (is_object($email_id)) {
        $email_id = $email_id->ID;
    }

    $last_contact_id = sanitize_key(DispatcherAdmin::get_post_meta($email_id, 'last_contact_id'));

    $audience_settings = DispatcherAdmin::get_post_meta($email_id, 'audience_settings');
    if (!is_array($audience_settings)) {
        $audience_settings = [];
    }
    //$total = (int) get_post_meta($email->ID, 'audience_total', true);

    $params = new \stdClass();
    $params->settings = $audience_settings;
    $params->last_contact_id = $last_contact_id;
    return $params;
}

add_action('wp_ajax_dispatcher_gutenberg', function () {
    check_ajax_referer('save', '_dispatchernonce');

    if (!current_user_can('administrator')) {
        wp_send_json_error('You do not have permission to do this.', 403);
    }

    $action = $_POST['act'] ?? '';
    $post_id = intval($_POST['post_id']);

    $post = get_post($post_id);

    if (!$post || $post->post_type !== Dispatcher::POST_TYPE) {
        wp_send_json_error('Missing or wrong post type');
        wp_die();
    }

    DispatcherAdmin::process_action($post, $action);
    if ($action === 'test') {
        echo 'Test email sent to ' . wp_get_current_user()->user_email;
        wp_die();
    }

    require_once __DIR__ . '/includes/status-metabox.php';
    wp_die();
});

// Save action from the classic editor
add_action('save_post_' . POST_TYPE, function ($post_id, $post, $update) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!wp_verify_nonce($_POST['_dispatchernonce'] ?? '', 'save')) {
        return;
    }

    $status = DispatcherAdmin::get_post_meta($post->ID, 'status');

    if ($status != 'sending') {
        $audience_id = sanitize_key($_POST['audience_id'] ?? '');

        DispatcherAdmin::update_post_meta($post_id, 'audience_id', $audience_id);
    }

    $action = sanitize_key($_POST['dispatcher_action'] ?? '');

    DispatcherAdmin::process_action($post, $action);
}, 10, 3);

function dispatcher_format_interval($delta) {

    if ($delta < 0) {
        return '(now)';
    }

    // To avoid warnings
    $delta = (float) $delta;

    $seconds = $delta % MINUTE_IN_SECONDS;
    $minutes = floor($delta % HOUR_IN_SECONDS / MINUTE_IN_SECONDS);
    $hours = floor($delta % DAY_IN_SECONDS / HOUR_IN_SECONDS);
    $days = floor($delta / DAY_IN_SECONDS);
    return ($days ? $days . ' days, ' : '')
            . ($days || $hours ? $hours . ' hours, ' : '')
            . ($days || $hours || $minutes ? $minutes . ' minutes, ' : '')
            . $seconds . ' seconds';
}
