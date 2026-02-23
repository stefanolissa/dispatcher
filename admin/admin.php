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

        if ($_GET['page'] ?? '' === 'dispatcher_settings') {
            add_action('admin_enqueue_scripts', function ($hook_suffix) {
                wp_enqueue_style('dispatcher', plugin_dir_url(__FILE__) . 'admin.css', [], DISPATCHER_VERSION);
            });
        }

        add_action('wp_ajax_dispatcher_audience_settings', [self::class, 'dispatcher_audience_settings']);

        add_action('dispatcher_audience_settings_dsp-roles', function ($audience) {
            $roles = $audience->data['roles'] ?? [];
            foreach (wp_roles()->roles as $key => $wp_role) {
                echo '<label><input type="checkbox" name="audience_data[roles][]" value="', esc_attr($key), '" ',
                in_array($key, $roles) ? 'checked' : '',
                '> ', esc_html($wp_role['name']), '</label><br>';
            }
        });
    }

    static function dispatcher_audience_settings() {
        check_ajax_referer('save', 'audience_nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error('You do not have permission to do this.', 403);
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $audience_settings = get_post_meta($post_id, 'audience_settings', true);
        if (!is_array($audience_data)) {
            $audience_settings = [];
        }

        $audience_id = sanitize_key($_POST['audience_id'] ?? '');

        Dispatcher::init_audiences();

        $audience = Dispatcher::$audiences[$audience_id] ?? false;

        if (!$audience) {
            echo 'Error: audience not found!';
            return;
        }

        // It's better to have a specific action to avoid to trigger all the Audiences
        //do_action('dispatcher_audience_settings', $audience);
        if (method_exists($audience, 'form')) {
            $audience->form($audience_settings);
        } else {
            echo 'No specific settings.';
        }
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
        if ($editor_context->post_type === Dispatcher::POST_TYPE) {
            $allowed_blocks = [
                'core/paragraph',
                'core/heading',
                'core/image',
                'core/list',
                'core/columns',
                'core/columns',
                'core/group',
                'core/spacer',
                    //'core/latest-posts'
            ];
        }
        return $allowed_blocks;
    }, 10, 2);
}

