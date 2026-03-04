<?php
defined('ABSPATH') || exit;

class DispatcherAdmin {

    static function init() {

        add_action('admin_menu', function () {
            add_submenu_page('edit.php?post_type=' . Dispatcher::POST_TYPE,
                    'Settings', 'Settings', 'administrator', 'dispatcher_settings',
                    function () {
                        include __DIR__ . '/settings.php';
                    }
            );
        });

        if (str_starts_with($_GET['page'] ?? '', 'dispatcher_')) {
            add_action('admin_enqueue_scripts', function ($hook_suffix) {
                wp_enqueue_style('dispatcher', plugin_dir_url(__FILE__) . 'admin.css', [], DISPATCHER_VERSION);
            });
        }

        add_action('wp_ajax_dsp_audience_settings', [self::class, 'ajax_audience_settings']);
        add_action('wp_ajax_dsp_audience_total', [self::class, 'ajax_audience_total']);
    }

    static function get_audience($id) {
        return Dispatcher::get_audience($id);
    }

    static function get_audience_total($audience, $params) {
        return Dispatcher::get_audience_total($audience, $params);
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
     * Loads and echoes the specific audience settings for the editor.
     */
    static function ajax_audience_settings() {
        check_ajax_referer('save', 'audience_nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error('You do not have permission to do this.', 403);
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $audience_settings = self::get_post_meta_array($post_id, 'audience_settings');

        $audience_id = sanitize_key($_POST['audience_id'] ?? '');

        $audience = self::get_audience($audience_id);

        if (!$audience) {
            echo 'Error: audience not found!';
            return;
        }

        // It's better to have a specific action to avoid to trigger all the Audiences
        //do_action('dispatcher_audience_settings', $audience);
        if (method_exists($audience, 'form')) {
            $params = new stdClass();
            $params->settings = $audience_settings;
            $audience->form($params);
        } else {
            echo 'No specific settings.';
        }
        wp_die();
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
        $total = self::get_audience_total($audience, $params);
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

if (!isset(Dispatcher::$settings['gutenberg_all_blocks'])) {
    add_filter('allowed_block_types_all', function ($allowed_blocks, $editor_context) {
        if ($editor_context->post->post_type === Dispatcher::POST_TYPE) {
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

    if ($screen->base === 'edit' && $screen->post_type === Dispatcher::POST_TYPE) {
        require_once __DIR__ . '/includes/post-list.php';
    }


    if ($screen->base === 'post' && $screen->post_type === Dispatcher::POST_TYPE) {
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
                        'margin' => false
                    ]
                ],
            ];

            return $theme_json->update_with($new_data);
        });
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

    $last_contact_id = sanitize_key(get_post_meta($email_id, 'dsp_last_contact_id', true));

    $audience_settings = get_post_meta($email_id, 'dsp_audience_settings', true);
    if (!is_array($audience_settings)) {
        $audience_settings = [];
    }
    //$total = (int) get_post_meta($email->ID, 'audience_total', true);

    $params = new stdClass();
    $params->settings = $audience_settings;
    $params->last_contact_id = $last_contact_id;
    return $params;
}

/**
 * Process an action (send, pause, ...) triggered from the editor.
 *
 * @param WP_Post $email
 * @param string $action
 */
function dispatcher_process_action($email, $action) {
    Dispatcher::log('Processing action', $action);
    if ($action === 'send') {
        Dispatcher::update_post_meta($email->ID, 'send_time', time());
        Dispatcher::update_post_meta($email->ID, 'last_contact_id', '0');
        $audience = Dispatcher::get_audience(Dispatcher::get_post_meta($email->ID, 'audience_id'));
        if ($audience) {
            $params = dispatcher_build_audience_params($email);
            $total = DispatcherAdmin::get_audience_total($audience, $params);
            Dispatcher::update_post_meta($email->ID, 'audience_total', $total);
        } else {
            update_post_meta($email->ID, 'dsp_audience_total', 0);
        }
        update_post_meta($email->ID, 'dsp_status', 'sending');
        //Dispatcher::run();
    } elseif ($action === 'pause') {
        update_post_meta($email->ID, 'dsp_status', 'paused');
    } elseif ($action === 'continue') {
        update_post_meta($email->ID, 'dsp_status', 'sending');
    } elseif ($action === 'resend') {
        Dispatcher::update_post_meta($email->ID, 'send_time', time());
        Dispatcher::update_post_meta($email->ID, 'last_contact_id', '0');
        $audience = Dispatcher::get_audience(get_post_meta($email->ID, 'audience_id'));
        if ($audience) {
            $params = dispatcher_build_audience_params($email);
            $total = DispatcherAdmin::get_audience_total($audience, $params);
            Dispatcher::update_post_meta($email->ID, 'audience_total', $total);
        } else {
            Dispatcher::update_post_meta($email->ID, 'audience_total', 0);
        }
        update_post_meta($email->ID, 'dsp_status', 'sending');
        //Dispatcher::run();
    } elseif ($action === 'stop') {
        update_post_meta($email->ID, 'dsp_last_contact_id', '0');
        update_post_meta($email->ID, 'dsp_status', 'draft');
    } elseif ($action === 'test') {

        $headers = ['Content-Type: text/html'];
        if (Dispatcher::$settings['from_email'] ?? '') {
            $headers[] = 'From: ' . Dispatcher::$settings['from_email'];
        }
        $message = Dispatcher::render_message($email);
        $to = wp_get_current_user()->user_email;
        wp_mail($to, $email->post_title . ' [PREVIEW]', $message, $headers);
    }
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

    dispatcher_process_action($post, $action);
    if ($action === 'test') {
        echo 'Test email sent to ' . wp_get_current_user()->user_email;
        wp_die();
    }

    require_once __DIR__ . '/includes/status-metabox.php';
    wp_die();
});

add_filter('post_updated_messages', function ($messages) {
    $messages[Dispatcher::POST_TYPE][99] = 'Test sent to ' . esc_html(wp_get_current_user()->user_email);
    return $messages;
});

add_filter('enter_title_here', function ($title, $post) {
    if ($post->post_type === Dispatcher::POST_TYPE) {
        return 'Email subject';
    }
    return $title;
}, 10, 2);

add_action('add_meta_boxes', function () {
    if (get_current_screen()->is_block_editor()) {
        add_meta_box('dsp-status', 'Sending', 'dispatcher_status_gutenberg_meta_box', [Dispatcher::POST_TYPE], 'side', 'high');
    } else {
        add_meta_box('dsp-status', 'Sending', 'dispatcher_status_classic_meta_box', [Dispatcher::POST_TYPE], 'side', 'high');
    }
});

function dispatcher_status_classic_meta_box($post) {
    global $wpdb;
    ?>
    <script>
        function fn_dispatcher_action(act) {
            jQuery('#dsp-action').val(act);
            if (!confirm('Proceed?')) {
                return false;
            }

            if (typeof tinyMCE !== 'undefined') {
                tinyMCE.triggerSave();
            }

            // Because the "save" button is different for draft and published posts...
            if (document.getElementById('save-post')) {
                jQuery('#save-post').click();
            } else {
                jQuery('#publish').click();
            }
            return false;
        }
    </script>
    <?php
    echo '<div id="dsp-status-meta-box">';
    require_once __DIR__ . '/includes/status-metabox.php';
    echo '</div>';

    echo '<div id="dsp-audience-meta-box">';
    require_once __DIR__ . '/includes/audience-metabox.php';
    echo '</div>';
}

function dispatcher_status_gutenberg_meta_box($post) {
    ?>
    <script>
        function fn_dispatcher_action(act) {
            if (!confirm('Proceed?')) {
                return false;
            }
            const data = {
                action: 'dispatcher_gutenberg',
                _dispatchernonce: '<?= wp_create_nonce('save') ?>',
                act: act,
                post_id: wp.data.select('core/editor').getCurrentPostId()
            };

            jQuery.post(ajaxurl, data, function (response) {
                if (act === 'test') {
                    alert(response);
                } else {
                    jQuery('#dsp-status-meta-box').html(response);
                }
            }).fail(function () {
                alert('Server error happened.');
            }).always(function () {
            });
        }
    </script>
    <?php
    echo '<div id="dsp-status-meta-box">';
    require_once __DIR__ . '/includes/status-metabox.php';
    echo '</div>';

    echo '<div id="dsp-audience-meta-box">';
    require_once __DIR__ . '/includes/audience-metabox.php';
    echo '</div>';
}

// Save action from the classic editor
add_action('save_post_' . Dispatcher::POST_TYPE, function ($post_id, $post, $update) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $status = Dispatcher::get_post_meta($post->ID, 'status');

    if ($status != 'sending') {
        if (wp_verify_nonce($_POST['audience_nonce'] ?? '', 'save')) {

            $audience_settings = wp_unslash($_POST['audience_data'] ?? []);
            $audience_id = sanitize_key($_POST['audience_id'] ?? '');

//        $audience = new stdClass();
//        $audience->id = $audience_id;
//        $audience->data = $audience_data;
//        $audience->total = 0;
            //do_action('dispatcher_audience_save', $audience);
            //do_action('dispatcher_audience_save_' . $audience_id, $audience);

            Dispatcher::update_post_meta($post_id, 'audience_id', $audience_id);
            Dispatcher::update_post_meta($post_id, 'audience_settings', $audience_settings);
            //update_post_meta($post_id, 'audience_total', (int) $audience->total);
        }
    }

    $action = sanitize_key($_POST['dispatcher_action'] ?? '');

    dispatcher_process_action($post, $action);
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