add_action('current_screen', function () {

    $screen = get_current_screen();

    if ($screen->base === 'edit' && $screen->post_type === Dispatcher::POST_TYPE) {

        add_action('admin_head', function () {
            echo '<style>
        .column-id { width: 4rem }
        .column-status { width: 6rem }
        .column-audience { width: 15rem }
        .column-send-time { width: 15rem }
        span.dsp-status { background-color: #ddd; display: inline-block; padding: .2em .5em; min-width: 5em; text-align: center; border-radius: 5em; }
        span.dsp-status.dsp-status-sending { background-color: #dde; }
        span.dsp-status.dsp-status-sent { background-color: #ded; }
        span.dsp-status.dsp-status-paused { background-color: #ede; }
        </style>';
        });

        add_action('admin_notices', function () {

            $next = wp_next_scheduled('dispatcher_run');
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    Emails are sent in batches every 15 minutes. Next batch in <?= dispatcher_format_interval($next - time()) ?>
                </p>
            </div>
            <?php
        });
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
function dispatcher_build_audience($email) {
    $audience_id = sanitize_key(get_post_meta($email->ID, 'audience_id', true));

    $last_contact_id = sanitize_key(get_post_meta($email->ID, 'last_contact_id', true));

    $audience_data = get_post_meta($email->ID, 'audience_data', true);
    if (!is_array($audience_data)) {
        $audience_data = [];
    }
    $total = (int) get_post_meta($email->ID, 'audience_total', true);

    $audience = new stdClass();
    $audience->id = $audience_id;
    $audience->data = $audience_data;
    $audience->last_contact_id = $last_contact_id;
    $audience->total = $total;
    return $audience;
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
        update_post_meta($email->ID, 'dispatcher_send_time', time());
        update_post_meta($email->ID, 'dispatcher_last_contact_id', '0');
        $audience = dispatcher_build_audience($email);
        do_action('dispatcher_audience_update_total_' . $audience->id, $audience);
        update_post_meta($email->ID, 'audience_total', (int) $audience->total);
        update_post_meta($email->ID, 'dispatcher_status', 'sending');
        Dispatcher::run();
    } elseif ($action === 'pause') {
        update_post_meta($email->ID, 'dispatcher_status', 'paused');
    } elseif ($action === 'continue') {
        update_post_meta($email->ID, 'dispatcher_status', 'sending');
    } elseif ($action === 'resend') {
        update_post_meta($email->ID, 'dispatcher_send_time', time());
        update_post_meta($email->ID, 'dispatcher_last_contact_id', '0');
        $audience = dispatcher_build_audience($email);
        do_action('dispatcher_audience_update_total_' . $audience->id, $audience);
        update_post_meta($email->ID, 'audience_total', (int) $audience->total);
        update_post_meta($email->ID, 'dispatcher_status', 'sending');
        Dispatcher::run();
    } elseif ($action === 'stop') {
        update_post_meta($email->ID, 'dispatcher_last_contact_id', '0');
        update_post_meta($email->ID, 'dispatcher_status', 'draft');
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

    dispatcher_echo_status_meta_box_content($post);
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

add_filter('manage_dispatcher_email_posts_columns', function ($columns) {
    //$columns = array_merge(['id' => 'ID'], $columns);
    $columns['status'] = 'Status';
    $columns['send_time'] = 'Sending date';
    $columns['audience'] = 'Audience';
    $columns['id'] = 'ID';

    return $columns;
});

add_action('manage_dispatcher_email_posts_custom_column', function ($column, $post_id) {
    global $wpdb;
    switch ($column) {
        case 'id' :
            echo (int) $post_id;
            break;
        case 'audience' :
            $audience = get_post_meta($post_id, 'dispatcher_audience', true);

            $roles = $audience['roles'] ?? [];
            if (!$roles) {
                echo 'None';
            } else {
                echo esc_html(implode(', ', $roles));
            }
            break;
        case 'status' :
            $status = get_post_meta($post_id, 'dispatcher_status', true) ?: 'draft';
            echo '<span class="dsp-status dsp-status-', esc_attr($status), '">';
            echo esc_html(ucfirst($status));
            if ($status === 'sending') {
                $total = (int) $wpdb->get_var("select count(*) from {$wpdb->users}");
                $last_id = (int) get_post_meta($post_id, 'dispatcher_user_id', true);
                $sent = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->users} where id <= %d", $last_id));
                $percent = floor($sent * 100.0 / $total);
                echo ' (', (int) $percent, '%)';
            }
            echo '</span>';
            break;

        case 'send_time' :
            $send_time = get_post_meta($post_id, 'dispatcher_send_time', true);
            if ($send_time) {
                echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), $send_time);
            }
            break;
    }
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
    $status = get_post_meta($post->ID, 'dispatcher_status', true) ?: 'draft';

    $total = (int) $wpdb->get_var("select count(*) from {$wpdb->users}");
    $last_id = (int) get_post_meta($post->ID, 'dispatcher_user_id', true);
    $sent = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->users} where id <= %d", $last_id));
    $percent = floor($sent * 100.0 / $total);

    echo '<div id="dsp-status-meta-box">';

    echo '<p style="float: left; text-align: left; font-size: 1.2em">';
    if ($status === 'sending') {
        echo '<p style="float: left; text-align: left; font-size: 1.2em">', esc_html(ucfirst($status)), ' (', $percent, '%)</p>';
    } else {
        echo esc_html(ucfirst($status));
    }
    echo '</p>';

    echo '<p style="float: right; text-align: right">';

    echo '<input type="submit" name="dispatcher_test" value="Test" class="button button-secondary button-large" onclick="return fn_dispatcher_action(\'test\')"> ';
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
    echo '<p style="clear: both">';
    if ($status === 'sending') {
        $next = wp_next_scheduled('dispatcher_run');
        echo '<small>Next batch will be sent in ', dispatcher_format_interval($next - time()), '</small>';
    } elseif ($status === 'sent') {
        $send_time = get_post_meta($post->ID, 'dispatcher_send_time', true);
        if ($send_time) {
            echo 'Sent on ', wp_date(get_option('date_format') . ' ' . get_option('time_format'), $send_time),
            ' to ', intval(get_post_meta($post->ID, 'dispatcher_count', true)), ' users';
        }
    }
    echo '</p>';

    echo '<input type="hidden" name="dispatcher_action" id="dsp-action" value="">';
    echo '</div>';

    echo '<div id="dsp-audience-meta-box">';
    dispatcher_echo_audience_meta_box_content($post);
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

            jQuery.post('<?= admin_url('admin-ajax.php') ?>', data, function (response) {
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
    dispatcher_echo_status_meta_box_content($post);
    echo '</div>';

    echo '<div id="dsp-audience-meta-box">';
    dispatcher_echo_audience_meta_box_content($post);
    echo '</div>';
}

function dispatcher_echo_status_meta_box_content($post) {
    global $wpdb;
    $status = get_post_meta($post->ID, 'dispatcher_status', true) ?: 'draft';

    $total = (int) $wpdb->get_var("select count(*) from {$wpdb->users}");
    $last_id = (int) get_post_meta($post->ID, 'dispatcher_user_id', true);
    $sent = (int) $wpdb->get_var($wpdb->prepare("select count(*) from {$wpdb->users} where id <= %d", $last_id));
    $percent = floor($sent * 100.0 / $total);

    echo '<p style="float: left; text-align: left; font-size: 1.2em">';
    if ($status === 'sending') {
        //$next = wp_next_scheduled('dispatcher_run');
        echo '<p style="float: left; text-align: left; font-size: 1.2em">', esc_html(ucfirst($status)), ' (', $percent, '%)</p>';
        //echo '<small>Next batch will be sent in ', dispatcher_format_interval($next - time()), '</small>';
    } else {
        echo esc_html(ucfirst($status));
    }
    echo '</p>';

    echo '<p style="float: right; text-align: right">';

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
    echo '<p style="clear: both">';
    if ($status === 'sending') {
        $next = wp_next_scheduled('dispatcher_run');
        echo '<small>Next batch will be sent in ', dispatcher_format_interval($next - time()), '</small>';
    } elseif ($status === 'sent') {
        $send_time = get_post_meta($post->ID, 'dispatcher_send_time', true);
        if ($send_time) {
            echo 'Sent on ', wp_date(get_option('date_format') . ' ' . get_option('time_format'), $send_time),
            ' to ', intval(get_post_meta($post->ID, 'dispatcher_count', true)), ' users';
        }
    }
    echo '</p>';

    echo '<input type="hidden" name="dispatcher_action" id="dispatcher_action" value="">';
}

function dispatcher_echo_audience_meta_box_content($post) {
    ?>
    <script>
        jQuery(function () {
            jQuery('select#audience').on('change', function () {
                jQuery('#audience-settings').html('Loading...');
                this.disabled = true;
                const data = {
                    action: 'dispatcher_audience_settings',
                    audience_nonce: jQuery('#audience_nonce').val(),
                    audience_id: this.value,
                    post_id: jQuery('#audience_post_id').val()
                };
                jQuery.post('<?= admin_url('admin-ajax.php') ?>', data, function (response) {
                    jQuery('#audience-settings').html(response);
                    jQuery('select#audience').prop('disabled', false);
                }).fail(function () {
                    alert('Server error happened.');
                }).always(function () {
                });
            });
            jQuery('select#audience').trigger('change');
        });
    </script>
    <?php

    Dispatcher::init_audiences();

    $audience_id = sanitize_key(get_post_meta($post->ID, 'audience_id', true));
    wp_nonce_field('save', 'audience_nonce');
    echo '<input type="hidden" name="audience_post_id" id="audience_post_id" value="' . ((int)$post->ID) . '">';
    echo '<select name="audience_id" id="audience">';
    foreach (Dispatcher::$audiences as $audience) {
        echo '<option value="', esc_attr(sanitize_key($audience->id)), '" ', ($audience_id == $audience->id ? 'selected' : ''), '>', esc_html($audience->name), '</option>';
    }
    echo '</select>';

    echo '<div id="audience-settings" style="margin-top: 1rem"></div>';
}

// Save action from the classic editor
add_action('save_post_' . Dispatcher::POST_TYPE, function ($post_id, $post, $update) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_verify_nonce($_POST['audience_nonce'] ?? '', 'save')) {

        $audience_settings = wp_unslash($_POST['audience_data'] ?? []);
        $audience_id = sanitize_key($_POST['audience_id'] ?? '');

//        $audience = new stdClass();
//        $audience->id = $audience_id;
//        $audience->data = $audience_data;
//        $audience->total = 0;

        //do_action('dispatcher_audience_save', $audience);
        //do_action('dispatcher_audience_save_' . $audience_id, $audience);

        update_post_meta($post_id, 'audience_id', $audience_id);
        update_post_meta($post_id, 'audience_settings', $audience_settings);
        //update_post_meta($post_id, 'audience_total', (int) $audience->total);
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
